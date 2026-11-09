import json, sys
from collections import defaultdict
g=json.load(open(sys.argv[1] + '/graph.json')); d=json.load(open(sys.argv[1] + '/demand.json'))
items={int(k):v for k,v in g['items'].items()}
R={r['name']:r for r in g['recipes'] if not (r['out'] and not r['out_name'])}
PVP={'Free Action Potion','Living Action Potion','Limited Invulnerability Potion','Swiftness Potion','Greater Stoneshield Potion','Restorative Potion',
'Caustic Smog Potion','Potion of Venomous Blood','Potion of Predatory Senses','Major Healing Potion','Superior Healing Potion','Heavy Runecloth Bandage',
'Thorium Grenade','EZ-Thro Thorium Grenade','EZ-Thro Dark Bomb','EZ-Thro Mana Bomb','SAF-T Clever Dynamite','Dense Dynamite','Major Frenzy Potion',
'Superior Frenzy Potion','Major Spellblasting Potion','Superior Spellblasting Potion','Nature Protection Potion','Greater Nature Protection Potion',
'Greater Fire Protection Potion','Greater Frost Protection Potion','Greater Shadow Protection Potion','Mighty Rage Potion','Major Mana Potion','Superior Mana Potion'}
VENDOR={'Crystal Vial','Leaded Vial','Empty Vial','Imbued Vial','Soothing Spices','Hot Spices','Mild Spices','Refreshing Spring Water','Rune Thread',
'Heavy Silken Thread','Silken Thread','Fine Thread','Coarse Thread','Simple Flour','Salt','Black Lotus','Arcane Crystal'}
names=set(x[1] for x in d['endgame_recipes'])|{n for n in PVP if n in R}
agg=defaultdict(lambda:{'recipes':[], 'pvp':0,'raid':0,'ench':0})
for n in names:
    r=R[n]
    kind='pvp' if n in PVP else ('ench' if r['enchant'] or r['prof']=='Enchanting' else 'raid')
    for i,c,nm in r['reagents']:
        a=agg[nm]; a['recipes'].append(f'{n} x{c}'); a[kind]+=1
rows=sorted(agg.items(),key=lambda kv:-len(kv[1]['recipes']))
for nm,a in rows[:60]:
    tag='VENDOR' if nm in VENDOR else ''
    print(f"{len(a['recipes']):3d} pvp{a['pvp']:2d} raid{a['raid']:2d} ench{a['ench']:2d} {nm} {tag} :: {'; '.join(a['recipes'][:7])}")
