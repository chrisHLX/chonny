using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Runtime.InteropServices.WindowsRuntime;
using Windows.Graphics.Capture;
using Windows.Graphics.DirectX;
using Windows.Graphics.DirectX.Direct3D11;
using Windows.Media.Core;
using Windows.Media.MediaProperties;
using Windows.Media.Transcoding;
using Windows.Storage;
using WinRT;

namespace MindCollector.Desktop;

/// <summary>
/// The app's own recorder (2026-11-12, Chriso: "lets build our own into the app"), so players need no
/// OBS. This reverses docs/vision-experiment.md's "do not build a recorder": OBS was the step new
/// players tripped on (Cbags' replay buffer, 12 Nov).
///
/// Stage 1, video: Windows' own capture of the WoW window (Windows.Graphics.Capture). Each frame is
/// copied on the graphics card into one of our own textures and the capture's frame handed straight
/// back, then our texture goes to the hardware H.264 encoder (MediaStreamSource into MediaTranscoder).
/// Nothing passes through the CPU. Handing the encoder the capture's own frames stalled it: the
/// encoder holds frames while it buffers, and capture has only a few (22 frames in 10s on 12 Nov).
/// Frames are capped at <see cref="Options.Fps"/>: the window delivers one per screen refresh.
/// </summary>
public sealed class NativeRecorder
{
    public sealed record Options(int Fps = 60, uint BitsPerSecond = 16_000_000, bool Audio = true);

    public sealed record Result(string Path, int Width, int Height, int Captured, int Encoded, int Skipped, int Dropped,
        TimeSpan Length, TimeSpan Took, long Bytes, string? Error, string? Audio = null);

    /// <summary>Our textures waiting for the encoder, at most; more than this and the oldest is dropped.</summary>
    private const int Backlog = 12;

