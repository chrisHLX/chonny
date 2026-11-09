"""Answers /ask questions typed in WoW: Forever, using Claude, and sends the answer back to the game.

How it fits together:
  1. In game, `/ask <question>` (MindCollector Observer addon) saves the question and reloads the UI,
     which is when WoW writes the addon's saved data to disk.
  2. This script watches that file (WTF/Account/*/SavedVariables/MindCollectorObserver.lua). For each new
     question it runs Claude Code headless (`claude -p`) with web search, Wowhead and your Chrome.
  3. The answer shows in a small always-on-top window straight away, and is written to the addon's
     Answers.lua. The next /reload in game loads it: the answer goes to chat and the addon window,
     and any points go on the world map, with a waypoint on the nearest.

An addon cannot talk to anything outside the game while it runs, so files at a reload are the only
link (the same route TradeSkillMaster's and WeakAuras' desktop apps use).

    python ask_bridge.py [--wow "C:/World of Warcraft/_forever_"] [--model sonnet] [--no-window]

Standard library only (tkinter for the window). Python 3.10+.
"""
import argparse, glob, json, os, queue, re, subprocess, sys, threading, time
from pathlib import Path

ADDON = "MindCollectorObserver"
STATE_DIR = Path(os.environ.get("LOCALAPPDATA", ".")) / "MindCollector" / "ask"
MAX_AGE = 2 * 3600          # ignore questions older than this (seconds)
TIMEOUT = 480               # one Claude run

SYSTEM = """You answer a question typed inside World of Warcraft: Forever by a player who is in the game right now.
Forever is Blizzard's level-60 remake of the original world (launched 2026-11-04), with new zones, recipes and items.
Your answer is shown in the game, and the points you return are drawn on the in-game world map.

Rules:
- Facts about Forever come from Wowhead's Forever pages (https://www.wowhead.com/forever/...), not Classic or retail pages.
  Find the page with WebSearch (for example: site:wowhead.com/forever ashenvale bear) or WebFetch.
- For where an NPC, object or herb is: open its Wowhead Forever page in Chrome and read window.g_mapperData with JavaScript.
  It is keyed by Wowhead zone id; each value is a list of {count, coords: [[x, y], ...]}, with x and y in percent (0-100)
  of that zone's map. The page's location text ("This NPC can be found in ...") names the zones; match ids to names by count.
  Return those coordinates exactly. Never estimate or invent coordinates: if you cannot read them, return no points and say so.
- Zone names exactly as the game shows them (for example "Ashenvale", "Stonetalon Mountains").
- At most 150 points. If there are more, keep the ones in the player's zone, spread across the whole area.
- answer: at most three short sentences in plain words, no markdown. Say what you marked and anything useful
  (level, where they cluster, which is nearest the player). If you are unsure of something, say so.
- When the prompt gives "From the Forever game files" facts about an item, they are exact (recipes, professions,
  skill, level required): use them over any website. A low level requirement doesn't mean nobody uses it at 60.
- Close any browser tabs you opened."""

SCHEMA = {
    "type": "object",
    "properties": {
        "answer": {"type": "string"},
        "points": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "zone": {"type": "string"},
                    "x": {"type": "number"},
                    "y": {"type": "number"},
                    "label": {"type": "string"},
                },
                "required": ["zone", "x", "y"],
            },
        },
    },
    "required": ["answer", "points"],
}

# ---------------------------------------------------------------- reading WoW's saved variables

