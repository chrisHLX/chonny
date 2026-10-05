# The basics of one game, minute by minute, from the raw log: where each side's damage went, how
# long each player spent in crowd control and from what, who our CC landed on, each healer's health
# and mana, healing by source (a self-healing target shows here), the big cooldowns in order, kicks,
# and each player's lowest health per minute. Written 2026-10-05 for "new players need the basics".
#   python tools/match-review/basicsread.py MATCH_ID [BUCKET_SECONDS=60] [ME=Dijonhoney]
# Field offsets are the 12.1 advanced log (19 fields from infoGUID; damage amount at 31, swing at 28).
import gzip, csv, sys, re, collections

MATCH = sys.argv[1]
BUCKET = int(sys.argv[2]) if len(sys.argv) > 2 else 60
path = f'D:/MindCollector/arena-logs/raw/{MATCH}.log.gz'
raw = gzip.open(path, 'rt', encoding='utf-8', errors='replace').read().splitlines()

CC = {'Polymorph', 'Hammer of Justice', 'Freezing Trap', 'Ring of Frost', "Dragon's Breath", 'Intimidation',
      'Scatter Shot', 'Blinding Light', 'Chimaeral Sting', 'Repentance', 'Binding Shot', 'Fear', 'Cyclone',
      'Paralysis', 'Leg Sweep', 'Imprison', 'Sigil of Misery', 'Fel Eruption', 'Chaos Nova', 'Storm Bolt',
      'Intimidating Shout', 'Disarm', 'Hammer of Justice', 'Searing Glare', 'Shield of Virtue', 'Asphyxiate',
      'Strangulate', 'Blind', 'Gouge', 'Kidney Shot', 'Cheap Shot', 'Sap', 'Mighty Bash', 'Incapacitating Roar',
      'Psychic Scream', 'Mind Control', 'Song of Chi-Ji', 'Hex', 'Capacitor Totem', 'Solar Beam', 'Silence',
      'Spear Hand Strike', 'Counterspell', 'Counter Shot', 'Disrupt', 'Rebuke', 'Pummel', 'Mind Freeze', 'Kick',
      'Polymorph(Pig)', 'Tiger Rush', 'Wing Buffet', 'Shockwave', 'Sigil of Silence', 'Ring of Frost'}
BIG = {'Combustion', 'Bestial Wrath', 'Call of the Wild', 'Avenging Wrath', 'Avenging Crusader', 'Divine Shield',
       'Blessing of Protection', 'Lay on Hands', 'Blessing of Sacrifice', 'Ice Block', 'Alter Time',
       'Aspect of the Turtle', 'Gladiator\'s Medallion', 'Blur', 'Netherwalk', 'Darkness', 'Metamorphosis',
       'Divine Protection', 'Aura Mastery', 'Hammer of Justice', 'Blinding Light', 'Polymorph', 'Freezing Trap',
       'Ring of Frost', 'Dragon\'s Breath', 'Intimidation', 'Scatter Shot', 'Chimaeral Sting', 'Greater Invisibility',
       'Mass Invisibility', 'Survival of the Fittest', 'Exhilaration', 'Bloodshed', 'Stampede', 'Repentance',
       'Shifting Power', 'Searing Glare', 'Divine Toll', 'Light of Dawn', 'Spellwarding', 'Cloak of Shadows',
       'The Hunt', 'Void Metamorphosis', 'Collective Anguish', 'Fel Barrage', 'Imprison', 'Reverse Magic'}

def ts(line):
    d, t = line.split('  ', 1)[0].rsplit(' ', 1)
    # 10/5/2026 12:54:03.53311
    hh, mm, ss = t.split(':')
    return int(hh) * 3600 + int(mm) * 60 + float(ss)

