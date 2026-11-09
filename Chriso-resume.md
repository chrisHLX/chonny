# Chriso: AI skills record

A working record of the skills I'm building on MindCollector, written so that I can:

1. pull lines from it for a resume or a LinkedIn profile;
2. see what kinds of work and companies would value these skills;
3. keep improving the skills deliberately, the same way MindCollector helps me improve at arena.

Started 2026-10-02. Update it like a match review: add evidence when something new is shipped,
and be honest about the gaps.

---

## 1. What I have actually done

All of it comes from one project: **MindCollector** (mindcollector.com), a live website for World
of Warcraft arena players. I built and ran it with AI coding agents (mainly Claude Code) from
July 2025 to now.

**Scale, from the repo:** 386 commits over 15 months, rising from a few a month to about 100 a
month by August–September 2026. It's a real production site on a server I deploy to myself, with
real sign-ups, a game addon, and a desktop log manager.

**What I did and what the AI did.** I didn't write most of the code by hand. I set the vision,
made the product decisions, supplied the domain knowledge, judged whether outputs were right,
caught the AI's mistakes, and decided what to ship. The AI wrote the code under my direction.
Say this plainly on a resume, because it's exactly the skill employers are now trying to hire.

---

## 2. The skills, with the technical terms for them

Each skill is listed in plain words, then with the term the industry uses, then with my evidence.

### Directing AI agents to build real software
*Industry terms: agentic coding, AI-assisted software development, "AI-native" development,
human-in-the-loop engineering.*

- I built a full web product (Laravel, Livewire, MySQL, Redis) by directing AI coding agents,
  and shipped it to a live Linux server.
- I keep a standing instruction file for the agent (`CLAUDE.md`) holding project rules, traps
  that have bitten before, and how to deploy. Every new AI session starts from it.
  *(Term: context engineering / agent instructions / system prompts.)*
- I moved 790KB of history out of that file into an archive so the agent's working context stays
  focused. *(Term: context window management.)*

### Building a knowledge system the AI works from
*Industry terms: knowledge engineering, knowledge management, building a "source of truth",
retrieval grounding / RAG (retrieval-augmented generation).*

- **The arena model** (`arena-structure.md`, public at `/brain`). It's a written theory of how
  arena works, and every claim in it is tagged as observed, derived or hypothesis. AI-written
  guides must be drafted from it and may not assert a hypothesis as settled fact.
- **A source pipeline** (`docs/arena/sources/`): raw transcripts are kept untouched, distilled into
  notes that keep the exact quotes, and only then folded into the model. Every rule can be traced
  back to a source. *(Term: data provenance / audit trail.)*
- **Source tiers**: a player's own review counts as evidence; another AI's agreement doesn't,
  because it had read our model first. *(Term: avoiding circular validation / model contamination.)*

### Checking AI output instead of trusting it
*Industry terms: AI evaluation ("evals"), output validation, hallucination detection, AI quality
assurance, red-teaming your own system.*

- I published 16 AI-written arena guides **specifically so they could be corrected**, then
  exported reader corrections and fed them back in. *(Term: human feedback loop, RLHF-style
  feedback collection in plain form.)*
- I caught the AI planning around abilities that one character can't have at the same time. That
  led to an automatic checker, which found four impossible plans in 11 published guides.
- I audited 100 AI-authored quiz questions against the game data. 9 contradicted our model and 5
  contradicted the live spell data. The conclusion was to generate facts from data rather than
  have an AI write them. *(Term: grounding / factuality evaluation.)*
- I learned not to trust an AI agent's report of its own work: one reported success while its own
  numbers contradicted each other.
- I designed an "Ask about this game" feature (`docs/ai/ask-about-this-game.md`). It covers model
  choice, cost per question, prompt caching, what the model may and may not see, and an eval
  before any model swap. *(Term: LLM feature design / LLM product spec.)*

### Turning messy real-world data into something reliable
*Industry terms: data engineering, ETL pipelines, data quality, data validation, telemetry
analysis.*

