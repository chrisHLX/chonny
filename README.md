# MindCollector

A platform for understanding your own World of Warcraft arena games. It reads a player's games from
their combat log and shows what happened and where they stand against the players they actually
meet, on top of a clean, current model of every class, spell, talent and mechanic.

Laravel, Livewire 3, Alpine and Tailwind; MySQL and Redis. A Windows desktop app
(`tools/desktop-app/`) sends games to the site as they are played.

- `VISION.md`: what this is for.
- `CLAUDE.md`: how to work in the repo: commands, environment, an index of the rules, and where
  everything else is written down.
- `docs/README.md`: every doc, by area and status.
- `match-review.md`: the match review and the desktop app, end to end.

```bash
composer run dev     # server, queue, logs and vite
composer test
```
