# Defensive trading across every archived game: does a side that answers more of the other side's
# goes with defensives win more, does that differ by rating, and does a kick on the healer inside
# a go change how many defensives the go draws? Reads storage/app/population (wow:population).
#   python tools/match-review/poptrades.py
# Written 2026-10-07 for Chriso's "the counter to a go is effective trading" question.
import json, glob, os, sys, statistics
sys.stdout.reconfigure(encoding='utf-8')
POP = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "storage", "app", "population", "*.json")
games = [json.load(open(f, encoding='utf-8')) for f in glob.glob(POP)]

# Per game and side: the defensives that side pressed answering the OTHER side's goes.
rows = []
for g in games:
    mmr = g.get('mmr') or {}
    won_us = any(p['won'] for p in g['players'].values() if p['side'] == 'us')
    for side in ('us', 'them'):
        against = [go for go in g['goes'] if go['side'] != side]   # their goes, answered by this side
        if not against:
            continue
        rows.append({
            'side': side, 'won': won_us if side == 'us' else not won_us,
            'defsPerGo': sum(go['defs'] for go in against) / len(against),
            'goesFaced': len(against), 'killedBy': sum(1 for go in against if go['kill'] or go['killLater']),
            'mmr': mmr.get(side), 'bracket': g['bracket'],
        })

def wr(sel): return (sum(r['won'] for r in sel) / len(sel) * 100) if sel else float('nan')
print(f'side-games {len(rows)}')
print('\n== defensives spent per enemy go, against winning')
for lo, hi in [(0, 1), (1, 2), (2, 3), (3, 4), (4, 99)]:
    s = [r for r in rows if lo <= r['defsPerGo'] < hi]
    print(f'  {lo}-{hi} a go: win {wr(s):5.1f}%  n={len(s)}')

rated = [r for r in rows if r['mmr']]
med = statistics.median(r['mmr'] for r in rated)
print(f'\n== by rating (median side MMR {med:.0f})')
for label, sel in [('above median MMR', [r for r in rated if r['mmr'] >= med]), ('below', [r for r in rated if r['mmr'] < med])]:
    print(f'  {label:18s} defensives per enemy go: median {statistics.median(r["defsPerGo"] for r in sel):.2f}  mean {statistics.mean(r["defsPerGo"] for r in sel):.2f}  n={len(sel)}')
for label, sel in [('above median MMR', [r for r in rated if r['mmr'] >= med]), ('below', [r for r in rated if r['mmr'] < med])]:
    m = statistics.median(r['defsPerGo'] for r in sel)
    hi = [r for r in sel if r['defsPerGo'] > m]; lo = [r for r in sel if r['defsPerGo'] <= m]
    print(f'  {label:18s} more defensives than median: win {wr(hi):5.1f}% (n={len(hi)})   fewer: {wr(lo):5.1f}% (n={len(lo)})')

print('\n== a kick on their healer in the peak, and the defensives the go drew')
goes = [go for g in games for go in g['goes']]
for label, sel in [('healer kicked in peak', [x for x in goes if x['healerKicked']]), ('not kicked', [x for x in goes if not x['healerKicked']])]:
    print(f'  {label:22s} defensives drawn per go {statistics.mean(x["defs"] for x in sel):.2f}  killed {sum(1 for x in sel if x["kill"] or x["killLater"]) / len(sel) * 100:.1f}%  n={len(sel)}')
print('\n== defensives drawn by a go, against whether it killed')
for d in range(0, 6):
    s = [x for x in goes if (x['defs'] == d if d < 5 else x['defs'] >= 5)]
    print(f'  {d}{"+" if d == 5 else ""} drawn: killed {sum(1 for x in s if x["kill"] or x["killLater"]) / max(1, len(s)) * 100:5.1f}%  n={len(s)}')
