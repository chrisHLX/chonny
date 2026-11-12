# MindCollector Observer (WoW: Forever)

Remembers the items you look at, prices your professions' recipes, and tells you later what changed.
Items and professions only since 2026-11-10 (Chriso): talents, spells and players were noise, and
what was saved of them is dropped at the next login.

- **A look** is an item tooltip you hold up for 2.5 seconds or more (`/mco min` changes it), or an
  item you open in the auction house. Each look keeps where you were (auction, vendor, bags,
  profession, chat link...), the auction price last seen, how many you had, and a **guess** at why.
- **The guess** comes from your professions and the Forever recipe data in `Data.lua`: "for levelling
  Alchemy (you're 130)", "maybe selling (you have 40)", "a recipe for later", "planning a craft". It
  is a guess and says so. `/mco why <text>` (or the box in the window) keeps your real reason beside it.
- **Later**, when the auction house shows a new price for something you looked at a day or more
  before, it says so in chat if the price moved 10% or more: "Copper Bar: 12s when you looked on
  09 Nov, 18s now: up 50% in 7 days. You were probably for levelling Blacksmithing."
- **Professions:** for each of your professions, the recipes that give a point at your skill, in the
  game's colours (orange always, yellow and green less often), cheapest per skill point first. Cost
  is each reagent's cheapest auction listing you last saw, or your last full scan, or the vendor's
  price for vials, thread and spices. Click one for its reagents, what you have, the chance of a
  point, and what it makes. "Coming up" lists the next recipes within 30 points.
- **Where a recipe comes from** (2026-11-10). Trainer or recipe item is read from the client data
  exactly (the recipe item's "on learn" effect names its recipe; matching names missed "Recipe: Elixir
  of Ogre's Strength", which teaches "Elixir of Ogre Strength"). What a trainer recipe needs, and
  where anything is taught or sold, is only on Blizzard's servers, so the addon writes down what you
  see: opening a profession trainer records every recipe it offers (skill, level, cost, the trainer
  and their spot), and opening a vendor records any recipe items for sale (price, limited supply,
  bought with a currency) and its real prices for vials, thread and spices. A recipe with a known
  place has "Show on map"; a recipe item nobody has seen for sale has "Ask where to get it" (/ask).
  Making a reagent yourself is used when it's cheaper than buying it, and the switch at the top of a
  profession's list sorts by what's left after selling the product (less the auction house's 5%).
- **Most profit** (2026-11-10; the same switch, clicked again): every recipe at or under your skill,
  points or not, by what one craft earns: the product's cheapest listing less the 5% cut, less the
  reagents. Each row also shows how many are listed and how that moved between your last two full scans
  ("not much selling" when they rose and the price didn't). Fewer listed at a held price isn't proof of
  selling, because listings also run out (Chriso's Rage Potions, 2026-11-11). So each scan records how
  long every listing has left (`GetReplicateItemTimeLeft`: under 30m, 30m-2h, 2-12h, over 12h), and
  the next scan counts as possibly expired every listing whose band starts inside the gap between them.
  Only a drop bigger than that says "at least N sold"; otherwise "N could have run out: can't tell".
  Scans close together give the sharpest answer. Scans from before this change say "sold or ran out".
  One cheapest listing is not a market: a thin one (2 listed) can't take many.
- **Shared prices** (2026-11-12): each scan records its realm, region and faction, and the
  MindCollector app (0.6.11) sends it to the website and writes everyone's prices for that market
  into `Market.lua` (`MindCollectorObserverMarket`, read into `ns.pool` at load). A pooled reading
  is used when it's newer than yours, the selling read says "(all players)" when it comes from the
  pool, and the footer says how many players' scans the prices are from. `Market.lua` is a new file:
  the first time, the game needs a full restart. **The neutral goblin auction house** (Booty Bay,
  Gadgetzan, Everlook) has its own listings: opening one says so, its prices are not noted, and its
  scan is kept apart (`DB.otherScans.Neutral`) and shared as its own market, so it never mixes
  with your faction's prices here or in the pool.
- **Used in** (2026-11-11): under any item's details (Items, Market, Ledger, and what a recipe
  makes), every Forever recipe that takes it, one section per profession, yours first and open.
  Click a header to open or close it. Each recipe shows how many it takes, the skill it's learned
  at and goes grey at, your colour for it, what the craft sells for, and "used at 60" when what it
  makes is used at max level (`used_at_60` in `observer_data.py`, field 11 of a recipe in
  `Data.lua`). Click a recipe to open it in Professions. The details scroll now, so long lists fit.
  A **Quests** section (2026-11-12) lists the quests that ask for the item, the ones your faction and
  class can take first, in the game's level colour, with level, zone (or class or profession) and
  who it's limited to. From Wowhead's Forever item pages (`tools/forever-market/wowhead/quests.json`),
  for the crafting items Wowhead lists as quest objectives; how many a quest asks for isn't there.
- **`/mco scan`** (auction house open; also the button at the bottom of the window) reads the whole
  auction house with `C_AuctionHouse.ReplicateItems`. It waits until the list stops growing, then
  reads it, and says "scanned N auctions" when done. The server allows one every 15 minutes. One
  scan prices every reagent at once; two on different days fill the Market tab with the biggest moves.
- **The window** (`/mco` or the spyglass on the minimap): Items, Professions, Market and Ask, with a
  search box. `ui.lua` draws it; `main.lua` records.

WoW writes the addon's data to disk only at a `/reload` or logout, so a scan or new looks reach the
desktop app after the next one. Prices come only from what the auction house shows you and from
scans you start; the addon never searches the auction house on its own.

## Data

`MindCollectorObserverDB` (account-wide SavedVariables): `subjects` (every looked-at item, up to 30
looks each), `prices` (a daily price history for those), `lastScan` and `prevScan` (one price per
item, with its name, from the last two full scans), `profs` (your professions and skill), `asks`.

`Data.lua` is generated: `python tools/forever-market/observer_data.py <wago wowf folder> Data.lua`
(see `docs/forever/market-research-2026-11-07.md`, "Re-running the numbers"). It holds which
professions use each reagent, every recipe per profession, item names, and vendor prices. The skill
a recipe becomes learnable at is not in the client data, so it uses the recipe item's required
skill, or yellow minus 25 (shown as "from about").

## Built against

Blizzard's Forever UI source (Gethe/wow-ui-source, branch `forever`, 1.60.1.70862): the
`C_AuctionHouse` API and events and `TooltipDataProcessor.AddTooltipPostCall`. Recording items
worked in game on 2026-11-09 (113 looks in the first day). The full scan and the Professions tab are
untested in game.