    /// <summary>Records <paramref name="hwnd"/> for <paramref name="length"/> into an MP4 at <paramref name="path"/>.</summary>
    public static async Task<Result> RecordAsync(IntPtr hwnd, string path, TimeSpan length, Options? options = null)
    {
        options ??= new Options();
        var watch = Stopwatch.StartNew();
        using var gpu = new Gpu();
        var item = Capture.ForWindow(hwnd);
        var width = item.Size.Width & ~1;
        var height = item.Size.Height & ~1;

        var textures = new TexturePool(gpu, Backlog + 6);
        var queue = new Queue<(TexturePool.Slot Slot, TimeSpan At)>();
        var gate = new SemaphoreSlim(0);
        int captured = 0, skipped = 0, dropped = 0, encoded = 0;
        var minGap = TimeSpan.FromSeconds(1.0 / options.Fps) - TimeSpan.FromMilliseconds(1);
        TimeSpan? first = null, lastKept = null, lastStamp = null;
        var stopping = false;

        // Stage 2: what the PC plays (AudioLoopback). Both streams count from the moment it starts.
        AudioLoopback? audio = null;
        string? audioNote = null;
        if (options.Audio)
        {
            try { audio = new AudioLoopback(); }
            catch (Exception e) { audioNote = $"no sound: {e.Message}"; }
        }
        var chunks = new Queue<AudioLoopback.Chunk>();
        var chunkGate = new SemaphoreSlim(0);
        if (audio != null)
            audio.Data += c => { lock (chunks) chunks.Enqueue(c); chunkGate.Release(); };
        TimeSpan? origin = null;

        using var pool = Direct3D11CaptureFramePool.CreateFreeThreaded(gpu.Device, DirectXPixelFormat.B8G8R8A8UIntNormalized, 2, item.Size);
        using var session = pool.CreateCaptureSession(item);
        session.IsCursorCaptureEnabled = false;
        pool.FrameArrived += (p, _) =>
        {
            using var frame = p.TryGetNextFrame();
            if (frame == null) return;
            captured++;
            var t = frame.SystemRelativeTime;
            if (stopping || origin == null || t < origin || (lastKept is { } k && t - k < minGap)) { skipped++; return; }
            var slot = textures.CopyOf(frame.Surface);
            if (slot == null) { dropped++; return; }   // every texture is with the encoder
            lastKept = t;
            lock (queue)
            {
                while (queue.Count >= Backlog) { textures.Return(queue.Dequeue().Slot); dropped++; }
                queue.Enqueue((slot, t));
            }
            gate.Release();
        };

        var video = VideoEncodingProperties.CreateUncompressed(MediaEncodingSubtypes.Bgra8, (uint)width, (uint)height);
        var videoStream = new VideoStreamDescriptor(video);
        MediaStreamSource source;
        if (audio != null)
        {
            var pcm = AudioEncodingProperties.CreatePcm((uint)audio.SampleRate, (uint)audio.Channels, 32);
            pcm.Subtype = MediaEncodingSubtypes.Float;
            source = new MediaStreamSource(videoStream, new AudioStreamDescriptor(pcm));
        }
        else source = new MediaStreamSource(videoStream);
        source.BufferTime = TimeSpan.Zero;
        source.IsLive = true;
        source.Starting += (_, e) => e.Request.SetActualStartPosition(TimeSpan.Zero);
        source.SampleRequested += async (_, e) =>
        {
            var deferral = e.Request.GetDeferral();
            try
            {
                if (e.Request.StreamDescriptor is AudioStreamDescriptor)
                {
                    AudioLoopback.Chunk? chunk = null;
                    while (chunk == null)
                    {
                        if (stopping) break;
                        if (!await chunkGate.WaitAsync(500)) continue;
                        lock (chunks) { if (chunks.Count > 0) chunk = chunks.Dequeue(); }
                    }
                    if (chunk == null) { e.Request.Sample = null; return; }
                    var at = TimeSpan.FromSeconds(chunk.FirstFrame / (double)audio!.SampleRate);
                    var sound = MediaStreamSample.CreateFromBuffer(chunk.Data.AsBuffer(), at);
                    sound.Duration = TimeSpan.FromSeconds(chunk.Frames / (double)audio.SampleRate);
                    e.Request.Sample = sound;
                    return;
                }
                (TexturePool.Slot Slot, TimeSpan At)? next = null;
                while (next == null)
                {
                    if (stopping) break;
                    if (!await gate.WaitAsync(500)) continue;
                    lock (queue) { if (queue.Count > 0) next = queue.Dequeue(); }
                }
                if (next is not { } n) { e.Request.Sample = null; return; }   // the end of the stream
                first ??= n.At;
                var stamp = n.At - origin!.Value;
                if (lastStamp is { } ls && stamp <= ls) stamp = ls + TimeSpan.FromMilliseconds(1);
                lastStamp = stamp;
                var sample = MediaStreamSample.CreateFromDirect3D11Surface(n.Slot.Surface, stamp);
                sample.Processed += (_, _) => { textures.Return(n.Slot); Interlocked.Increment(ref encoded); };
                e.Request.Sample = sample;
            }
            finally { deferral.Complete(); }
        };

        var profile = MediaEncodingProfile.CreateMp4(VideoEncodingQuality.HD1080p);
        profile.Audio = audio != null ? AudioEncodingProperties.CreateAac((uint)audio.SampleRate, (uint)Math.Min(audio.Channels, 2), 192_000) : null;
        profile.Video.Width = (uint)width;
        profile.Video.Height = (uint)height;
        profile.Video.Bitrate = options.BitsPerSecond;
        profile.Video.FrameRate.Numerator = (uint)options.Fps;
        profile.Video.FrameRate.Denominator = 1;
        profile.Video.PixelAspectRatio.Numerator = 1;
        profile.Video.PixelAspectRatio.Denominator = 1;

        var folder = await StorageFolder.GetFolderFromPathAsync(System.IO.Path.GetDirectoryName(System.IO.Path.GetFullPath(path))!);
        var file = await folder.CreateFileAsync(System.IO.Path.GetFileName(path), CreationCollisionOption.ReplaceExisting);
        string? error = null;
        using (var stream = await file.OpenAsync(FileAccessMode.ReadWrite))
        {
            var transcoder = new MediaTranscoder { HardwareAccelerationEnabled = true };
            var prepared = await transcoder.PrepareMediaStreamSourceTranscodeAsync(source, stream, profile);
            if (!prepared.CanTranscode) error = $"cannot encode: {prepared.FailureReason}";
            else
            {
                if (audio != null) { audio.Start(); origin = audio.Started; }
                else origin = AudioLoopback.QpcNow();
                session.StartCapture();
                var run = prepared.TranscodeAsync().AsTask();
                await Task.Delay(length);
                stopping = true;
                gate.Release();
                chunkGate.Release();
                try { await run.WaitAsync(TimeSpan.FromSeconds(20)); }
                catch (Exception e) { error = e.Message; }
            }
        }
        lock (queue) while (queue.Count > 0) textures.Return(queue.Dequeue().Slot);
        textures.Dispose();
        if (audio != null)
        {
            audioNote = $"sound {audio.SampleRate} Hz x{audio.Channels}: {audio.Written / (double)audio.SampleRate:F2}s, " +
                $"{audio.Padded / (double)audio.SampleRate:F2}s of it silence filled in, loudest {audio.Peak:F3}";
            audio.Dispose();
        }
        var bytes = new FileInfo(path).Length;
        return new Result(path, width, height, captured, encoded, skipped, dropped, lastStamp ?? TimeSpan.Zero, watch.Elapsed, bytes, error, audioNote);
    }

