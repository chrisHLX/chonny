# Ask about this game — design

Status: **proposal, 2026-10-02.** Nothing below is built yet. Correct it here before it is.

## What it is

A box on a game's review page (and later in the desktop app) where a player asks a question about
their own games in plain words. Examples: "why did we lose round 4?", "was my trinket on the
Shatter go right?", "what do our losses against Resto Druid comps have in common?". A model answers
from the game data the site has already measured, and from the arena model we have written down.

It is the product version of what Claude Code does in this repo when we review games. The
difference: it reads **measurements**, not raw combat logs, and it can only use the tools listed
below. It cannot write a new measuring script when a question needs one. Those questions are
recorded instead, and become the next tools (see "The loop").

## Where it runs

On the website, in Laravel. Never in the desktop app, for three reasons:

- the API key cannot ship inside an app;
- each question costs money, so usage needs a limit (the existing credits);
- the arena model, spell data and game measurements all live on the server already.

## The model

**Claude Opus 5** (`claude-opus-5`, $5 / $25 per million input / output tokens), with adaptive
thinking and effort `high`. These are Anthropic's defaults for this kind of reasoning-heavy
question. **Claude Sonnet 5** (`claude-sonnet-5`, $2 / $10) is the cheaper option; switching is
Chriso's call (see "Decisions"). Whichever is chosen, measure it on the eval below before
changing it.

The server-side refusal fallback (`fallbacks: "default"`, beta `server-side-fallback-2026-07-01`)
is on by default, so a question the model declines on safety grounds is re-run on a fallback model
rather than simply failing. Arena questions should never trip it, but it costs nothing when unused.

Called through the official PHP SDK (`composer require anthropic-ai/sdk`, `Anthropic\Client`). The
site's existing AI features call OpenAI and Gemini over raw HTTP through `AiService`; this one is
separate and does not change those.

## What the model is told (the system prompt)

Built once from files in the repo, so it stays in step with them and caches well:

| Part | Source | Size |
|---|---|---|
| Who it is and the rules below | written for this feature | ~1k tokens |
| The arena model, reader-facing | `data/brain/brain.md` | ~5k |
| The arena model, full, with [OBS]/[DER]/[HYP] tags | `arena-structure.md` | ~17k |
| What each measure means (goes, lockout, "outside their goes", the loss rules and their weights) | a short primer written for this feature from `match-review-operations.md` | ~3k |

About 26k tokens, identical for every question, and cached: after the first question it is read
at a tenth of the price.

Deliberately **not** included:
- `match-review-analysis.md`. It is Chriso's team's findings; another player's answers must come
  from their own games.
- The raw-log sections of `match-review-operations.md`. The model never sees a log.

The rules it is given, each one already a rule of this project:

1. **Every number comes from a tool result.** If a measure isn't available, say so; never estimate one.
2. **Say the sample.** Anything resting on fewer than 10 games is "a lead, not a finding"
   (`MatchAnalysisService::LEAD_BELOW`).
3. **A [HYP] claim in the model may be offered as reasoning, never as settled** (`arena-structure.md` Part 0).
4. **No win probabilities, and never call a window a kill** (CLAUDE.md rule 33).
5. **The loss rules are an estimate, not a verdict.** The log cannot see positioning, calls or
   mistakes nobody pressed a button for. Say so when it matters.
6. **Name the game and round** an answer rests on, so the player can open it.
7. **Opponents' experience is as their character is now,** and Gladiator seasons count across a
   whole account.

## The tools

Every tool is scoped to the signed-in player on the server. The player's id comes from the session,
never from the model's input, so no prompt can reach someone else's games.

