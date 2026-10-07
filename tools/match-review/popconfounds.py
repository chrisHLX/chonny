# The same go effects inside bands of game time and go length, the confound check behind docs/learning/population-findings-2026-10-06.md. Reads storage/app/population.
#   python tools/match-review/popconfounds.py
# Written 2026-10-06. Output names no one: players are hashed in the population files.
import json, glob, sys
import os
POP = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "storage", "app", "population", "*.json")
sys.stdout.reconfigure(encoding='utf-8')
games = [json.load(open(f, encoding='utf-8')) for f in glob.glob(POP)]
goes = [dict(go, bracket=g['bracket']) for g in games for go in g['goes'] if 'from' in go]
print('goes with timing', len(goes))
K = lambda x: x['kill'] or x['killLater']
L = lambda x: x['healerLocked'] >= 2 or x['healerKicked']
def r(sel):
    n = len(sel); return (sum(1 for x in sel if K(x)) / n * 100 if n else float('nan')), n

bands = [(0, 60), (60, 120), (120, 180), (180, 300), (300, 9999)]
print('\n== kill rate by go start (time in game)')
for lo, hi in bands:
    v, n = r([x for x in goes if lo <= x['from'] < hi]); print(f'  {lo:4d}-{hi:<4d}s  {v:5.1f}%  n={n}')

def within(label, cond):
    print(f'\n== {label}, inside each time band (with / without)')
    for lo, hi in bands:
        band = [x for x in goes if lo <= x['from'] < hi]
        a, na = r([x for x in band if cond(x)]); b, nb = r([x for x in band if not cond(x)])
        print(f'  {lo:4d}-{hi:<4d}s  with {a:5.1f}% (n={na:4d})   without {b:5.1f}% (n={nb:4d})')

within('their healer locked or kicked in the peak', L)
within('4+ healer control links', lambda x: x['healerCc'] >= 4)
within('4+ of their defensives on cooldown at the start', lambda x: x['drained'] >= 4)
within('joint peak and healer locked', lambda x: x['joint'] and L(x))

print('\n== go length as a confound (kill rate by length)')
for lo, hi in [(0, 10), (10, 20), (20, 30), (30, 45), (45, 9999)]:
    v, n = r([x for x in goes if lo <= x['len'] < hi]); print(f'  {lo:3d}-{hi:<4d}s long  {v:5.1f}%  n={n}')
print('\n== healer locked, inside each go-length band')
for lo, hi in [(0, 10), (10, 20), (20, 30), (30, 9999)]:
    band = [x for x in goes if lo <= x['len'] < hi]
    a, na = r([x for x in band if L(x)]); b, nb = r([x for x in band if not L(x)])
    print(f'  {lo:3d}-{hi:<4d}s  locked {a:5.1f}% (n={na})   free {b:5.1f}% (n={nb})')
print('\n== spread (press timing) inside go-length bands, two pressers')
for lo, hi in [(0, 15), (15, 25), (25, 9999)]:
    band = [x for x in goes if lo <= x['len'] < hi and x['spread'] is not None]
    a, na = r([x for x in band if x['spread'] <= 3]); b, nb = r([x for x in band if x['spread'] > 3])
    print(f'  {lo:3d}-{hi:<4d}s  within 3s {a:5.1f}% (n={na})   apart {b:5.1f}% (n={nb})')
