-- Gear (2026-11-26): what the game itself says about weapons and armour, for a gear page on the website
-- (Chriso: the best gear a player can get at their level, from the auction house, vendors and drops).
-- Everything here is read from the game, never worked out:
--
--   /mco items       asks the game for every weapon and armour number in GearIds.lua (19,048) and keeps
--                    what comes back: name, quality, item level, required level, slot, type, the stats
--                    the game reports, weapon damage and speed, and the tooltip's effect and requirement
--                    lines ("Equip: ...", "Requires Frostwolf Clan - Honored"). The client's files hold
--                    stats for only 7,921 of those; the server sends the rest when asked, as it does for
--                    a loot addon's boss page. A number the server refuses is not in Forever (items of
--                    other WoW versions that share the file, and raid gear before its raid opens). Once
--                    per game build; it picks up where it stopped after a /reload.
--   suffix versions  a full scan keeps each "of the Bandit", "of the Monkey"... apart, with its own
--                    cheapest price and count (the scan's per-item `v`), and keeps each version's stats.
--                    Forever marks the suffix as a bonus number on the item link (Ancient Crown of the
--                    Bandit = item:15602 with bonus 12751), not the old suffix field.
--   vendor lists     every item a vendor sells, with its price in gold, honor or other items, how many
--                    are in stock, and any "Requires" line (reputation, PvP rank), each time you open one.
--
-- Measured on 2026-11-26 with a one-off probe: answers come back in about 0.01s, refusals in about
-- 0.35s, and 500 asked at 25 a second drew no complaint from the server. All ten items that the files
-- also describe came back with exactly the files' stats.

local _, ns = ...

local PACE = 25        -- requests a second
local TIMEOUT = 20     -- seconds to wait for an answer before counting it as no reply
local function say(m) if ns.say then ns.say(m) end end

-- ---------------------------------------------------------------- reading one item

local KEEP = { "^Equip: ", "^Use: ", "^Chance on hit: ", "^Requires ", "^Classes: ", "^Races: ", "^Unique" }

-- The tooltip lines worth keeping (effects and requirements, not the required level, which GetItemInfo
-- gives), and a weapon's damage and speed: "71 - 134 Damage | Speed 2.60" becomes {71, 134, 2.6}.
local function readTooltip(data)
    if type(data) ~= "table" or type(data.lines) ~= "table" then return nil, nil end
    local keep, dmg
    for i, l in ipairs(data.lines) do
        local left = l.leftText
        if i > 1 and type(left) == "string" and left ~= "" then
            local lo, hi = left:match("^(%d+) %- (%d+) .-Damage")
            local speed = type(l.rightText) == "string" and tonumber(l.rightText:match("([%d%.]+)$") or "")
            if lo and not dmg then
                dmg = { tonumber(lo), tonumber(hi), speed }
            elseif not left:match("^Requires Level") then
                for _, pattern in ipairs(KEEP) do
                    if left:match(pattern) then
                        keep = keep or {}
                        keep[#keep + 1] = left
                        break
                    end
                end
            end
        end
    end
    return keep, dmg
end

-- GetItemStats' keys without their wrapping: ITEM_MOD_STRENGTH_SHORT is STRENGTH, RESISTANCE0_NAME
-- (armour) stays as it is.
local function stats(link)
    local ok, t = pcall(C_Item.GetItemStats, link)
    if not ok or type(t) ~= "table" or not next(t) then return nil end
    local out = {}
    for k, v in pairs(t) do out[(k:gsub("^ITEM_MOD_", ""):gsub("_SHORT$", ""))] = v end
    return out
end

-- {name, quality, item level, required level, slot, class, subclass, bind, sell price, stats, lines, damage}
-- or nil while the game doesn't have it.
local function readItem(id)
    local name, link, q, ilvl, req, _, _, _, slot, _, sell, class, sub, bind = C_Item.GetItemInfo(id)
    if not name or not link then return nil end
    local ok, tip = pcall(C_TooltipInfo.GetItemByID, id)
    local lines, dmg = readTooltip(ok and tip or nil)
    return { name, q, ilvl, req, slot, class, sub, bind or 0, sell or 0, stats(link), lines, dmg }
end

-- ---------------------------------------------------------------- the sweep

local function gearIds()
    if not ns.gearIdList then
        local out, n = {}, 0
        for gap in (ns.gearGaps or ""):gmatch("%d+") do
            n = n + tonumber(gap)
            out[#out + 1] = n
        end
        ns.gearIdList = out
    end
    return ns.gearIdList
end

local function buildKey()
    local version, build = GetBuildInfo()
    return version .. "." .. build
end

local sweep      -- the state in DB.items while a sweep runs this session
local pending = {} -- [id] = when it was asked
local ticker

local function counts(s)
    local got, gone = 0, 0
    for _ in pairs(s.got) do got = got + 1 end
    for _ in pairs(s.gone) do gone = gone + 1 end
    return got, gone
end

local function finishSweep()
    if ticker then ticker:Cancel() ticker = nil end
    local s = sweep
    sweep = nil
    s.done = time()
    local got, gone = counts(s)
    local none = 0
    for _ in pairs(s.none) do none = none + 1 end
    say(string.format("items: done. %d in the game, %d the server says aren't, %d with no reply. /reload to save them for the website.", got, gone, none))
end

local function settle(id, success)
    local asked = pending[id]
    if not asked or not sweep then return end
    if success == false then
        sweep.gone[id] = true
    else
        local rec = readItem(id)
        if not rec then return end -- not in yet: wait for the next look
        sweep.got[id] = rec
    end
    pending[id] = nil
    sweep.none[id] = nil
end

local function step()
    local s = sweep
    if not s then return end
    -- never during a fight: the requests are cheap, but a fight is no time to find out otherwise
    if InCombatLockdown() or UnitAffectingCombat("player") then return end
    local ids = gearIds()
    local now = GetTime()
    -- IsItemDataCachedByID, not GetItemInfo: GetItemInfo on an item the client lacks asks the server
    -- again, and the probe asked each refused item about 20 times that way
    for id, at in pairs(pending) do
        if C_Item.IsItemDataCachedByID(id) then settle(id) end
        if pending[id] and now - at > TIMEOUT then
            pending[id] = nil
            s.none[id] = true
        end
    end
    local id = ids[s.pos]
    if id then
        s.pos = s.pos + 1
        if s.got[id] or s.gone[id] then return end
        local rec = C_Item.IsItemDataCachedByID(id) and readItem(id)
        if rec then
            s.got[id] = rec
        else
            pending[id] = now
            C_Item.RequestLoadItemDataByID(id)
        end
        if s.pos % 2000 == 0 then
            local got, gone = counts(s)
            say(string.format("items: %d of %d asked, %d in the game so far, %d not.", s.pos, #ids, got, gone))
        end
    elseif s.retried then
        if not next(pending) then finishSweep() end
    elseif not next(pending) then
        -- one more go for those with no reply
        s.retried = true
        for nid in pairs(s.none) do
            pending[nid] = now
            C_Item.RequestLoadItemDataByID(nid)
        end
    end
end

local function startSweep(again)
    local DB = ns.DB
    if not DB then return end
    if not (C_Item and C_Item.RequestLoadItemDataByID and C_Item.IsItemDataCachedByID and C_TooltipInfo and C_TooltipInfo.GetItemByID) then
        say("items: this game client can't be asked for items, so there's nothing to do.")
        return
    end
    if sweep then say(string.format("items: already asking (%d of %d).", sweep.pos, #gearIds())) return end
    local build = buildKey()
    if again or not DB.items or DB.items.build ~= build then
        DB.items = { build = build, t = time(), pos = 1, got = {}, gone = {}, none = {} }
    elseif DB.items.done then
        local got, gone = counts(DB.items)
        say(string.format("items: already read for this game build on %s: %d in the game, %d not. /mco items again to read them afresh.",
            date("%d %b", DB.items.done), got, gone))
        return
    end
    sweep = DB.items
    sweep.none = sweep.none or {}
    sweep.retried = nil
    -- carrying on after a /reload: the last few asked may not have been answered before it
    sweep.pos = math.max(1, (sweep.pos or 1) - PACE * 2)
    local left = #gearIds() - sweep.pos + 1
    say(string.format("items: asking the game for %d weapons and armour, %d a second (about %d minutes). It pauses in a fight and carries on after a /reload.",
        left, PACE, math.ceil(left / PACE / 60)))
    ticker = C_Timer.NewTicker(1 / PACE, step)
end

-- ---------------------------------------------------------------- suffix versions in a scan

local isGear = {}
local function gear(id)
    local g = isGear[id]
    if g == nil then
        local _, _, _, _, _, classID = C_Item.GetItemInfoInstant(id)
        g = classID == 2 or classID == 4
        isGear[id] = g
    end
    return g
end

-- The link's bonus numbers as "12751" (or "a.b" for several), or nil when it has none. Forever's link
-- is item:id:enchant:gem1-4:suffix:unique:level:spec:context:count:bonus...; 15602::::::::41:1485::1:12751.
local function bonusKey(link)
    local s = link:match("item:([%-%d:]+)")
    if not s then return nil end
    local f = {}
    for v in (s .. ":"):gmatch("([^:]*):") do f[#f + 1] = v end
    local n = tonumber(f[12] or "")
    if not n or n < 1 or n > 16 then return nil end
    local b = {}
    for k = 13, 12 + n do
        if not tonumber(f[k] or "") then return nil end
        b[#b + 1] = f[k]
    end
    return table.concat(b, ".")
end

local scanLinks = {} -- ["15602:12751"] = a link, for this scan's suffix versions

-- From main.lua's readScan, for each auction: a weapon or armour piece with a suffix gets its own price
-- and count in the item's `v`, by bonus.
function ns.gearVariant(a, index, id, unit, count)
    if not gear(id) or not C_AuctionHouse.GetReplicateItemLink then return end
    local ok, link = pcall(C_AuctionHouse.GetReplicateItemLink, index)
    local key = ok and type(link) == "string" and bonusKey(link)
    if not key then return end
    a.v = a.v or {}
    local v = a.v[key]
    if v then
        if unit < v[1] then v[1] = unit end
        v[2] = v[2] + count
    else
        a.v[key] = { unit, count }
    end
    scanLinks[id .. ":" .. key] = scanLinks[id .. ":" .. key] or link
end

-- From finishScan: each suffix version not seen before gets its name and stats, a few a second.
function ns.gearVariantsAfterScan()
    local DB = ns.DB
    if not DB then return end
    DB.variants = DB.variants or {}
    local todo = {}
    for key, link in pairs(scanLinks) do
        if not DB.variants[key] then todo[#todo + 1] = { key, link, 0 } end
    end
    scanLinks = {}
    if #todo == 0 then return end
    local i, t
    i = 0
    t = C_Timer.NewTicker(0.02, function()
        i = i + 1
        local x = todo[i]
        if not x then
            t:Cancel()
            return
        end
        local name = C_Item.GetItemInfo(x[2])
        if name then
            DB.variants[x[1]] = { name, stats(x[2]) }
        elseif x[3] < 3 then
            -- the base item isn't loaded yet: ask, and look again at the end
            x[3] = x[3] + 1
            C_Item.RequestLoadItemDataByID(tonumber(x[1]:match("^(%d+)")))
            todo[#todo + 1] = x
        end
    end)
end

-- ---------------------------------------------------------------- vendor lists

-- From main.lua's readVendor: the whole list, kept by the vendor's NPC number.
function ns.gearVendorList(here, who)
    local DB = ns.DB
    local guid = UnitGUID("npc")
    local npc = guid and tonumber(select(6, strsplit("-", guid)) or "")
    if not DB or not npc then return end
    local items = {}
    for i = 1, (GetMerchantNumItems() or 0) do
        local link = GetMerchantItemLink(i)
        local id = link and C_Item.GetItemInfoInstant(link)
        local info = C_MerchantFrame.GetItemInfo(i)
        if id and info then
            local costs
            if info.hasExtendedCost and GetMerchantItemCostInfo then
                for c = 1, (GetMerchantItemCostInfo(i) or 0) do
                    local _, value, costLink, currency = GetMerchantItemCostItem(i, c)
                    local costId = costLink and C_Item.GetItemInfoInstant(costLink)
                    if value then
                        costs = costs or {}
                        costs[#costs + 1] = { value, costId or currency or "?" }
                    end
                end
            end
            local reqs
            local ok, tip = pcall(C_TooltipInfo.GetMerchantItem, i)
            if ok and type(tip) == "table" and type(tip.lines) == "table" then
                for _, l in ipairs(tip.lines) do
                    local left = l.leftText
                    if type(left) == "string" and left:match("^Requires ") and not left:match("^Requires Level") then
                        reqs = reqs or {}
                        reqs[#reqs + 1] = left
                    end
                end
            end
            items[#items + 1] = { id, info.price or 0, info.stackCount or 1,
                (info.numAvailable or -1) >= 0 and info.numAvailable or nil, costs, reqs }
        end
    end
    if #items == 0 then return end
    DB.vendorLists = DB.vendorLists or {}
    DB.vendorLists[npc] = { name = who, zone = here.zone, sub = here.sub, mapID = here.mapID, x = here.x, y = here.y,
        t = time(), items = items }
end

-- ---------------------------------------------------------------- events

local f = CreateFrame("Frame")
f:RegisterEvent("ITEM_DATA_LOAD_RESULT")
f:SetScript("OnEvent", function(_, _, id, success)
    if sweep and pending[id] then settle(id, success) end
end)

function ns.itemSweep(arg)
    startSweep((arg or ""):lower() == "again")
end

-- What /mco items has so far, for the window or a question.
function ns.itemSweepState()
    local s = ns.DB and ns.DB.items
    if not s then return nil end
    local got, gone = counts(s)
    return { build = s.build, got = got, gone = gone, asked = s.pos - 1, of = #gearIds(), done = s.done, running = sweep ~= nil }
end
