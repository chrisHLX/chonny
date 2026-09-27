# Match Review — reading your own arena games

How a played game gets from WoW into something you can read, what the log can and cannot be made
to say, and what has actually been measured from it so far.

Written 2026-09-27 at the end of the session that built it. `addon-upgrades.md` holds what Chriso
wants next from the capture side; this holds how the thing works and what it has found.
CLAUDE.md's rule 12 holds the Solo Shuffle log structure and is not repeated here.

---

## Where it stands

Deployed and live at commit `575ea01`. `/wow/game-review` is **auth-gated and scoped to the
signed-in user** — it shipped public for about twenty minutes on 2026-09-25 and that must not
recur; the route middleware and the component's own scoping are both load-bearing.

Two ways in, one assembler:

```
WoWCombatLog-*.txt
   │
   ├─ wow:sync ──→ wow:ingest-combatlog ──→ archive (raw/ + metadata/, on D:)
   │                                            │
   │                                            └─→ LobbyReviewService::buildFromArchive()
   │                                                        │
   └─ browser upload ─→ ArenaReviewIngestService ──────────→ assemble()  ← one place
                              (arena_rounds)                      │
                                                                  ▼
                                                        arena_reviews (owned rows)
                                                                  │
                                                            /wow/game-review
```

`assemble()` is deliberately input-agnostic. Verified by replaying all six rounds of a real lobby
through the upload path and getting a result identical to the archive path.

**Reviews are database rows, not files.** They were briefly committed, which published other
people's names, talents and gear. `arena_reviews` and `arena_rounds` both carry `user_id`.

**The raw log is not retained on upload.** A round is derived on arrival into
`arena_rounds.payload` — metadata, throughput, every player's COMBATANT_INFO, and the moments — and
the text discarded. That payload is the only second chance: **anything not captured at derive time
cannot be recovered without the player re-uploading.** This is why moment detection had to go in
before real uploads started.

### The pieces

| Thing | Where |
|---|---|
| Split a log into rounds, derive metadata | `CombatLogIngestService` |
| Measure healing / absorbs / damage | `CombatantThroughputService` |
| Build, gear and the stat block | `ArenaLogService::extractCombatantInfo()` |
| Resolve a build to talent names | `ArenaLogService::resolveCombatantTalents()` |
| Find the moments in a round | `ArenaMomentService` |
| Assemble and store a review | `LobbyReviewService` |
| Browser upload | `ArenaReviewIngestService`, `ArenaUploadController` |
| The page | `App\Livewire\GameReview` |

Local loop: `php artisan wow:sync` then `tools/arena.bat`. `wow:forget-games --before=` removes
games and **records its cutoff** so a later bare sync cannot resurrect them — it did exactly that
once, rebuilding 196 deliberately deleted games minutes after they were deleted.

---

## What the combat log actually says

Everything here was measured against real 12.1.0.69933 logs, not assumed. Field counts are stable
per event type, so **every offset is read from the END of the line** — the advanced-logging block
in the middle has grown between patches and the suffix has not.

| Event | Fields | Offsets |
|---|---|---|
| `SPELL_HEAL`, `SPELL_PERIODIC_HEAL` | 36 | amount `-5`, overhealing `-3` |
| `SPELL_DAMAGE`, `SPELL_PERIODIC_DAMAGE` | 42 | amount `-11`, baseAmount `-10`, overkill `-9`, **critical `-4`** |
| `SWING_DAMAGE`, `SWING_DAMAGE_LANDED` | 38 | amount `-10` |
| `SPELL_ABSORBED` | **22 or 19** | shield caster `-10`, absorbed `-3` |

**The crit flag is at `-4`, not `-5`.** Spell damage lines carry a trailing `ST`/`AOE` tag that
shifts the documented suffix by one. Reading `-5` returns `absorbed`, which is almost always 0, so
a crit silently reads as "not a crit". This was got wrong once and only surfaced because a hit that
looked impossible turned out to be an ordinary critical strike.

**`amount` vs `baseAmount` on a crit.** For a critical strike `amount` is the crit and
`baseAmount` is the non-crit figure, so `amount > baseAmount` is normal and is **not** an
amplification effect. Their ratio is the crit multiplier.

**`SPELL_ABSORBED` has two lengths** — 22 when a spell was absorbed, 19 when a melee swing was,
because the swing form carries no spellId/name/school for the incoming hit. A fixed index for the
shield's caster reads the wrong field on 1,656 of 20,440 events in a single lobby.

