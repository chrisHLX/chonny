# Copy the app's pages with every player's name swapped for a label ("Enemy Rogue", "Your Warlock",
# "You"), so screenshots of them can go on the public site. Names come from every card's rosters and
# from names.txt beside the pages folder (allnames.php). Prints, per page, any name still in it: every
# list must be empty before a picture is taken. See README.md.
# usage: python anon.py <pages dir> <out dir> file1.html file2.html ...
import re, sys, os, json, html

src, out, files = sys.argv[1], sys.argv[2], sys.argv[3:]
os.makedirs(out, exist_ok=True)
CLASSES = ['Death Knight', 'Demon Hunter', 'Druid', 'Evoker', 'Hunter', 'Mage', 'Monk', 'Paladin',
           'Priest', 'Rogue', 'Shaman', 'Warlock', 'Warrior']

NAME = re.compile(r'<span class="name"[^>]*>([^<]+)</span>(<span class="you">YOU</span>)?\s*<div class="small muted">\s*([^&<]+)', re.S)

def rosters(text):
    """[(name, class, side, is_you)] from a card's team tables; side 'them' or 'us'."""
    rows = []
    for chunk in re.split(r'<div class="label">', text)[1:]:
        side = 'them' if chunk.startswith(('Enemy team', 'Them')) else 'us' if chunk.startswith('Your team') else 'any'
        body = chunk.split('<div class="label">')[0]
        for m in NAME.finditer(body):
            spec = html.unescape(m.group(3)).strip()
            cls = next((c for c in CLASSES if spec.endswith(c)), spec.split()[-1] if spec else 'player')
            rows.append((html.unescape(m.group(1)).strip(), cls, side, bool(m.group(2)), spec[:-len(cls)].strip() or cls))
    return rows

# Every name the cards know, with its class; and the player's own characters.
known, mine, myspec = {}, set(), {}
for f in os.listdir(src):
    if re.fullmatch(r'[0-9a-f]{32}\.html', f):
        for name, cls, side, you, spec in rosters(open(os.path.join(src, f), encoding='utf-8').read()):
            known.setdefault(name, cls)
            if you:
                mine.add(name)
                myspec[name] = (spec + ' ' + cls) if spec != cls else cls

# The independent list: every name in the database's payloads (allnames.php), minus real words that
# turned up in it (Chi-Ji, a pet called Boar, Beast Mastery). Without a class from a roster: "Player".
STOP = {'Chi', 'Shadow', 'Soul', 'Beast', 'Efflorescence', 'Freedom', 'Boar'}
ALL = {n for n in open(os.path.join(os.path.dirname(os.path.abspath(src)), 'names.txt'), encoding='utf-8').read().splitlines() if n and n not in STOP}
for n in ALL:
    known.setdefault(n, 'Player')

def label_map(text, own_as_spec=False):
    sides, specs, count = {}, {}, {}
    for name, cls, side, you, spec in rosters(text):
        if side == 'any' and not you:
            continue
        sides.setdefault(name, set()).add('you' if you else side)
        specs[name] = spec
    # Only the names on this page, numbered in the order they first appear on it.
    present = sorted((n for n in known if n in text and re.search(r'(?<![\w])' + re.escape(n) + r'(?![\w])', text)), key=text.index)
    key = lambda n: (min(sides[n]) if len(sides.get(n, ())) == 1 else 'none', known[n])
    for name in present:
        if name not in mine:
            count[key(name)] = count.get(key(name), 0) + 1
    labels, used = {}, {}
    for name in present:
        if name in mine:
            labels[name] = myspec.get(name, 'You') if own_as_spec else 'You'
            continue
        s = sides.get(name, set())
        word = known[name]
        if count.get(key(name), 0) > 1 and name in specs and specs[name] != word:
            word = specs[name] + ('' if len(s) == 1 else ' ' + known[name])  # two of one class: by spec
        base = ('Enemy ' if s == {'them'} else 'Your ' if s == {'us'} else '') + word
        used[base] = used.get(base, 0) + 1
        labels[name] = base if used[base] == 1 else f'{base} {used[base]}'
    return labels

def anonymise(text, own_as_spec=False):
    labels = label_map(text, own_as_spec)
    names = [n for n in sorted(labels, key=len, reverse=True) if n in text]
    if not names:
        return text
    pat = re.compile(r'(?<![\w])(' + '|'.join(re.escape(n) for n in names) + r")(?:-[\w']+)?(?![\w])")
    return pat.sub(lambda m: labels[m.group(1)], text)

report = {}
for f in files:
    text = anonymise(open(os.path.join(src, f), encoding='utf-8').read(), f.startswith('comp-')).replace('<span class="you">YOU</span>', '')
    open(os.path.join(out, f), 'w', encoding='utf-8').write(text)
    plain = re.sub(r'<[^>]+>', ' ', re.sub(r'<(style|script)[^>]*>.*?</\1>', ' ', text, flags=re.S))
    left = sorted({n for n in (ALL | set(known)) - {'Player'} if re.search(r'(?<![\w])' + re.escape(n) + r'(?![\w])', plain)})
    report[f] = left
print(json.dumps({'known': len(known), 'mine': sorted(mine), 'left': report}, ensure_ascii=False, indent=1))
