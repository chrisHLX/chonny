# Monetisation: what the numbers say

A read of mindcollector.com's actual usage against the case for putting it behind a paywall.
Written 2026-09-24, covering **2026-09-08 → 2026-09-24** — the sixteen days since the player
guide builder shipped (`f14117a`).

Prompted by an outside critique (a Gemini assessment of the live site) arguing the product is
"a free community resource, not a product designed to convert," and proposing a Pro tier, a
creator marketplace and an AI matchup generator.

**The short version: that critique answers the wrong question.** It diagnoses a
monetisation-framing problem. The data says distribution. Every strategy it proposes assumes an
audience that does not exist yet.

---

## 1. The numbers

Actions first, because they are **crawler-immune** — a bot does not register, build a guide,
leave a comment or answer a question. Page views over this window are not filterable (the
`is_bot` column only starts on 2026-09-24), so the action counts carry the argument.

| | |
|---|---|
| Registrations in the window | **3** (2 verified) — 11 accounts all-time |
| People who have **ever** built a guide | **3** — the owner, `cbags`, `czaroko21` |
| Guides created | 49 = 16 machine-drafted + 33 "human" |
| …of those 33, with real content | **15**, built by those three accounts |
| …guest drafts with 1–4 steps | 5 |
| …empty shells (one auto-section, no steps) | 13 |
| Guide steps placed | 399 across 162 sections |
| Reader comments | **2** |
| Quiz attempts started | 1,153 |
| …that answered a single question | **30** (2.6%); 25 finished |
| Referrers recorded | google.com ×2, 1seoservices.com ×1, kr.account.battle.net ×1 |

Page views rose from ~240/day to ~1,300/day across the window, which looks like growth and is
not. On 2026-09-24, the first day with bot classification running, **417 of 528 views were
crawlers (79%)**. The rise is search and AI crawlers discovering the site.

Top pages by view: `wow_comps` 1,679 · `wow_quiz` 1,314 · `wow_quiz_play` 1,140 ·
`class_guide` 1,011 · `guide_show` 855 · `landing` 802. Further down: `guide_builder` 103 ·
`brain` 93 · `matchup_lab` 90.

Of the 855 `guide_show` views, **784 were anonymous and 71 signed in.** People do read the
guides; they read them logged out.

---

## 1b. The five standard funnel questions, answered

Asked 2026-09-24. Measured from **nginx**, not the DB — nginx has carried the user agent and
`Referer` all along, while `page_view_events` only started classifying on 2026-09-24. Window is
everything nginx still retains: **2026-09-10 → 2026-09-24, 15 days.** A 30–60 day answer is not
available; the logs are rotated away.

**Q1 — Top-of-funnel volume.** **1,502 distinct human-like IPs** over 15 days, averaging ~127 a
day (range 77–193). **84% appear on one day only.** Crawlers were **51% of all served pages**
(4,634 crawler vs 4,431 human requests).

**Q2 — Traffic sources.** Overwhelmingly direct. Of 4,431 human requests: 2,199 direct/no
referrer, **235 from `zmstrophies.com.au`** (the other site on the same VPS — almost certainly
not genuine referral; treat as noise), 92 Google, **43 Reddit**, then single digits for Bing,
DuckDuckGo, t.co, Hacker News, Facebook. Genuinely external, excluding the trophy site: **~224
requests in 15 days.**

**Q3 — Engagement depth.** 2,067 sessions (IP + 30-minute gap). **Mean 2.14 pages, median 1.
82% are single-page.** But the tail is real: **114 sessions reached 5+ pages, 58 reached 10+**,
and of multi-page sessions the mean is 5.5 minutes with **55 running over ten minutes**. A small
group engages seriously; most arrivals bounce.

**Q4 — CTA click-through.** **Not answerable. There is no click tracking on this site.** The only
client beacon is the WoW Comps tab bar (`TrackController`), so no button impression or click is
recorded anywhere. The page-level funnel answers the same question:

| step | IPs | of visitors |
|---|---|---|
| distinct human visitors | 1,502 | 100% |
| saw the landing page | 961 | 64.0% |
| viewed any content page | 713 | 47.5% |
| reached a guide page | 176 | 11.7% |
| reached `/login` | 35 | 2.3% |
| reached `/register` | 28 | 1.9% |
| **completed registration** | **3** | **0.20%** |

