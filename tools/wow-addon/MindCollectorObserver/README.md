# MindCollector Observer (WoW: Forever)

Remembers what you look at and tells you later what changed.

- **A look** is any tooltip you hold up for 2.5 seconds or more (`/mco min` changes it): items,
  spells, talents, currencies, quests, players. Opening an item's page in the auction house is a look
  straight away. Each look keeps where you were (auction, vendor, bags, talents, chat link...), the
  auction price last seen, how many you had, and a **guess** at why.
- **The guess** comes from your professions and the Forever recipe data in `Data.lua`: "for levelling
  Blacksmithing (you're 120)", "maybe selling (you have 40)", "a recipe for later", "weighing this
  talent". It is a guess and says so. `/mco why <text>` keeps your real reason beside it, which is the
  check on whether the guesses are any good.
- **Later**, when the auction house shows a new price for something you looked at a day or more
  before, it says so in chat if the price moved 10% or more: "Copper Bar: 12s when you looked on
  09 Nov, 18s now: up 50% in 7 days. You were probably for levelling Blacksmithing." When you take a
  talent you were reading, it says how long ago you first read it.
- **`/mco scan`** (auction house open) reads the whole auction house with `C_AuctionHouse.ReplicateItems`
  and compares every price with the previous scan ("Market movers" in the window). Untested on
  Forever's servers: if it isn't allowed, the addon says so after a minute.
- **The window** (`/mco` or the spyglass on the minimap, which you can drag around it): tabs for
  Items, Talents, Spells, Players, Other and Market movers, with a search box. Each row shows the
  icon, the name in its quality or class colour, the price when you first looked against the latest
  one, and underneath the guess, where you were, how many looks and when. Click a row for the
  details and every look; type the real reason in "Why were you looking?". Shift-click an item to
  link it in chat. "Scan auction house" at the bottom does the same as `/mco scan`. The window lives
  in `ui.lua`; `main.lua` does the recording.

Prices come only from what the auction house shows you and from scans you start. The addon never
searches the auction house on its own.

## Data

`MindCollectorObserverDB` (account-wide SavedVariables): `subjects` (every looked-at thing, with up
to 30 looks each), `prices` (a daily price history, only for things you looked at), `lastScan` and
`prevScan` (one price per item from the last two full scans). The desktop app can read this file
later to show the same on its pages.

`Data.lua` is generated: `python tools/forever-market/observer_data.py <wago wowf folder> Data.lua`
(see `docs/forever/market-research-2026-11-07.md`, "Re-running the numbers"). The skill a recipe
becomes learnable at is not in the client data, so it uses the recipe item's required skill, or
yellow minus 25.

## Built against

Blizzard's Forever UI source (Gethe/wow-ui-source, branch `forever`, 1.60.1.70862): the
`C_AuctionHouse` API and events, `TooltipDataProcessor.AddTooltipPostCall`, and the talent screen's
`TalentDisplay.TooltipHook` event. None of it has been run in the game yet.
