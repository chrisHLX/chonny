# Player-round habits against winning: the logging player's failed casts by reason, off-target control (the macro signal), pets on target, casts kicked. Reads storage/app/population.
#   python tools/match-review/pophabits.py
# Written 2026-10-06. Output names no one: players are hashed in the population files.
import json, glob, collections, sys, statistics
import os
POP = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "storage", "app", "population", "*.json")
sys.stdout.reconfigure(encoding='utf-8')
rows = []
for f in glob.glob(POP):
    g = json.load(open(f, encoding='utf-8'))
    for h, p in g['players'].items():
        if p['alive'] < 45 or p['free'] <= 0:
            continue
        p = dict(p); p['h'] = h; p['bracket'] = g['bracket']
        rows.append(p)

def wr(sel):
    return (sum(1 for p in sel if p['won']) / len(sel) * 100) if sel else float('nan')

def split(label, sel, key, cut):
    a = [p for p in sel if key(p) >= cut]; b = [p for p in sel if key(p) < cut]
    print(f'  {label:58s} >= {cut}: win {wr(a):5.1f}% (n={len(a)})   below: win {wr(b):5.1f}% (n={len(b)})')

loggers = [p for p in rows if p['logger'] and p['failed'] is not None]
print('logger rounds', len(loggers))
reasons = collections.Counter()
for p in loggers:
    for r, n in p['failed'].items():
        reasons[r] += n
print('  top reasons', reasons.most_common(12))
per_min = lambda p, r: p['failed'].get(r, 0) / (p['free'] / 60)
print('== logger failures a minute free vs winning the round')
for r in ['Out of range', 'Target not in line of sight', 'Target needs to be in front of you.', "Can't do that while moving", 'Interrupted', 'Invalid target', 'No target', 'Item is not ready yet']:
    vals = [per_min(p, r) for p in loggers]
    med = statistics.median(vals)
    nz = [v for v in vals if v > 0]
    cut = statistics.median(nz) if nz else 0
    split(f'{r} (per free minute; median of rounds with any = {cut:.2f})', loggers, lambda p, r=r: per_min(p, r), round(cut, 2) if cut else 0.01)

print('== control and kicks off the damage target (macro proxy), players with 3+')
sel = [p for p in rows if p['control'] >= 3]
split('off-target share', sel, lambda p: p['offTarget'] / p['control'], 0.5)
print('== pets on the owner target, 30+ hits')
sel = [p for p in rows if p['petHits'] >= 30]
split('pet on owner target share', sel, lambda p: p['petOnTarget'] / p['petHits'], 0.75)
print('== own casts kicked (all players), per free minute')
if 'kicked' in rows[0]:
    split('kicked a minute', rows, lambda p: p['kicked'] / (p['free'] / 60), 0.5)
print('== dying first')
split('died first', rows, lambda p: 1 if p['diedFirst'] else 0, 1)
