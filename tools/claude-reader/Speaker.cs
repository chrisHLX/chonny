using Windows.Media.Core;
using Windows.Media.Playback;
using Windows.Media.SpeechSynthesis;

namespace ClaudeReader;

/// <summary>
/// Reads a list of sentences aloud with Windows' own voices (Windows.Media.SpeechSynthesis), one at a
/// time through a media player: so it can pause mid-sentence, step back or on a sentence, and say which
/// sentence it is on. The next sentence is made while the current one plays, so there's no gap.
/// Events come from Windows' threads; the window marshals them.
/// </summary>
public sealed class Speaker : IDisposable
{
    public event Action<int>? SentenceStarted;
    public event Action? Finished;
    public event Action<string>? Failed;

    public bool Playing { get; private set; }
    public bool Paused { get; private set; }
    public int Index { get; private set; } = -1;

    private readonly SpeechSynthesizer _synth = new();
    private readonly MediaPlayer _player = new() { AudioCategory = MediaPlayerAudioCategory.Speech };
    private List<string> _sentences = [];
    private int _generation;
    private Task<SpeechSynthesisStream>? _next;
    private int _nextIndex = -1;

    public Speaker()
    {
        _player.MediaEnded += (_, _) => { if (Playing) PlayAt(Index + 1, _generation); };
        _player.MediaFailed += (_, e) => Failed?.Invoke(e.ErrorMessage);
    }

    /// <summary>The voices installed with Windows (George, Susan, Hazel ...), by name.</summary>
    public static IReadOnlyList<string> Voices => SpeechSynthesizer.AllVoices.Select(v => v.DisplayName).ToList();

    public void SetVoice(string? name)
    {
        var v = SpeechSynthesizer.AllVoices.FirstOrDefault(x => x.DisplayName.Equals(name, StringComparison.OrdinalIgnoreCase))
            ?? SpeechSynthesizer.AllVoices.FirstOrDefault(x => name != null && x.DisplayName.Contains(name, StringComparison.OrdinalIgnoreCase));
        if (v != null) _synth.Voice = v;
        _nextIndex = -1;   // a sentence made ahead in the old voice is made again
    }

    /// <summary>Speed: 1.0 is normal; 0.5 to 3.</summary>
    public void SetRate(double rate)
    {
        _synth.Options.SpeakingRate = Math.Clamp(rate, 0.5, 3.0);
        _nextIndex = -1;
    }

    public void Play(List<string> sentences, int start = 0)
    {
        _sentences = sentences;
        _nextIndex = -1;
        PlayAt(start, ++_generation);
    }

    public void PauseResume()
    {
        if (!Playing) return;
        if (Paused) { _player.Play(); Paused = false; }
        else { _player.Pause(); Paused = true; }
    }

    public void Stop()
    {
        _generation++;
        Playing = Paused = false;
        _player.Pause();
        _player.Source = null;
    }

    /// <summary>Back (-1) or on (+1) a sentence, or to a given one.</summary>
    public void Skip(int delta) { if (Playing) JumpTo(Index + delta); }

    public void JumpTo(int index)
    {
        if (_sentences.Count == 0) return;
        PlayAt(Math.Clamp(index, 0, _sentences.Count - 1), ++_generation);
    }

    private async void PlayAt(int i, int generation)
    {
        if (generation != _generation) return;
        if (i >= _sentences.Count)
        {
            Playing = Paused = false;
            Index = -1;
            Finished?.Invoke();
            return;
        }
        Index = i;
        Playing = true;
        Paused = false;
        try
        {
            var stream = _nextIndex == i && _next != null ? await _next : await Make(_sentences[i]);
            if (generation != _generation) { stream.Dispose(); return; }
            _player.Source = MediaSource.CreateFromStream(stream, stream.ContentType);
            _player.Play();
            SentenceStarted?.Invoke(i);
            if (i + 1 < _sentences.Count) { _nextIndex = i + 1; _next = Make(_sentences[i + 1]); }
            else _nextIndex = -1;
        }
        catch (Exception e)
        {
            Playing = false;
            Failed?.Invoke(e.Message);
        }
    }

    private Task<SpeechSynthesisStream> Make(string text) => _synth.SynthesizeTextToStreamAsync(text).AsTask();

    public void Dispose()
    {
        Stop();
        _player.Dispose();
        _synth.Dispose();
    }
}
