-- Written by the MindCollector app: auction house prices pooled from every player who shares their
-- scans (mindcollector.com, /api/forever/market). The game loads it when the UI loads (/reload).
-- It must exist when the game starts: WoW only picks up an addon's files at launch.
-- Shape: { market, at (when the app fetched it), t (newest scan), players, items = { [itemID] =
-- { t, min, listed, prev t, prev min, prev listed, prev listed by time left (4: under 30m, 30m-2h,
-- 2-12h, over 12h), lowest in 7 days, scans } } }
MindCollectorObserverMarket = nil
