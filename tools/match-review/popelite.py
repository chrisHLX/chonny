"""What do the best players' games look like? Tiers every team in every archived round two ways:
by the team's MMR (rated 3v3 and 2v2 only; a Solo Shuffle round carries none) and by its players'
arena history (best 3v3 rating ever, Gladiator seasons) from popexperience.php. Then two reads:

 1. Style: the measures of top-tier teams against everyone else's, win or lose. What the best do
    more or less of.
 2. Within the top tier: the winning team against the losing team of the SAME round (a paired
    read, so the lobby, the patch and the comps are held), and the same pairing in the rest, to
    see whether what wins at the top is what wins below it.

A correlation over rounds, never a cause; the top tier is small (say so beside every number).
Inputs: storage/app/population-features.json (popfeatures.php) and
storage/app/population-experience.json (popexperience.php). Both name players: gitignored.
    python tools/match-review/popelite.py
Written 2026-10-07.
"""
import json
import statistics as st
from collections import defaultdict

rows = json.load(open('storage/app/population-features.json'))
xp = json.load(open('storage/app/population-experience.json'))
SEASON = '2026-08-01'

# Damage against the spec's own median this season, so a Mage and a Hunter compare.
dmg_by_spec = defaultdict(list)
for r in rows:
    if r['playedAt'] >= SEASON:
        for p in r['players']:
            if not p['healer'] and p['free'] > 30:
                dmg_by_spec[p['spec']].append(p['damage'] / (p['free'] / 60))
dmg_median = {s: st.median(v) for s, v in dmg_by_spec.items() if len(v) >= 10}


def side_features(r, side):
    other = 'them' if side == 'us' else 'us'
    ps = [p for p in r['players'] if p['side'] == side]
    if not ps:
        return None
    alive = sum(p['alive'] for p in ps) or 1
    mins = alive / 60
    dps = [p for p in ps if not p['healer']]
    g = r['goes'][side]
    f = {
        'won': (r['usWon'] if side == 'us' else not r['usWon']),
        'goes/min': g['n'] / (r['duration'] / 60) if r['duration'] else None,
        'first go (s)': g['firstAt'],
        'goes: healer locked %': 100 * g['locked'] / g['n'] if g['n'] else None,
        'goes: joint %': 100 * g['joint'] / g['n'] if g['n'] else None,
        'goes: tight %': 100 * g['tight'] / g['n'] if g['n'] else None,
        'goes: killed %': 100 * g['killed'] / g['n'] if g['n'] else None,
        'healer CC per go': g['healerCc'] / g['n'] if g['n'] else None,
        'their defs drawn per go': g['drained'] / g['n'] if g['n'] else None,
        'free exchanges won': r['free'][side],
        'free exchanges given': r['free'][other],
        'kicks/min': sum(p['kicks'] for p in ps) / mins,
        'CC casts/min': sum(p['control'] for p in ps) / mins,
        'CC off the kill target %': (100 * sum(p['offTarget'] for p in ps) / max(1, sum(p['control'] for p in ps))),
        'casts kicked/min': sum(p['kicked'] for p in ps) / mins,
        'idle % of alive': 100 * sum(p['idle'] for p in ps) / alive,
        'locked % of alive': 100 * sum(p['locked'] for p in ps) / alive,
        'died first': r['firstDeath'] is not None and r['firstDeath']['side'] == side,
    }
    rel = [p['damage'] / (p['free'] / 60) / dmg_median[p['spec']] for p in dps
           if p['free'] > 30 and p['spec'] in dmg_median and r['playedAt'] >= SEASON]
    f['DPS damage vs spec median'] = st.mean(rel) if rel else None
    for T in ('60', '120'):
        s = (r['state'] or {}).get(T)
        if s:
            f[f'own defs down @{T}s'] = s[side]['defensive']
            f[f'defs ahead @{T}s'] = s[other]['defensive'] - s[side]['defensive']
            f[f'own offs down @{T}s'] = s[side]['offensive']
    # Who they are: best 3v3 ever and Gladiator seasons of the players found.
    found = [xp.get(p['name']) for p in ps]
    found = [e for e in found if e and e.get('found')]
    f['_xp'] = st.mean(e.get('exp3v3') or 0 for e in found) if found else None
    f['_glad'] = sum(e.get('gladSeasons') or 0 for e in found) if found else None
    f['_found'] = len(found) / len(ps)
    f['_mmr'] = r['mmr'].get(side)
    return f


teams = []   # (round, side, features)
for r in rows:
    for side in ('us', 'them'):
        f = side_features(r, side)
        if f:
            teams.append((r, side, f))

METRICS = [k for k in teams[0][2] if not k.startswith('_') and k not in ('won', 'died first')]


