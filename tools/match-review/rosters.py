import gzip, json, os, sys, datetime
sys.stdout.reconfigure(encoding="utf-8", errors="replace")
A = "D:/MindCollector/arena-logs"
SPEC = {}
rows = []
for fn in os.listdir(A + "/metadata"):
    try:
        m = json.load(open(f"{A}/metadata/{fn}", encoding="utf-8-sig"))
    except Exception:
        print("unreadable", fn); continue
    if m.get("startInfo", {}).get("bracket") != "3v3":
        continue
    names = [u["name"] for u in m["units"] if u["id"].startswith("Player")]
    if not any(n.startswith("Hozzaarr") for n in names) or not any(n.startswith("Skylake") for n in names):
        continue
    t = datetime.datetime.fromtimestamp(m["startTime"] / 1000)
    raw = f"{A}/raw/{m['id']}.log.gz"
    start = end = None
    teams = {}
    specs = {}
    with gzip.open(raw, "rt", encoding="utf-8", errors="replace") as f:
        for line in f:
            body = line.split("  ", 1)[-1]
            if body.startswith("ARENA_MATCH_START"):
                start = body.strip()
            elif body.startswith("ARENA_MATCH_END"):
                end = body.strip()
            elif body.startswith("COMBATANT_INFO"):
                p = body.split(",")
                teams[p[1]] = p[2]
                specs[p[1]] = p[24] if len(p) > 24 else "?"
    rows.append((t, m["result"], m["playerTeamRating"], end, start, teams, specs, m["units"], m["durationInSeconds"]))
rows.sort()
for t, res, ptr, end, start, teams, specs, units, dur in rows:
    e=end.split(",")
    r=[int(e[3]),int(e[4])]
    other = r[1] if r[0]==ptr else r[0]
    mineteam = next(teams[u["id"]] for u in units if u["name"].startswith("Skylake"))
    en = sorted(u["spec"] for u in units if u["id"] in teams and teams[u["id"]]!=mineteam)
    print(t.strftime("%H:%M"), "WON " if res==3 else "LOST", dur, "us", ptr, "them", other, "diff", other-ptr, en)

out = []
for t, res, ptr, end, start, teams, specs, units, dur in rows:
    e = end.split(",")
    r = [int(e[3]), int(e[4])]
    mineteam = next(teams[u["id"]] for u in units if u["name"].startswith("Skylake"))
    out.append({
        "time": t.strftime("%H:%M"), "won": res == 3, "us": ptr, "them": r[1] if r[0] == ptr else r[0],
        "players": [{"name": u["name"], "spec": u["spec"], "side": "us" if teams[u["id"]] == mineteam else "them"}
                    for u in units if u["id"] in teams],
    })
json.dump(out, open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "games.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