**1.9% of visitors ever reach the register page.** Of the 28 who did, 3 finished — 11% form
completion, small-n but not obviously broken. The loss is upstream of the form, not in it.

**Q5 — Which pages.** By human requests: `/` 1,690 · **`/modules` 332** · `/wow/comps` 218 ·
`/browse-guides` 172 · `/dashboard` 141 · `/login` 71 · `/claudes-comp-guides` 67 · `/guides` 63
· `/wow/pvp-guides/rogue/subtlety` 62 · `/brain` 48. Per-page *time* is not recorded — only
whole-session duration, above.

`/modules` at number two is unexplained and worth checking: it is the dormant learning platform,
deliberately unlinked from the sidebar, yet it is the second most requested path on the site.

---

## 1c. The era this analysis had missed entirely

**Added 2026-09-24 after the site owner pointed out there was an earlier version.** Everything
above reads the last 15 days. The site had a different front door until **2026-09-16**
(`4b563e2`, "Make the site root a public front page with the game-plan feed"). Before that, `/`
was a playstyle diagnostic: *"Find out what kind of player you are — and what's stopping you
from climbing,"* one button, *Find your playstyle*. It was promoted with a Reddit post in
mid-July.

That version converted several times better, and the evidence is in the database.

| | diagnostic era | WoW quiz era |
|---|---|---|
| Attempts | 523 (250 in the launch fortnight) | 1,155 |
| **Completed** | **52 — 50 of them in the launch fortnight, 20%** | **25 (2.2%)** |
| Signed-in at the time | 4 of 523 — **it was a guest funnel** | — |
| Sign-ups per day | **1.14/day in launch week** | 0.22/day |

**Nine times the completion rate, five times the sign-up rate.** And it ran on guests: 519 of the
523 attempts had no account, across 469 distinct sessions. People tried it *first* and signed up
after.

The five real sign-ups of that week (`kiwi`, `applehero75`, `dantheman`, `paranoiax`,
`czaroko21`) each landed during an active diagnostic window — 2 to 8 attempts in the two hours
before each. **That is ecological, not individual attribution**: it shows they signed up while
the diagnostic was busy, not that each personally finished one. Suggestive, not proof.

Module questions were answered too: **196 `question_user` rows across 5 users, 225 attempts, 149
correct.** Small, but real people working through real questions — which the current quiz has not
reproduced.

**A data-quality warning.** `diagnostic_attempts.last_question_index` cannot be trusted: it says
15 attempts got past question one while 52 completed, which is impossible. Use `completed_at`
only, and do not quote a drop-off curve from that column.

### What this does to the conclusion

The earlier draft of this file said the constraint was arrival, not conversion. **That was drawn
from a 15-day window and it was wrong as a statement about the product.** The fuller picture is
sequential:

1. **July** — a Reddit post brought qualified traffic, and the diagnostic converted it at 20%
   completion and ~1.14 sign-ups a day.
2. **July → September** — that post aged out. Traffic decayed. No second post.
3. **2026-09-16** — the front door was replaced with a feed of other people's plans, and the
   Training pages were hidden the next day (`8a2e074`).

So the site **solved the problem that was actually reported** — the owner's account is that
players found the quizzes "not specific enough", which the spell data, the Brain and the guides
now fix — and in the same move **removed the mechanism that had been acquiring them.**

The asymmetry is the point. A feed asks a visitor to browse strangers' content. The diagnostic
asked them a question about *themselves* they could answer in two minutes without an account.
One of those converts cold Reddit traffic and one does not.

**The comparison is not perfectly clean:** July traffic was Reddit-qualified — people who clicked
a post about an arena tool — while today's arrivals are largely direct and crawler-adjacent. Some
of the gap is audience quality rather than the hook. It is not enough to explain 9x.

---

## 2. The measurement trap that nearly produced the wrong conclusion

**Recorded because it will recur.**

The first retention measure tried was "sessions appearing on more than one day": **4 of 5,587
(0.1%)**. Read naively that is a retention crisis, and it was very nearly written up as one.

It measures nothing of the sort. `SESSION_LIFETIME` is **120 minutes**, so a visitor returning
the next day gets a fresh `session_id`. That query counts sittings that cross midnight.

**`session_id` cannot identify a returning visitor on this site. `user_id` is the only durable
identifier we hold.** Measured that way:

