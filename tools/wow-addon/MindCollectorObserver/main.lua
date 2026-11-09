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
    DB.version = 1
    DB.minDwell = DB.minDwell or 2.5
    DB.minChange = DB.minChange or 0.10
    DB.subjects = DB.subjects or {}   -- ["item:2840"] = {kind, id, name, first, last, looks, secs, events = {...}, notes = {...}}
    DB.prices = DB.prices or {}       -- [itemID] = { {d = "2026-11-09", t, min, q, src}, ... } for things you looked at
    DB.lastScan = DB.lastScan or nil  -- {t, n, prices = {[itemID] = {min, q}}}
    DB.prevScan = DB.prevScan or nil
    DB.told = DB.told or {}           -- [subjectKey] = time we last reported a change
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
    if not DB.subjects["item:" .. itemID] then
        -- only keep a history for things you have looked at; the full scan keeps everything else
        return
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
    if not data or data.type == nil then return end
    if ns.owns and ns.owns(tooltip:GetOwner()) then return end -- the addon's own window
    local T = Enum.TooltipDataType
    local ctx = contextOf(tooltip)
    local firstLine = data.lines and data.lines[1] and data.lines[1].leftText
    if data.type == T.Item then
        local id = data.id
        if not id then return end
        begin(tooltip, "item", id, C_Item.GetItemNameByID(id) or firstLine, ctx)
    elseif data.type == T.Spell then
        if ctx == "talents" then
            -- the talent hook records these with their rank; this only covers it if that hook never fired
            local c = tracking[tooltip]
            if not (c and c.kind == "talent") then begin(tooltip, "talent", data.id, C_Spell.GetSpellName(data.id), ctx) end
            return
        end
        begin(tooltip, "spell", data.id, C_Spell.GetSpellName(data.id) or firstLine, ctx)
    elseif data.type == T.Unit then
        if UnitExists("mouseover") and UnitIsPlayer("mouseover") then
            local name, realm = UnitName("mouseover")
            local _, class = UnitClass("mouseover")
            begin(tooltip, "player", name .. (realm and realm ~= "" and ("-" .. realm) or ""),
                string.format("%s (%s %s)", name, UnitLevel("mouseover") or "?", class or "?"), ctx,
                { class = class, level = UnitLevel("mouseover") })
        end
    else
        local kind = "other"
        for k, v in pairs(T) do if v == data.type then kind = k:lower() end end
        if data.id or firstLine then begin(tooltip, kind, data.id or firstLine, firstLine, ctx) end
    end
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

local function onBrowse(results)
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
    if info then notePrice(itemID, info.unitPrice, C_AuctionHouse.GetCommoditySearchResultsQuantity(itemID), "item page") end
end

local function onItemSearch(itemKey)
    if not itemKey or not itemKey.itemID then return end
    openedItem(itemKey.itemID)
    local info = C_AuctionHouse.GetItemSearchResultInfo(itemKey, 1)
    if info and info.buyoutAmount then
        notePrice(itemKey.itemID, info.buyoutAmount, C_AuctionHouse.GetItemSearchResultsQuantity(itemKey), "item page")
    end
end

local scanPending, scanTimer

local function finishScan(agg, n)
    DB.prevScan = DB.lastScan
    DB.lastScan = { t = time(), n = n, prices = agg }
    local moved, items = 0, 0
    for id, a in pairs(agg) do
        items = items + 1
        if DB.subjects["item:" .. id] then
            notePrice(id, a[1], a[2], "scan", true)
            if checkChange("item:" .. id, true) then moved = moved + 1 end
        end
    end
    say(string.format("scanned %d auctions, %d items. %d thing%s you looked at moved since; /mco to see.",
        n, items, moved, moved == 1 and "" or "s"))
    if ns.refresh then ns.refresh() end
end

local function readScan()
    local n = C_AuctionHouse.GetNumReplicateItems()
    local agg, i = {}, 0
    local ticker
    ticker = C_Timer.NewTicker(0, function()
        local stop = math.min(i + 2000, n)
        while i < stop do
            local name, _, count, _, _, _, _, _, _, buyout, _, _, _, _, _, _, itemID = C_AuctionHouse.GetReplicateItemInfo(i)
            if itemID and buyout and buyout > 0 and count and count > 0 then
                local unit = buyout / count
                local a = agg[itemID]
                if not a then agg[itemID] = { unit, count, name } -- the name too, so the app can show items you never hovered
                else
                    if unit < a[1] then a[1] = unit end
                    a[2] = a[2] + count
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

local function scan()
    if not (AuctionHouseFrame and AuctionHouseFrame:IsShown()) then
        say("open the auction house first.")
        return
    end
    scanPending = true
    local ok, err = pcall(C_AuctionHouse.ReplicateItems)
    if not ok then
        scanPending = false
        say("the full scan isn't allowed here: " .. tostring(err))
        return
    end
    say("scanning the whole auction house; this takes a few seconds.")
    if scanTimer then scanTimer:Cancel() end
    scanTimer = C_Timer.NewTimer(60, function()
        if scanPending then
            scanPending = false
            say("no scan came back. The server may allow one scan every 15 minutes, or not at all in Forever.")
        end
    end)
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
ev:RegisterEvent("SPELLS_CHANGED")

local talentCheckQueued
ev:SetScript("OnEvent", function(_, event, ...)
    if event == "PLAYER_LOGIN" then
        initDB()
        TooltipDataProcessor.AddTooltipPostCall(TooltipDataProcessor.AllTypes, onTooltip)
        GameTooltip:HookScript("OnHide", function() hidden(GameTooltip) end)
        ItemRefTooltip:HookScript("OnHide", function() hidden(ItemRefTooltip) end)
        if EventRegistry then EventRegistry:RegisterCallback("TalentDisplay.TooltipHook", onTalentTooltip, ns) end
        if ns.makeMinimapButton then ns.makeMinimapButton() end
        if ns.loadAnswers then C_Timer.After(3, ns.loadAnswers) end
        C_Timer.After(8, function() checkTalents() end)
    elseif event == "PLAYER_LOGOUT" then
        for _, tip in ipairs({ GameTooltip, ItemRefTooltip }) do
            local c = tracking[tip]
            finish(tip, c and c.hiddenAt)
        end
    elseif event == "AUCTION_HOUSE_SHOW" then
        ahOpenedItem = nil
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
        if scanPending then
            scanPending = false
            readScan()
        end
    elseif event == "SPELLS_CHANGED" then
        if DB and not talentCheckQueued then
            talentCheckQueued = true
            C_Timer.After(2, function() talentCheckQueued = false; checkTalents() end)
        end
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
