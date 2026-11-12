# Claude Reader

A small Windows tray app that reads text aloud with Windows' own voices: Claude Code's replies as they
finish, and anything else you give it. Nothing leaves the PC and it uses no Claude tokens: the voices
are the ones installed with Windows, and replies come from the conversation file Claude Code keeps.

## What it does

- **Claude's replies.** The Claude Code hook (`tools/claude-speak/speak_reply.py`) hands each finished
  reply to the reader, which reads it and keeps it. **Replay** (or **Ctrl+Alt+R** from anywhere) reads
  the last reply again; double-click any earlier one in the list.
- **Anything else.** **Open file…** or drop a file on the window: `.txt`, `.md` (read without the
  markdown symbols) or `.pdf`. **Read copied text** reads what's on the clipboard, for something copied
  off a web page.
- **PDFs** are read by Windows itself: each page drawn by its PDF reader and read by its text
  recognition (OCR), so scanned PDFs work too. Lines a page wraps mid-sentence are joined back up, and a
  word hyphenated across lines is put back together.
- **Controls:** Pause/Resume, Stop, Back and Next a sentence, speed, voice. The sentence being read is
  highlighted; double-click a sentence to read from there. **Ctrl+Alt+P** pauses and **Ctrl+Alt+S**
  stops from anywhere, in a game too.
- **Game-safe.** The window is never always-on-top and never takes focus, and it doesn't pop up at all
  while a fullscreen window (a game) is in front. Built after WoW froze (12 Nov 2026) as the old speech
  window (always on top) appeared over it in borderless fullscreen D3D12. Untick "Show this window when
  reading" and it stays in the tray.
- **Sending Claude a new message** stops a reply being read, but not a file you're listening to.

## Setting it up on a PC

```powershell
$dn = "$env:LOCALAPPDATA\Microsoft\dotnet8\dotnet.exe"   # or dotnet, if the .NET 8 SDK is on the PATH
& $dn publish -c Release -o publish
New-Item -ItemType Directory -Force "$env:LOCALAPPDATA\Programs\ClaudeReader" | Out-Null
Copy-Item publish\ClaudeReader.exe "$env:LOCALAPPDATA\Programs\ClaudeReader\ClaudeReader.exe"
```

Then the hooks in `tools/claude-speak/README.md` (the same two as before). The hook starts the reader
when it isn't running. One exe with .NET inside, so nothing else needs installing.

Kept in `%LOCALAPPDATA%\ClaudeReader`: `settings.json` (voice, speed, read replies, pop up),
`history.json` (the last 40 things read), and `inbox\`, where the hook (and a second start of the app
with a file) leaves messages: `{"kind": "reply", "text", "silent"}`, `{"kind": "stop"}`,
`{"kind": "open", "path"}`, `{"kind": "show"}`.

`ClaudeReader.exe --extract FILE OUT` writes a file's text, in the sentences it would read, to OUT:
for checking a PDF reads right without listening to it.

Checked 2026-11-12: a two-page test PDF (wrapped lines, a hyphenated word) came back word for word as
whole sentences; a markdown file read without its symbols, its table row by row; a test reply through
the hook started the reader, was read, and was kept in the history.
