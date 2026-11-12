using System.Diagnostics;
using System.Runtime.InteropServices;

namespace MindCollector.Desktop;

/// <summary>
/// What the PC plays, for the app's own recorder (NativeRecorder, stage 2, 2026-11-12): Windows' audio
/// engine (WASAPI) in loopback on the default output, which is what OBS's "Desktop Audio" hears, so it
/// includes voice chat too. Its format is the output's mix format, 32-bit float in practice.
///
/// Windows sends nothing at all while nothing plays, so the gaps are filled with silence against the
/// clock: the audio stays in step with the video, and a fight that starts in silence still has sound
/// once the game makes some. <see cref="Chunk"/> carries each stretch with its place in the stream
/// (frames since <see cref="Start"/>), and <see cref="Started"/> is that moment on the QPC clock the
/// video's frames are stamped with.
/// </summary>
public sealed class AudioLoopback : IDisposable
{
    public sealed record Chunk(byte[] Data, long FirstFrame, int Frames);

    public int SampleRate { get; }
    public int Channels { get; }
    public int BytesPerFrame => Channels * 4;
    public TimeSpan Started { get; private set; }
    public long Written => Interlocked.Read(ref _written);
    public long Padded => Interlocked.Read(ref _padded);
    public float Peak { get; private set; }

    public event Action<Chunk>? Data;

    private readonly IAudioClient _client;
    private readonly IAudioCaptureClient _capture;
    private Thread? _thread;
    private volatile bool _stop;
    private long _written, _padded;

    /// <summary>Allowed lag before silence fills a gap: Windows hands audio over in ~10ms packets.</summary>
    private static readonly double Slack = 0.1;

    public AudioLoopback()
    {
        var enumerator = (IMMDeviceEnumerator)new MMDeviceEnumerator();
        Check(enumerator.GetDefaultAudioEndpoint(0 /* render */, 1 /* multimedia */, out var device));
        var iidClient = new Guid("1CB9AD4C-DBFA-4c32-B178-C2F568A703B2");
        Check(device.Activate(ref iidClient, 23 /* CLSCTX_ALL */, IntPtr.Zero, out var client));
        _client = (IAudioClient)client;
        Check(_client.GetMixFormat(out var format));
        try
        {
            var tag = (ushort)Marshal.ReadInt16(format, 0);
            Channels = (ushort)Marshal.ReadInt16(format, 2);
            SampleRate = Marshal.ReadInt32(format, 4);
            var bits = (ushort)Marshal.ReadInt16(format, 14);
            var isFloat = tag == 3 || (tag == 0xFFFE && Marshal.PtrToStructure<Guid>(format + 24) == new Guid("00000003-0000-0010-8000-00aa00389b71"));
            if (!isFloat || bits != 32) throw new NotSupportedException($"the output's mix format is not 32-bit float (tag {tag}, {bits} bits)");
            // shared, loopback, a one-second buffer
            Check(_client.Initialize(0, 0x00020000, 10_000_000, 0, format, IntPtr.Zero));
        }
        finally { Marshal.FreeCoTaskMem(format); }
        var iidCapture = new Guid("C8ADBD64-E71E-48a0-A4DE-185C395CD317");
        Check(_client.GetService(ref iidCapture, out var capture));
        _capture = (IAudioCaptureClient)capture;
    }

    public void Start()
    {
        Started = QpcNow();
        Check(_client.Start());
        var clock = Stopwatch.StartNew();
        _thread = new Thread(() => Run(clock)) { IsBackground = true, Name = "MindCollector audio", Priority = ThreadPriority.AboveNormal };
        _thread.Start();
    }

