-- MindCollector Observer (WoW: Forever, interface 16001).
--
-- Remembers what you look at for a while: any tooltip held up for DB.minDwell seconds (items, spells,
-- talents, currencies, quests, players), and any item you open in the auction house. For each look it
-- stores where you were, the auction price it last saw, how many you had, and a guess at why you
-- were looking, built from your professions and the Forever recipe data in Data.lua. When it later
-- sees a new price for something you looked at, or you take a talent you were reading, it tells you
-- what changed since.
--
-- Prices come only from what the auction house shows you (browse lists, item pages) and from a full
-- scan you start with /mco scan while the auction house is open. Nothing is searched behind your back.
--
-- /mco            the window
-- /mco scan       full auction house scan (auction house open; the server allows one every 15 min)
-- /mco why <text> say why you looked at the last thing; the guess stays, your answer is kept beside it
-- /mco min <sec>  how long a tooltip must stay up to count as a look (default 2.5)

local _, ns = ...
local DB
local PREFIX = "|cffd4af37MindCollector|r "
local DAY = 86400

local PROF_NAMES = { [164] = "Blacksmithing", [165] = "Leatherworking", [171] = "Alchemy", [185] = "Cooking",
    [197] = "Tailoring", [202] = "Engineering", [333] = "Enchanting", [129] = "First Aid" }
-- Forever's per-profession child skill lines, in case GetProfessionInfo returns those
local CHILD_SKILL = { [2937] = 171, [2938] = 164, [2939] = 185, [2940] = 333, [2941] = 202, [2942] = 129,
    [2945] = 165, [2948] = 197 }

local function say(msg) DEFAULT_CHAT_FRAME:AddMessage(PREFIX .. msg) end

local function money(c)
    if not c then return "?" end
    c = math.floor(c + 0.5)
    local g, s, cp = math.floor(c / 10000), math.floor(c / 100) % 100, c % 100
    if g > 0 then return string.format("%dg %ds", g, s) end
    if s > 0 then return string.format("%ds %dc", s, cp) end
    return cp .. "c"
end

local function dayOf(t) return date("%Y-%m-%d", t) end
local function shortDate(t) return date("%d %b", t) end

local function initDB()
    MindCollectorObserverDB = MindCollectorObserverDB or {}
    DB = MindCollectorObserverDB
    ns.DB = DB
    -- prices pooled from every player who shares their scans, written by the app (Market.lua)
    ns.pool = type(MindCollectorObserverMarket) == "table" and MindCollectorObserverMarket or nil
    DB.version = 1
    DB.minDwell = DB.minDwell or 2.5
    DB.minChange = DB.minChange or 0.10
    DB.subjects = DB.subjects or {}   -- ["item:2840"] = {kind, id, name, first, last, looks, secs, events = {...}, notes = {...}}
    DB.prices = DB.prices or {}       -- [itemID] = { {d = "2026-11-09", t, min, q, src}, ... } for things you looked at
    DB.lastScan = DB.lastScan or nil  -- {t, n, prices = {[itemID] = {min, q}}}
    DB.prevScan = DB.prevScan or nil
    DB.told = DB.told or {}           -- [subjectKey] = time we last reported a change
    -- Only items are kept since 2026-11-10: players, spells, talents and the rest were noise.
    for key, s in pairs(DB.subjects) do
        if s.kind ~= "item" then DB.subjects[key] = nil end
    end
end

-- ---------------------------------------------------------------- your professions

local function myProfessions()
    local out = {}
    local list = { GetProfessions() }
    for i = 1, select("#", GetProfessions()) do
        local idx = list[i]
        if idx then
            local name, _, rank, maxRank, _, _, skillLine = GetProfessionInfo(idx)
            if skillLine then
                local id = CHILD_SKILL[skillLine] or skillLine
                out[id] = { name = name or PROF_NAMES[id], rank = rank or 0, max = maxRank }
            end
        end
    end
    return out
end

-- Your professions as they are now, kept for the Professions tab and the desktop app.
local function saveProfessions()
    if not DB then return end
    local out = {}
    for id, p in pairs(myProfessions()) do out[id] = { name = p.name, rank = p.rank, max = p.max } end
    DB.profs, DB.profsAt = out, time()
    if ns.refresh then ns.refresh() end
end
ns.myProfessions = myProfessions

-- ---------------------------------------------------------------- prices

-- Prices seen this session for every item the auction house showed, looked at or not (not saved).
local seen = {}