| Tool | What it returns | Built on |
|---|---|---|
| `list_games` | The player's games, filtered by date, bracket, result, comp or spec: id, time, record, specs on each side, MMR | `ArenaRound`, grouped by lobby as the cards do |
| `get_game` | One game, round by round: each death's kill read, goes, defensives, overlaps, interrupts, lockout, damage and healing by ability, the loss rules' items, opponents' experience, the player's notes | `GameCardService`'s view models (trimmed: the model gets rows, not HTML) |
| `compare_games` | Wins against losses over a set of games, and the "where the losses came from" split | `MatchAnalysisService` |
| `spell_facts` | One ability's cooldown, charges, DR category, duration, immunities, whether it's usable while CC'd | `SpellProfile` |
| `find_abilities` | Abilities by curated property ("a Holy Paladin defensive usable while stunned") | `AbilityFinder` |
| `record_gap` | The model notes a question the data could not answer, and what was missing | a new table, read by us |

Tool results are trimmed rows, not the stored payloads. A stored round is about 25 KB of JSON,
most of it data the model doesn't need.

## How a question runs

1. The player asks on the review page. The site checks their credit balance against the most a
   question can cost, saves the question, and returns at once.
2. A queued job runs the tool loop: ask → the model calls tools → the server runs them → repeat
   until it answers, **at most 8 tool rounds**. It runs on its own queue with a longer time limit
   than the existing 90-second workers. Production needs one more Supervisor program for this.
3. The page polls and shows the answer when it lands. Follow-up questions continue the same
   conversation, which is stored per game.

Why a queued job and not a live stream: production has one CPU core and 12 PHP-FPM workers. A
request held open for a minute per question would tie them up and slow the site for everyone. The
API call itself is waiting, not computing, so it costs the queue worker almost nothing.

Conversations are only ever appended to, never edited, so the cache keeps working, and so does the
model's carried-over reasoning on models that check for edited history.

## What it costs

Estimates from the sizes above, to be replaced by measurements from the first real questions:

- **Opus 5:** roughly 20–40 US cents a question; the first question after the cache lapses adds
  about 15 cents.
- **Sonnet 5:** roughly 10–15 cents a question.

Most of the cost is thinking and the game data a question pulls in, not the system prompt.

Charging uses the existing credits (`CreditService::spendAiCredits`) and one `AiRequest` row per
question. `TokenService` needs Claude's prices added, and must learn to price cache writes (1.25×)
and cache reads (0.1×) separately: it currently takes a single "cached" flag for all input.

## The loop

This feature is also how the model gets better. Two sources feed back into the repo:

- `record_gap` entries: questions the data couldn't answer. Each is a candidate measure or tool,
  the same way `match-review-tools.md` grew.
- Answers a player marks wrong. These are corrections to the arena model, folded in as reader
  corrections are today (`docs/arena/synthesis-process.md`).

## How we know it works

An eval before launch, built from questions we have already answered by hand. The question-and-
finding pairs in `match-review-analysis.md` (26 and 30 Sep) come with the measured answer, so each
can be asked of the feature and graded against what we found. Run it on each prompt or model
change, and keep its cost per run in view.

## Build order

1. `TokenService`: Claude prices with cache read and write.
2. The six tools, each with a test against fixture rounds (the `GameCardTest` fixtures already
   have the right shape).
3. `GameQuestionService`: the system prompt, the tool loop, credits, the `AiRequest` row.
4. A conversations table and the queued job; the production Supervisor program.
5. The box on `/wow/game-review/{id}`, tracked per rule 28.
6. The eval, run before it is shown to anyone but Chriso.

## Decisions for Chriso

- **Claude for this feature,** alongside the OpenAI and Gemini calls the site already makes. Yes?
- **Opus 5 or Sonnet 5.** Opus is the default here; Sonnet is about 40% of the cost.
- **Free allowance.** How many questions a player gets before it costs credits, or whether it is
  paid-only.
- **Who sees it first.** Suggest Chriso only, then teammates, then everyone.
- **Privacy wording.** A question sends that game's measurements, including other players' names
  and specs, to Anthropic. The privacy policy should say so before anyone else uses it.
