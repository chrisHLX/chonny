using System.Collections.Concurrent;
using System.Net.WebSockets;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace MindCollector.Desktop;

/// <summary>
/// OBS Studio's own remote control: obs-websocket 5, built into OBS since 28, on 127.0.0.1 (port
/// 4455 unless changed). The app uses it to start and stop a recording and to choose its folder
/// (Recorder). No library: the protocol is JSON over a websocket, with one SHA-256 handshake.
///
///   Hello (op 0) -> Identify (op 1) -> Identified (op 2), then Request (op 6) / RequestResponse
///   (op 7) and Event (op 5). Auth: base64(sha256(base64(sha256(password + salt)) + challenge)).
///
/// Only the Outputs events are asked for (bit 6): RecordStateChanged says when the recording really
/// started (the first frame's wall-clock time, which is what lines the video up with the log) and
/// gives the file's path when it stops.
/// </summary>
public sealed class ObsClient : IAsyncDisposable
{
    public sealed class ObsException(string message) : Exception(message);

    private const int OutputsEvents = 1 << 6;

    private readonly ClientWebSocket _ws = new();
    private readonly SemaphoreSlim _send = new(1, 1);
    private readonly ConcurrentDictionary<string, TaskCompletionSource<JsonElement>> _pending = new();
    private readonly CancellationTokenSource _cts = new();
    private Task? _loop;

    public string ObsVersion { get; private set; } = "";
    public string WebSocketVersion { get; private set; } = "";
    public bool Connected => _ws.State == WebSocketState.Open;

    /// <summary>RecordStateChanged: OBS's state name (OBS_WEBSOCKET_OUTPUT_STARTED, _STOPPED, ...) and the file, once there is one.</summary>
    public event Action<string, string?>? RecordStateChanged;

    public static async Task<ObsClient> ConnectAsync(int port, string? password, CancellationToken ct)
    {
        var client = new ObsClient();
        try
        {
            await client.OpenAsync(port, password, ct);
            return client;
        }
        catch
        {
            await client.DisposeAsync();
            throw;
        }
    }

    private async Task OpenAsync(int port, string? password, CancellationToken ct)
    {
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
        timeout.CancelAfter(TimeSpan.FromSeconds(5));
        try
        {
            await _ws.ConnectAsync(new Uri($"ws://127.0.0.1:{port}"), timeout.Token);
        }
        catch (Exception e) when (e is WebSocketException || (e is OperationCanceledException && !ct.IsCancellationRequested))
        {
            throw new ObsException("OBS is not answering. Open OBS, and check Tools > WebSocket Server Settings > Enable WebSocket server.");
        }

        var hello = await ReceiveAsync(timeout.Token) ?? throw new ObsException("OBS closed the connection straight away.");
        if (Op(hello) != 0) throw new ObsException("OBS did not say hello as expected.");
        var d = hello.GetProperty("d");
        WebSocketVersion = d.TryGetProperty("obsWebSocketVersion", out var v) ? v.GetString() ?? "" : "";

        var identify = new JsonObject { ["rpcVersion"] = 1, ["eventSubscriptions"] = OutputsEvents };
        if (d.TryGetProperty("authentication", out var auth))
        {
            if (string.IsNullOrEmpty(password))
                throw new ObsException("OBS asks for a password and none was found in its settings.");
            var secret = Sha256Base64(password + auth.GetProperty("salt").GetString());
            identify["authentication"] = Sha256Base64(secret + auth.GetProperty("challenge").GetString());
        }
        await SendAsync(new JsonObject { ["op"] = 1, ["d"] = identify }, timeout.Token);

        var identified = await ReceiveAsync(timeout.Token);
        if (identified is not { } id || Op(id) != 2)
        {
            throw _ws.CloseStatus == (WebSocketCloseStatus)4009
                ? new ObsException("OBS turned down the password. Open Settings > Set up OBS again.")
                : new ObsException($"OBS would not connect ({_ws.CloseStatusDescription ?? "no reason given"}).");
        }

        _loop = Task.Run(ReadLoopAsync);
        var version = await RequestAsync("GetVersion", null, ct);
        ObsVersion = version.TryGetProperty("obsVersion", out var ov) ? ov.GetString() ?? "" : "";
    }

