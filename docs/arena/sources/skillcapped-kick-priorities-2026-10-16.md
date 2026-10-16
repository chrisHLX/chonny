# Source note: Skill Capped's interrupt priorities, every spec, 12.1

**Who:** Skill Capped, the PvP guide site whose video courses are made with multi-rank-one
players. The written spec pages carry no author's name.
**What:** each spec page's "Important Casts" list: the spec's casts worth an interrupt, in three
tiers (high, medium, low), with a few one-line notes ("rarely hardcast", "cannot be interrupted").
**When:** pages updated 21–22 Aug 2026 for patch 12.1 (Midnight Season 2); read 16 Oct 2026.
**Where:** one page per spec, linked from `data/kick-priorities/priorities.json`. Not copied here
(`synthesis-process.md`, step 1: a published guide is linked, not landed). The file holds spell
names and tiers only.
**Why it is worth reading:** it is the one piece of written rank-one advice that is the same shape
for every spec and that the combat log can check. Everything else on those pages is talents, stats
and gear, which the spell data and the Murlok builds already cover better, or video behind a
paywall.

**Standing caveat.** A list of what to kick is a ranking by people who play at the top, for the
patch they wrote it in. It says nothing about when a kick is better held (for a cast in the next
go, or to bait), and it is the same list at every rating.

---

## 1. What to kick is ranked, and crowd control on your team comes first [adds: Part 10]

Across the 26 specs with a list, the high tier is the same three kinds of cast: crowd control
with a cast time (Polymorph, Cyclone, Hex, Fear, Sleep Walk, Mind Control, Searing Glare), the big
healing cooldowns cast or channelled (Ultimate Penitence, Divine Hymn, Tranquility, Dream Breath),
and the cast that starts a spec's burst (Chaos Bolt, Glacial Spike, Stormkeeper, Void Torrent).
Filler (Smite, Wrath, Lightning Bolt, Living Flame) is low, and mostly not worth a kick at all.

What it does to the model: Part 10 had kicks only as hidden state to force out. The lists add the
other half, which cast to spend a kick on when one is ready. [OBS, one source]

## 2. Some listed casts are never stopped in the archive [contradicts the lists, in part]

Counted by `tools/match-review/kickpriority.php` over 597 games from 18 Aug 2026:

| Spec | Cast | Casts | Stopped |
|---|---|---|---|
| Discipline Priest | Penance | 8,738 | 0 |
| Marksmanship Hunter | Aimed Shot | 1,376 begun | 0 |
| Mistweaver Monk | Enveloping Mist | 331 begun | 0 |
| Havoc Demon Hunter | Eye Beam | 418 | 0 |
| Windwalker Monk | Fists of Fury | 496 | 0 |
| Devourer Demon Hunter | Void Ray | 347 | 0 |

Channels are stopped elsewhere (Ray of Frost 67, Drain Soul 98, Soothing Mist 34), so this is not
a logging gap. For several of these the lists themselves say "cannot be interrupted" (Aimed Shot,
Eye Beam, Fists of Fury, The Hunt); they are listed as casts to watch, not to kick. For others the
list says nothing and the archive says no one stops them (Penance, Enveloping Mist, Sheilun's
Gift). Mistweaver's Enveloping Mist and Vivify are cast instantly during Soothing Mist, which is
stopped, so the cast to kick there is the channel.

What it does: a cast the archive has never seen stopped is shown on the site with that fact and is
never counted as a missed kick. Whether Penance is uninterruptible this patch, or simply never
kicked, is open (`arena-open-questions.md`, C16). [DER]

## 3. The archive kicks casts the lists leave out [adds]

Benediction (Holy Priest, 47 stopped), Voidform (Shadow, 25), Shadow Mend (Discipline, 21),
Consume and Devour (Devourer, 22 and 10). Shown on each spec's page as "kicked often in these
games", kept apart from the published tiers. [DER]

## 4. Most high-priority casts are not stopped [adds, measured]

Of the high-tier casts begun in the archive, few are stopped: Polymorph from a Fire Mage 262 of
1,630, Power Word: Radiance 7 of 2,145, Mind Control 26 of 291. That alone says nothing about
skill, since most casts begin with no kick ready or out of its reach. Measure 15
(`RoundAnalysisService`, `habits.priority`) asks the fairer question, the share stopped when the
player was alive, free, had their kick ready and stood within its range; its population numbers are in `data/population/norms.json`
(`priorityKickShare`). [DER]

---

## What this source does NOT support

- **When to hold a kick.** The lists rank casts. They do not say when a kick is better kept for
  the next go, or spent early to bait, which Part 10's forcing routine depends on.
- **Anything rating-specific.** One list for every bracket and rating.
- **Line of sight, or who on a team should kick.** The measure judges range from where both
  players stood (the log's positions); line of sight it cannot see, which every place showing the
  number says.
- **Kicks the log does not write as interrupts.** A Shadow Priest's Silence never produces an
  interrupt line (303 presses, 88 rounds); the measure counts its press on a caster mid-cast.
- **Talented cooldowns.** Kicks are read at the spell data's base cooldown, so a talented kick is taken as
  down for longer than it was and chances are under-counted, never over-counted.

## Distillation log

| Date | Pulled | Landed in |
|---|---|---|
| 2026-10-16 | The 26 lists, names and tiers only | `data/kick-priorities/priorities.json` |
| 2026-10-16 | Each listed cast against 597 games; never-stopped and never-cast casts; casts kicked but not listed | `data/kick-priorities/evidence.json` (`kickpriority.php`) |
| 2026-10-16 | What to kick, ranked | `arena-structure.md` Part 10.1 |
| 2026-10-16 | Is Penance interruptible this patch? | `arena-open-questions.md` C16 |
| 2026-10-16 | Chances measured per player | `RoundAnalysisService` version 15; the site's spec guides, the app's Basics, Improve and Comps pages |
