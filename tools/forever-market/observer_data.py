"""Writes the MindCollector Observer addon's Data.lua from the Forever recipe graph.

For every reagent: the professions that use it and the skill ranges where those recipes can still
give a point (from the recipe being learnable to it going grey), and up to three things it makes
that are used at 60. For every recipe item: its profession and required skill.

Which recipes a recipe item teaches comes from the item's "on learn" effect (ItemEffect TriggerType 6,
whose SpellID is the recipe), not from names: "Recipe: Elixir of Ogre's Strength" teaches "Elixir of
Ogre Strength" (fixed 2026-11-10). Required skill is not in the client data (MinSkillLineRank is 1),
so a recipe counts as learnable from its recipe item's RequiredSkillRank when there is one, else from
yellow - 25; the addon replaces that with what a trainer shows once you visit one.

    python observer_data.py <folder with wago wowf CSVs and graph.json> <path to Data.lua> [wowhead folder]

The optional wowhead folder holds <profession>.json files read from Wowhead's Forever pages in the
player's browser (2026-11-10): per recipe the skill it's learned at, its colours, how it's obtained
(2 drop, 4 quest, 5 vendor, 6 trainer), the trainer's fee and how many a craft makes; per recipe item
the vendors (zone, faction, stock, price), the best drops and quests. Packed as
{"S": {spell: [learn, orange, yellow, green, grey, "sources", fee, made min, made max]},
 "I": {item: [skill, spell, [[npc, zone, alliance, horde, stock, price]], [[npc, zone, pct, level]], drops, [quests]]},
 "N": {npc: name}}. Zone names come from the client's AreaTable.
"""
import csv, json, sys
from collections import defaultdict

D, OUT = sys.argv[1], sys.argv[2]
csv.field_size_limit(10**9)
PROF_ID = {'Blacksmithing': 164, 'Leatherworking': 165, 'Alchemy': 171, 'Cooking': 185, 'Tailoring': 197,
           'Engineering': 202, 'Enchanting': 333, 'First Aid': 129}
USED_AT_60 = {'Free Action Potion', 'Swiftness Potion', 'Thistle Tea', 'Thorium Grenade', 'EZ-Thro Thorium Grenade',
              'Dense Dynamite', 'EZ-Thro Dark Bomb', 'EZ-Thro Mana Bomb', 'SAF-T Clever Dynamite', 'Dark Iron Bomb',
              'Iron Grenade', 'Goblin Sapper Charge', 'Heavy Runecloth Bandage', 'Restorative Potion',
              'Greater Stoneshield Potion', 'Elixir of Greater Agility', 'Elixir of Giants', 'Arcane Elixir',
              'Elixir of Shadow Power', 'Elixir of Greater Firepower', 'Elixir of Frost Power', 'Elixir of Superior Defense',
              'Nature Protection Potion', 'Fire Protection Potion', 'Frost Protection Potion', 'Shadow Protection Potion',
              'Magic Resistance Potion', 'Invisibility Potion', 'Lesser Invisibility Potion', 'Blackmouth Oil',
              'Dense Sharpening Stone', 'Dense Weightstone'}

g = json.load(open(f'{D}/graph.json'))
items = {int(k): v for k, v in g['items'].items()}
icls = {int(k): v for k, v in g['itemclass'].items()}
recipes = [r for r in g['recipes'] if not (r['out'] and not r['out_name'])
           and all(i in items and items[i]['name'] for i, n, _ in r['reagents'])]

sparse = {int(r['ID']): r for r in csv.DictReader(open(f'{D}/ItemSparse.csv', encoding='utf-8', newline=''))}
effects = {int(r['ID']): r for r in csv.DictReader(open(f'{D}/ItemEffect.csv', encoding='utf-8', newline=''))}
teaches = {}   # recipe item -> the recipe spell it teaches
for r in csv.DictReader(open(f'{D}/ItemXItemEffect.csv', encoding='utf-8', newline='')):
    e = effects.get(int(r['ItemEffectID']))
    if e and e['TriggerType'] == '6' and int(e['SpellID'] or 0) > 0:
        teaches[int(r['ItemID'])] = int(e['SpellID'])