    /// <summary>The window of a running WoW (Forever's first when both run), or zero.</summary>
    public static IntPtr FindWow()
    {
        var wows = Process.GetProcessesByName("Wow").Where(p => p.MainWindowHandle != IntPtr.Zero).ToList();
        var forever = wows.FirstOrDefault(p => { try { return p.MainModule?.FileName.Contains("_forever_", StringComparison.OrdinalIgnoreCase) == true; } catch { return false; } });
        return (forever ?? wows.FirstOrDefault())?.MainWindowHandle ?? IntPtr.Zero;
    }

    // ---------------------------------------------------------------- the graphics card

    /// <summary>
    /// Our Direct3D 11 device, as the raw device and context (for copying) and as the WinRT device
    /// (for capture). Protected for use from several threads: capture copies on its thread while the
    /// encoder reads on its own.
    /// </summary>
    private sealed class Gpu : IDisposable
    {
        public readonly IntPtr D3dDevice;
        public readonly IntPtr Context;
        public readonly IDirect3DDevice Device;
        public readonly object Lock = new();

        [DllImport("d3d11.dll", ExactSpelling = true)]
        private static extern int D3D11CreateDevice(IntPtr adapter, int driverType, IntPtr software, uint flags,
            IntPtr featureLevels, uint featureLevelCount, uint sdkVersion, out IntPtr device, out int featureLevel, out IntPtr context);

        [DllImport("d3d11.dll", ExactSpelling = true)]
        private static extern int CreateDirect3D11DeviceFromDXGIDevice(IntPtr dxgiDevice, out IntPtr graphicsDevice);

        public Gpu()
        {
            // hardware driver, BGRA support (for capture and the encoder), the D3D11 SDK version
            Marshal.ThrowExceptionForHR(D3D11CreateDevice(IntPtr.Zero, 1, IntPtr.Zero, 0x20, IntPtr.Zero, 0, 7, out D3dDevice, out _, out Context));
            var iidMt = new Guid("9B7E4E00-342C-4106-A19F-4F2704F689F0"); // ID3D10Multithread
            if (Marshal.QueryInterface(D3dDevice, ref iidMt, out var mt) == 0)
            {
                Vtbl.Call<SetBool>(mt, 5)(mt, 1);   // SetMultithreadProtected(TRUE)
                Marshal.Release(mt);
            }
            var iid = new Guid("54ec77fa-1377-44e6-8c32-88fd5f44c84c"); // IDXGIDevice
            Marshal.ThrowExceptionForHR(Marshal.QueryInterface(D3dDevice, ref iid, out var dxgi));
            try
            {
                Marshal.ThrowExceptionForHR(CreateDirect3D11DeviceFromDXGIDevice(dxgi, out var inspectable));
                try { Device = MarshalInterface<IDirect3DDevice>.FromAbi(inspectable); }
                finally { Marshal.Release(inspectable); }
            }
            finally { Marshal.Release(dxgi); }
        }

        public void Dispose()
        {
            Device.Dispose();
            Marshal.Release(Context);
            Marshal.Release(D3dDevice);
        }

        [UnmanagedFunctionPointer(CallingConvention.StdCall)] private delegate int SetBool(IntPtr self, int value);
    }

    /// <summary>
    /// Textures of the capture's size on our device, each wrapped as a WinRT surface for the encoder.
    /// CopyOf takes a free one and copies a captured frame into it; Return gives it back once the
    /// encoder has used it. Made as needed, up to <c>max</c>.
    /// </summary>
    private sealed class TexturePool(Gpu gpu, int max) : IDisposable
    {
        public sealed class Slot(IntPtr texture, IDirect3DSurface surface)
        {
            public readonly IntPtr Texture = texture;
            public readonly IDirect3DSurface Surface = surface;
        }

        private readonly Stack<Slot> _free = new();
        private readonly List<Slot> _all = new();
        private D3D11Texture2DDesc? _desc;

        [DllImport("d3d11.dll", ExactSpelling = true)]
        private static extern int CreateDirect3D11SurfaceFromDXGISurface(IntPtr dxgiSurface, out IntPtr graphicsSurface);

        public Slot? CopyOf(IDirect3DSurface captured)
        {
            var source = TextureOf(captured);
            try
            {
                Slot? slot;
                lock (_free)
                {
                    if (!_free.TryPop(out slot))
                    {
                        if (_all.Count >= max) return null;
                        slot = Make(source);
                        _all.Add(slot);
                    }
                }
                lock (gpu.Lock) Vtbl.Call<CopyResource>(gpu.Context, 47)(gpu.Context, slot.Texture, source);
                return slot;
            }
            finally { Marshal.Release(source); }
        }

        public void Return(Slot slot) { lock (_free) _free.Push(slot); }