-- The newest price we have from any source: your looked-at history, this session, or the last full scan.
local function latestPrice(itemID, maxAge)
    local best
    local h = DB.prices[itemID]
    if h and h[#h] then best = h[#h] end
    local s = seen[itemID]
    if s and (not best or s.t > best.t) then best = s end
    local sc = DB.lastScan and DB.lastScan.prices[itemID]
    if sc and (not best or DB.lastScan.t > best.t) then best = { t = DB.lastScan.t, min = sc[1], q = sc[2], src = "scan" } end
    -- everyone's scans, pooled by the app (Market.lua), when one is newer than anything of yours
    local pl = ns.pool and ns.pool.items and ns.pool.items[itemID]
    if pl and (not best or pl[1] > best.t) then best = { t = pl[1], min = pl[2], q = pl[3], src = "pool" } end
    if best and (not maxAge or time() - best.t <= maxAge) then return best end
    return nil
end

local function firstPriceSince(itemID, since)
    for _, p in ipairs(DB.prices[itemID] or {}) do
        if p.t >= since then return p end
    end
end

local checkChange -- defined below

local function notePrice(itemID, minPrice, qty, src, quiet)
    if not itemID or not minPrice or minPrice <= 0 then return end
    if src ~= "scan" then seen[itemID] = { t = time(), min = minPrice, q = qty, src = src } end
    if not DB.subjects["item:" .. itemID] and not (DB.tracked and DB.tracked[itemID]) then
        -- only keep a history for things you have looked at or track; the full scan keeps everything else
        return
    end
    -- A tracked item keeps every reading, not one a day: whether listings fall between two scans an
    -- hour apart is the thing worth seeing (2026-11-10, 59 then 76 listed got folded into one day).
    if DB.tracked and DB.tracked[itemID] then
        DB.track = DB.track or {}
        local t = DB.track[itemID] or {}
        DB.track[itemID] = t
        local prev = t[#t]
        if not (prev and time() - prev.t < 120 and prev.min == minPrice and prev.q == qty) then
            table.insert(t, { t = time(), min = minPrice, q = qty, src = src })
            if #t > 300 then table.remove(t, 1) end
        end
    end
    local h = DB.prices[itemID]
    if not h then h = {}; DB.prices[itemID] = h end
    local d, last = dayOf(time()), h[#h]
    if last and last.d == d then
        last.t, last.min, last.q, last.src = time(), minPrice, qty, src
    else
        table.insert(h, { d = d, t = time(), min = minPrice, q = qty, src = src })
        if #h > 120 then table.remove(h, 1) end
    end
    if not quiet then checkChange("item:" .. itemID) end
end

-- ---------------------------------------------------------------- the guess

local function itemGuess(id, ctx, ev)
    local profs = myProfessions()
    local rec = ns.recipeItems[id]
    if rec then
        local skill, need = rec[1], rec[2]
        local mine = profs[skill]
        local pname = PROF_NAMES[skill] or "a profession"
        if mine then
            if mine.rank >= need then
                return string.format("thinking of learning it (%s %d, it needs %d)", pname, mine.rank, need)
            end
            return string.format("a recipe for later (it needs %s %d, you have %d)", pname, need, mine.rank)
        end
        return "a recipe to resell (you don't have " .. pname .. ")"
    end
    local r = ns.reagents[id]
    if r then
        for skill, spans in pairs(r.p) do
            local mine = profs[skill]
            if mine then
                for _, sp in ipairs(spans) do
                    if mine.rank >= sp[1] - 10 and mine.rank < sp[2] then
                        return string.format("for levelling %s (you're %d)", mine.name or PROF_NAMES[skill], mine.rank)
                    end
                end
            end
        end
        for skill, spans in pairs(r.p) do
            local mine = profs[skill]
            if mine and mine.rank < spans[1][1] then
                return string.format("for %s later (it's used from %d, you're %d)", mine.name or PROF_NAMES[skill], spans[1][1], mine.rank)
            end
        end
    end
    if ctx == "profession" then return "planning a craft" end
    if (ctx == "auction" or ctx == "vendor") and ev.n and ev.n > 0 then
        return "maybe selling (you have " .. ev.n .. ")"
    end
    local _, _, _, equipLoc, _, classID = C_Item.GetItemInfoInstant(id)
    if equipLoc and equipLoc ~= "" and equipLoc ~= "INVTYPE_NON_EQUIP_IGNORE" then
        return "weighing it as gear"
    end
    if r and r.u then return "watching the price (used at 60 in " .. r.u[1] .. ")" end
    if r then return "watching the price of a crafting material" end
    if classID == 0 then return "stocking up on a consumable" end
    if ctx == "auction" then return "checking the price" end
    return "reading about it"
end

local function guess(c, ev)
    if c.kind == "item" then return itemGuess(c.id, c.ctx, ev) end
    if c.kind == "talent" then
        local x = c.extra or {}
        if (x.rank or 0) == 0 then return "weighing this talent (you don't have it)" end
        if x.max and x.rank < x.max then return "thinking about another point here" end
        return "re-reading a talent you have"
    end
    if c.kind == "spell" then return "checking what a spell does" end
    if c.kind == "player" then return "sizing up a player" end
    if c.kind == "object" and c.ctx == "world" then return "out gathering" end
    return "reading about it"
end

-- ---------------------------------------------------------------- recording a look

local function record(c, dwell)
    local t = time()
    local s = DB.subjects[c.key]
    if not s then
        s = { kind = c.kind, id = c.id, name = c.name, first = t, looks = 0, secs = 0, events = {} }
        DB.subjects[c.key] = s
        local p = c.kind == "item" and seen[c.id]
        if p then notePrice(c.id, p.min, p.q, p.src, true) end -- start its history with what the AH just showed
    end
    s.name = c.name or s.name
    if c.kind == "player" and c.extra then s.class, s.level = c.extra.class, c.extra.level end
    s.last = t
    s.secs = s.secs + dwell
    local last = s.events[#s.events]
    if last and t - last.t < 120 and last.ctx == c.ctx then
        last.s = last.s + math.floor(dwell + 0.5)  -- the same look, the mouse just came back
        DB.lastKey = c.key
        if ns.refresh then ns.refresh() end
        return
    end
    s.looks = s.looks + 1
    local ev = { t = t, s = math.floor(dwell + 0.5), ctx = c.ctx, ch = UnitName("player") }
    if c.kind == "item" then
        local p = latestPrice(c.id, 6 * 3600)
        if p then ev.p, ev.q = p.min, p.q end
        ev.n = C_Item.GetItemCount(c.id, true)
    elseif c.kind == "talent" and c.extra then
        ev.rank, ev.max = c.extra.rank, c.extra.max
        if (c.extra.rank or 0) > 0 then s.hadIt = true end
    end
    ev.g = guess(c, ev)
    table.insert(s.events, ev)
    if #s.events > 30 then table.remove(s.events, 1) end
    DB.lastKey = c.key
    if ns.refresh then ns.refresh() end
end

-- One subject per tooltip at a time; a look ends when the tooltip hides or shows something else.
local tracking = {}

local function finish(tooltip, at)
    local c = tracking[tooltip]
    if not c then return end
    tracking[tooltip] = nil
    local dwell = (at or GetTime()) - c.start
    if dwell >= DB.minDwell or c.force then record(c, dwell) end
end

-- Some frames hide and re-show the tooltip while you hover (bag buttons refreshing), so a hide only
-- ends the look if the same thing hasn't come back within 0.4 seconds.
local function hidden(tooltip)
    local c = tracking[tooltip]
    if not c then return end
    local at = GetTime()
    c.hiddenAt = at
    C_Timer.After(0.4, function()
        if tracking[tooltip] == c and c.hiddenAt == at then finish(tooltip, at) end
    end)
end

local function begin(tooltip, kind, id, name, ctx, extra, force)
    if not id then return end
    local key = kind .. ":" .. tostring(id)
    local c = tracking[tooltip]
    if c and c.key == key then c.hiddenAt = nil return end
    if c then finish(tooltip, c.hiddenAt) end
    tracking[tooltip] = { key = key, kind = kind, id = id, name = name, ctx = ctx, extra = extra, start = GetTime(), force = force }
end

-- Where the tooltip's owner sits: walk up its frames and match the ones we know.
local CONTEXTS = {
    { "^AuctionHouseFrame", "auction" }, { "^MerchantFrame", "vendor" }, { "^Professions", "profession" },
    { "^TradeSkill", "profession" }, { "TalentsFrame", "talents" }, { "SpellBook", "spellbook" },
    { "^PlayerSpellsFrame", "talents" }, { "^CharacterFrame", "character" }, { "^PaperDoll", "character" },
    { "^ContainerFrame", "bags" }, { "^BankFrame", "bank" }, { "^LootFrame", "loot" }, { "^Quest", "quests" },
    { "^WorldMapFrame", "map" }, { "Mail", "mail" }, { "^TradeFrame", "trade" }, { "ActionButton", "action bar" },
    { "^MultiBar", "action bar" }, { "^ChatFrame", "chat" },
}

local function contextOf(tooltip)
    if tooltip == ItemRefTooltip then return "chat link" end
    local f, depth = tooltip:GetOwner(), 0
    local dn
    if f and f.GetDebugName then
        local ok, name = pcall(f.GetDebugName, f)
        if ok and type(name) == "string" then dn = name end
    end
    -- Talents and the spellbook share PlayerSpellsFrame; their unnamed children only show in the debug path
    if dn and dn:find("TalentsFrame") then return "talents" end
    if dn and dn:find("SpellBook") then return "spellbook" end
    while f and depth < 15 do
        local n = f.GetName and f:GetName()
        if n then
            for _, m in ipairs(CONTEXTS) do
                if n:find(m[1]) then return m[2] end
            end
        end
        f = f.GetParent and f:GetParent()
        depth = depth + 1
    end
    if dn then
        for _, m in ipairs(CONTEXTS) do
            if dn:find((m[1]:gsub("^%^", ""))) then return m[2] end
        end
    end
    return "world"
end

local function onTooltip(tooltip, data)
    if tooltip ~= GameTooltip and tooltip ~= ItemRefTooltip then return end
    -- Items only, checked first (2026-11-12): this runs for every tooltip of every kind, and spell,
    -- buff and unit tooltips refresh constantly while hovered, so nothing else is worked out for them.
    if not data or data.type ~= Enum.TooltipDataType.Item then return end
    if ns.owns and ns.owns(tooltip:GetOwner()) then return end -- the addon's own window
    local T = Enum.TooltipDataType
    local ctx = contextOf(tooltip)
    local firstLine = data.lines and data.lines[1] and data.lines[1].leftText
    if data.type == T.Item then
        local id = data.id
        if not id then return end
        begin(tooltip, "item", id, C_Item.GetItemNameByID(id) or firstLine, ctx)
    end
    -- Since 2026-11-10 only items are recorded (Chriso: focus on items and professions).
end

local function onTalentTooltip(_, button)
    local spellID = button and button.GetSpellID and button:GetSpellID()
    if not spellID then return end
    local ok, info = pcall(function() return button.nodeInfo or (button.GetNodeInfo and button:GetNodeInfo()) end)
    info = ok and info or nil
    local extra = info and { rank = info.currentRank or info.ranksPurchased or 0, max = info.maxRanks } or nil
    begin(GameTooltip, "talent", spellID, C_Spell.GetSpellName(spellID), "talents", extra)
end

-- ---------------------------------------------------------------- telling you what changed

local function pctText(p)
    if p >= 0 then return string.format("|cff40d060up %d%%|r", math.floor(p * 100 + 0.5)) end
    return string.format("|cffe05050down %d%%|r", math.floor(-p * 100 + 0.5))
end

-- The look this change is measured from: your most recent look at least a day old.
local function referenceLook(s, itemID)
    for i = #s.events, 1, -1 do
        local e = s.events[i]
        if time() - e.t >= DAY then
            local p = e.p
            if not p then
                local fp = firstPriceSince(itemID, e.t)
                if fp and fp.t - e.t <= 2 * DAY then p = fp.min end
            end
            if p then return e, p end
        end
    end
end

checkChange = function(key, quiet)
    local s = DB.subjects[key]
    if not s or s.kind ~= "item" then return end
    local e, then_ = referenceLook(s, s.id)
    local cur = latestPrice(s.id, 6 * 3600)
    if not e or not cur then return end
    local p = (cur.min - then_) / then_
    if math.abs(p) < DB.minChange then return end
    local told = DB.told[key]
    if told and dayOf(told) == dayOf(time()) then return end
    DB.told[key] = time()
    local days = math.max(1, math.floor((time() - e.t) / DAY + 0.5))
    local note = string.format("%s when you looked on %s, %s now: %s in %d day%s", money(then_), shortDate(e.t),
        money(cur.min), pctText(p), days, days == 1 and "" or "s")
    s.notes = s.notes or {}
    table.insert(s.notes, { t = time(), text = note })
    if #s.notes > 10 then table.remove(s.notes, 1) end
    if not quiet then
        say(string.format("%s: %s. You were probably %s.", s.name or ("item " .. s.id), note, e.why or e.g or "looking"))
    end
    return true
end

local function checkTalents(quiet)
    local found = 0
    for _, s in pairs(DB.subjects) do
        local ok, known = false, false
        if s.kind == "talent" and not s.hadIt and not s.takenAt then ok, known = pcall(C_SpellBook.IsSpellKnown, s.id) end
        if ok and known then
            s.takenAt = time()
            if dayOf(s.first) ~= dayOf(time()) then
                found = found + 1
                local note = string.format("taken on %s; you first read it on %s (%d look%s, %ds)", shortDate(time()),
                    shortDate(s.first), s.looks, s.looks == 1 and "" or "s", math.floor(s.secs + 0.5))
                s.notes = s.notes or {}
                table.insert(s.notes, { t = time(), text = note })
                if not quiet then say(string.format("%s: %s.", s.name or "a talent", note)) end
            end
        end
    end
    return found
end

-- ---------------------------------------------------------------- auction house

local ahOpenedItem -- the item page you opened, recorded as a look even without a tooltip

-- Which auction house is open (2026-11-12, Chriso): your faction's, or a goblin one in Booty Bay,
-- Gadgetzan or Everlook that belongs to neither and has its own listings. Its prices must not mix
-- with your faction's, here or in everyone's pooled prices. The auction API doesn't say, so: the
-- goblin towns, or an auctioneer of neither faction, mean the neutral house. Set when one opens.
local NEUTRAL_TOWNS = { ["Booty Bay"] = true, ["Gadgetzan"] = true, ["Everlook"] = true }
local ahHouse
local function houseFaction()
    if NEUTRAL_TOWNS[GetSubZoneText() or ""] or NEUTRAL_TOWNS[GetMinimapZoneText() or ""] then return "Neutral" end
    local f = UnitExists("npc") and UnitFactionGroup("npc") or nil
    if f == "Horde" or f == "Alliance" then return f end
    if UnitExists("npc") then return "Neutral" end
    return UnitFactionGroup("player")
end
local function atOwnHouse() return not ahHouse or ahHouse == UnitFactionGroup("player") end

local function onBrowse(results)
    if not atOwnHouse() then return end
    for _, r in ipairs(results or C_AuctionHouse.GetBrowseResults() or {}) do
        if r.itemKey then notePrice(r.itemKey.itemID, r.minPrice, r.totalQuantity, "browse") end
    end
end

local function openedItem(itemID)
    if ahOpenedItem ~= itemID then
        ahOpenedItem = itemID
        local c = { key = "item:" .. itemID, kind = "item", id = itemID, name = C_Item.GetItemNameByID(itemID), ctx = "auction" }
        record(c, 0)
    end
end

local function onCommodity(itemID)
    if not itemID then return end
    openedItem(itemID)
    local info = C_AuctionHouse.GetCommoditySearchResultInfo(itemID, 1)
    if info and atOwnHouse() then notePrice(itemID, info.unitPrice, C_AuctionHouse.GetCommoditySearchResultsQuantity(itemID), "item page") end
end

local function onItemSearch(itemKey)
    if not itemKey or not itemKey.itemID then return end
    openedItem(itemKey.itemID)
    local info = C_AuctionHouse.GetItemSearchResultInfo(itemKey, 1)
    if info and info.buyoutAmount and atOwnHouse() then
        notePrice(itemKey.itemID, info.buyoutAmount, C_AuctionHouse.GetItemSearchResultsQuantity(itemKey), "item page")
    end
end

local scanPending, scanTimer
local scanHouse -- the auction house the running scan reads

-- Where a full scan is, for the window's footer: nil when none is running.
local function scanStatus(text)
    ns.scanStatus = text
    if ns.refresh then ns.refresh() end
end

-- The four time-left bands (under 30m, 30m-2h, 2-12h, over 12h) as {shortest, longest} seconds, keyed
-- by the raw value a scan reads. The API's enum counts from 0; the old auction API counted from 1. A full
-- scan always holds some of the shortest and the longest, so a 0 or a 4 among the values settles which.
local bands
local function bandTimes()
    local base = bands[0] and 0 or (bands[4] and 1 or 0)
    local fallback = { { 0, 1800 }, { 1800, 7200 }, { 7200, 43200 }, { 43200, 172800 } }
    local E = Enum and Enum.AuctionHouseTimeLeftBand or {}
    local names = { "Short", "Medium", "Long", "VeryLong" }
    local out = {}
    for k = 0, 3 do
        local lo, hi = fallback[k + 1][1], fallback[k + 1][2]
        local ok, a, b = pcall(C_AuctionHouse.GetTimeLeftBandInfo, E[names[k + 1]] or k)
        if ok and a and b and b > 0 then lo, hi = a, b end
        out[k + base] = { lo, hi }
    end
    return out
end

local function finishScan(agg, n)
    scanStatus(nil)
    for _, a in pairs(agg) do if a.tl and not next(a.tl) then a.tl = nil end end
    -- Where it was scanned, so the app can pool it with other players' scans of the same market
    -- (2026-11-12): realm, region, the auction house's faction (`faction`: Horde, Alliance or Neutral)
    -- and yours (`side`). Forever is one realm per region, and Horde, Alliance and the goblin towns
    -- each have their own auction house (Chriso), so in practice a market is region and house.
    local okRegion, region = pcall(GetCurrentRegionName)
    local mine = UnitFactionGroup("player")
    local house = scanHouse or mine
    local entry = { t = time(), n = n, prices = agg, bands = next(bands) and bandTimes() or nil,
        realm = (GetNormalizedRealmName and GetNormalizedRealmName()) or GetRealmName(),
        region = okRegion and region or nil, faction = house, side = mine }
    if house ~= mine then
        -- a neutral house: kept apart, never your last scan or a price in your history, but still
        -- shared, as its own market
        DB.otherScans = DB.otherScans or {}
        DB.otherScans[house] = entry
        say(string.format("scanned the %s auction house: %d auctions. Kept apart from your faction's prices.", house == "Neutral" and "neutral (goblin)" or house, n))
        if ns.refresh then ns.refresh() end
        return
    end
    DB.prevScan = DB.lastScan
    DB.lastScan = entry
    local moved, items = 0, 0
    for id, a in pairs(agg) do
        items = items + 1
        if DB.subjects["item:" .. id] or (DB.tracked and DB.tracked[id]) then
            notePrice(id, a[1], a[2], "scan", true)
            if checkChange("item:" .. id, true) then moved = moved + 1 end
        end
    end
    say(string.format("scanned %d auctions, %d items. %d thing%s you looked at moved since; /mco to see. WoW saves it for the app at your next /reload.",
        n, items, moved, moved == 1 and "" or "s"))
    if ns.refresh then ns.refresh() end
end

local function readScan()
    local n = C_AuctionHouse.GetNumReplicateItems()
    local agg, i = {}, 0
    bands = {}
    local ticker
    ticker = C_Timer.NewTicker(0.01, function()
        local stop = math.min(i + 2000, n)
        while i < stop do
            local name, _, count, _, _, _, _, _, _, buyout, _, _, _, _, _, _, itemID = C_AuctionHouse.GetReplicateItemInfo(i)
            if itemID and buyout and buyout > 0 and count and count > 0 then
                local unit = buyout / count
                local a = agg[itemID]
                if not a then
                    a = { unit, count, name, tl = {} } -- the name too, so the app can show items you never hovered
                    agg[itemID] = a
                else
                    if unit < a[1] then a[1] = unit end
                    a[2] = a[2] + count
                end
                -- How long each listing has left, so the next scan can tell expiry from selling (2026-11-11).
                -- Kept as the raw value: whether the game counts bands from 0 or 1 is settled in finishScan.
                local band = C_AuctionHouse.GetReplicateItemTimeLeft and C_AuctionHouse.GetReplicateItemTimeLeft(i)
                if band then
                    a.tl[band] = (a.tl[band] or 0) + count
                    bands[band] = (bands[band] or 0) + 1
                end
            end
            i = i + 1
        end
        if i >= n then
            ticker:Cancel()
            finishScan(agg, n)
        end
    end)
end

-- The list can still be arriving when its event fires: read it once the count holds still for a second.
local waiting
local function waitForScan()
    if waiting then return end
    waiting = true
    local last = -1
    local function check()
        if not scanPending then waiting = false return end
        local n = C_AuctionHouse.GetNumReplicateItems() or 0
        if n > 0 and n == last then
            waiting, scanPending = false, false
            say(string.format("reading %d auctions...", n))
            scanStatus(string.format("Reading %d auctions...", n))
            readScan()
            return
        end
        last = n
        scanStatus(n > 0 and string.format("Auctions arriving: %d so far...", n) or "Waiting for the auction house...")
        C_Timer.After(1, check)
    end
    C_Timer.After(1, check)
end

local function scan()
    if not (AuctionHouseFrame and AuctionHouseFrame:IsShown()) then
        say("open the auction house first.")
        return
    end
    scanPending = true
    scanHouse = ahHouse or houseFaction()
    local ok, err = pcall(C_AuctionHouse.ReplicateItems)
    if not ok then
        scanPending = false
        scanStatus(nil)
        say("the full scan isn't allowed here: " .. tostring(err))
        return
    end
    say("scanning the whole auction house; this takes a few seconds.")
    scanStatus("Asked the auction house for everything...")
    if scanTimer then scanTimer:Cancel() end
    scanTimer = C_Timer.NewTimer(90, function()
        if scanPending and (C_AuctionHouse.GetNumReplicateItems() or 0) == 0 then
            scanPending = false
            scanStatus(nil)
            say("no scan came back. The server allows one full scan every 15 minutes; if it has been longer, Forever may not allow it at all.")
        end
    end)
end

-- ---------------------------------------------------------------- trainers and vendors (2026-11-10)
-- The client data doesn't say what skill a trainer recipe needs or where anything is taught or sold;
-- Blizzard's servers do. So the addon writes down what you see: every recipe a profession trainer
-- offers (skill, level, cost, the trainer and where they stand), and every recipe a vendor sells, with
-- the vendor's price of reagents like vials.

local function whereAmI()
    local mapID = C_Map.GetBestMapForUnit("player")
    local pos = mapID and C_Map.GetPlayerMapPosition(mapID, "player")
    local x, y
    if pos then x, y = pos:GetXY() end
    return { zone = GetZoneText(), sub = GetSubZoneText(), mapID = mapID,
        x = x and math.floor(x * 1000 + 0.5) / 10, y = y and math.floor(y * 1000 + 0.5) / 10 }
end

-- Reads only what the trainer window lists, and never touches its filters: switching a filter fires
-- TRAINER_UPDATE, which read again and switched again, and on 2026-11-10 that froze the game at a
-- trainer. One read at a time, at most one waiting.
local readingTrainer, trainerQueued = false, false
local function readTrainer()
    trainerQueued = false
    if readingTrainer or not (DB and IsTradeskillTrainer and IsTradeskillTrainer()) then return end
    readingTrainer = true
    local here = whereAmI()
    local who = UnitName("npc") or "a trainer"
    local key = who .. "@" .. (here.zone or "?")
    DB.trainerSeen = DB.trainerSeen or {}
    DB.trainers = DB.trainers or {}
    DB.trainerSeen[key] = { name = who, zone = here.zone, sub = here.sub, mapID = here.mapID, x = here.x, y = here.y, t = time() }
    local n = 0
    for i = 1, (GetNumTrainerServices() or 0) do
        local name, kind, _, reqLevel = GetTrainerServiceInfo(i)
        if name and kind ~= "header" then
            local _, rank = GetTrainerServiceSkillReq(i)
            local cost = GetTrainerServiceCost(i)
            local k = name:lower()
            local rec = DB.trainers[k] or { at = {} }
            rec.skill, rec.level, rec.cost, rec.t = rank, reqLevel, cost, time()
            rec.at[key] = true
            DB.trainers[k] = rec
            n = n + 1
        end
    end
    readingTrainer = false
    if n > 0 and not DB.trainerSeen[key].told then
        DB.trainerSeen[key].told = true
        say(string.format("noted %d recipes %s teaches, with the skill and level each needs.", n, who))
    end
    if ns.refresh then ns.refresh() end
end

local function readVendor()
    if not DB then return end
    DB.vendors = DB.vendors or {}
    DB.vendorPrices = DB.vendorPrices or {}
    local here, who, found = nil, UnitName("npc") or "a vendor", 0
    for i = 1, (GetMerchantNumItems() or 0) do
        local link = GetMerchantItemLink(i)
        local id = link and C_Item.GetItemInfoInstant(link)
        local info = C_MerchantFrame and C_MerchantFrame.GetItemInfo and C_MerchantFrame.GetItemInfo(i)
        if id and info then
            if ns.recipeItems and ns.recipeItems[id] then
                here = here or whereAmI()
                DB.vendors[id] = { by = who, zone = here.zone, sub = here.sub, mapID = here.mapID, x = here.x, y = here.y,
                    price = info.price, limited = (info.numAvailable or -1) >= 0 and info.numAvailable or nil,
                    currency = info.hasExtendedCost or nil, t = time() }
                found = found + 1
            elseif ns.vendor and ns.vendor[id] and info.price and info.price > 0 and not info.hasExtendedCost then
                DB.vendorPrices[id] = math.floor(info.price / math.max(info.stackCount or 1, 1) + 0.5)
            end
        end
    end
    if found > 0 and ns.refresh then ns.refresh() end
end

-- ---------------------------------------------------------------- the ledger (2026-11-10)
-- What you make, what you spend and what you earn. It only listens: hooks run after the game's own
-- call and events are read, nothing is changed, so it can't feed back on itself.
--   craft     a recipe cast that finished (UNIT_SPELLCAST_SUCCEEDED), with its reagents' value then
--   ahbuy     an auction house purchase, at the price you confirmed
--   ahsale    gold from a sale taken at the mailbox, matched to its invoice (item, count, after the cut)
--   vbuy      a vendor purchase;  vsell  gold from selling to a vendor (the item isn't known)
-- Entry: {t, k, i = itemID or 0, n = item name, q = count, c = copper (+ in, - out), v = reagent value}

local function ledger(entry)
    if not DB then return end
    DB.ledger = DB.ledger or {}
    entry.t = entry.t or time()
    table.insert(DB.ledger, entry)
    while #DB.ledger > 3000 do table.remove(DB.ledger, 1) end
    if ns.refresh then ns.refresh() end
end
ns.ledger = ledger

-- recipe spell -> {profession, recipe}, built once
local recipeBySpell
local function recipeFor(spell)
    if not recipeBySpell then
        recipeBySpell = {}
        for pid, list in pairs(ns.recipes or {}) do
            for _, r in ipairs(list) do recipeBySpell[r[1]] = { pid, r } end
        end
    end
    return recipeBySpell[spell]
end

local function reagentValue(r)
    local total = 0
    for i = 1, #r[8], 2 do
        local id = r[8][i]
        local p = latestPrice(id)
        local v = (DB.vendorPrices and DB.vendorPrices[id]) or (ns.vendor and ns.vendor[id])
        local price = (p and v and math.min(p.min, v)) or (p and p.min) or v
        if not price then return nil end
        total = total + price * r[8][i + 1]
    end
    return math.floor(total + 0.5)
end

local function onCast(unit, _, spell)
    if unit ~= "player" or not spell then return end
    local hit = recipeFor(spell)
    if not hit then return end
    local r = hit[2]
    ledger({ k = "craft", i = r[7], n = r[2], q = 1, c = 0, v = reagentValue(r), p = hit[1] })
end

-- auction house purchases: the quote comes with COMMODITY_PRICE_UPDATED, the purchase with its success event
local pendingCommodity, pendingBid
local function hookAuctionHouse()
    if not C_AuctionHouse then return end
    if C_AuctionHouse.StartCommoditiesPurchase then
        hooksecurefunc(C_AuctionHouse, "StartCommoditiesPurchase", function(itemID, quantity)
            pendingCommodity = { i = itemID, q = quantity }
        end)
    end
    if C_AuctionHouse.PlaceBid then
        hooksecurefunc(C_AuctionHouse, "PlaceBid", function(auctionID, amount)
            pendingBid = { id = auctionID, c = amount }
        end)
    end
end

-- the mailbox: a snapshot of sale invoices, so gold arriving can be matched to what sold
local mailOpen, merchantOpen, ahOpen, invoices = false, false, false, {}
-- Only adds: if the inbox refreshes before the gold lands in your bags, the invoice must still be
-- here to match it. An invoice leaves the list when its gold is matched, or when the mailbox closes.
local function snapshotMail(fresh)
    if fresh then invoices = {} end
    local have = {}
    for _, inv in ipairs(invoices) do have[inv.key] = (have[inv.key] or 0) + 1 end
    local seen = {}
    for i = 1, (GetInboxNumItems and GetInboxNumItems() or 0) do
        local _, _, _, subject, money = GetInboxHeaderInfo(i)
        if money and money > 0 then
            local kind, itemName, _, _, buyout, _, cut, _, _, _, count = GetInboxInvoiceInfo(i)
            if kind == "seller" then
                local key = (itemName or subject or "?") .. ":" .. money .. ":" .. (count or 0)
                seen[key] = (seen[key] or 0) + 1
                if seen[key] > (have[key] or 0) then
                    table.insert(invoices, { key = key, money = money, n = itemName or subject, q = count, gross = buyout, cut = cut })
                end
            end
        end
    end
end

local lastMoney
local function onMoney()
    local now = GetMoney()
    local delta = lastMoney and (now - lastMoney) or 0
    lastMoney = now
    if delta == 0 or not DB then return end
    if mailOpen and delta > 0 then
        for idx, inv in ipairs(invoices) do
            if inv.money == delta then
                table.remove(invoices, idx)
                ledger({ k = "ahsale", i = 0, n = inv.n, q = inv.q or 1, c = delta, gross = inv.gross, cut = inv.cut })
                return
            end
        end
    elseif merchantOpen and delta > 0 then
        ledger({ k = "vsell", i = 0, n = "Sold to a vendor", q = 1, c = delta })
    end
end

if BuyMerchantItem then
    hooksecurefunc("BuyMerchantItem", function(index, quantity)
        local link = GetMerchantItemLink(index)
        local id = link and C_Item.GetItemInfoInstant(link)
        local info = C_MerchantFrame and C_MerchantFrame.GetItemInfo and C_MerchantFrame.GetItemInfo(index)
        if not (id and info and info.price) or info.hasExtendedCost then return end
        local stack = math.max(info.stackCount or 1, 1)
        local q = quantity or stack
        ledger({ k = "vbuy", i = id, n = info.name or C_Item.GetItemNameByID(id), q = q, c = -math.floor(info.price * q / stack + 0.5) })
    end)
end

-- ---------------------------------------------------------------- tracking an item (2026-11-10)
-- A tracked item keeps its cheapest price and how many are listed at every scan and every time the
-- auction house shows it, so you can see whether listings get bought up and the price holds.

function ns.isTracked(id) return DB and DB.tracked and DB.tracked[id] ~= nil end

function ns.toggleTrack(id)
    if not (DB and id) then return end
    DB.tracked = DB.tracked or {}
    if DB.tracked[id] then
        DB.tracked[id] = nil
        say((C_Item.GetItemNameByID(id) or "that item") .. ": no longer tracked.")
    else
        DB.tracked[id] = time()
        -- it shows in Items from now on, looked at or not
        local key = "item:" .. id
        if not DB.subjects[key] then
            DB.subjects[key] = { kind = "item", id = id, name = C_Item.GetItemNameByID(id) or (ns.itemNames and ns.itemNames[id]),
                first = time(), last = time(), looks = 0, secs = 0, events = {} }
        end
        local p = seen[id] or (DB.lastScan and DB.lastScan.prices[id] and { min = DB.lastScan.prices[id][1], q = DB.lastScan.prices[id][2], src = "scan" })
        if p then notePrice(id, p.min, p.q, p.src, true) end
        say((C_Item.GetItemNameByID(id) or "that item") .. ": tracking its price and listings at every scan from now on.")
    end
    if ns.refresh then ns.refresh() end
end

-- What you post on the auction house, so the ledger can show posted against sold.
local pendingPost
local function postHook(kind)
    return function(item, _, quantity, a, b)
        local ok, id = pcall(C_Item.GetItemID, item)
        if not ok or not id then return end
        local unit = kind == "commodity" and a or (b and quantity and quantity > 0 and math.floor(b / quantity)) or a
        pendingPost = { i = id, q = quantity or 1, unit = unit }
    end
end
local function hookPosting()
    if not C_AuctionHouse then return end
    for _, f in ipairs({ "PostCommodity", "ConfirmPostCommodity" }) do
        if C_AuctionHouse[f] then hooksecurefunc(C_AuctionHouse, f, postHook("commodity")) end
    end
    for _, f in ipairs({ "PostItem", "ConfirmPostItem" }) do
        if C_AuctionHouse[f] then hooksecurefunc(C_AuctionHouse, f, postHook("item")) end
    end
end

-- ---------------------------------------------------------------- shared with ui.lua

ns.latestPrice, ns.money, ns.pctText, ns.shortDate, ns.scan, ns.say = latestPrice, money, pctText, shortDate, scan, say
ns.PROF_NAMES = PROF_NAMES
local function toggle() if ns.toggle then ns.toggle() end end
local function refresh() if ns.refresh then ns.refresh() end end

-- ---------------------------------------------------------------- events and commands

local ev = CreateFrame("Frame")
ev:RegisterEvent("PLAYER_LOGIN")
ev:RegisterEvent("PLAYER_LOGOUT")
ev:RegisterEvent("AUCTION_HOUSE_SHOW")
ev:RegisterEvent("AUCTION_HOUSE_CLOSED")
ev:RegisterEvent("AUCTION_HOUSE_BROWSE_RESULTS_UPDATED")
ev:RegisterEvent("AUCTION_HOUSE_BROWSE_RESULTS_ADDED")
ev:RegisterEvent("COMMODITY_SEARCH_RESULTS_UPDATED")
ev:RegisterEvent("ITEM_SEARCH_RESULTS_UPDATED")
ev:RegisterEvent("REPLICATE_ITEM_LIST_UPDATE")
ev:RegisterEvent("SKILL_LINES_CHANGED")
ev:RegisterUnitEvent("UNIT_SPELLCAST_SUCCEEDED", "player") -- your casts only: crafts for the ledger
ev:RegisterEvent("COMMODITY_PRICE_UPDATED")
ev:RegisterEvent("COMMODITY_PURCHASE_SUCCEEDED")
ev:RegisterEvent("ITEM_PURCHASED")
ev:RegisterEvent("MAIL_SHOW")
ev:RegisterEvent("MAIL_INBOX_UPDATE")
ev:RegisterEvent("MAIL_CLOSED")
ev:RegisterEvent("MERCHANT_CLOSED")
ev:RegisterEvent("PLAYER_MONEY")
ev:RegisterEvent("AUCTION_HOUSE_AUCTION_CREATED")
ev:RegisterEvent("TRAINER_SHOW")
ev:RegisterEvent("TRAINER_UPDATE")
ev:RegisterEvent("MERCHANT_SHOW")
ev:RegisterEvent("MERCHANT_UPDATE")

local talentCheckQueued
ev:SetScript("OnEvent", function(_, event, ...)
    if event == "PLAYER_LOGIN" then
        initDB()
        TooltipDataProcessor.AddTooltipPostCall(TooltipDataProcessor.AllTypes, onTooltip)
        GameTooltip:HookScript("OnHide", function() hidden(GameTooltip) end)
        ItemRefTooltip:HookScript("OnHide", function() hidden(ItemRefTooltip) end)
        if ns.makeMinimapButton then ns.makeMinimapButton() end
        if ns.loadAnswers then C_Timer.After(3, ns.loadAnswers) end
        hookAuctionHouse()
        hookPosting()
        lastMoney = GetMoney()
        C_Timer.After(5, saveProfessions)
    elseif event == "PLAYER_LOGOUT" then
        for _, tip in ipairs({ GameTooltip, ItemRefTooltip }) do
            local c = tracking[tip]
            finish(tip, c and c.hiddenAt)
        end
    elseif event == "AUCTION_HOUSE_SHOW" then
        ahOpenedItem = nil
        ahHouse = houseFaction()
        if ahHouse ~= UnitFactionGroup("player") then say("this is the " .. (ahHouse == "Neutral" and "neutral (goblin)" or ahHouse) .. " auction house: its prices are kept apart from yours.") end
    elseif event == "AUCTION_HOUSE_CLOSED" then
        ahOpenedItem = nil
    elseif event == "AUCTION_HOUSE_BROWSE_RESULTS_UPDATED" then
        onBrowse()
    elseif event == "AUCTION_HOUSE_BROWSE_RESULTS_ADDED" then
        onBrowse((...))
    elseif event == "COMMODITY_SEARCH_RESULTS_UPDATED" then
        onCommodity((...))
    elseif event == "ITEM_SEARCH_RESULTS_UPDATED" then
        onItemSearch((...))
    elseif event == "REPLICATE_ITEM_LIST_UPDATE" then
        if scanPending then waitForScan() end
    elseif event == "UNIT_SPELLCAST_SUCCEEDED" then
        onCast(...)
    elseif event == "COMMODITY_PRICE_UPDATED" then
        local _, total = ...
        if pendingCommodity then pendingCommodity.c = total end
    elseif event == "COMMODITY_PURCHASE_SUCCEEDED" then
        local c = pendingCommodity
        if c and c.c then ledger({ k = "ahbuy", i = c.i, n = C_Item.GetItemNameByID(c.i) or ("item " .. c.i), q = c.q, c = -c.c }) end
        pendingCommodity = nil
    elseif event == "ITEM_PURCHASED" then
        local itemID = ...
        if pendingBid and itemID then
            ledger({ k = "ahbuy", i = itemID, n = C_Item.GetItemNameByID(itemID) or ("item " .. itemID), q = 1, c = -pendingBid.c })
        end
        pendingBid = nil
    elseif event == "MAIL_SHOW" then
        mailOpen = true
        snapshotMail(true)
    elseif event == "MAIL_INBOX_UPDATE" then
        if mailOpen then snapshotMail() end
    elseif event == "MAIL_CLOSED" then
        mailOpen = false
        invoices = {}
    elseif event == "PLAYER_MONEY" then
        onMoney()
    elseif event == "AUCTION_HOUSE_AUCTION_CREATED" then
        local p = pendingPost
        pendingPost = nil
        if p then ledger({ k = "post", i = p.i, n = C_Item.GetItemNameByID(p.i) or ("item " .. p.i), q = p.q, c = 0, unit = p.unit }) end
    elseif event == "SKILL_LINES_CHANGED" then
        if DB then saveProfessions() end
    elseif event == "TRAINER_SHOW" or event == "TRAINER_UPDATE" then
        -- the list fills a moment after the window opens; UPDATE comes often, so one read waits at a time
        if not trainerQueued then
            trainerQueued = true
            C_Timer.After(0.5, readTrainer)
        end
    elseif event == "MERCHANT_SHOW" or event == "MERCHANT_UPDATE" then
        merchantOpen = true
        C_Timer.After(0.2, readVendor)
    elseif event == "MERCHANT_CLOSED" then
        merchantOpen = false
    end
end)

SLASH_MINDCOLLECTORASK1 = "/ask"
SlashCmdList.MINDCOLLECTORASK = function(msg) if ns.ask then ns.ask(msg) end end

SLASH_MINDCOLLECTOROBSERVER1 = "/mco"
SlashCmdList.MINDCOLLECTOROBSERVER = function(msg)
    local cmd, rest = (msg or ""):match("^%s*(%S*)%s*(.-)%s*$")
    cmd = cmd:lower()
    if cmd == "" then
        toggle()
    elseif cmd == "scan" then
        scan()
    elseif cmd == "why" then
        local s = DB.lastKey and DB.subjects[DB.lastKey]
        local e = s and s.events[#s.events]
        if not e or rest == "" then say("usage: /mco why <why you were looking at the last thing>") return end
        e.why = rest
        say(string.format("noted for %s: %s (the guess was: %s)", s.name or DB.lastKey, rest, e.g or "none"))
        refresh()
    elseif cmd == "clear" then
        if ns.clearPins then ns.clearPins() end
        say("map points cleared.")
    elseif cmd == "min" then
        local v = tonumber(rest)
        if v and v >= 0.5 then DB.minDwell = v say("a look now needs " .. v .. "s.") else say("usage: /mco min 2.5") end
    else
        say("/mco (window), /mco scan, /mco why <text>, /mco min <seconds>, /mco clear (map points), /ask <question>")
    end
end