t0 = None
units = {}   # guid -> (name, team)
owner = {}
rows = []
for line in raw:
    head, body = line.split('  ', 1)
    if body.startswith('COMBATANT_INFO'):
        p = body.split(',')
        units[p[1]] = [None, p[2], re.search(r',(\d+),\[', body).group(1)]  # team, spec
        continue
    t = ts(line)
    if t0 is None:
        t0 = t
    try:
        p = next(csv.reader([body]))
    except Exception:
        continue
    rows.append((t - t0, p))
    if len(p) > 9 and p[1] in units and units[p[1]][0] is None:
        units[p[1]][0] = p[2].split('-')[0]
    if len(p) > 9 and p[5] in units and units[p[5]][0] is None:
        units[p[5]][0] = p[6].split('-')[0]
    if p[0] == 'SPELL_SUMMON':
        owner[p[5]] = p[1]
    # advanced blocks: infoGUID, ownerGUID
    for i in (12, 9):
        if len(p) > i + 2 and p[i + 1].startswith('Player-') and (p[i].startswith('Pet-') or p[i].startswith('Creature-')):
            owner[p[i]] = p[i + 1]

ME = sys.argv[3] if len(sys.argv) > 3 else 'Dijonhoney'
me = [g for g, u in units.items() if u[0] and u[0].startswith(ME)][0]
myteam = units[me][1]
ours = {g for g, u in units.items() if u[1] == myteam}
theirs = {g for g, u in units.items() if u[1] != myteam}
name = lambda g: (units.get(g) or [g[:10]])[0]
print('us:', [name(g) + ':' + units[g][2] for g in ours], ' them:', [name(g) + ':' + units[g][2] for g in theirs])

def src_player(g):
    return g if g in units else owner.get(g)