    private void Run(Stopwatch clock)
    {
        var buffer = new MemoryStream();
        while (!_stop)
        {
            Thread.Sleep(10);
            buffer.SetLength(0);
            var frames = 0;
            while (_capture.GetNextPacketSize(out var next) == 0 && next > 0)
            {
                if (_capture.GetBuffer(out var data, out var n, out var flags, out _, out _) != 0) break;
                var bytes = (int)n * BytesPerFrame;
                var chunk = new byte[bytes];
                if ((flags & 0x2) == 0) // not AUDCLNT_BUFFERFLAGS_SILENT
                {
                    Marshal.Copy(data, chunk, 0, bytes);
                    for (var i = 0; i < bytes; i += 4)
                    {
                        var v = Math.Abs(BitConverter.ToSingle(chunk, i));
                        if (v > Peak) Peak = v;
                    }
                }
                _capture.ReleaseBuffer(n);
                buffer.Write(chunk);
                frames += (int)n;
            }
            // nothing playing: fill with silence up to the clock, less the slack
            var expected = (long)((clock.Elapsed.TotalSeconds - Slack) * SampleRate);
            var behind = expected - (_written + frames);
            if (behind > 0)
            {
                buffer.Write(new byte[behind * BytesPerFrame]);
                frames += (int)behind;
                Interlocked.Add(ref _padded, behind);
            }
            if (frames == 0) continue;
            Data?.Invoke(new Chunk(buffer.ToArray(), _written, frames));
            Interlocked.Add(ref _written, frames);
        }
    }

    public void Dispose()
    {
        _stop = true;
        _thread?.Join(1000);
        try { _client.Stop(); } catch { }
        Marshal.ReleaseComObject(_capture);
        Marshal.ReleaseComObject(_client);
    }

    /// <summary>Now on the clock Windows.Graphics.Capture stamps frames with (QPC, in 100ns ticks).</summary>
    public static TimeSpan QpcNow() => TimeSpan.FromTicks((long)(Stopwatch.GetTimestamp() * (10_000_000.0 / Stopwatch.Frequency)));

    private static void Check(int hr) { if (hr < 0) Marshal.ThrowExceptionForHR(hr); }

    // ---------------------------------------------------------------- WASAPI

    [ComImport, Guid("BCDE0395-E52F-467C-8E3D-C4579291692E")]
    private class MMDeviceEnumerator { }

    [ComImport, Guid("A95664D2-9614-4F35-A746-DE8DB63617E6"), InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
    private interface IMMDeviceEnumerator
    {
        [PreserveSig] int EnumAudioEndpoints(int dataFlow, int stateMask, out IntPtr devices);
        [PreserveSig] int GetDefaultAudioEndpoint(int dataFlow, int role, out IMMDevice device);
    }

    [ComImport, Guid("D666063F-1587-4E43-81F1-B948E807363F"), InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
    private interface IMMDevice
    {
        [PreserveSig] int Activate(ref Guid iid, int clsCtx, IntPtr activationParams, [MarshalAs(UnmanagedType.IUnknown)] out object iface);
    }

    [ComImport, Guid("1CB9AD4C-DBFA-4c32-B178-C2F568A703B2"), InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
    private interface IAudioClient
    {
        [PreserveSig] int Initialize(int shareMode, int streamFlags, long bufferDuration, long periodicity, IntPtr format, IntPtr sessionGuid);
        [PreserveSig] int GetBufferSize(out uint frames);
        [PreserveSig] int GetStreamLatency(out long latency);
        [PreserveSig] int GetCurrentPadding(out uint padding);
        [PreserveSig] int IsFormatSupported(int shareMode, IntPtr format, out IntPtr closest);
        [PreserveSig] int GetMixFormat(out IntPtr format);
        [PreserveSig] int GetDevicePeriod(out long defaultPeriod, out long minimumPeriod);
        [PreserveSig] int Start();
        [PreserveSig] int Stop();
        [PreserveSig] int Reset();
        [PreserveSig] int SetEventHandle(IntPtr handle);
        [PreserveSig] int GetService(ref Guid iid, [MarshalAs(UnmanagedType.IUnknown)] out object service);
    }

    [ComImport, Guid("C8ADBD64-E71E-48a0-A4DE-185C395CD317"), InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
    private interface IAudioCaptureClient
    {
        [PreserveSig] int GetBuffer(out IntPtr data, out uint frames, out uint flags, out ulong devicePosition, out ulong qpcPosition);
        [PreserveSig] int ReleaseBuffer(uint frames);
        [PreserveSig] int GetNextPacketSize(out uint frames);
    }
}
