"""Materials gathered while levelling (item level <= MAXLVL) that level-40+ consumables and
high-skill enchants use. Prints each material with its item level and its endgame consumers,
and each consumer's effect values, so you can judge whether it is used at 60.

    python levelling_now.py <folder with wago wowf CSVs and graph.json> [MAXLVL=50]
"""
import csv, json, re, sys
from collections import defaultdict

D = sys.argv[1]
MAXLVL = int(sys.argv[2]) if len(sys.argv) > 2 else 50
csv.field_size_limit(10**9)

def rows(n):
    return csv.DictReader(open(f'{D}/{n}.csv', encoding='utf-8', newline=''))

g = json.load(open(f'{D}/graph.json'))
items = {int(k): v for k, v in g['items'].items()}
icls = {int(k): v for k, v in g['itemclass'].items()}
recipes = [r for r in g['recipes'] if not (r['out'] and not r['out_name'])
           and all(i in items and items[i]['name'] for i, n, _ in r['reagents'])]
made = {r['out'] for r in recipes if r['out']}

# item -> use spell -> description with $s1/$s2/$d filled in
desc = {int(r['ID']): r['Description_lang'] for r in rows('Spell')}
pts = defaultdict(dict)
for r in rows('SpellEffect'):
    try:
        pts[int(r['SpellID'])][int(r['EffectIndex'])] = float(r['EffectBasePointsF'] or 0)
    except ValueError:
        pass
eff = {int(r['ID']): int(r['SpellID']) for r in rows('ItemEffect')}
use = defaultdict(list)
for r in rows('ItemXItemEffect'):
    sp = eff.get(int(r['ItemEffectID']))
    if sp:
        use[int(r['ItemID'])].append(sp)

def effect_text(item):
    out = []
    for sp in use.get(item, []):
        t = desc.get(sp, '')
        t = re.sub(r'\$(\d*)s(\d)', lambda m: str(int(pts[int(m.group(1)) if m.group(1) else sp].get(int(m.group(2)) - 1, 0))), t)
        out.append(re.sub(r'\s+', ' ', t)[:140])
    return ' / '.join(out)

# endgame consumers: consumables for level 35+, enchants learned at 250+, and consumables with a low
# level requirement that stay in use at 60 (a judgment call from playing vanilla; correct as needed)
USED_AT_60 = {'Free Action Potion', 'Swiftness Potion', 'Thistle Tea', 'Thorium Grenade', 'EZ-Thro Thorium Grenade',
              'Dense Dynamite', 'EZ-Thro Dark Bomb', 'EZ-Thro Mana Bomb', 'SAF-T Clever Dynamite', 'Dark Iron Bomb',
              'Iron Grenade', 'Goblin Sapper Charge', 'Heavy Runecloth Bandage', 'Restorative Potion',
              'Greater Stoneshield Potion', 'Elixir of Greater Agility', 'Elixir of Giants', 'Arcane Elixir',
              'Elixir of Shadow Power', 'Elixir of Greater Firepower', 'Elixir of Frost Power', 'Elixir of Superior Defense',
              'Nature Protection Potion', 'Fire Protection Potion', 'Frost Protection Potion', 'Shadow Protection Potion',
              'Holy Protection Potion', 'Magic Resistance Potion', 'Invisibility Potion', 'Lesser Invisibility Potion',
              'Frost Oil', 'Shadow Oil', 'Blackmouth Oil', 'Gift of Arthas', 'Elixir of Detect Lesser Invisibility',
              'Mageblood Potion', 'Dense Sharpening Stone', 'Dense Weightstone'}
consumers = defaultdict(list)
for r in recipes:
    lvl = r['out_reqlvl'] if r['out'] else 0
    cons = r['out'] and icls.get(r['out'], [None])[0] == 0
    if (cons and lvl >= 35) or (r['enchant'] and r['yellow'] >= 250) or r['name'] in USED_AT_60:
        for i, n, _ in r['reagents']:
            consumers[i].append((r, n))

RAW = {5, 6, 7, 8, 9, 10, 11, 12}  # trade goods subclasses: cloth, leather, metal/stone, meat, herb, elemental, fish, enchanting
rows_out = []
for i, cs in consumers.items():
    c = icls.get(i, [None, None])
    if c[0] != 7 or c[1] not in RAW or i in made and c[1] in (7,):
        continue
    it = items[i]
    if it['ilvl'] > MAXLVL:
        continue
    rows_out.append((it['ilvl'], it['name'], c[1], cs))

KIND = {5: 'cloth', 6: 'leather', 7: 'metal/stone', 8: 'meat', 9: 'herb', 10: 'elemental', 11: 'fish', 12: 'enchanting'}
for ilvl, name, sub, cs in sorted(rows_out):
    print(f'\n[{ilvl}] {name} ({KIND[sub]}) - {len(cs)} endgame uses')
    for r, n in sorted(cs, key=lambda x: -x[0]['out_reqlvl']):
        e = effect_text(r['out']) if r['out'] else 'enchant'
        print(f"    x{n} {r['name']} (lvl {r['out_reqlvl']}, {r['prof']}, {'recipe item' if r['from_item'] else 'trainer'}) :: {e}")
