"""Reagent demand for WoW: Forever from the recipe graph.

1. Filter to recipes whose product (or enchant) and every reagent exist in Forever's ItemSparse.
2. Levelling model per profession: at each skill point, pick the trainer recipe (not taught by a
   recipe item) that is not grey and has the lowest expected cost per skill point, where
   cost = sum(reagent count * vendor sell price) as a rarity proxy, and the chance of a point is
   1 below yellow, (grey - s) / (grey - yellow) from yellow to grey. Required skill is not in the
   DB2 (MinSkillLineRank is 1), so a recipe is treated as available from yellow - 25.
3. Endgame consumables: level-45+ potions/elixirs/flasks/food/oils/explosives/bandages and
   high-skill enchants, and the reagents they consume.

The levelling path is rough: it can pick recipes that drop rather than train, because the DB2 does
not say where a recipe is learned. Use it for which materials a profession burns, not for counts.
"""
import json, sys
from collections import defaultdict

D = sys.argv[1]
g = json.load(open(D + '/graph.json'))
items = {int(k): v for k, v in g['items'].items()}
icls = {int(k): v for k, v in g['itemclass'].items()}
R = []
for r in g['recipes']:
    if r['out'] and not r['out_name']:
        continue  # product not in Forever (SoD leftovers)
    if any(i not in items or not items[i]['name'] for i, n, _ in r['reagents']):
        continue
    if not r['out'] and not r['enchant']:
        continue
    R.append(r)

def cost(r):
    return sum(n * max(items[i]['sell'], 1) for i, n, _ in r['reagents'])

# which recipe makes which item (for intermediates)
maker = {}
for r in R:
    if r['out'] and r['out'] not in maker:
        maker[r['out']] = r

out = {'kept': len(R), 'new_forever': sum(r['spell'] >= 1_000_000 for r in R)}
by_prof = defaultdict(list)
for r in R:
    by_prof[r['prof']].append(r)
out['per_prof'] = {p: dict(total=len(v), new=sum(r['spell'] >= 1_000_000 for r in v),
                           trainer=sum(not r['from_item'] for r in v)) for p, v in by_prof.items()}

# ---- levelling ----
lvl = {}
for p, rs in by_prof.items():
    trainer = [r for r in rs if not r['from_item'] and r['grey'] > r['yellow'] > 0]
    used = defaultdict(float)
    path = []
    s = 1
    while s < 300:
        best = None
        for r in trainer:
            req = max(1, r['yellow'] - 25)
            if req > s or s >= r['grey']:
                continue
            pch = 1.0 if s < r['yellow'] else (r['grey'] - s) / (r['grey'] - r['yellow'])
            if pch <= 0.15:
                continue
            c = cost(r) / pch
            if best is None or c < best[0]:
                best = (c, r, 1 / pch)
        if best is None:
            path.append((s, None))
            s += 1
            continue
        c, r, crafts = best
        for i, n, _ in r['reagents']:
            used[i] += n * crafts
        if not path or path[-1][1] != r['name']:
            path.append((s, r['name']))
        s += 1
    lvl[p] = dict(path=path, reagents=sorted(((items[i]['name'], round(n), items[i]['sell']) for i, n in used.items()),
                                             key=lambda x: -x[1]))
out['levelling'] = lvl

# ---- endgame consumables ----
CONS = {0}  # ClassID 0 = consumable
end = []
for r in R:
    lvlreq = r['out_reqlvl'] if r['out'] else 0
    c = icls.get(r['out'], [None, None]) if r['out'] else [None, None]
    is_cons = c[0] == 0
    if r['enchant'] and r['yellow'] >= 250:
        end.append(r)
    elif is_cons and (lvlreq >= 45 or r['yellow'] >= 270):
        end.append(r)
reagent_use = defaultdict(list)
for r in end:
    for i, n, name in r['reagents']:
        reagent_use[name].append((r['name'], n, r['prof'], icls.get(r['out'], [None, None])[1] if r['out'] else 'enchant'))
out['endgame_recipes'] = [(r['prof'], r['name'], r['out_reqlvl'], r['yellow'], r['from_item'], r['spell'] >= 1_000_000,
                           [(name, n) for i, n, name in r['reagents']]) for r in end]
out['endgame_reagents'] = sorted(((k, len(v), v) for k, v in reagent_use.items()), key=lambda x: -x[1])
json.dump(out, open(D + '/demand.json', 'w'), indent=1)
print('kept', out['kept'], 'new', out['new_forever'])
for p, v in out['per_prof'].items():
    print(p, v)
