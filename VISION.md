# MindCollector — Product Vision

*Last revised: 2026-07-21. Supersedes two earlier centers of gravity: content (the platform generates questions/modules) and diagnostics (the platform profiles the player). See "Vision History" at the bottom.*

## Vision

MindCollector is an AI-assisted knowledge extraction platform that transforms expert mental models into structured learning experiences.

Rather than asking AI to invent educational content, MindCollector helps experienced players externalize how they actually think, then converts that knowledge into curated learning modules, diagnostic assessments, and personalized recommendations.

The goal is not to build another wiki. The goal is to capture the mental models experts use to make decisions, and make those teachable.

---

## Core Philosophy

Knowledge is no longer scarce. Expertise is.

Large language models can generate unlimited educational content, but they cannot determine what should be taught or how an expert organizes knowledge under pressure. MindCollector combines human expertise with AI-assisted structuring: experts provide their mental models; AI organizes them, identifies gaps, verifies facts, and transforms them into structured educational resources. Humans remain responsible for the knowledge being taught.

This reframes what a "canonical module" is: not AI-generated-then-verified content, but **structured player expertise**. The AI's job shrinks from "know the class" to "organize what an expert already said" — a far more reliable task, and one that visibly demonstrates its own value (a player can see their raw brain dump next to the structured output and judge the transformation directly).

---

## What MindCollector Collects

MindCollector does not primarily collect facts. It collects mental models.

A mental model is the way an experienced person organizes knowledge, recognizes patterns, and makes decisions. By capturing these mental models and transforming them into structured educational resources, MindCollector preserves expertise in a form that others can learn from. The modules, diagnostics, recall questions, and recommendations are all different representations of the same captured mental model — not independent content types that happen to share a domain.

---

## The Educational Pipeline

```text
Expert
  ↓
Brain Dump
  ↓
AI extracts concepts, relationships, and decision rules
  ↓
Expert review
  ↓
Canonical Module
  ↓
Recall Questions
  ↓
Diagnostic Variants
  ↓
Personalized Recommendations
  ↓
Learning
```

The canonical module becomes the educational foundation for every downstream system.

---

## Canonical Modules

A Canonical Module is MindCollector's educational representation of a specific context — e.g. Discipline Priest, Arms Warrior, Subtlety Rogue, Terran, Jungle, No-Limit Hold'em.

A module is not encyclopedic. It represents the smallest, most useful mental model required to understand that context competitively. Every module is owned, reviewed, and maintained by a human. AI assists with authoring but does not become the source of truth.

---

## Diagnostics

Diagnostics do not generate knowledge. They assess understanding.

Users first select their subject context (e.g. World of Warcraft → Priest → Discipline). This determines which pre-authored diagnostic variants are used. The diagnostic itself stays deterministic and universal — see the architectural boundary below.

---

## Recommendations

Recommendations connect diagnostic results to educational content. A recommendation is never generic — it connects:

```text
Weak Area → Concept → Canonical Module → Relevant Section → Learning Module
```

The diagnostic identifies what the learner struggles with. The module teaches the underlying mental model.

---

## AI's Role

AI is an educational assistant, not an educational authority. Its responsibilities:

- Structuring expert brain dumps
- Extracting concepts and decision rules
- Identifying missing knowledge
- Creating educational drafts
- Generating recall questions
- Generating diagnostic variants
- Verifying factual accuracy against current information
- Suggesting updates over time

AI accelerates authorship. Humans curate the curriculum.

---

## Runtime Philosophy

Runtime behavior should remain deterministic wherever it's teaching or testing. AI should not dynamically generate educational content while a user is learning or taking a diagnostic — the flow is:

```text
Expert Knowledge → AI-assisted authoring → Human approval → Stored educational assets → Runtime delivery
```

**Precise caveat, not a purity claim:** this codebase already makes limited, deliberate runtime AI calls — `DiagnosticProfileService` interprets a completed diagnostic's answers into a profile, and `NextStepService`/`RecommendationService` occasionally pick *which* stored concept or module to surface next. None of these invent facts, questions, or content on the fly — they interpret and route, using only already-authored, already-verified material. The distinction that matters isn't "AI never runs at runtime," it's "AI never authors at runtime." Keep this precise when this file is revised again — overclaiming determinism here would misdescribe the actual system the first time someone checks.

---

## Architectural boundary — context vs. evidence (unchanged, now confirmed twice)

Context declarations (class/spec/race/role — see "Subject Context Dimensions" in CLAUDE.md) are facts, not evidence. They filter which module gets *recommended after* a diagnosis; they never feed the diagnostic AI itself. The diagnostic stays universal and class-blind by design.

A player-sourced Discipline Priest module makes the *post-diagnosis module recommendation* sharper for a Disc-declared user whose growth area matches it. It does not, and should not, change the diagnostic questions themselves. This is the same environment-modeling boundary documented under the Player Model Design Principle in CLAUDE.md — this vision shift confirms it against a second real feature rather than changing it.

---

## What this validates before building automation

The hypothesis worth testing isn't "can AI structure a brain dump into a module" — that's proven, done live twice in one session (Discipline Priest, Feral Druid). The hypothesis that actually matters is **"will players actually brain-dump their expertise unprompted."**

The cheap test: show a real brain dump next to its structured output as a before/after, and gauge the reaction — before building any dump-to-module pipeline, authoring UI, or automation around it. Automating the flow is worth doing once that reaction is real, not before.

---

## Pattern findings from the first two brain dumps (Discipline Priest, Feral Druid)

- **Build/talent path is always the entry point**, not an afterthought (Oracle vs. Void Weaver; Wildstalker). It needs its own template slot — folding it into general Identity buried the single most load-bearing fact in both dumps.
- **A condensed "priority cooldowns to track" list is distinct from the full cooldown inventory** in both dumps — players separate "what I actually watch for" from "everything that exists." Deserves its own page, not a merge into Major Offensive/Defensive Cooldowns.
- **Players self-flag confidence per fact** ("I think that's the CD," "don't know how long it lasts") — this must be preserved in structured output, not smoothed into false uniform certainty. Same discipline this codebase already applies to self-reported reflection evidence (`InterpretReflectionJob` code-clamps confidence rather than trusting the prompt alone).
- **Game mode shifts expertise within the same class/spec** — arena vs. jungle vs. solo shuffle produced visibly different depth and scope in the same player's own account (confirmed self-aware: "I'm less certain with Feral, it takes more practice"). Not yet a modeled dimension. Noted for future consideration, not solved here.

---

## Scope discipline

This direction stays scoped to classes/specs the founder has genuine, high-rated, first-hand experience on. Scaling to classes/specs he doesn't play is explicitly deferred to a future pro-author system — a different, harder problem (sourcing and vetting *other* experts) that this pattern does not solve and this phase does not attempt.

---

## Vision History

1. **Content-first** (earliest) — the platform's value was the size and quality of its AI-generated question/module bank.
2. **Diagnostics-first** — the platform's value shifted to profiling the player (traits, archetype, mastery) and routing them to existing content.
3. **Expertise-capture** (current, 2026-07-21) — the platform's value is capturing and structuring *how experts actually think*, with content, diagnostics, and recommendations all becoming downstream representations of that captured expertise. The name "MindCollector" fits this framing more literally than either prior one.
