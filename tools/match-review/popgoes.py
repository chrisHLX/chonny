# Go kill rates across every archived game (both sides) by condition: control on their healer, healer locked or kicked in the peak, damage together (joint), press spread, defensives drained. Reads storage/app/population (wow:population).
#   python tools/match-review/popgoes.py
# Written 2026-10-06. Output names no one: players are hashed in the population files.
import json, glob, collections, os, sys
import os
POP = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "storage", "app", "population", "*.json")
sys.stdout.reconfigure(encoding='utf-8')
games = [json.load(open(f, encoding='utf-8')) for f in glob.glob(POP)]
goes = []
for g in games:
    mmr = g.get('mmr') or {}
    for go in g['goes']:
        go = dict(go)
        go['bracket'] = g['bracket']
        go['mmr'] = mmr.get(go['side'])
        goes.append(go)

def rate(sel, label):
    n = len(sel)
    k = sum(1 for x in sel if x['kill'] or x['killLater'])
    k0 = sum(1 for x in sel if x['kill'])
    print(f'  {label:44s} n={n:5d}  kill(+30s)={k/n*100 if n else 0:5.1f}%   kill-in-go={k0/n*100 if n else 0:5.1f}%')

print('== spread between the first offensive presses of different players')
two = [x for x in goes if x['spread'] is not None]
for lo, hi in [(0,1),(1,2),(2,4),(4,7),(7,10),(10,15),(15,999)]:
    rate([x for x in two if lo <= x['spread'] < hi], f'{lo}-{hi}s')
print('== one presser only'); rate([x for x in goes if x['pressers'] == 1], 'one player pressed')
print('== joint peak (two players each 25%+ of the 6s peak) x healer locked')
L = lambda x: x['healerLocked'] >= 2 or x['healerKicked']
for j in (True, False):
    for l in (True, False):
        rate([x for x in goes if x['joint'] == j and L(x) == l], f'joint={j} healerLocked={l}')
print('== healer locked seconds in the peak')
for lo, hi in [(0,0.01),(0.01,2),(2,4),(4,6.01)]:
    rate([x for x in goes if lo <= x['healerLocked'] < hi and not x['healerKicked']], f'{lo}-{hi}s (no kick)')
rate([x for x in goes if x['healerKicked']], 'healer kicked in the peak')
print('== their defensives on cooldown at the start (drained)')
for d in range(0, 5):
    rate([x for x in goes if (x['drained'] == d if d < 4 else x['drained'] >= 4)], f'drained={d}{"+" if d==4 else ""}')
print('== healer CC links in the go')
for c in range(0, 5):
    rate([x for x in goes if (x['healerCc'] == c if c < 4 else x['healerCc'] >= 4)], f'healerCc={c}{"+" if c==4 else ""}')
print('== tight (every link within 5s) vs loose')
rate([x for x in goes if x['good']], 'good'); rate([x for x in goes if not x['good']], 'loose')
print('== by bracket')
for b in sorted({x['bracket'] for x in goes}, key=str):
    rate([x for x in goes if x['bracket'] == b], str(b))
print('== drained >=1 AND healer locked, vs neither')
rate([x for x in goes if x['drained'] >= 1 and L(x)], 'drained and locked')
rate([x for x in goes if x['drained'] >= 1 and not L(x)], 'drained, healer free')
rate([x for x in goes if x['drained'] == 0 and L(x)], 'nothing drained, locked')
rate([x for x in goes if x['drained'] == 0 and not L(x)], 'nothing drained, healer free')