class LuaReader:
    """Just enough Lua to read a SavedVariables file: tables, strings, numbers, booleans, nil."""

    def __init__(self, s):
        self.s, self.i = s, 0

    def ws(self):
        s = self.s
        while self.i < len(s):
            if s[self.i] in " \t\r\n,;":
                self.i += 1
            elif s.startswith("--", self.i):
                j = s.find("\n", self.i)
                self.i = len(s) if j < 0 else j + 1
            else:
                break

    def value(self):
        self.ws()
        s, c = self.s, self.s[self.i]
        if c == "{":
            return self.table()
        if c == '"':
            return self.string()
        if s.startswith("true", self.i):
            self.i += 4; return True
        if s.startswith("false", self.i):
            self.i += 5; return False
        if s.startswith("nil", self.i):
            self.i += 3; return None
        m = re.compile(r"-?\d+(\.\d+)?([eE][-+]?\d+)?").match(s, self.i)
        if not m:
            raise ValueError(f"unexpected {s[self.i:self.i + 20]!r}")
        self.i = m.end()
        t = m.group(0)
        return float(t) if any(ch in t for ch in ".eE") else int(t)

    def string(self):
        s, out = self.s, []
        self.i += 1
        while s[self.i] != '"':
            if s[self.i] == "\\":
                n = s[self.i + 1]
                if n.isdigit():
                    m = re.compile(r"\d{1,3}").match(s, self.i + 1)
                    out.append(chr(int(m.group(0)) & 0xFF)); self.i = m.end(); continue
                out.append({"n": "\n", "t": "\t", "r": "\r"}.get(n, n)); self.i += 2
            else:
                out.append(s[self.i]); self.i += 1
        self.i += 1
        # the file is read as latin-1, so each char is one byte; rebuild the UTF-8 the game wrote
        return "".join(out).encode("latin-1", "replace").decode("utf-8", "replace")

    def table(self):
        self.i += 1
        arr, d = [], {}
        while True:
            self.ws()
            if self.s[self.i] == "}":
                self.i += 1
                break
            if self.s[self.i] == "[":
                self.i += 1
                k = self.value()
                self.ws(); self.i += 1  # ]
                self.ws(); self.i += 1  # =
                d[k] = self.value()
            else:
                m = re.compile(r"([A-Za-z_]\w*)\s*=").match(self.s, self.i)
                if m:
                    self.i = m.end(); d[m.group(1)] = self.value()
                else:
                    arr.append(self.value())
        if d and not arr:
            return d
        if arr and not d:
            return arr
        for n, v in enumerate(arr, 1):
            d[n] = v
        return d

def read_saved(path):
    text = Path(path).read_text(encoding="latin-1")
    out = {}
    for m in re.finditer(r"^([A-Za-z_]\w*)\s*=\s*", text, re.M):
        r = LuaReader(text)
        r.i = m.end()
        try:
            out[m.group(1)] = r.value()
        except Exception:
            pass
    return out

# ---------------------------------------------------------------- writing the answers for the addon

def lua_str(s):
    s = str(s).replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n").replace("\r", "")
    return '"' + s + '"'

def write_answers(path, answers):
    lines = ["-- Written by tools/forever-ask/ask_bridge.py; the game loads it when the UI loads (/reload).",
             "MindCollectorObserverAnswers = {"]
    for aid, a in sorted(answers.items(), key=lambda kv: kv[1]["t"])[-25:]:
        pins = ", ".join("{zone = %s, x = %s, y = %s, label = %s}" % (lua_str(p["zone"]), round(float(p["x"]), 2),
                         round(float(p["y"]), 2), lua_str(p.get("label") or "")) for p in a.get("points", []))
        lines.append("  [%s] = {t = %d, answer = %s, pins = {%s}}," % (lua_str(aid), a["t"], lua_str(a["answer"]), pins))
    lines.append("}")
    tmp = Path(str(path) + ".tmp")
    tmp.write_text("\n".join(lines) + "\n", encoding="utf-8", newline="\n")
    os.replace(tmp, path)

# ---------------------------------------------------------------- facts from the game files

FACTS = {}
FACT_NAMES = []

def load_facts():
    f = Path(__file__).with_name("item_facts.json")
    if f.exists():
        FACTS.update(json.loads(f.read_text(encoding="utf-8")))
        FACT_NAMES.extend(sorted(FACTS, key=len, reverse=True))