| account | signed up | views | distinct days | first → last |
|---|---|---|---|---|
| chriso | 2026-07-16 | 1,100 | 27 | 08-10 → 09-24 |
| czaroko21 | 2026-07-20 | 52 | 4 | 09-13 → 09-17 |
| #15 | 2026-09-20 | 10 | 4 | 09-20 → 09-24 |
| cbags | 2026-09-13 | 51 | 3 | 09-13 → 09-17 |
| #14 | 2026-09-19 | 3 | 1 | 09-19 → 09-19 |

**Four of the five accounts that have ever browsed came back on another day.** n is tiny, but it
points the opposite way to the session figure — and it inverts the conclusion. Retention among
people who sign up is not the problem. Eleven people have ever signed up. That is the problem.

---

## 3. The outside critique, point by point

### Right

- **No value gate.** Accurate — everything is free and ungated.
- **"Distribute to high-intent hubs."** This is the real finding, and it appears as step 3 of a
  four-step action plan when it is the whole thing. **Correction (2026-09-24):** an earlier draft
  of this file said distribution had never been tried. That was wrong — nginx shows a
  r/worldofpvp post, *"arena cooldown and comp planning website"* (`1wip12z`), referring traffic
  in the window. It has been tried once, at small scale: **43 referred requests over 15 days.**
  The finding is that it has not been tried *repeatedly*, not that it is untested.
- **The engine is good.** Fair, and consistent with what is built.

### Wrong

- **The social proof is one person.** It read `Crawlordx-Frostmourne · 2645 · Gladiator` and
  inferred a creator ecosystem. That is the owner's own character. The proposed **creator
  marketplace has a supply of two**, and neither brought measurable referral traffic.
- **The AI matchup generator already shipped.** The Matchup Lab went live 2026-09-23 (90 views,
  36 sessions). More importantly its proposed form — "AI generates a customized game plan" — is
  what rule 33 forbids: there is no outcome corpus to fit a probability to, which is why the Lab
  says *kill window*, never *kill*.
- **"$20–30 a season to cross a rating threshold" is the pitch to reject.** It requires promising
  a competitive edge. That conflicts directly with the epistemics corrected on 2026-09-23:
  `[HYP]` claims stay proposals, and machine guides are published *to be corrected*. Charging for
  a "2400+ matchup matrix" means selling certainty the framework says we do not have. That is a
  real constraint on the business model, not a framing problem to write around.
- **"Class quizzes as working top-of-funnel"** — 2.6% of attempts answer one question.
- **"The gap isn't your code."** Partly wrong. Two comments in sixteen days means the correction
  loop — the stated design centre of the machine guides — is idle.

---

## 4. What the constraint actually is

**Both, in sequence — and a paywall addresses neither.**

Arrival is genuinely thin now: ~127 human visitors a day, ~224 genuinely external referred
requests in 15 days, 3 sign-ups. But section 1c shows the site converted cold traffic at 20%
once, so "nobody wants it" is not supported. What is missing is a **front door that asks the
visitor a question about themselves**, plus a repeated reason for anyone to arrive.

Driving traffic into the current landing page spends it at roughly a fifth of the rate the old
one converted at. **Sequence matters: restore the hook first, then drive traffic into it.**

---

## 5. What to do instead

1. **Put a diagnostic-style hook back on the front page** — the highest-leverage change on this
   page. It is the one thing measured to convert cold traffic here, it needs no account to try,
   and the content problem that sank it in July (questions "not specific enough") is exactly what
   the spell data, the Brain and the guides now solve. See `system-integration.md`, which was
   written before this traffic evidence turned up and is corroborated by it.
2. **Fix account #15.** Signed up 2026-09-20, returned on four separate days including today,
   still stuck unverified behind a mail failure. A real person is repeatedly trying to get in and
   cannot. Higher yield than any pricing page.
3. **Repeat the distribution test, with an artifact.** The one r/worldofpvp post drove 43 referred
   requests — a real but small signal from a single attempt. The next one should carry something
   worth upvoting on its own (a CC chain rendered from our own data) rather than a link. One post
   is a sample of one; the channel is unevaluated until it has been tried several times.
4. **Do not build a paywall yet.** Revisit when a distribution test moves the referrer numbers.

## 6. What would change this conclusion

- External referrals reaching a few hundred a week.
- Sign-ups in the tens per week rather than three per fortnight.
- Reader comments on guides climbing — that loop working is the signal the product is wanted.
- A second and third creator building real guides unprompted.

Any one of those moving makes the monetisation question worth reopening. Until then it is a
question about a product nobody has been shown.