dmg = collections.defaultdict(lambda: collections.Counter())   # bucket -> (src,dst) -> amount
heal = collections.defaultdict(lambda: collections.Counter())
cc = collections.defaultdict(lambda: collections.Counter())    # bucket -> (dst, spell) -> seconds
open_aura = {}
events = []
hp = {}  # guid -> list (t, pct, mana)
deaths = []
for t, p in rows:
    ev = p[0]
    b = int(t // BUCKET)
    if ev in ('SPELL_DAMAGE', 'SPELL_PERIODIC_DAMAGE', 'RANGE_DAMAGE', 'SWING_DAMAGE', 'SWING_DAMAGE_LANDED'):
        if ev == 'SWING_DAMAGE_LANDED':
            continue
        amt_i = 28 if ev.startswith('SWING') else 31
        if len(p) <= amt_i:
            continue
        s = src_player(p[1]); d = p[5]
        if s in ours and d in theirs:
            dmg[b][(name(s), name(d))] += int(p[amt_i])
        elif s in theirs and d in ours:
            dmg[b][(name(s), name(d))] += int(p[amt_i])
    if ev in ('SPELL_HEAL', 'SPELL_PERIODIC_HEAL') and len(p) > 33:
        s = src_player(p[1])
        if s in units:
            heal[b][(name(s), name(p[5]))] += int(p[31]) - int(p[33])
    # hp / mana snapshot from advanced block on casts
    if ev.startswith('SPELL') and len(p) > 25 and p[12] in units:
        try:
            cur, mx = int(p[14]), int(p[15])
            ptypes = p[22].split('|'); curp = p[23].split('|'); maxp = p[24].split('|')
            mana = None
            if '0' in ptypes:
                k = ptypes.index('0'); mana = int(curp[k]) / max(1, int(maxp[k])) * 100
            hp.setdefault(p[12], []).append((t, cur / mx * 100, mana))
        except Exception:
            pass
    if ev in ('SPELL_AURA_APPLIED', 'SPELL_AURA_REFRESH') and p[10] in CC and p[5] in units:
        open_aura[(p[5], p[10])] = (t, p[1])
    if ev == 'SPELL_AURA_REMOVED' and (p[5], p[10]) in open_aura:
        st, src = open_aura.pop((p[5], p[10]))
        for bb in range(int(st // BUCKET), int(t // BUCKET) + 1):
            lo = max(st, bb * BUCKET); hi = min(t, (bb + 1) * BUCKET)
            if hi > lo:
                cc[bb][(name(p[5]), p[10])] += hi - lo
    if ev == 'SPELL_CAST_SUCCESS' and p[10] in BIG and src_player(p[1]) in units:
        events.append((t, name(src_player(p[1])), p[10], name(p[5]) if p[5] in units else ''))
    if ev == 'SPELL_INTERRUPT':
        events.append((t, name(src_player(p[1])), 'KICK ' + p[10] + '>' + p[13], name(p[5])))
    if ev == 'UNIT_DIED' and p[5] in units:
        deaths.append((t, name(p[5])))

print('deaths', [(round(t), n) for t, n in deaths])
nb = max(dmg) + 1
print(f'\n== damage done per {BUCKET}s, by target (k) ==')
for b in range(nb):
    row = collections.Counter()
    for (s, d), a in dmg[b].items():
        if s in [name(g) for g in ours]:
            row[d] += a
    inc = collections.Counter()
    for (s, d), a in dmg[b].items():
        if s in [name(g) for g in theirs]:
            inc[d] += a
    print(f'{b*BUCKET:4d}s  ours->', {k: round(v / 1000) for k, v in row.most_common()}, ' | theirs->', {k: round(v / 1000) for k, v in inc.most_common()})

print(f'\n== who each of ours hit, whole game (k) ==')
tot = collections.Counter()
for b in dmg:
    for (s, d), a in dmg[b].items():
        tot[(s, d)] += a
for (s, d), a in sorted(tot.items(), key=lambda x: -x[1]):
    print(f'  {s:>14} -> {d:<14} {a/1000:8.0f}k')

print(f'\n== CC seconds per {BUCKET}s on each player ==')
for b in range(nb):
    per = collections.Counter(); spells = collections.defaultdict(set)
    for (d, s), sec in cc[b].items():
        per[d] += sec; spells[d].add(s)
    print(f'{b*BUCKET:4d}s', {d: f'{round(sec,1)}s ' + '/'.join(sorted(spells[d])) for d, sec in per.most_common()})

print('\n== healer hp / mana samples ==')
for g, samples in hp.items():
    if units[g][2] in ('65', '105', '256', '257', '264', '270', '1468'):
        out = []
        last = -999
        for t, h, m in samples:
            if t - last >= BUCKET / 2:
                out.append(f'{int(t)}s hp{h:.0f} mana{m:.0f}' if m is not None else f'{int(t)}s hp{h:.0f}')
                last = t
        print(name(g), ':', '; '.join(out))

print('\n== cooldowns, CC casts and kicks ==')
for t, s, sp, d in events:
    print(f'  {t:6.1f}  {s:>12}  {sp:<28} {d}')

print('\n== healing done (after overheal), whole game (k) ==')
th = collections.Counter()
for b in heal:
    for k, a in heal[b].items():
        th[k] += a
for (s, d), a in th.most_common(14):
    print(f'  {s:>12} -> {d:<12} {a/1000:8.0f}k')
print('\n== healing taken per minute by Tek / Saradaeyes, by source (k) ==')
for b in sorted(heal):
    row = collections.Counter()
    for (s, d), a in heal[b].items():
        if d in [name(g) for g in theirs]:
            row[f'{s}>{d}'] += a
    print(f'{b*BUCKET:4d}s', {k: round(v/1000) for k, v in row.most_common()})

print('\n== lowest health per minute (%) ==')
for g, samples in hp.items():
    if g in theirs or g in ours:
        lows = collections.defaultdict(lambda: 100.0)
        for t, h, m in samples:
            lows[int(t // BUCKET)] = min(lows[int(t // BUCKET)], h)
        print(f'{name(g):>12}', ' '.join(f'{lows[b]:3.0f}' for b in range(nb)))