def facts_for(question):
    """Game-file facts for up to four items the question names (longest names first, no overlaps)."""
    q, found, taken = question.lower(), [], []
    for name in FACT_NAMES:
        if len(name) < 4:
            continue
        for m in re.finditer(r"(?<![a-z])" + re.escape(name) + r"(?![a-z])", q):
            if any(a <= m.start() < b for a, b in taken):
                continue
            taken.append((m.start(), m.end()))
            found.append(FACTS[name])
            break
        if len(found) >= 4:
            break
    out = []
    for f in found:
        uses = "; ".join(f"{u[0]} ({u[1]} {u[2]}" + (f", needs level {u[3]}" if u[3] else "") + f", x{u[4]})"
                         for u in f["used_in"])
        made = "; ".join(f"{m[0]} ({m[1]} {m[2]}: " + ", ".join(f"{n} x{c}" for n, c in m[3]) + ")" for m in f["made_by"])
        line = f"- {f['name']} (item level {f['item_level']})"
        if uses:
            line += f". Used in: {uses}"
        if made:
            line += f". Made by: {made}"
        out.append(line)
    return out

# ---------------------------------------------------------------- asking Claude

def find_claude(explicit):
    if explicit:
        return explicit
    for name in ("claude.exe", "claude"):
        for d in os.environ.get("PATH", "").split(os.pathsep):
            p = Path(d) / name
            if p.exists():
                return str(p)
    hits = sorted(glob.glob(os.path.expandvars(
        r"%USERPROFILE%\.vscode\extensions\anthropic.claude-code-*\resources\native-binary\claude.exe")))
    if hits:
        return hits[-1]
    sys.exit("Can't find claude. Pass --claude <path to claude.exe>.")

def ask_claude(claude, model, ask):
    where = ask.get("zone") or "?"
    if ask.get("subzone"):
        where += f" ({ask['subzone']})"
    pos = f" at {ask['x']}, {ask['y']}" if ask.get("x") is not None else ""
    prompt = (f"Question: {ask.get('q', '')}\n\n"
              f"Player: {ask.get('ch', '?')}, level {ask.get('level', '?')} {str(ask.get('class', '')).lower()}, "
              f"in {where}{pos} (map id {ask.get('mapID', '?')}).")
    if ask.get("profs"):
        prompt += f" Professions: {ask['profs']}."
    facts = facts_for(ask.get("q", ""))
    if facts:
        prompt += "\n\nFrom the Forever game files (recipe, skill, level required, count):\n" + "\n".join(facts)
    cmd = [claude, "-p", prompt, "--output-format", "json", "--json-schema", json.dumps(SCHEMA),
           "--append-system-prompt", SYSTEM, "--chrome", "--model", model,
           "--allowedTools", "WebSearch", "WebFetch", "mcp__claude-in-chrome",
           "--disallowedTools", "Bash", "Edit", "Write", "NotebookEdit", "PowerShell"]
    r = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=TIMEOUT,
                       cwd=STATE_DIR)
    try:
        j = json.loads(r.stdout)
    except json.JSONDecodeError:
        raise RuntimeError((r.stderr or r.stdout or "no output")[:300])
    out = j.get("structured_output")
    if not out:
        res = (j.get("result") or "").strip().strip("`")
        res = res[res.find("{"):res.rfind("}") + 1] if "{" in res else res
        out = json.loads(res)
    pts = []
    for p in out.get("points") or []:
        try:
            x, y = float(p["x"]), float(p["y"])
        except (KeyError, TypeError, ValueError):
            continue
        if 0 <= x <= 100 and 0 <= y <= 100 and p.get("zone"):
            pts.append({"zone": p["zone"], "x": x, "y": y, "label": p.get("label") or ""})
    return {"answer": (out.get("answer") or "").strip(), "points": pts[:150], "cost": j.get("total_cost_usd")}

