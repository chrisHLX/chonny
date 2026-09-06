# Vision

A website that gives a player everything they need to reach Gladiator or higher in WoW arena.

There are no certainties in the path to Gladiator each season. But the game itself is knowable, and that is what the site captures.

It combines comprehensive class and spell data derived directly from the game with in-depth insight from real players and real match data. Together, an aspiring Gladiator can find what they need to model and simulate what happens in arena.

## The website

- **Speed** — the technology prioritises speed for the user.
- **Optimisation** — the architecture stays simple: the minimum number of parts needed to do the job.
- **Clean formatting** — information is presented with resolved, finished formatting. No broken icons or asset links, no placeholder symbols, no `(varies)` or raw formula calculations, no duplicate spells.
- **Clean data** — an accurate, up-to-date representation of spells, abilities, talents, and mechanics.

## The current focus

Getting the spell data confidently correct — accurate cooldowns, charges, talent modifiers, CC durations, and mechanics, presented cleanly. Every `(varies)`, every unresolved `$s1`/`${...}` token, every duplicate spell row, every missing icon is a defect to close, not a limitation to accept. Where a real value genuinely cannot be computed yet, the answer is to get the data — never to ship a placeholder, and never to fabricate a number.

## The future

Undecided. Once the spell data is confidently correct, the project moves on to whatever comes next.

## On the `.md` files

The Markdown files in this repo are reference and history, not gates. Follow the vision; implementation is Claude's domain. Where a note says something "can break," treat it as a warning to check before relying on it — not a rule that stops the work.

Sections in `CLAUDE.md` and elsewhere describing the older learning-platform direction (modules, quizzes, diagnostics, credits, AI content generation, the Next Step / Reflection loop) are kept as a record of how the code got here. They are not the current direction, and the systems they describe are dormant.

## Vision history

1. **Content-first** — the value was the size and quality of an AI-generated question/module bank.
2. **Diagnostics-first** — the value shifted to profiling the player and routing them to content.
3. **Expertise-capture** — the value was capturing how experts think, with content/diagnostics/recommendations as downstream representations.
4. **WoW arena spell data (current)** — a fast, clean, accurate reference for class/spell/talent/mechanics data plus player and match insight, aimed squarely at players pushing for Gladiator.