def mean(v):
    v = [x for x in v if x is not None]
    return (st.mean(v), len(v)) if v else (None, 0)


def compare(title, a, b, la, lb):
    print(f'\n== {title}\n   {"":32s} {la:>18s} {lb:>18s}')
    for label, fn in (('won %', lambda f: 100 * f['won']), ('died first %', lambda f: 100 * f['died first'])):
        ma, na = mean([fn(f) for f in a]); mb, nb = mean([fn(f) for f in b])
        print(f'   {label:32s} {ma:12.1f} n={na:<4d} {mb:12.1f} n={nb:<4d}')
    for k in METRICS:
        ma, na = mean([f.get(k) for f in a]); mb, nb = mean([f.get(k) for f in b])
        if ma is None or mb is None:
            continue
        print(f'   {k:32s} {ma:12.2f} n={na:<4d} {mb:12.2f} n={nb:<4d}')


def paired(title, rounds):
    """Winner minus loser, same round. 'winner higher' is the share of rounds where the winning
    team measured more (ties left out); 50% is no signal."""
    print(f'\n== {title}: {len(rounds)} rounds, winner against loser of the same round')
    print(f'   {"":32s} {"winner mean":>12s} {"loser mean":>12s} {"winner higher":>14s}')
    for k in METRICS:
        if k == 'goes: killed %':   # the winner's goes killed by definition
            continue
        w, l, up, n = [], [], 0, 0
        for fw, fl in rounds:
            a, b = fw.get(k), fl.get(k)
            if a is None or b is None:
                continue
            w.append(a); l.append(b)
            if a != b:
                n += 1; up += a > b
        if len(w) < 8:
            continue
        print(f'   {k:32s} {st.mean(w):12.2f} {st.mean(l):12.2f} {100 * up / n if n else 0:9.0f}% of {n}')


def pairs(pred):
    out = []
    by_round = defaultdict(dict)
    for r, side, f in teams:
        by_round[r['id']][side] = (r, f)
    for d in by_round.values():
        if len(d) != 2:
            continue
        (r, fu), (_, ft) = d['us'], d['them']
        if not pred(r, fu, ft):
            continue
        out.append((fu, ft) if fu['won'] else (ft, fu))
    return out


# Coverage.
xps = [f['_xp'] for _, _, f in teams if f['_xp']]
print(f'teams: {len(teams)}; with a team MMR {sum(1 for _, _, f in teams if f["_mmr"])};'
      f' with any player history found {len(xps)}; players looked up {len(xp)},'
      f' found {sum(1 for e in xp.values() if e.get("found"))}')
for cut in (1800, 2100, 2400, 2700):
    print(f'   teams whose players average a best 3v3 of {cut}+: {sum(1 for x in xps if x >= cut)}')

# Tier 1: team MMR (rated 3v3 only: 2v2 MMR runs on its own scale and comps).
mmr3 = [(r, s, f) for r, s, f in teams if r['bracket'] == '3v3' and f['_mmr']]
top = [f for r, s, f in mmr3 if f['_mmr'] >= 2100]
rest = [f for r, s, f in mmr3 if f['_mmr'] < 2100]
compare('Rated 3v3 teams by MMR: 2100+ against below 2100', top, rest, '2100+ MMR', 'below 2100')

# Tier 2: experience. A team whose found players average a best 3v3 of 2400+ (Gladiator rating),
# with at least two of three found, any bracket.
def xp_tier(f):
    if f['_xp'] is None or f['_found'] < 0.6:
        return None
    return 'elite' if f['_xp'] >= 2400 else ('mid' if f['_xp'] >= 1900 else 'low')

for bracket in ('Rated Solo Shuffle', '3v3'):
    sel = [(r, s, f) for r, s, f in teams if r['bracket'] == bracket]
    compare(f'{bracket} teams by history: best 3v3 ever averaging 2400+ against below 1900',
            [f for _, _, f in sel if xp_tier(f) == 'elite'], [f for _, _, f in sel if xp_tier(f) == 'low'],
            'elite (2400+)', 'below 1900')

# The paired reads.
both_elite = pairs(lambda r, a, b: xp_tier(a) == 'elite' and xp_tier(b) == 'elite')
paired('Both teams elite by history (any bracket)', both_elite)
top_mmr = pairs(lambda r, a, b: r['bracket'] == '3v3' and (a['_mmr'] or 0) >= 2100 and (b['_mmr'] or 0) >= 2100)
paired('Rated 3v3, both teams 2100+ MMR', top_mmr)
low = pairs(lambda r, a, b: xp_tier(a) in ('low', 'mid') and xp_tier(b) in ('low', 'mid'))
paired('Neither team elite by history (the comparison)', low)

