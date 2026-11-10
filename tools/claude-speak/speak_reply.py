"""Reads Claude's replies aloud (Windows' own voices), from Claude Code hooks.

  Stop hook:              python speak_reply.py          speaks the reply that just finished
  UserPromptSubmit hook:  python speak_reply.py --stop   stops talking when you send the next message

Settings in config.json beside this file: voice (a Windows voice name, e.g. George, Susan, Hazel),
rate (1.0 is normal speed), max_chars. Create a file called "off" beside it to mute.
Everything stays on this PC: it uses the speech voices installed with Windows.
"""
import json, os, re, subprocess, sys, tempfile, time
from pathlib import Path

HERE = Path(__file__).resolve().parent
PID_FILE = HERE / "speaking.pid"
CONFIG = {"voice": "George", "rate": 1.1, "max_chars": 6000}


def config():
    c = dict(CONFIG)
    try:
        c.update(json.loads((HERE / "config.json").read_text(encoding="utf-8")))
    except (OSError, ValueError):
        pass
    return c


def stop_speaking():
    try:
        pid = int(PID_FILE.read_text())
    except (OSError, ValueError):
        return
    subprocess.run(["taskkill", "/PID", str(pid), "/T", "/F"], capture_output=True)
    try:
        PID_FILE.unlink()
    except OSError:
        pass


def last_reply(transcript):
    """The text of the last assistant message after the last thing the user typed."""
    entries = []
    with open(transcript, encoding="utf-8") as f:
        for line in f:
            try:
                entries.append(json.loads(line))
            except ValueError:
                pass
    texts = []
    for e in reversed(entries):
        m = e.get("message") or {}
        role, content = m.get("role"), m.get("content")
        if e.get("type") == "user" and role == "user":
            if isinstance(content, str) or any(isinstance(c, dict) and c.get("type") == "text" for c in content or []):
                break  # the user's own prompt: the turn starts here
            if texts:
                break  # a tool result before the final text: the final text is complete
            continue
        if e.get("type") == "assistant" and isinstance(content, list):
            t = "\n".join(c.get("text", "") for c in content if isinstance(c, dict) and c.get("type") == "text")
            if t.strip():
                texts.insert(0, t)
    return "\n\n".join(texts)


def for_speech(md):
    """Markdown into something worth listening to."""
    s = re.sub(r"```.*?```", " There's code on screen. ", md, flags=re.S)
    out = []
    for line in s.splitlines():
        l = line.strip()
        if re.fullmatch(r"\|?\s*:?-{2,}.*", l):
            continue  # a table's separator row
        if l.startswith("|"):
            cells = [c.strip() for c in l.strip("|").split("|") if c.strip()]
            l = ", ".join(cells)
        l = re.sub(r"^#{1,6}\s*", "", l)
        l = re.sub(r"^([-*+]|\d+\.)\s+", "", l)
        l = re.sub(r"(\*\*|__)(.+?)\1", r"\2", l).strip()  # bold first, so the stop below lands right
        if l and not re.search(r"[.!?:;,]$", l):
            l += "."
        out.append(l)
    s = "\n".join(out)
    s = re.sub(r"\[([^\]]+)\]\([^)]+\)", r"\1", s)          # links: their text
    s = re.sub(r"https?://\S+", "a link", s)
    s = re.sub(r"`([^`]*)`", r"\1", s)                      # inline code
    s = re.sub(r"(\*\*|__|\*|_)(?=\S)(.+?)(?<=\S)\1", r"\2", s)  # bold, italics
    for a, b in (("→", " to "), ("->", " to "), ("×", " times "), ("≥", " at least "), ("≤", " at most "),
                 ("~", "about "), ("&", " and "), ("·", ", "), ("—", ", "), ("–", " to ")):
        s = s.replace(a, b)
    s = re.sub(r"[ \t]+", " ", s)
    s = re.sub(r"\n{2,}", "\n", s)
    return s.strip()


def speak(text, cfg):
    stop_speaking()
    tmp = Path(tempfile.gettempdir()) / "claude-reply.txt"
    tmp.write_text(text, encoding="utf-8")
    args = ["powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-WindowStyle", "Hidden",
            "-File", str(HERE / "speak.ps1"), "-TextFile", str(tmp), "-Voice", str(cfg["voice"]), "-Rate", str(cfg["rate"])]
    # A hidden window of its own (PowerShell won't run with no console at all, so not DETACHED_PROCESS),
    # outside the hook's job where Windows allows it, so it keeps talking after the hook returns.
    base = 0x00000200 | 0x08000000  # new process group, no window
    try:
        p = subprocess.Popen(args, creationflags=base | 0x01000000, stdin=subprocess.DEVNULL,
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)  # | breakaway from job
    except OSError:
        p = subprocess.Popen(args, creationflags=base, stdin=subprocess.DEVNULL,
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    PID_FILE.write_text(str(p.pid))


def main():
    if "--stop" in sys.argv:
        stop_speaking()
        return
    if (HERE / "off").exists():
        return
    try:
        payload = json.load(sys.stdin)
    except ValueError:
        payload = {}
    if payload.get("stop_hook_active"):
        return
    text = payload.get("last_assistant_message") or ""
    if not text and payload.get("transcript_path"):
        for _ in range(6):  # the transcript can lag the hook by a moment
            text = last_reply(payload["transcript_path"])
            if text:
                break
            time.sleep(0.25)
    if "--test" in sys.argv:
        text = " ".join(a for a in sys.argv[1:] if a != "--test") or "This is how your replies will sound."
    text = for_speech(text)
    if not text:
        return
    cfg = config()
    if len(text) > cfg["max_chars"]:
        text = text[:cfg["max_chars"]].rsplit(".", 1)[0] + ". The rest is on screen."
    speak(text, cfg)


if __name__ == "__main__":
    main()
