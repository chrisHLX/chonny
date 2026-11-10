# Claude reads its replies aloud (Windows)

Two Claude Code hooks: when Claude finishes a reply it is read aloud with a Windows voice, and a small
window in the bottom-right corner shows while it speaks, with Pause, Stop and Mute replies. Typing a
new message stops the current reading.

## Setting it up on a PC

1. Copy `speak.ps1` and `speak_reply.py` to `C:\Users\<you>\.claude\speak\`.
2. Add the hooks to `C:\Users\<you>\.claude\settings.json` (edit the file yourself; mind the comma
   before `"hooks"` if other settings come first):

```json
"hooks": {
  "Stop": [
    { "hooks": [ { "type": "command", "command": "python \"C:/Users/<you>/.claude/speak/speak_reply.py\"", "timeout": 15 } ] }
  ],
  "UserPromptSubmit": [
    { "hooks": [ { "type": "command", "command": "python \"C:/Users/<you>/.claude/speak/speak_reply.py\" --stop", "timeout": 10 } ] }
  ]
}
```

Needs Python on the PATH and Windows PowerShell 5.1 (built in).

## Using it

- **Mute replies** in the window creates a file called `off` in the speak folder; delete it to hear
  replies again.
- An optional `config.json` in the speak folder sets `voice` (a OneCore voice name, default George),
  `rate` and `max_chars`.
- If the newer voices fail, it falls back to the old SAPI voice and writes why to `speak.log`.