# When the history differs: does the more experienced team win, and by how much?
diff = pairs(lambda r, a, b: a['_xp'] and b['_xp'] and a['_found'] >= .6 and b['_found'] >= .6)
for gap in (100, 200, 400):
    sel = [(w, l) for w, l in diff if abs(w['_xp'] - l['_xp']) >= gap]
    if sel:
        print(f'\nhistory gap {gap}+: the more experienced team won {100 * sum(w["_xp"] > l["_xp"] for w, l in sel) / len(sel):.0f}% of {len(sel)}')

# 3. The players themselves, by Gladiator seasons: each player's habits against the median of
# their own spec over the whole archive (1.00 = the spec's average), so a Rogue's kicks are read
# against Rogues. Every round a player played counts once.
HABITS = {
    'kicks/min': lambda p: p['kicks'] / (p['alive'] / 60),
    'CC casts/min': lambda p: p['control'] / (p['alive'] / 60),
    'casts kicked/min': lambda p: p['kicked'] / (p['alive'] / 60),
    'idle % of alive': lambda p: 100 * p['idle'] / p['alive'],
    'locked % of alive': lambda p: 100 * p['locked'] / p['alive'],
    'damage per free min': lambda p: p['damage'] / (p['free'] / 60) if p['free'] > 30 else None,
    'healing per free min': lambda p: p['healing'] / (p['free'] / 60) if p['free'] > 30 else None,
}
by_spec = defaultdict(lambda: defaultdict(list))
plays = []
for r in rows:
    for p in r['players']:
        if p['alive'] < 30:
            continue
        e = xp.get(p['name'])
        glad = e.get('gladSeasons') if e and e.get('found') else None
        won = r['usWon'] if p['side'] == 'us' else not r['usWon']
        vals = {k: fn(p) for k, fn in HABITS.items()}
        for k, v in vals.items():
            if v is not None:
                by_spec[p['spec']][k].append(v)
        plays.append((p, glad, won, vals))
spec_med = {s: {k: st.mean(v) for k, v in d.items() if len(v) >= 15} for s, d in by_spec.items()}


def glad_band(g):
    return None if g is None else ('3+ Gladiator seasons' if g >= 3 else ('1-2 seasons' if g >= 1 else 'never Gladiator'))


bands = ['never Gladiator', '1-2 seasons', '3+ Gladiator seasons']
# Each PLAYER counts once (the logging player and his regular partners would otherwise be most of
# the rows): a player's ratio is their total against what average players of the same specs did
# over the same rounds, and a band reads the median of its players' ratios.
per_player = defaultdict(lambda: {'glad': None, 'healer': False, 'n': 0, 'won': 0, 'first': 0,
                                  'got': defaultdict(float), 'exp': defaultdict(float)})
for p, g, w, vals in plays:
    pp = per_player[p['name']]
    pp['glad'] = g; pp['healer'] = p['healer']; pp['n'] += 1; pp['won'] += w; pp['first'] += p['diedFirst']
    for k, v in vals.items():
        m = spec_med.get(p['spec'], {}).get(k)
        if v is not None and m is not None:
            pp['got'][k] += v; pp['exp'][k] += m
print("\n== Players by Gladiator seasons, each player once: habits against their spec's average (1.00 = typical)")
print('   ' + ' ' * 24 + ''.join(f'{b:>24s}' for b in bands))
cnt = {b: [x for x in per_player.values() if glad_band(x['glad']) == b] for b in bands}
print('   ' + f'{"players (player-rounds)":24s}' + ''.join(f'{len(cnt[b]):>15d} ({sum(x["n"] for x in cnt[b]):>5d})  ' for b in bands))
print('   ' + f'{"won % (per player)":24s}' + ''.join(f'{100 * st.mean(x["won"] / x["n"] for x in cnt[b]):>24.1f}' for b in bands))
print('   ' + f'{"died first %":24s}' + ''.join(f'{100 * st.mean(x["first"] / x["n"] for x in cnt[b]):>24.1f}' for b in bands))
for role, is_healer in (('DPS', False), ('healer', True)):
    print(f'   -- {role}')
    for k in HABITS:
        if k == ('damage per free min' if is_healer else 'healing per free min'):
            continue
        cells = []
        for b in bands:
            ratios = [x['got'][k] / x['exp'][k] for x in cnt[b] if x['healer'] == is_healer and x['exp'][k]]
            cells.append(f'{st.median(ratios):>17.2f} n={len(ratios):<4d}' if len(ratios) >= 8 else f'{"-":>24s}')
        print(f'   {k:24s}' + ''.join(cells))