    /// <summary>One request; its responseData (an empty object when it has none). Throws when OBS says no.</summary>
    public async Task<JsonElement> RequestAsync(string type, JsonObject? data, CancellationToken ct)
    {
        var requestId = Guid.NewGuid().ToString("N");
        var tcs = new TaskCompletionSource<JsonElement>(TaskCreationOptions.RunContinuationsAsynchronously);
        _pending[requestId] = tcs;
        try
        {
            var d = new JsonObject { ["requestType"] = type, ["requestId"] = requestId };
            if (data != null) d["requestData"] = data;
            await SendAsync(new JsonObject { ["op"] = 6, ["d"] = d }, ct);

            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
            timeout.CancelAfter(TimeSpan.FromSeconds(15));
            var response = await tcs.Task.WaitAsync(timeout.Token);

            var status = response.GetProperty("requestStatus");
            if (!status.GetProperty("result").GetBoolean())
            {
                var comment = status.TryGetProperty("comment", out var c) ? c.GetString() : null;
                var code = status.TryGetProperty("code", out var n) ? n.GetInt32() : 0;
                throw new ObsException($"OBS said no to {type}: {comment ?? $"code {code}"}");
            }
            return response.TryGetProperty("responseData", out var rd) ? rd : JsonDocument.Parse("{}").RootElement;
        }
        catch (OperationCanceledException) when (!ct.IsCancellationRequested)
        {
            throw new ObsException($"OBS did not answer {type}.");
        }
        finally
        {
            _pending.TryRemove(requestId, out _);
        }
    }

    /// <summary>A request whose failure is not an error (an older OBS without it, a setting it does not have).</summary>
    public async Task<JsonElement?> TryRequestAsync(string type, JsonObject? data, CancellationToken ct)
    {
        try { return await RequestAsync(type, data, ct); }
        catch (ObsException) { return null; }
    }

    private async Task ReadLoopAsync()
    {
        try
        {
            while (!_cts.IsCancellationRequested)
            {
                var message = await ReceiveAsync(_cts.Token);
                if (message is not { } m) break;
                var d = m.GetProperty("d");
                switch (Op(m))
                {
                    case 7:
                        if (d.TryGetProperty("requestId", out var rid) && _pending.TryGetValue(rid.GetString() ?? "", out var tcs))
                            tcs.TrySetResult(d);
                        break;
                    case 5:
                        if (d.TryGetProperty("eventType", out var et) && et.GetString() == "RecordStateChanged" && d.TryGetProperty("eventData", out var ed))
                        {
                            var state = ed.TryGetProperty("outputState", out var s) ? s.GetString() ?? "" : "";
                            var path = ed.TryGetProperty("outputPath", out var p) && p.ValueKind == JsonValueKind.String ? p.GetString() : null;
                            try { RecordStateChanged?.Invoke(state, path); } catch { }
                        }
                        break;
                }
            }
        }
        catch { /* closed or cancelled */ }
        finally
        {
            foreach (var tcs in _pending.Values) tcs.TrySetException(new ObsException("OBS closed the connection."));
        }
    }

    private async Task<JsonElement?> ReceiveAsync(CancellationToken ct)
    {
        var buffer = new byte[16 * 1024];
        using var ms = new MemoryStream();
        while (true)
        {
            WebSocketReceiveResult r;
            try { r = await _ws.ReceiveAsync(buffer, ct); }
            catch (WebSocketException) { return null; }
            if (r.MessageType == WebSocketMessageType.Close) return null;
            ms.Write(buffer, 0, r.Count);
            if (r.EndOfMessage) break;
        }
        using var doc = JsonDocument.Parse(ms.ToArray());
        return doc.RootElement.Clone();
    }

    private async Task SendAsync(JsonObject message, CancellationToken ct)
    {
        var bytes = Encoding.UTF8.GetBytes(message.ToJsonString());
        await _send.WaitAsync(ct);
        try { await _ws.SendAsync(bytes, WebSocketMessageType.Text, true, ct); }
        catch (WebSocketException) { throw new ObsException("OBS closed the connection."); }
        finally { _send.Release(); }
    }

    private static int Op(JsonElement message) => message.GetProperty("op").GetInt32();

    private static string Sha256Base64(string text) => Convert.ToBase64String(SHA256.HashData(Encoding.UTF8.GetBytes(text)));

    public async ValueTask DisposeAsync()
    {
        _cts.Cancel();
        try
        {
            if (_ws.State == WebSocketState.Open)
            {
                using var t = new CancellationTokenSource(TimeSpan.FromSeconds(2));
                await _ws.CloseAsync(WebSocketCloseStatus.NormalClosure, "bye", t.Token);
            }
        }
        catch { }
        if (_loop != null) try { await _loop.WaitAsync(TimeSpan.FromSeconds(2)); } catch { }
        _ws.Dispose();
        _cts.Dispose();
    }
}
