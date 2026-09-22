# Matchup profiles

One small JSON per spec, feeding the **Matchup Lab** (`/wow/matchup-lab`,
`App\Livewire\MatchupLab`). Written by `php artisan wow:build-matchup-profiles`, which narrows
the same spec kit `wow:precompute-spell-kits` produces down to the four lists the engine needs.

```
data/matchup-profiles/{class}/{spec}.json   ~7KB each, 40 files, committed
```

## Why the files exist at all

A matchup needs **six specs at once**. The existing per-spec kits under `data/spell-kits/`
average ~750KB, so reading six of them is ~4.5MB of JSON parse plus a bulk rehydrate of every
referenced `Spell` row — on a box whose single vCPU is already the throughput ceiling. These
profiles are read six-at-a-time with no database query at all, and the simulation that runs on
top of them costs about 5ms.

They also carry **external** `spell_id` values, not internal `spells.id`. That is what makes the
committed file the real artifact rather than a placeholder: internal ids are patch-scoped and
reassigned on every rebuild, which is exactly why `deploy.sh` throws away the committed spell
kits and regenerates them per environment. A profile survives a rebuild.

## What is in one

| Key | What it holds | Where it comes from |
|---|---|---|
| `offensive` | Real offensive cooldowns, with cooldown and duration | `CooldownTabs::isEntry($entry, 'offensive')` — the site's single definition, which itself reads WoW Comps' reviewed floor and the arena-log-verified classification |
| `answers` | The answer pool: defensives, immunities, and the PvP trinket | `CooldownTabs::isEntry($entry, 'defensive')`, plus Gladiator's Medallion appended explicitly |
| `control` | Everything with a DR category, tagged hard or soft | the kit entry's **build-resolved** `drCategory`, never the raw column |
| `mobility` / `interrupts` | `spells.is_mobility` / `is_interrupt` | curated columns |

Only what a player can actually press: entries the spec's admin-default build really took, with
passives and `not_in_spellbook` internal copies dropped.

**Two different duration columns, on purpose.** Control reads `pvp_duration_seconds` and *only*
that — `duration_seconds` carries the PvE value for crowd control and is wrong by an order of
magnitude there (Blind reads 60s against a real 5s in arena; `CcChainBuilder`'s docblock has the
full reasoning). Buffs and mitigation have no such split, so `offensive` and `answers` read
`duration_seconds`, and carry `null` where it is absent rather than a guess.

## What a profile cannot say, and what breaks because of it

**Which answers can be cast on somebody else.** Nothing in the schema models who a spell may be
cast on. This is the same gap that let Banish and Shackle Horror ship in published guides as
control on players (`knowledge-gaps.md`, 2026-09-18). So `kind` records only the two
distinctions the data can actually make — `trinket` and `immunity` — and everything else is
`cooldown`. A guessed personal/external split would put a healer's externals on a DPS's own pool,
which is the precise error Part 2 of `arena-structure.md` warns about: *"their healer holding
three externals does not help a target the healer cannot reach."*

**Spend-driven cooldown reduction.** The numbers here are base and talent-modified cooldowns.
They do not include reduction driven by what a player spends during a game, because that is a
rotation run at a rate and no rate is recorded anywhere in this project. The error has a sign:
**every cadence derived from these files is an upper bound.** See `knowledge-gaps.md`
(2026-09-23) and C12 in `arena-open-questions.md`, which names the archive measurement that would
close it.

**One build per spec.** Each profile is built against that spec's current admin-default
`TalentBuild`. A talent swap into a matchup can change which go is even possible — Part 13 of the
model says that is where a real share of the edge at the top comes from — and none of that is
represented here.

## Regenerating

```bash
php artisan wow:build-matchup-profiles                 # every spec
php artisan wow:build-matchup-profiles rogue subtlety  # one
```

**Run it after `wow:precompute-spell-kits`, never before.** A profile is a narrowing of that
command's output, so this one reads the precomputed kit when it is fresh — a few seconds for a
whole sweep — and falls back to a full live `SpecKitComputer::compute()` per spec when it is
stale, which is the same ~7s-per-spec computation forty times over. `deploy.sh` runs them in that
order.

A full sweep runs one process per spec, for the same reason `PrecomputeSpellKits` does: a
single-process sweep accumulates memory and reproducibly OOMed production after 36 of 40 specs.

## The rule that governs what may be built on top of this

The engine reading these files (`App\Http\Services\CooldownGraphService`) reports **a kill
window**, meaning a window in which a go is a kill attempt rather than a strip — never a kill,
and never a win probability. There is no outcome corpus to fit a probability to: arena log search
is discontinued upstream, and the archive's comp index holds two entries. Part 19 of
`arena-structure.md` records why the source that proposed this feature was wrong to call the
crossing point "mathematically guaranteed", and that reading must not creep back into the page
copy.
