---
name: review-games
description: Review a player's own WoW arena games from the MindCollector archive - match analysis, game review, coaching. "look at my last games", "why did we lose", "is my teammate the problem", "what should I work on", "compare me to other Disc Priests", "how do I beat TSG". Use for any question answered from combat logs, the match review, the comp library, or the desktop app's measurements.
---

# Reviewing arena games

The method the match review settled, often after getting it wrong first. The map of the code is
`match-review.md`; the method is `match-review-operations.md`; past findings are
`match-review-analysis.md`. Read the map first.

## 1. Find the games

- The user's characters are in memory (`chonny_user_characters_and_teams`). The logging
  character of a stored round is `players[].logger`; in archive metadata it is `affiliation: 1`.
- **Games played while the desktop app was closed are not read in yet.** If recent games are
  missing, run `php artisan wow:sync` (it ingests WoW's log, then measures). Check
  `C:\World of Warcraft\_retail_\Logs` for a newer log than the last game in the archive.
- Stored rounds: `App\Models\ArenaRound` for `user_id` 2, `payload['analysis']` (version 8).
  Raw logs: `D:/MindCollector/arena-logs/raw/{match}.log.gz`. Run PHP through the PowerShell tool;
  Bash has no PHP. Put one-off scripts in the scratchpad and copy them into `storage/app/` to run.

## 2. Use the tools before writing a script

`tools/match-review/` (`match-review-tools.md` says what each answers): `patternread` (patterns
over every stored game), `cdledger` (defensives back at their goes), `warrant` (was each defensive
needed), `dispelread`, `kickread`, `specread` (one spec, side by side), `sessionread`, `killread`,
`tagaudit`. If a question needs a new script, leave it behind as a tool and add a line to
`match-review-tools.md`.

## 3. Read it honestly

- **Counts are questions.** For defensives ask three questions, in order: what was pressed, was it
  needed (`warrant.php`), what it left for the next go (`cdledger.php`). Never call an overlap or
  "Medallion up at a death" a mistake from the count. A Medallion breaks crowd control, so it
  matters only if the player was locked out.
- **Divide by the chance** before comparing players: dispels per minute of something to dispel,
  kicks per kickable cast with the kick ready. Instant crowd control cannot be kicked.
- **Check the user's own explanation against the data, and say plainly when it fails.** Two did on
  2026-10-02/03: lower dispels were not caused by pressure, and the Hunter's kicks were not slow.
- **Context goes beside every result:** difficulty (MMR gap, Gladiator seasons), teammates, the
  sample. Under 10 games it is a lead. One session is mostly noise.
- **A pattern is advice only once it holds across sessions** and in the pooled games
  (`patternread`). "Burst on their healer's crowd control" held twice, then failed over 325 goes.
- **Judge a decision by what was coming**: their go live, their crowd control ready for the
  player. Not by the outcome, and not by health alone.
- **Describe, do not blame.** Answer what the player could control next time. Name the teammate's
  pattern when the data shows it (Doubletapz died first in four losses) without making it a verdict.
- **Check the raw lines before any "never pressed"**: a button can be invisible because its spell
  is untagged or under the timeline's floor (`tagaudit.php`).

## 4. Write it down

Findings go in `match-review-analysis.md` under a dated heading, with the numbers and the games.
Methods go in `match-review-operations.md`. Then answer the user: the result first, then the
numbers, then what the log cannot see.