recipe_items = {}      # recipe item -> (profession, required skill, recipe spell)
item_for_spell = {}    # recipe spell -> (recipe item, required skill)
for iid, spell in teaches.items():
    row = sparse.get(iid)
    if not row:
        continue
    skill, rank = int(row['RequiredSkill'] or 0), int(row['RequiredSkillRank'] or 0)
    if skill in PROF_ID.values():
        recipe_items[iid] = (skill, rank, spell)
        # a few recipes have several items (a Craftsman's Writ, a reprint): keep the lowest requirement
        if spell not in item_for_spell or rank < item_for_spell[spell][1]:
            item_for_spell[spell] = (iid, rank)

def used_at_60(r):
    o = r['out']
    c = icls.get(o, [None])[0] if o else None
    lvl = r['out_reqlvl'] if o else 0
    if r['enchant']:
        return r['yellow'] >= 250
    if c == 0:
        return lvl >= 35 or r['name'] in USED_AT_60
    if c in (2, 4):
        return lvl >= 55
    return False

spans = defaultdict(lambda: defaultdict(list))
uses = defaultdict(list)
for r in recipes:
    pid = PROF_ID[r['prof']]
    lo = (item_for_spell[r['spell']][1] if r['spell'] in item_for_spell else 0) or max(1, r['yellow'] - 25)
    hi = max(r['grey'], r['yellow'], lo)  # a few recipes carry no grey value
    for i, n, _ in r['reagents']:
        spans[i][pid].append((lo, hi))
        if used_at_60(r):
            uses[i].append(r['out_name'] or r['name'])

def merge(iv):
    iv = sorted(iv)
    out = [list(iv[0])]
    for a, b in iv[1:]:
        if a <= out[-1][1]:
            out[-1][1] = max(out[-1][1], b)
        else:
            out.append([a, b])
    return out

def lua_str(s):
    return '"' + s.replace('\\', '\\\\').replace('"', '\\"') + '"'

lines = ['-- Generated by tools/forever-market/observer_data.py from the WoW: Forever client data',
         '-- (wago.tools product wowf). Do not edit by hand; re-run the script.',
         'local _, ns = ...',
         'ns.reagents = {']
for i in sorted(spans):
    profs = ', '.join(f'[{pid}] = {{' + ', '.join(f'{{{a}, {b}}}' for a, b in merge(iv)) + '}'
                      for pid, iv in sorted(spans[i].items()))
    u = list(dict.fromkeys(uses.get(i, [])))[:3]
    us = (', u = {' + ', '.join(lua_str(x) for x in u) + '}') if u else ''
    lines.append(f'  [{i}] = {{p = {{{profs}}}{us}}},')
lines.append('}')
live = {r['spell'] for r in recipes}
lines.append('ns.recipeItems = {')
for iid, (skill, rank, spell) in sorted(recipe_items.items()):
    if spell not in live or iid not in items:
        continue  # teaches a recipe that is not in Forever
    lines.append(f'  [{iid}] = {{{skill}, {rank}, {spell}}},')
lines.append('}')

# Every recipe, per profession, for the Professions tab: {spell, name, learnable from, yellow, grey,
# taught by a recipe item (1) or a trainer (0), what it makes, {reagent, count, reagent, count, ...},
# learnable-from is exact (1, the recipe item's) or estimated (0), the recipe item (0 for a trainer)}.
# "Learnable from" is the recipe item's required skill when there is one, else yellow - 25 (the client
# data has no required skill), so the tab says "about" for those.
lines.append('ns.recipes = {')
names = {}
for pid in sorted(set(PROF_ID.values())):
    rs = sorted((r for r in recipes if PROF_ID[r['prof']] == pid and r['yellow'] > 0), key=lambda r: (r['yellow'], r['name']))
    lines.append(f'  [{pid}] = {{')
    for r in rs:
        item, item_rank = item_for_spell.get(r['spell'], (0, 0))
        lo = item_rank or max(1, r['yellow'] - 25)
        grey = max(r['grey'], r['yellow'], lo)
        reag = ', '.join(f'{i}, {n}' for i, n, _ in r['reagents'])
        for i, n, nm in r['reagents']:
            names[i] = nm
        if r['out']:
            names[r['out']] = r['out_name']
        if item:
            names[item] = items[item]['name']
        lines.append(f'    {{{r["spell"]}, {lua_str(r["name"])}, {lo}, {r["yellow"]}, {grey}, {1 if item else 0}, '
                     f'{r["out"] or 0}, {{{reag}}}, {1 if item_rank else 0}, {item}}},')
    lines.append('  },')
