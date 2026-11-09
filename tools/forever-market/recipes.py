"""Build a recipe -> reagent graph for WoW: Forever from wago.tools DB2 CSVs (product wowf)."""
import csv, sys, json, os
from collections import defaultdict

D = sys.argv[1]
csv.field_size_limit(10**9)

def rows(name):
    with open(os.path.join(D, name + '.csv'), encoding='utf-8', newline='') as f:
        yield from csv.DictReader(f)

PROF = {164: 'Blacksmithing', 165: 'Leatherworking', 171: 'Alchemy', 185: 'Cooking', 197: 'Tailoring',
        202: 'Engineering', 333: 'Enchanting', 129: 'First Aid'}

spellname = {int(r['ID']): r['Name_lang'] for r in rows('SpellName')}
items = {}
for r in rows('ItemSparse'):
    items[int(r['ID'])] = dict(name=r['Display_lang'], q=int(r['OverallQualityID'] or 0), sell=int(r['SellPrice'] or 0),
                               buy=int(r['BuyPrice'] or 0), ilvl=int(r['ItemLevel'] or 0), reqlvl=int(r['RequiredLevel'] or 0),
                               stack=int(r['Stackable'] or 0), bond=int(r['Bonding'] or 0), desc=r['Description_lang'])
itemclass = {int(r['ID']): (int(r['ClassID']), int(r['SubclassID'])) for r in rows('Item')}

effects = defaultdict(list)
for r in rows('SpellEffect'):
    sid = int(r['SpellID'])
    effects[sid].append(dict(effect=int(r['Effect']), item=int(r['EffectItemType'] or 0),
                             bp=r.get('EffectBasePointsF') or r.get('EffectBasePoints'), aura=int(r['EffectAura'] or 0)))

reag = {}
for r in rows('SpellReagents'):
    lst = []
    for i in range(8):
        it, n = int(r[f'Reagent_{i}']), int(r[f'ReagentCount_{i}'])
        if it > 0 and n > 0:
            lst.append((it, n))
    reag[int(r['SpellID'])] = lst

# Recipe-teaching items (Recipe:, Pattern:, ...) by the name of what they teach
teach_prefix = ('Recipe: ', 'Pattern: ', 'Plans: ', 'Formula: ', 'Schematic: ', 'Manual: ')
taught_by_item = defaultdict(list)
for iid, it in items.items():
    for p in teach_prefix:
        if it['name'].startswith(p):
            taught_by_item[it['name'][len(p):].strip().lower()].append(iid)

recipes = []
for r in rows('SkillLineAbility'):
    sl = int(r['SkillLine'])
    if sl not in PROF:
        continue
    sid = int(r['Spell'])
    if sid not in reag or not reag[sid]:
        continue
    eff = effects.get(sid, [])
    made = [e['item'] for e in eff if e['effect'] == 24 and e['item']]
    is_enchant = any(e['effect'] == 53 for e in eff)
    name = spellname.get(sid, f'spell {sid}')
    if '[DNT]' in name or 'zzOLD' in name or name.startswith('Test'):
        continue
    out = made[0] if made else 0
    recipes.append(dict(
        prof=PROF[sl], spell=sid, name=name, min=int(r['MinSkillLineRank']),
        yellow=int(r['TrivialSkillLineRankLow']), grey=int(r['TrivialSkillLineRankHigh']),
        skillups=int(r['NumSkillUps']), out=out, out_name=items.get(out, {}).get('name', ''),
        out_q=items.get(out, {}).get('q', -1), out_reqlvl=items.get(out, {}).get('reqlvl', 0),
        out_class=itemclass.get(out, (None, None)), enchant=is_enchant,
        from_item=bool(taught_by_item.get(name.lower())),
        reagents=[(i, n, items.get(i, {}).get('name', f'item {i}')) for i, n in reag[sid]]))

json.dump(dict(recipes=recipes, items={k: v for k, v in items.items()}, itemclass={k: v for k, v in itemclass.items()}),
          open(os.path.join(D, 'graph.json'), 'w'))
print(len(recipes), 'recipes')
from collections import Counter
print(Counter(r['prof'] for r in recipes))
