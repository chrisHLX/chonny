# International players: China first

Started 2026-10-27, after two players from the Chinese region signed up in a day: user 20 (130 games
sent) and user 22 (signed up by email, not connected yet). What their games look like on the site today, why, what is broken
for them, and what would bring the regions together. **Verified** means checked against their stored
games or the code on this date; **to check** means not yet looked at.

## Why the site shows Chinese at all

Nothing here translates anything. A combat log is written in the language of the game client that
wrote it: a Chinese client writes `SPELL_CAST_SUCCESS,...,<spell id>,"激流",...` where an English one
writes `"Riptide"`. The spell id is the same in every region and language; only the name differs. Player and realm names come from the log too (`Name-血色十字军-CN`). Everything
is UTF-8 from the log to the page, so Chinese text passes through untouched and is shown as it came.

What is **not** from the log stays English: class and spec (from `COMBATANT_INFO`'s spec id, looked up
in our data), each player's kit in the arena frames (built from our spell data), and every word of the
page itself.

## What is broken for a Chinese player today (verified 2026-10-27)

The stored payload keeps a spell **by its name only**, never its id (`timeline.casts` rows are
`[t, name, target]`; go links, defensives and control rows carry `spell` as the log's name). Classification
already works, because `RoundAnalysisService` tags a cast by id while it reads the log: a Chinese go
still knows 虚空形态 is an offensive cooldown. What breaks is everything that later looks a spell up
**by name**:

1. **No icons on the timeline.** `SpellIconIndex::for()` looks names up in `spells.name`, which holds
   English names. 激流 matches nothing, so the casts line shows the characters with no icon.
2. **The arena frames never show a button used.** `WatchPageService::abilities()` matches each press
   to the player's kit by name. The kit is English (Riptide), the press is Chinese (激流), so no press
   ever lands: every button reads as ready all game. **This is a wrong reading, not just a cosmetic
   gap**, and it is on every Chinese game's Watch page.
3. **Probably: comparisons by spell name** (Improve, the shared averages in `data/population/norms.json`,
   the comp library's "how to play it"). Anything keyed by a spell's name will put Chinese names in a
   separate bucket from the English ones, so a Chinese player is compared with nobody and adds nothing
   to anyone else's averages. **To check**, measure by measure.
4. **Player pages for CN players** link with a guessed realm slug. Blizzard's global API does not serve
   China (it has its own host), so the realm list is never asked for (`RealmDirectory`, fixed
   2026-10-26 after it made every Chinese build time out).

Uploaded logs are not kept on the server (`ArenaReviewIngestService`: a round is measured on arrival
and the text is discarded). A game already stored with Chinese names can only be fixed by translating
when a page is drawn, or by the app sending it again (which a `RoundAnalysisService::VERSION` bump makes
every app do, for every player).

## Fixing the data: keep the spell id

The lasting fix is to store the id beside every name, at measure time, and look things up by id:

- `RoundAnalysisService` already holds the id when it reads a cast. Keep it in `timeline.casts`, go
  links, defensives and control rows, and store our **English** name next to the log's own name.
- Icons, frames, comparisons and averages then key on the id (or the English name), so a Chinese game
  and an English one meet in the same place.
- That is a measure change: bump `VERSION`, and every app re-sends. Do it together with other measure
  changes, not alone.

Until then, two smaller steps fix the Watch page without a re-measure:

- **A Chinese-to-English name table.** Each Chinese game's log pairs every name with its id, and our
  spell data holds the id's English name. The app could send the pairs, or the server could build them
  from Blizzard's localized spell names (the Game Data API returns names per locale; **to check**
  whether `zh_CN` names come from the global API or only from China's). With the table, the Watch page
  maps 激流 to Riptide before it looks up icons and matches presses.
- **The frames by id.** The same table lets the frames match presses by id.

## The pages in Chinese

Today every page is English and spell names show in whatever language the log used. Steps, smallest
first:

1. **Icons do most of the work.** Once icons draw, a casts line and a frame read the same in any
   language. This is the biggest gain for the least work, and it helps an English reader looking at
   a Chinese game too.
2. **Spell names in the reader's language.** With ids stored, a page can show 激流 to a Chinese reader
   and Riptide to an English one, from Blizzard's localized names.
3. **The page text in Chinese.** Laravel's language files, starting with the game card and the Watch
   page (short, fixed text). Machine translation is a start; a Chinese player checking it is better.
   Plain-copy rules apply in both languages.

## Reaching the site from China

Several things the site leans on are commonly blocked or unreliable inside China. **To check** from a
Chinese connection (a player's report, or a test from a China-based server) before acting on any of it:

| What | Where it is used | What to do |
|---|---|---|
| Google reCAPTCHA (`www.google.com/recaptcha`) | Sign-up, sign-in, password reset | Load it from `www.recaptcha.net`, Google's address for the same service that works where google.com does not. User 22's first sign-up bounced and the second went through (27 Oct 01:25 UTC); the cause is not known. |
| Google Fonts | Every page's Inter font | Self-host Inter. The page works without it, but a blocked font request can delay the first paint. |
| Google sign-in | Sign-in button | Hide it or let it fail quietly; email sign-up works. |
| Battle.net sign-in | Sign-in button | Chinese Battle.net accounts sign in at China's own Battle.net, which our app is not registered with. Email sign-up is the path for them. |
| YouTube | Uploading and playing recordings | Blocked. The video provider is meant to be interchangeable (VISION.md, the library): Bilibili is the Chinese equivalent, if Chinese players record. |
| The server's distance | Every request | Measure load times from China before deciding anything. |

## Bringing the regions together

The game is the same in every region; only the language and the players differ. What the site can
do that region-locked sites cannot:

- **One library across regions.** Comps, goes and falls keyed by spell id let a US player study a
  Chinese 2,400 game in their own language, and the other way round. Few places show cross-region
  arena play side by side.
- **Comparisons across or within regions.** A player's spec could be compared with their own region
  and with everyone; the shared averages should record the region so the two can be told apart
  (Chinese play may differ in pace and comps).
- **Leaderboards and player pages per region,** with CN marked as its own region rather than guessed
  into US or EU.

## The order to do it in

1. Fix the Watch page for Chinese games with a name table: icons, and frames that show buttons used.
   Wrong frames are the most serious problem.
2. Check every by-name lookup (Improve, the averages, the comp library) and list what else needs the id.
3. Keep the spell id at measure time, with the next `VERSION` bump.
4. Make sign-up reliable from China (reCAPTCHA from recaptcha.net, self-hosted font), once a Chinese
   player confirms what fails.
5. Spell names in the reader's language, then the page text, starting with the game card.
