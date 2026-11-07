using Windows.Media.MediaProperties;
using Windows.Media.Transcoding;
using Windows.Storage;

namespace MindCollector.Desktop;

/// <summary>
/// Cuts a video down to a part of it with Windows' own transcoder (Windows.Media.Transcoding,
/// 2026-11-06), for WoW Forever clips: the replay buffer holds ten minutes, and a fight is the minute
/// before it, the fight and a few seconds after.
///
/// It re-encodes (Windows has no cut without one), on the graphics card's encoder where there is one,
/// into the same format, size and frame rate as the source, at the source's own bitrate measured
/// from the file, so the cut looks the same. The cut is frame-exact: the cut clip starts exactly
/// <c>fromStart</c> into the source, which is what keeps the timing file exact. Nothing extra is
/// downloaded (ffmpeg would cut without re-encoding, but only at keyframes, and is an 80 MB download).
/// </summary>
internal static class ClipCutter
{
    /// <summary>Writes the cut to <paramref name="target"/>. Null when it worked, else why not.</summary>
    public static async Task<string?> CutAsync(string source, string target, TimeSpan fromStart, TimeSpan fromEnd, TimeSpan sourceLength)
    {
        if (sourceLength <= TimeSpan.Zero) return "the video's length is unknown";
        var src = await StorageFile.GetFileFromPathAsync(source);
        var folder = await StorageFolder.GetFolderFromPathAsync(Path.GetDirectoryName(Path.GetFullPath(target))!);
        var dst = await folder.CreateFileAsync(Path.GetFileName(target), CreationCollisionOption.ReplaceExisting);

        var profile = await MediaEncodingProfile.CreateFromFileAsync(src);
        const uint audioBitrate = 192_000;
        if (profile.Audio is { } sourceAudio)
        {
            // A fresh AAC profile, so the audio is encoded again. Given the source's own, Windows copies
            // the audio across and starts the copy partway through an AAC frame: the first packet is
            // half a frame, and Edge's decoder (the window's player) gives up on the whole video with
            // PIPELINE_ERROR_DECODE (2026-11-06, the Grish-Naz clip; seen in its first packet's bytes).
            profile.Audio = AudioEncodingProperties.CreateAac(sourceAudio.SampleRate is > 0 and var rate ? rate : 48_000,
                sourceAudio.ChannelCount is > 0 and var channels ? channels : 2, audioBitrate);
        }
        if (profile.Video is { } video)
        {
            // OBS's quality modes write no bitrate into the file; the file's own size over its length is the honest figure.
            var bitsPerSecond = new FileInfo(source).Length * 8 / sourceLength.TotalSeconds;
            video.Bitrate = (uint)Math.Clamp(bitsPerSecond - (profile.Audio != null ? audioBitrate : 0), 2_000_000, 80_000_000);
        }

        // TrimStopTime is where in the source to stop, not how much to take off the end, whatever its
        // documentation says (measured 2026-11-06: start 100s, stop 130s gave 30.00s; a stop before the
        // start throws a NullReferenceException from PrepareFileTranscodeAsync).
        var stop = sourceLength - fromEnd;
        if (stop <= fromStart) return "nothing is left of the video after the cut";
        var transcoder = new MediaTranscoder
        {
            TrimStartTime = fromStart,
            TrimStopTime = stop,
            HardwareAccelerationEnabled = true,
        };
        var prepared = await transcoder.PrepareFileTranscodeAsync(src, dst, profile);
        if (!prepared.CanTranscode)
        {
            try { await dst.DeleteAsync(); } catch { }
            return $"Windows cannot cut this video ({prepared.FailureReason})";
        }
        await prepared.TranscodeAsync();
        return null;
    }
}
