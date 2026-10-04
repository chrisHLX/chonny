# MindCollector

A coach for World of Warcraft arena players. It reads a player's own arena games from their
combat log, measures them against the players they actually meet, and tells them what to work on,
on top of a clean, current model of every class, spell, talent and mechanic.

Laravel, Livewire 3, Alpine and Tailwind; MySQL and Redis. A Windows desktop app
(`tools/log-manager/`) reads games in as they are played.

- `VISION.md`: what this is for.
- `CLAUDE.md`: how to work in the repo: commands, environment, architecture, and the rules that
  must not be broken.
- `docs/README.md`: every doc, by area and status.
- `match-review.md`: the match review and the desktop app, end to end.

```bash
composer run dev     # server, queue, logs and vite
composer test
```
