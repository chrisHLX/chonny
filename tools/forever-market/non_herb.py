"""Non-herb materials gathered while levelling (item level <= MAXLVL) that level-60 crafting needs,
following intermediates (Runecloth -> Bolt of Runecloth -> a robe). Level-60 buyers are:
consumables for level 35+ (or on levelling_now.USED_AT_60), enchants learned at 250+, crafted gear
and bags for level 55+, and anything learned at 275+. Also lists tradeable (not bind-on-pickup)
recipe items that teach one of those.

    python non_herb.py <folder with wago wowf CSVs and graph.json> [MAXLVL=52]
"""
import json, sys
from collections import defaultdict

D = sys.argv[1]
MAXLVL = int(sys.argv[2]) if len(sys.argv) > 2 else 52
sys.argv = sys.argv[:1] + [D]  # levelling_now reads argv[1]
import io, contextlib
with contextlib.redirect_stdout(io.StringIO()):
    import levelling_now as ln  # reuses its tables and USED_AT_60

items, icls, recipes = ln.items, ln.icls, ln.recipes
KIND = {5: 'cloth', 6: 'leather', 7: 'metal/stone', 8: 'meat', 10: 'elemental', 11: 'other', 12: 'enchanting'}

def end_use(r):
    o = r['out']
    c = icls.get(o, [None, None]) if o else [None, None]
    lvl = r['out_reqlvl'] if o else 0
    if r['enchant']:
        return 'enchant' if r['yellow'] >= 250 else None
    if c[0] == 0 and (lvl >= 35 or r['name'] in ln.USED_AT_60):
        return 'consumable'
    if c[0] in (2, 4) and lvl >= 55:
        return 'gear'
    if c[0] == 1 and r['yellow'] >= 250:
        return 'bag'
    if r['yellow'] >= 275 and c[0] != 7:
        return 'other 275+'
    return None

makers = defaultdict(list)
for r in recipes:
    if r['out']:
        makers[r['out']].append(r)

# demand[item] = list of (end recipe name, kind, path)
demand = defaultdict(list)
def push(item, n, endr, kind, path, depth):
    demand[item].append((endr, kind, path, n))
    if depth >= 3:
        return
    for m in makers.get(item, []):
        if m['prof'] == 'Enchanting' and 'Shard' in items[item]['name']:
            continue
        for i, k, _ in m['reagents']:
            push(i, n * k, endr, kind, path + [m['name']], depth + 1)

for r in recipes:
    kind = end_use(r)
    if kind:
        for i, n, _ in r['reagents']:
            push(i, n, r['name'], kind, [], 0)

VENDOR_WORDS = ('Vial', 'Thread', 'Spices', 'Flux', 'Salt', 'Spring Water', 'Flour', 'Dye', 'Simple Wood', 'Blasting Powder')
out = []
for i, uses in demand.items():
    c = icls.get(i, [None, None])
    it = items[i]
    if c[0] == 7 and c[1] == 9:
        continue  # herbs
    if c[0] not in (3, 7) or it['ilvl'] > MAXLVL or i in makers:
        continue  # gems (3) and raw trade goods only, not intermediates
    if any(w in it['name'] for w in VENDOR_WORDS):
        continue
    ends = {}
    for endr, kind, path, n in uses:
        ends.setdefault(endr, (kind, path, n))
    by = defaultdict(int)
    for kind, _, _ in ends.values():
        by[kind] += 1
    out.append((len(ends), it['ilvl'], it['name'], 'gem' if c[0] == 3 else KIND.get(c[1], c[1]), dict(by), ends))

for cnt, ilvl, name, kind, by, ends in sorted(out, key=lambda x: (-x[0])):
    ex = sorted(ends.items(), key=lambda kv: -kv[1][2])[:6]
    exs = '; '.join(f"{e} ({k}{', via ' + ' > '.join(p) if p else ''}, {n:g})" for e, (k, p, n) in ex)
    print(f"{cnt:3d} [{ilvl}] {name} ({kind}) {by} :: {exs}")

# tradeable recipe items teaching a level-60 recipe
print('\n== Tradeable recipe items for level-60 recipes ==')
by_name = {r['name'].lower(): r for r in recipes}
isp = {}
import csv
for row in csv.DictReader(open(f'{D}/ItemSparse.csv', encoding='utf-8', newline='')):
    isp[int(row['ID'])] = row
for iid, it in items.items():
    for p in ('Recipe: ', 'Pattern: ', 'Plans: ', 'Formula: ', 'Schematic: ', 'Manual: '):
        if it['name'].startswith(p):
            r = by_name.get(it['name'][len(p):].strip().lower())
            if not r or not end_use(r):
                continue
            bond = int(isp[iid]['Bonding'] or 0)
            if bond == 1:
                continue  # bind on pickup
            print(f"  {it['name']} [needs {isp[iid]['RequiredSkillRank']}, item lvl {it['ilvl']}, {'BoE' if bond == 2 else 'no bind'}] -> {end_use(r)}")