        private Slot Make(IntPtr like)
        {
            if (_desc == null)
            {
                Vtbl.Call<GetDesc>(like, 10)(like, out var d);   // ID3D11Texture2D::GetDesc
                d.Usage = 0;                // default
                d.BindFlags = 0x20 | 0x8;   // render target, shader resource: what the encoder's colour conversion reads
                d.CPUAccessFlags = 0;
                d.MiscFlags = 0;
                d.MipLevels = 1;
                d.ArraySize = 1;
                _desc = d;
            }
            var desc = _desc.Value;
            Marshal.ThrowExceptionForHR(Vtbl.Call<CreateTexture2D>(gpu.D3dDevice, 5)(gpu.D3dDevice, ref desc, IntPtr.Zero, out var texture));
            var iid = new Guid("cafcb56c-6ac3-4889-bf47-9e23bbd260ec"); // IDXGISurface
            Marshal.ThrowExceptionForHR(Marshal.QueryInterface(texture, ref iid, out var dxgi));
            try
            {
                Marshal.ThrowExceptionForHR(CreateDirect3D11SurfaceFromDXGISurface(dxgi, out var inspectable));
                try { return new Slot(texture, MarshalInterface<IDirect3DSurface>.FromAbi(inspectable)); }
                finally { Marshal.Release(inspectable); }
            }
            finally { Marshal.Release(dxgi); }
        }

        /// <summary>The ID3D11Texture2D behind a WinRT surface (the caller releases it).</summary>
        private static IntPtr TextureOf(IDirect3DSurface surface)
        {
            var abi = MarshalInterface<IDirect3DSurface>.FromManaged(surface);
            try
            {
                var iidAccess = new Guid("A9B3D012-3DF2-4EE3-B8D1-8695F457D3C1"); // IDirect3DDxgiInterfaceAccess
                Marshal.ThrowExceptionForHR(Marshal.QueryInterface(abi, ref iidAccess, out var access));
                try
                {
                    var iidTexture = new Guid("6f15aaf2-d208-4e89-9ab4-489535d34f9c"); // ID3D11Texture2D
                    Marshal.ThrowExceptionForHR(Vtbl.Call<GetInterface>(access, 3)(access, ref iidTexture, out var texture));
                    return texture;
                }
                finally { Marshal.Release(access); }
            }
            finally { Marshal.Release(abi); }
        }

        public void Dispose()
        {
            lock (_free)
                foreach (var s in _all)
                {
                    s.Surface.Dispose();
                    Marshal.Release(s.Texture);
                }
        }

        [UnmanagedFunctionPointer(CallingConvention.StdCall)] private delegate void CopyResource(IntPtr self, IntPtr dst, IntPtr src);
        [UnmanagedFunctionPointer(CallingConvention.StdCall)] private delegate void GetDesc(IntPtr self, out D3D11Texture2DDesc desc);
        [UnmanagedFunctionPointer(CallingConvention.StdCall)] private delegate int CreateTexture2D(IntPtr self, ref D3D11Texture2DDesc desc, IntPtr data, out IntPtr texture);
        [UnmanagedFunctionPointer(CallingConvention.StdCall)] private delegate int GetInterface(IntPtr self, ref Guid iid, out IntPtr p);
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct D3D11Texture2DDesc
    {
        public uint Width, Height, MipLevels, ArraySize;
        public int Format;
        public uint SampleCount, SampleQuality;
        public int Usage;
        public uint BindFlags, CPUAccessFlags, MiscFlags;
    }

    /// <summary>A COM method by its place in the interface's table.</summary>
    private static class Vtbl
    {
        public static T Call<T>(IntPtr self, int index) where T : Delegate
        {
            var table = Marshal.ReadIntPtr(self);
            return Marshal.GetDelegateForFunctionPointer<T>(Marshal.ReadIntPtr(table, index * IntPtr.Size));
        }
    }

    private static class Capture
    {
        [ComImport, Guid("3628E81B-3CAC-4C60-B7F4-23CE0E0C3356"), InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
        private interface IGraphicsCaptureItemInterop
        {
            IntPtr CreateForWindow([In] IntPtr window, [In] ref Guid iid);
            IntPtr CreateForMonitor([In] IntPtr monitor, [In] ref Guid iid);
        }

        public static GraphicsCaptureItem ForWindow(IntPtr hwnd)
        {
            var factory = ActivationFactory.Get("Windows.Graphics.Capture.GraphicsCaptureItem");
            var interop = factory.AsInterface<IGraphicsCaptureItemInterop>();
            var iid = new Guid("79C3F95B-31F7-4EC2-A464-632EF5D30760"); // IGraphicsCaptureItem
            var ptr = interop.CreateForWindow(hwnd, ref iid);
            try { return GraphicsCaptureItem.FromAbi(ptr); }
            finally { Marshal.Release(ptr); }
        }
    }
}