**`SWING_DAMAGE` and `SWING_DAMAGE_LANDED` overlap.** 1,540 and 3,864 events in one lobby, 836 of
them the same hit reported twice. Sum both and melee is over-counted; take either alone and a
third or half is lost. De-duplicate on (timestamp, source, target, amount).

**Self-damage is not output.** A health-cost or redirected-damage ability otherwise counts toward
its own team and puts a damage peak on the wrong side of the scoreboard.

**Pets** are credited via `SPELL_SUMMON` (source is the owner). The advanced-param `ownerGUID` is
no help — on a pet's own damage event it reads all zeroes, because those params describe the
event's *target*. A pet already out before logging started cannot be attributed and is reported as
`unattributedPetDamage` rather than guessed onto somebody.

**`UNIT_DIED`'s trailing field is `unconsciousOnDeath`** — 1 for a feign, 0 for a real death.
Without that filter one Beast Mastery Hunter "dies" six times a lobby.

### COMBATANT_INFO

**The stat block is 22 fields on this client where the documented layout is 21.** One field in the
dodge/parry/block/crit/speed/lifesteal run is undocumented, so counting forward mislabels
everything after it — a naive read put `armor` on a versatility value. Labels are anchored from
both ends and the haste and versatility triples are *verified* (each must be three equal values);
if they do not match, the block is reported unaligned rather than shown wrong. Values are
**ratings**, not percentages.

**Gear** is `(itemId, ilvl, (enchants), (bonusIds), (gems))` per slot, two fields after the talent
bracket. Read the **median** ilvl — a shirt or tabard at ilvl 1 drags a mean down twenty points.

**The talent `entryId` is not `talent_node_entries.external_talent_id`** — two genuinely different
Blizzard id spaces, 0 of 17 matched. `nodeId` does match `external_node_id`.
`resolveCombatantTalents()` already reconciles this with a median-offset method verified 12/12
across 114 archived matches; **do not reinvent a node-only workaround**, it is cruder.

---

## Moments: what happened in a round

`ArenaMomentService`. **Moments are found from the cooldowns, not from a clock.** An earlier pass
looked for a health collapse inside a fixed window and needed a threshold nobody could defend — at
35% it fired every twelve seconds on ordinary churn, at 85% it was arbitrary the other way.

**Two cooldown bars, and the second one matters.** 45s+ is worth listing; **only 90s+ anchors a
moment**. Without the split a busy round is one moment: somebody presses something every few
seconds, the chain never breaks, and a real round came out as a single 44-second window holding 38
commitments. Avatar and Combustion anchor; Colossus Smash and a trinket ride along.

**The classification labels, it does not decide.** `data/arena-logs/spell-classification/*.json`
is a hand-reviewed list of 304 ids that says which way a cast points. It is **not** a list of what
matters — letting it decide put Penance, a nine-second rotational spell, in as a commitment. And
`mixed-cooldowns.json` (25 spells) means **both**, not defensive: reading the flag naively filed
Avatar under things a Warrior pressed to survive.

A moment carries: what each side committed, the pressured player and their health curve, control
that landed on a defending healer (**with who broke it, named**), and deaths.

---

## What has been measured

Small samples. Every number below is from one player's own games and is a starting point, not a
finding about the game.

### Mixed cooldowns are mostly not mixed

Damage done during the buff against the caster's own rate for the rest of the round:

| | casts | dmg done | dmg taken |
|---|---|---|---|
| Ultimate Penitence | 15 | 6.07x | 0.00x |
| Bestial Wrath | 5 | 1.80x | 1.12x |
| Eye Beam | 24 | 1.42x | 1.09x |
| **Avatar** | **27** | **1.31x** | **1.12x** |
| Metamorphosis | 6 | 1.17x | 2.08x |

Of the 25 spells `mixed-cooldowns.json` calls both, **only Ultimate Penitence behaves that way**.
Avatar and Metamorphosis are pressed *into* more damage, not to avoid it. Treating them as a
defensive trade is wrong.

### Measuring a defensive honestly

Comparing damage taken during a buff against the **round average** biases every defensive towards
looking useless — you press them when you are being focused and the average includes quiet time.
The control is damage taken in the **same length of time immediately before the cast**.

**An external must be measured on the ally it was cast on, not the caster.** Pain Suppression on
the Priest's own damage taken was n=2 noise; redirected to the recipient it is **n=29, +63%**.
But **same side only** — Touch of Karma, The Hunt and Ray of Frost target an enemy, and
redirecting there measured the victim instead, turning Touch of Karma from 1.48x into 0.28x.

