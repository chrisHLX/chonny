# Kick priorities

Which casts of each spec are worth an interrupt, and what the archive says about each one.
Read by `App\Support\KickPriorities`, and through it by measure 15 (`RoundAnalysisService`,
`habits.priority`), the app's Basics, Improve and Comps pages, and the site's spec guides
(`/wow/pvp/{class}/{spec}`, "What to kick").

| File | Holds | Written by |
|---|---|---|
| `priorities.json` | each spec's casts by tier (high, medium, low), with a link to the page it came from | by hand, from Skill Capped's per-spec "Important Casts" lists for 12.1, read 2026-10-16. Spell names and tiers only: the pages are linked, not copied |
| `evidence.json` | for every listed cast, the ids it goes out under and how often it was begun, finished and stopped across the archive; the casts stopped often that no list names | `php -d memory_limit=2G tools/match-review/kickpriority.php --write` |

## How a cast counts

- **Shown** on a spec's page when someone in the archive cast it. A listed cast no one pressed
  (Mass Polymorph, Ring of Fire) is left out.
- **Counted as a chance to kick** when it is high or medium AND the archive has seen it stopped at
  least once. Penance went out 8,738 times and was never stopped, so letting one through is not a
  miss (`arena-open-questions.md` C16).
- **Low** is shown, never counted: filler is rarely worth the kick.
- **A chance in the measure** also needs the kick ready and the cast begun within its range,
  judged from both players' positions in the log (`RoundAnalysisService::priorityKicks()`). A
  Shadow Priest's Silence is written as crowd control, never as an interrupt, so its stop is the
  press on the caster mid-cast; a healer's kick (Wind Shear, Quell) is measured only in a round
  they used it (`KickPriorities::ALWAYS_HAS`, `LOGGED_AS_CONTROL`).
- **Kicked often but not listed** (Benediction, Shadow Mend) is shown apart, as what the games
  show, never mixed into the published tiers.

## Keeping it right

- **After a big batch of games or a new list:** re-run the tool with `--write`, commit
  `evidence.json`, then re-run `wow:game-cards` (the Comps page reads the files when drawn). The
  measure reads the files at measure time, so a change to which casts count needs a re-measure
  (`wow:sync --skip-ingest --fresh`) to reach stored rounds.
- **The tool never edits `priorities.json`.** A cast it never sees stopped, or one it sees stopped
  often that no list has, is a proposal for the player to decide on.
- **Reviewed by the player:** not yet. Until then the tiers are the published ones as they stood.
- **A new patch:** re-read the lists (their pages carry the date), and say in `about` when.