lines.append('}')

# Names, so the addon can show an item it hasn't cached yet.
lines.append('ns.itemNames = {')
for i in sorted(names):
    lines.append(f'  [{i}] = {lua_str(names[i])},')
lines.append('}')

# What vendors sell for a fixed price (vials, thread, spices, flux...): the client's buy price, used
# when the auction house has none.
VENDOR = ('Vial', 'Thread', 'Spices', 'Flux', 'Salt', 'Refreshing Spring Water', 'Simple Flour', 'Simple Wood',
          'Dye', 'Holy Salts', 'Mild Spices', 'Ice Cold Milk', 'Malt', 'Hops', 'Bottle of', 'Skin of Dwarven')
lines.append('ns.vendor = {')
for i in sorted(names):
    nm = names[i]
    if any(w in nm for w in VENDOR) and items[i]['buy'] > 0 and 'Vial of' not in nm:
        lines.append(f'  [{i}] = {items[i]["buy"]},')
lines.append('}')
# Wowhead's recipe facts, when they have been read (see the docstring).
WH = sys.argv[3] if len(sys.argv) > 3 else None
if WH:
    import glob, os
    areas = {}
    area_csv = f'{D}/AreaTable.csv'
    if os.path.exists(area_csv):
        for row in csv.DictReader(open(area_csv, encoding='utf-8', newline='')):
            areas[int(row['ID'])] = row['AreaName_lang']
    S, I, N = {}, {}, {}
    for f in sorted(glob.glob(os.path.join(WH, '*.json'))):
        j = json.load(open(f, encoding='utf-8'))
        S.update(j.get('S', {})); I.update(j.get('I', {})); N.update(j.get('N', {}))
    zone = lambda z: areas.get(int(z), '') if z and int(z) > 0 else ''
    lines.append("-- From Wowhead's Forever pages, read in the player's browser on " + __import__('datetime').date.today().isoformat())
    lines.append('ns.wh = {')
    for sp, v in sorted(S.items(), key=lambda kv: int(kv[0])):
        learn, o, y, g, gr, src, fee, mn, mx = v
        lines.append(f'  [{sp}] = {{{learn}, {o}, {y}, {g}, {gr}, {lua_str(src)}, {fee}, {mn}, {mx}}},')
    lines.append('}')
    lines.append('ns.recipeSources = {')
    for it, v in sorted(I.items(), key=lambda kv: int(kv[0])):
        skill, spell, vendors, drops, ndrops, quests = v
        vs = ', '.join(f'{{{lua_str(N.get(str(n), "?"))}, {lua_str(zone(z))}, {a}, {h}, {st}, {pr}}}' for n, z, a, h, st, pr in vendors)
        ds = ', '.join(f'{{{lua_str(N.get(str(n), "?"))}, {lua_str(zone(z))}, {pct}, {lvl}}}' for n, z, pct, lvl in drops)
        qs = ', '.join(lua_str(q) for q in quests)
        lines.append(f'  [{it}] = {{skill = {skill}, spell = {spell}, v = {{{vs}}}, d = {{{ds}}}, nd = {ndrops}, q = {{{qs}}}}},')
    lines.append('}')
    print(f'wowhead: {len(S)} recipes, {len(I)} recipe items')

open(OUT, 'w', encoding='utf-8', newline='\n').write('\n'.join(lines) + '\n')
print(f'{len(spans)} reagents, {sum(1 for l in lines if l.startswith("  [") and "p =" not in l)} recipe items -> {OUT}')