Zone effects (Aura Mastery, Anti-Magic Zone, Darkness) are still wrong: they protect a team and
the log gives no target to redirect to.

### Crowd control broken by your own damage

DR-immune measure: between the control landing and ending, did the caster's **own team** put
damage on the target? (Counting "ended before half its listed duration" is wrong — DR halves a
second Fear, so perfectly held control reads as a 50% break.)

| | control cast | broken by own team |
|---|---|---|
| A coordinated 3v3 team | 50 | **30%** |
| An LFG 3v3 team | 53 | **53%** |
| Solo Shuffle, both sides | 113 | **70% / 70%** |

That reads as a coordination scale. The named culprits are the actionable part — a Fire Mage's
Ignite and a Disc Priest's Shadow Word: Pain breaking their own team's fears and polymorphs.

### The answer pool, observed

Per spec, what was actually spent when that player was under pressure, and **how often it was
their first answer**. That `1st` column separates the reflex from the considered:

```
Arms Warrior        Berserker Shout   4 uses, 1st 4/4, 2.4s in    ← automatic, gone immediately
Unholy DK           Anti-Magic Zone   5 uses, 1st 4/5, 1.3s in    ← reflex
Holy Paladin        Divine Protection 6 uses, 1st 0/6, 7.3s in    ← still up at seven seconds
```

`arena-structure.md` Part 2 says a guide should be organised around exactly this. The Matchup Lab
models what a spec *could* press; this is what they *do*.

**What observation cannot supply is the ideal go.** Part 5: "openers are engineered between games,
not looked up." Averaging an uncoordinated team's games would codify its mistakes as doctrine.

### One hit, explained

19:51 on 26 Sep, Unholy DK → Retribution Paladin, `Dread Plague (Erupt)`, **538,666**, overkill
27,854, **critical** (369,784 base × 1.46). Every other Dread Plague event in that game did
6,000–17,000. He had the full Unholy chain up for fifteen seconds — Army of the Dead, Dark
Transformation feeding Commander of the Dead, Ghoulish Frenzy and Gift of the San'layn, with
Essence of the Blood Queen stacking off Vampiric Strikes right up to the moment it fired.

**The mechanism is not determinable from the spell data.** All three Dread Plague spell ids
(1240996, 1241171, 1242564) carry an identical description — sibling recovery filling the copies,
not three real descriptions (rule 3). That text says the plague "explodes when the host dies", but
nothing died before the hit: the Paladin died 20ms *after* it, and nobody else carried the plague.
Either the description belongs to a different id in the family or the Erupt has a trigger the
tooltip does not cover.

---

## Known defects and open items

- **Zone-effect defensives measure only the caster.** Aura Mastery reads 0.07x.
- **Health reads `?` for a player who is not being hit** — HP is sampled from damage events only.
  Reading it from heal events too would fix it.
- **`Evangelism` (35 casts) and `Gladiator's Medallion` (66) have no `duration_seconds`**, so any
  window-based measure skips them. Correct for an instant dispel, a real gap for Evangelism.
- **nginx has no `client_max_body_size` for this site** (so 1MB) and PHP is at
  `upload_max_filesize=2M`. A round gzips to 123–923KB, so **rounds over about four minutes will
  413** on the browser upload. One line of nginx config fixes it; not done, not authorised.
- **3v3 does not group by roster** and must not — facing the same team twice in a session is
  ordinary and grouping would weld two games into one. Only round-based brackets group.
- **The analysis scripts are throwaway.** The moment detector is committed and tested
  (`ArenaMomentService`, `ArenaMomentTest`); the mixed-cooldown study, the CC-break study, the
  answer-pool study and the big-hit finder exist only as session scratchpad and would need
  rebuilding. The major-cooldown report for 15 games is saved at `major-cooldowns-26sep.txt`.

## Where this could go

1. **Make the analyses real commands.** The major-cooldown table and the big-hit finder both earn
   their place next to the moments.
2. **Feed the answer pool into guide drafting.** It can fill a guide's Defensives section from
   measurement; the Sequence stays a person's job, per Part 5.
3. **Record observed-vs-curated corrections somewhere durable.** Avatar being classified mixed
   while being used purely offensively is a real correction to curated data and currently lives
   only in this file.
4. **Compare a played game to a guide's `Sequence`** — the original goal. The moments are the
   half that was missing.
