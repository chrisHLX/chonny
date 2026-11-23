# WoW Forever PvP Guides (2026-11-23)

`/wow/forever/pvp/{class}` lists every ability of a class in WoW Forever, grouped the way retail's
Spells table groups them: **Active Abilities**, then **Buffs & Passives**, each by category (Crowd
Control, Defensive, Mobility, Utility, Offensive, Other). There are no talent builds yet: an ability
that a one-rank talent gives (Cold Snap, Mortal Strike) is listed with a "Talent" badge, and talents'
passive effects are left out.

| Piece | Where |
|---|---|
| The data the page reads | `data/forever/abilities.json` (committed) |
| What builds it | `php artisan forever:abilities {folder} [--download] [--icons] [--listfile=...]` (`BuildForeverAbilities`) |
| Tooltips | `App\Support\ForeverTooltip` |
| Icons | `storage/app/public/forever-icons/{file id}.jpg` (committed, like retail's) |
| The page | `ForeverPvpController`, `resources/views/forever/pvp.blade.php`, tracked as `forever_pvp` (slot = class) |
| Tests | `ForeverPvpGuidesTest`, `ForeverTooltipTest` |

## Rebuilding after a Forever patch

```bash
php -d memory_limit=1G artisan forever:abilities C:/path/to/wowf --download --icons --listfile=C:/path/community-listfile.csv
```

Run it locally: it reads the retail spell tables for curation (below), so it needs the local DB. It
prints what it left out and why, and which abilities took retail's curation. Read those lines before
committing. `--download` fetches the DB2 CSVs from wago.tools (product `wowf`); `--icons` fetches any
missing icon from Blizzard's icon server by file id. A few ids get a 403 there (Lesser Heal, Faerie
Fire), and `--listfile` (wowdev's `community-listfile.csv`, 154 MB) supplies their names, which the
server does serve.

## What a class has

- The class's three tree skill lines: `SkillRaceClassInfo.Flags = 1040`, exactly 27 lines.
- Plus every one-rank talent (`Talent` rows with a single `SpellRank`).
- Left out: unnamed and hidden spells (`Attributes_0 & 0x80`); Season of Discovery runes left in
  the tables (`AcquireMethod = 3` with no level: none of the 242 was cast in 36,456 player casts in
  Chriso's logs of 20-22 Nov, while the ranked ones Forever kept, such as Penance, Water Shield and
  Shifting Power, were); a multi-rank talent's spells; a talent's or another spell's effect (cast, not
  learned, by a talent or a spell of another name, or named like one of the class's multi-rank talents:
  Impact, Frostbite, Clearcasting, Hellfire Effect, Moonkin Aura, Pyroclasm); a form's own passive.
- Ranks are one ability, by name. The rank shown is the highest a level 60 has. A copy another rank
  casts, or one with no cost, only stands for the ability when nothing else does (Shadowguard's
  buff, not its damage bolt).

## How the category is decided

Retail's own categoriser (`ModuleSpellReferenceService::categorizeFromOwnEffects`, then
`categorizeFromEffects` on the same-named siblings) is fed the same inputs it has in retail:

- **Effect types under retail's names.** `Effect` and `EffectAura` numbers are named from retail's raw
  dumps (`data/spelldata/raw/*.txt` print "Apply Aura (6) | Modify Damage Taken% (87)"). Checked on the
  346 spell ids both games share: 249 have the same effect, and the rest are spells retail reworked.
  Effect 58 (weapon damage, on Auto Shot and Raptor Strike) is on no retail spell, so it is named in
  the command.
- **The mechanic**, from `SpellCategories.Mechanic`, named by pairing shared spells (the `MECHANIC`
  table in the command says how each number was named).
- **Retail's curated columns** (`dr_category`, `is_interrupt`, `is_mobility`) carried over only where
  a Forever rank has the same spell id AND the same name as a retail spell. Never by name alone:
  retail's Blast Wave is a curated knockback and Forever's is a different spell. Never by id alone:
  retail reused id 99 (Demoralizing Roar in Forever, Incapacitating Roar in retail).
- **An interrupt** is any ability with an Interrupt Cast (68) effect, which is what retail's
  `is_interrupt` says: Shield Bash and Earth Shock read like Kick.
- **Siblings** are the ability's own ranks and the same-named spells they cast (Blizzard's damage).
  Not every spell of that name: Forever's tables hold NPC spells, and an NPC "Stealth" with a stun made
  Stealth read as Crowd Control.

Two changes to the shared categoriser came out of this, both in `ModuleSpellReferenceService`:
`Weapon Damage` in the damage pattern (the old `Weapon % Damage` matched no type in the data), and
`Disarm` in the mechanic map. The first also changes retail: Auto Shot, Windlash, Warbreaker and 8
other pressable retail spells move from Other to Offensive at the next `import:spelldata`.

## Tooltips

`ForeverTooltip` works out only what the tables state. A tooltip with anything else ships with no
description (117 of 496 on 2026-11-23), never a raw token or a guessed number:

- **Damage and healing ranges.** Forever's effects carry a `Variance` (Frostbolt rank 1: 19 with 0.407,
  where Classic's tooltip read "18 to 20"). How that becomes the tooltip's "X to Y" has not been
  checked against the game. One in-game tooltip of a known rank would settle it.
- `$<variable>`, `$?condition[...]`, `$@spelldesc`, `$g` (gender), `$q` (resurrection health), `$z`.

## Known gaps

- **Categories follow retail's rules, quirks included.** Drain Life, Siphon Life and Devouring Plague
  read Defensive (a health leech is not in the damage pattern; retail's Drain Life is Defensive too).
  Curse of the Elements reads Defensive (a "Damage Taken%" aura, without the sign). Buffs, totems,
  Cold Snap and Presence of Mind are Other, as in retail.
- **Not checked as real buttons:** a few abilities have no level and were never cast in Chriso's logs
  (Black Arrow, Heart of the Lion, Aspect of the Viper, Aspect of the Falcon, Enchanted Flare, Redirect,
  Shadowfiend, Felfire, Lightwell Renew). They may be leftovers. Only someone who plays the class can
  say.
- Cooldowns are base values. Talents that shorten them aren't applied.
