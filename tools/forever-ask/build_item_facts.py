"""Writes item_facts.json for the ask bridge: for every Forever item that a recipe makes or uses, what
it goes into and what makes it, straight from the client data (tools/forever-market graph.json).

The bridge adds these facts to the prompt when a question names the item, so answers about what
something is used for come from the game files, not from a guide site.

    python build_item_facts.py <folder with graph.json> [item_facts.json]
"""
import json, sys
from collections import defaultdict

D = sys.argv[1]
OUT = sys.argv[2] if len(sys.argv) > 2 else 'item_facts.json'
g = json.load(open(f'{D}/graph.json'))
items = {int(k): v for k, v in g['items'].items()}
recipes = [r for r in g['recipes'] if not (r['out'] and not r['out_name'])
           and all(i in items and items[i]['name'] for i, n, _ in r['reagents'])]

uses, made = defaultdict(list), defaultdict(list)
for r in recipes:
    what = r['out_name'] or r['name']
    lvl = r['out_reqlvl'] if r['out'] else None
    for i, n, _ in r['reagents']:
        uses[i].append([what, r['prof'], r['yellow'], lvl, n])
    if r['out']:
        made[r['out']].append([r['name'], r['prof'], r['yellow'], [[nm, n] for i, n, nm in r['reagents']]])

facts = {}
for i in set(uses) | set(made):
    it = items[i]
    facts[it['name'].lower()] = {
        'name': it['name'], 'id': i, 'item_level': it['ilvl'],
        'used_in': sorted(uses.get(i, []), key=lambda u: -(u[3] or 0))[:40],
        'made_by': made.get(i, [])[:5],
    }
json.dump(facts, open(OUT, 'w', encoding='utf-8'), separators=(',', ':'))
print(len(facts), 'items ->', OUT)