- I built a pipeline that imports game data from several sources (SimulationCraft dumps,
  Blizzard's API, my own addon exports, combat logs) and reconciles them, with hand-checked
  override files that win over the raw data.
- I ingest and measure my own combat logs: rounds, deaths, crowd control, healing and damage.
  Each field's position was measured, not assumed, and verified 16/16 against a third party's data.
- I found and fixed a parsing assumption that silently dropped 83% of Solo Shuffle games.
- I worked to a rule of "show a visible gap rather than a confident wrong number". *(Term: data
  integrity.)*

### Analysing performance from data
*Industry terms: performance analytics, sports/esports analytics, root-cause analysis.*

- I review my team's games from combat logs: which pushes converted, where defensive cooldowns
  were wasted, and wins against losses.
- I wrote session reviews such as the Disc LFG session on 1 October
  (`docs/reviews/2026-10-01-disc-lfg-session.md`), where I asked a hard question about my own
  play and got an evidence-based answer.

### Product judgment
*Industry terms: product management, product discovery, user research, metrics-driven decisions.*

- I wrote down the vision and changed it four times as I learned. The track: AI content →
  diagnostics → capturing expertise → arena data and game plans (`VISION.md`).
- I read real usage before deciding on a paywall (`monetisation-read.md`). About 79% of traffic
  was crawlers and there were 3 real sign-ups, so the problem was distribution, not pricing. I
  rejected an AI's confident monetisation advice because the data didn't support it.
- I reversed a sign-up wall on the same day once I saw the reasoning against it: cold visitors
  won't sign up for content they haven't tried.
- I treat speed, simple architecture and clean output as product principles.

### Running a live service
*Industry terms: DevOps, deployment, production operations, incident response, security
awareness.*

- I deploy to a Linux (Ubuntu, Nginx, PHP-FPM) server with a deploy script I had built after a
  real outage (500 errors from stale cached code, 2026-08-28).
- I fixed a real security hole: an unlocked field let anyone write into admin data. I also made
  game reviews private once I realised they exposed other players' details.
- I ran a tester onboarding guide and an addon install flow (`docs/testers/how-to-test.md`).

### Working with several AI models
*Industry terms: multi-model workflows, LLM vendor evaluation.*

- I used Claude for building, Gemini for critique and web-grounded research, and OpenAI for
  question generation.
- I know their cost and quality trade-offs well enough to spec which model a feature should use
  and why.

---

## 3. Resume lines (draft; pick and trim)

**Founder & Product Lead, MindCollector**: *Jul 2025 – present*

- Built and operate a live analytics and learning platform for competitive World of Warcraft
  players by directing AI coding agents (Claude Code); 380+ commits, self-managed Linux
  production.
- Designed a grounded knowledge base, with every claim tagged by confidence and traced to source,
  that all AI-generated guides must be drafted from.
- Built evaluation and feedback loops around AI output; an audit of 100 AI-authored quiz
  questions found 9 contradicting the domain model and 5 contradicting live game data and impossible plans in 4 of 11 published AI guides.
- Built a data pipeline reconciling four external game-data sources and player combat logs; found
  and fixed a parsing assumption that silently dropped 83% of recorded matches.
- Used real usage data (79% crawler traffic, 3 sign-ups) to redirect strategy from monetisation
  to distribution, rejecting AI-generated advice that the numbers didn't support.

*(Add your other work history and education above or below this. This file only covers the
MindCollector skills.)*

---

## 4. Where this is useful

The skill that transfers best isn't the WoW knowledge. It's **using AI to build real things while
staying the person who knows when it's wrong.** Most companies are trying to work out how to do
that right now.

| Kind of work | What it uses from the above | Examples of who hires for it |
|---|---|---|
| **AI product manager / technical PM** | vision, deciding what to build, specs for AI features, reading usage data | software companies adding AI features; startups |
| **AI implementation / solutions role** (helping a business actually use AI) | directing agents, context files, knowing where AI fails | consultancies, AI vendors' customer teams, mid-size businesses adopting AI |
| **AI evaluation / AI quality / trust & safety analyst** | catching hallucinations, audits, eval design, source tiers | AI labs, AI data companies (e.g. Scale AI, Surge, Mercor), companies shipping chatbots |
| **Domain-expert AI trainer** (paid to judge and correct model output in a field you know) | expert judgment plus correction loops; gaming is a niche domain | AI data / training platforms; often contract or remote |
| **Gaming & esports analytics / tools** | combat-log parsing, performance review, game data | WoW and esports tool sites (Raider.IO, Warcraft Logs, Wowhead, Icy Veins), overlay / coaching apps (Overwolf, Mobalytics, Blitz), esports teams, game studios' player-insight teams |
| **Data analyst** | pipelines, reconciliation, "measure before claiming" | almost any industry |
| **Coaching / edtech** | turning performance data into learning, feedback loops | edtech, sports-tech, corporate training tools |
| **Solo founder / indie builder** | all of it | yourself, if MindCollector or something after it finds users |

**On "will this be my job?"** Keep two tracks. MindCollector has real usage but no audience yet;
the September read showed the problem is getting players to find it. Even if it never pays, the
same 15 months have produced a portfolio that most "AI skills" job applicants can't show: a live
product, a public repo history, and written evidence of judgment. Both tracks get stronger from
the same work.

---

## 5. Deliberate practice: what to get better at

Same idea as the arena reviews: name the weakness, measure it, work on it.

| Skill | Where I am (honest) | How to improve it | Evidence to collect |
|---|---|---|---|
| **Reading code myself** | I direct the code but rarely read it closely | ask the agent to explain one change per session in plain words; read the diff before committing | being able to explain any file to someone else |
| **Distribution / marketing** | weakest area: 3 sign-ups in 16 days | one experiment at a time (a Reddit post, a Discord, a streamer, a guide shared after a tournament), each measured | sign-ups and returning users per experiment |
| **Talking to users** | mostly me and a few testers | five short conversations with real arena players about what they'd pay for or use weekly | notes from each conversation |
| **Explaining the work** | strong in writing (the docs), untested out loud | a 2-minute spoken pitch of MindCollector; a portfolio page | the recorded pitch, the page |
| **AI evaluation as a named skill** | doing it informally | build the small eval set planned for "Ask about this game" and run it on two models | an eval write-up with numbers |
| **Finishing over expanding** | the vision has moved 4 times | pick one feature per month that real players use, finish it | monthly "shipped and used" list |
| **Fundamentals** (SQL, how web apps work, Git) | picking up by exposure | one short course or one day a month on the area the agent explained least clearly | being able to do small fixes without the agent |

---

## 6. Evidence log

Add a dated line whenever something resume-worthy happens. Keep the number in it.

- 2025-07-22: first commit.
- 2026-08-28: production outage from stale cache; built the deploy script so it can't recur.
- 2026-09-13 → 19: 16 AI-drafted guides published for correction; 33 reader notes collected.
- 2026-09-23: talent-feasibility checker found 4 impossible plans in 11 AI guides.
- 2026-09-24: question-bank audit (100 questions: 9 contradict the model, 5 the live data); usage read
  redirected strategy to distribution.
- 2026-09-25: Solo Shuffle parsing fix recovered the 83% of games that were being dropped.
- 2026-10-01: game cards, desktop log manager and tester onboarding shipped.
- 2026-10-02: "Ask about this game" LLM feature spec written.