# ---------------------------------------------------------------- the loop

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--wow", default=r"C:\World of Warcraft\_forever_")
    ap.add_argument("--model", default="sonnet")
    ap.add_argument("--claude")
    ap.add_argument("--no-window", action="store_true")
    args = ap.parse_args()

    STATE_DIR.mkdir(parents=True, exist_ok=True)
    state_file = STATE_DIR / "answers.json"
    answers = json.loads(state_file.read_text(encoding="utf-8")) if state_file.exists() else {}
    addon_dir = Path(args.wow) / "Interface" / "AddOns" / ADDON
    answers_lua = addon_dir / "Answers.lua"
    claude = find_claude(args.claude)
    load_facts()
    if not addon_dir.exists():
        sys.exit(f"No {ADDON} addon in {addon_dir}")
    print(f"Watching {args.wow} for /ask questions. Claude: {claude} ({args.model}). Ctrl+C to stop.")

    ui = queue.Queue()
    busy = set()

    def log(msg):
        line = time.strftime("%H:%M:%S ") + msg
        print(line, flush=True)
        with open(STATE_DIR / "bridge.log", "a", encoding="utf-8") as f:
            f.write(line + "\n")

    def work(ask):
        aid = ask["id"]
        log(f"asking: {ask.get('q')}")
        ui.put(("asking", ask.get("q", "")))
        try:
            res = ask_claude(claude, args.model, ask)
            if not res["answer"]:
                res["answer"] = "No answer came back."
        except subprocess.TimeoutExpired:
            res = {"answer": "Claude took too long on this one; try asking it more narrowly.", "points": []}
        except Exception as e:
            res = {"answer": f"Couldn't answer: {e}", "points": []}
        res["t"] = int(time.time())
        res["q"] = ask.get("q", "")
        answers[aid] = res
        state_file.write_text(json.dumps(answers, indent=1), encoding="utf-8")
        write_answers(answers_lua, answers)
        cost = f", ${res['cost']:.2f}" if res.get("cost") else ""
        log(f"answered ({len(res['points'])} points{cost}): {res['answer']}")
        ui.put(("answer", res["q"], res["answer"], len(res["points"])))
        busy.discard(aid)

    seen_mtimes = {}

    def poll():
        for sv in glob.glob(str(Path(args.wow) / "WTF" / "Account" / "*" / "SavedVariables" / f"{ADDON}.lua")):
            m = os.path.getmtime(sv)
            if seen_mtimes.get(sv) == m or time.time() - m < 1:
                continue  # unchanged, or still being written
            seen_mtimes[sv] = m
            try:
                db = read_saved(sv).get("MindCollectorObserverDB") or {}
            except Exception as e:
                log(f"couldn't read {sv}: {e}")
                continue
            asks = db.get("asks") or []
            if isinstance(asks, dict):
                asks = list(asks.values())
            for a in asks:
                if not isinstance(a, dict) or not a.get("id"):
                    continue
                if a["id"] in answers or a["id"] in busy or a.get("status") == "answered":
                    continue
                if time.time() - (a.get("t") or 0) > MAX_AGE:
                    continue
                busy.add(a["id"])
                threading.Thread(target=work, args=(a,), daemon=True).start()

    if not answers_lua.exists():
        write_answers(answers_lua, answers)

    if args.no_window:
        while True:
            poll()
            time.sleep(2)

    import tkinter as tk
    root = tk.Tk()
    root.title("MindCollector: Ask")
    root.attributes("-topmost", True)
    root.configure(bg="#0a0a0a")
    root.geometry("420x170+40+40")
    head = tk.Label(root, text="Waiting for /ask in game", fg="#d4af37", bg="#0a0a0a", font=("Segoe UI", 10, "bold"),
                    anchor="w", justify="left", wraplength=400)
    head.pack(fill="x", padx=10, pady=(8, 2))
    body = tk.Label(root, text="Type /ask <question> in WoW. The UI reloads to send it; the answer shows here, "
                    "then /reload in game puts it on the map.", fg="#dddddd", bg="#0a0a0a",
                    font=("Segoe UI", 10), anchor="nw", justify="left", wraplength=400)
    body.pack(fill="both", expand=True, padx=10, pady=(0, 8))

    def tick():
        try:
            poll()
            while True:
                msg = ui.get_nowait()
                if msg[0] == "asking":
                    head.config(text="Asking Claude: " + msg[1])
                    body.config(text="Searching... this usually takes a minute or two.")
                else:
                    head.config(text=msg[1])
                    extra = f"\n\n{msg[3]} map points. Type /reload in game to see them." if msg[3] else \
                        "\n\nType /reload in game to see it there too."
                    body.config(text=msg[2] + extra)
                    root.deiconify(); root.lift()
        except queue.Empty:
            pass
        root.after(2000, tick)

    root.after(500, tick)
    root.mainloop()

if __name__ == "__main__":
    main()
