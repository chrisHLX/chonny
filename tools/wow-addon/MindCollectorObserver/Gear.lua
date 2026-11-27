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

-- In the game and not: once the app has sent the sweep only the counts are kept (main.lua's initDB).
local function counts(s)
    if not s.got then return s.gotN or 0, s.goneN or 0 end
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

-- The bonus count at field `at` and the bonus numbers after it, as "12751" (or "a.b" for several).
-- A bonus number is never one digit, which keeps a context field from being read as one.
local function bonusesAt(f, at)
    local n = tonumber(f[at] or "")
    if not n or n < 1 or n > 16 then return nil end
    local b = {}
    for k = at + 1, at + n do
        local x = tonumber(f[k] or "")
        if not x or x < 10 then return nil end
        b[#b + 1] = f[k]
    end
    return table.concat(b, ".")
end

-- The link's bonus numbers, or nil when it has none. Forever writes two layouts (read in game,
-- 2026-11-27): an auction house link has a context field before the count,
-- item:1994::::::::1:1489::1:1:12727:1:28:5255, and a shift-clicked one does not,
-- item:15602::::::::41:1485::1:12751. 0.6.39 read only the second, so every auction's key was "1".
local function bonusKey(link)
    local s = link:match("item:([%-%d:]+)")
    if not s then return nil end
    local f = {}
    for v in (s .. ":"):gmatch("([^:]*):") do f[#f + 1] = v end
    return bonusesAt(f, 13) or bonusesAt(f, 12)
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

-- From finishScan: each suffix version not seen before gets its name, stats and link (for its tooltip
-- in the Gear tab), a few a second. The app sends only the name and stats.
function ns.gearVariantsAfterScan()
    local DB = ns.DB
    if not DB then return end
    DB.variants = DB.variants or {}
    local todo = {}
    for key, link in pairs(scanLinks) do
        -- one kept before 27 Nov has no link (its third field): read it again for the Gear tab's tooltip
        if not DB.variants[key] or not DB.variants[key][3] then todo[#todo + 1] = { key, link, 0 } end
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
            DB.variants[x[1]] = { name, stats(x[2]), x[2] }
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

-- ---------------------------------------------------------------- the Gear tab (27 Nov)
-- The best gear on the auction house for this character, by the stats the player puts in order
-- (Chriso, 27 Nov: a list of stats you move up and down, say Intellect, Spirit, Stamina, and gear is
-- ranked by it; one stat on its own works too). Candidates are the items in the newest prices (your
-- scan, or everyone's pooled ones) that the game says this character can use (C_PlayerInfo.CanUseItem)
-- at their level; suffix versions from your own scan come in on their own.
--
-- How gear is ranked, as Chriso put it: an item must have your first stat; the ones with all your
-- stats come first, then those missing one, and so on; among those, the ones with your higher stats
-- first (Intellect and Spirit before Intellect and Stamina); then the most of your first stat, then of
-- your second. What you wear in the slot is ranked the same way, so an item ranked above it is better.
-- Hit and crit count as the tooltip's % (10 hit rating, 14 crit rating to 1% at any level).

-- key, label, short label, the game's stat keys with a factor
ns.GEAR_STATS = {
    { "str", "Strength", "Str", { { "STRENGTH", 1 } } },
    { "agi", "Agility", "Agi", { { "AGILITY", 1 } } },
    { "sta", "Stamina", "Sta", { { "STAMINA", 1 } } },
    { "int", "Intellect", "Int", { { "INTELLECT", 1 } } },
    { "spi", "Spirit", "Spi", { { "SPIRIT", 1 } } },
    { "armor", "Armor", "Armor", { { "RESISTANCE0_NAME", 1 } } },
    { "ap", "Attack Power", "AP", { { "ATTACK_POWER", 1 }, { "MELEE_ATTACK_POWER", 1 } } },
    { "rap", "Ranged Attack Power", "RAP", { { "ATTACK_POWER", 1 }, { "RANGED_ATTACK_POWER", 1 } } },
    { "crit", "Crit %", "Crit %", { { "CRIT_RATING", 1 / 14 } } },
    { "hit", "Hit %", "Hit %", { { "HIT_RATING", 1 / 10 } } },
    { "sp", "Spell Damage", "Spell dmg", { { "SPELL_POWER", 1 }, { "SPELL_DAMAGE_DONE", 1 } } },
    { "heal", "Healing", "Healing", { { "SPELL_POWER", 1 }, { "SPELL_HEALING_DONE", 1 } } },
    { "mp5", "Mana per 5 sec", "MP5", { { "MANA_REGENERATION", 1 } } },
    { "def", "Defense", "Def", { { "DEFENSE_SKILL_RATING", 1 } } },
    { "dodge", "Dodge rating", "Dodge", { { "DODGE_RATING", 1 } } },
    { "parry", "Parry rating", "Parry", { { "PARRY_RATING", 1 } } },
    { "block", "Block rating", "Block", { { "BLOCK_RATING", 1 } } },
    { "dps", "Weapon DPS", "DPS", { { "DAMAGE_PER_SECOND", 1 } } },
}
local STAT_BY_KEY = {}
for _, s in ipairs(ns.GEAR_STATS) do STAT_BY_KEY[s[1]] = s end
ns.GEAR_STAT_BY_KEY = STAT_BY_KEY

-- name, the slots an item can have, the character sheet slots it replaces (the weaker of two rings or trinkets)
ns.GEAR_SLOTS = {
    { "Head", { "INVTYPE_HEAD" }, { 1 } }, { "Neck", { "INVTYPE_NECK" }, { 2 } },
    { "Shoulder", { "INVTYPE_SHOULDER" }, { 3 } }, { "Back", { "INVTYPE_CLOAK" }, { 15 } },
    { "Chest", { "INVTYPE_CHEST", "INVTYPE_ROBE" }, { 5 } }, { "Wrist", { "INVTYPE_WRIST" }, { 9 } },
    { "Hands", { "INVTYPE_HAND" }, { 10 } }, { "Waist", { "INVTYPE_WAIST" }, { 6 } },
    { "Legs", { "INVTYPE_LEGS" }, { 7 } }, { "Feet", { "INVTYPE_FEET" }, { 8 } },
    { "Finger", { "INVTYPE_FINGER" }, { 11, 12 } }, { "Trinket", { "INVTYPE_TRINKET" }, { 13, 14 } },
    { "One-hand", { "INVTYPE_WEAPON", "INVTYPE_WEAPONMAINHAND" }, { 16 } }, { "Two-hand", { "INVTYPE_2HWEAPON" }, { 16 } },
    { "Off hand", { "INVTYPE_WEAPONOFFHAND", "INVTYPE_SHIELD", "INVTYPE_HOLDABLE" }, { 17 } },
    { "Ranged", { "INVTYPE_RANGED", "INVTYPE_RANGEDRIGHT", "INVTYPE_THROWN", "INVTYPE_RELIC" }, { 18 } },
}
local SLOT_OF = {}
for _, s in ipairs(ns.GEAR_SLOTS) do for _, loc in ipairs(s[2]) do SLOT_OF[loc] = s[1] end end

local function charKey() return UnitName("player") or "?" end

-- This character's stats, most wanted first: { "int", "spi", "sta" }.
function ns.gearOrder()
    local DB = ns.DB
    return DB and DB.gearOrder and DB.gearOrder[charKey()] or {}
end

local function saveOrder(order)
    ns.DB.gearOrder = ns.DB.gearOrder or {}
    ns.DB.gearOrder[charKey()] = order
end

-- The stat panel's buttons: add a stat at the bottom, take one out, move one up (-1) or down (+1).
function ns.gearAdd(key)
    local order = ns.gearOrder()
    for _, k in ipairs(order) do if k == key then return end end
    if not STAT_BY_KEY[key] then return end
    local copy = { unpack(order) }
    copy[#copy + 1] = key
    saveOrder(copy)
end

function ns.gearRemove(key)
    local copy = {}
    for _, k in ipairs(ns.gearOrder()) do if k ~= key then copy[#copy + 1] = k end end
    saveOrder(copy)
end

function ns.gearMove(key, step)
    local copy = { unpack(ns.gearOrder()) }
    for i, k in ipairs(copy) do
        local j = i + step
        if k == key and copy[j] then
            copy[i], copy[j] = copy[j], copy[i]
            break
        end
    end
    saveOrder(copy)
end

-- The picked stats' values in an item, from its stats table (keys as stats() gives them).
local function valuesOf(st, order)
    local values = {}
    for _, key in ipairs(order) do
        local v = 0
        for _, f in ipairs(STAT_BY_KEY[key] and STAT_BY_KEY[key][4] or {}) do v = v + (st and st[f[1]] or 0) * f[2] end
        values[key] = v
    end
    return values
end

-- Whether values a rank above values b for this order (the rule at the top of this section); nil
-- when they rank the same.
local function above(a, b, order)
    local na, nb = 0, 0
    for _, k in ipairs(order) do
        if a[k] > 0 then na = na + 1 end
        if b[k] > 0 then nb = nb + 1 end
    end
    if na ~= nb then return na > nb end
    for _, k in ipairs(order) do
        if (a[k] > 0) ~= (b[k] > 0) then return a[k] > 0 end
    end
    for _, k in ipairs(order) do
        if math.abs(a[k] - b[k]) > 0.001 then return a[k] > b[k] end
    end
    return nil
end
ns.gearAbove = above

local function shown(key, v)
    local r = math.floor(v * 10 + 0.5) / 10
    local s = r % 1 == 0 and string.format("%d", r) or string.format("%.1f", r)
    return (key == "crit" or key == "hit") and (s .. "%") or s
end

-- "Int 12  ·  Spi 4": the picked stats an item has, in the order picked.
function ns.gearValuesText(values, order)
    local out = {}
    for _, k in ipairs(order) do
        if values[k] > 0 then out[#out + 1] = STAT_BY_KEY[k][3]:gsub(" %%", "") .. " " .. shown(k, values[k]) end
    end
    return table.concat(out, "  ·  ")
end

-- What an item changes against what you wear, in the order picked: { {"+6 Int", true}, {"-3 Sta", false} }.
-- Stats that don't change are left out.
function ns.gearDiff(values, mine, order)
    local out = {}
    for _, k in ipairs(order) do
        local d = values[k] - (mine and mine[k] or 0)
        if math.abs(d) > 0.001 then
            out[#out + 1] = { (d > 0 and "+" or "-") .. shown(k, math.abs(d)) .. " " .. STAT_BY_KEY[k][3]:gsub(" %%", ""), d > 0 }
        end
    end
    return out
end

-- What the game says about one item, kept for the session: nil while it isn't loaded. Items the client
-- lacks are asked for, at most ASK_AT_ONCE each time the tab draws: the sweep asks 25 a second, and a
-- first look could otherwise send a few thousand requests at once.
local ASK_AT_ONCE = 150
local info, asked, budget = {}, {}, 0
local function itemInfo(id)
    local i = info[id]
    if i then return i end
    if not C_Item.IsItemDataCachedByID(id) then
        if not asked[id] and budget > 0 then
            asked[id] = true
            budget = budget - 1
            C_Item.RequestLoadItemDataByID(id)
        end
        return nil
    end
    local name, link, q, _, req, _, _, _, loc, icon = C_Item.GetItemInfo(id)
    if not name then return nil end
    local ok, usable = pcall(C_PlayerInfo.CanUseItem, id)
    i = { id = id, name = name, link = link, q = q or 1, req = req or 0, loc = loc, icon = icon, stats = stats(link),
        usable = not ok or usable ~= false }
    info[id] = i
    return i
end

-- What you wear in a slot: of its places (two rings, two trinkets) the one ranked lower, since that's
-- the one an upgrade replaces. { name, link, values, empty }.
local function wearing(places, order)
    local worst
    for _, inv in ipairs(places) do
        local link = GetInventoryItemLink("player", inv)
        local w = { link = link, empty = link == nil, values = valuesOf(link and stats(link), order),
            name = link and C_Item.GetItemInfo(link) }
        if not worst or above(worst.values, w.values, order) then worst = w end
    end
    return worst
end

-- The Gear tab's content: per slot what you wear and what's on the auction house, best first.
-- { order, slots = { {name, mine, list = { {name, id, link, icon, q, req, price, when, values, better}, ... }} }, loading = n }
-- Kept until the prices, the stats picked, your level or what you wear change, and while nothing is
-- still loading: the list and the details both draw from it, and it walks every priced item.
local viewCache
function ns.gearView()
    local order = ns.gearOrder()
    local DB = ns.DB
    local level = UnitLevel("player") or 60
    local worn = {}
    for inv = 1, 18 do worn[inv] = GetInventoryItemLink("player", inv) or "" end
    local key = table.concat({ DB.lastScan and DB.lastScan.t or 0, ns.pool and ns.pool.t or 0, table.concat(order, ","), level,
        table.concat(worn, ";") }, "|")
    if viewCache and viewCache.key == key then return viewCache.view end
    local out = { order = order, slots = {}, loading = 0 }
    if #order == 0 then return out end
    local first = order[1]
    budget = ASK_AT_ONCE
    local scan = DB.lastScan and DB.lastScan.prices or {}
    local pool = ns.pool and ns.pool.items or {}
    local seen, bySlot = {}, {}
    local function add(slot, x)
        bySlot[slot] = bySlot[slot] or {}
        table.insert(bySlot[slot], x)
    end
    local function consider(id)
        if seen[id] then return end
        seen[id] = true
        local _, _, _, loc, _, classID = C_Item.GetItemInfoInstant(id)
        local slot = SLOT_OF[loc or ""]
        if not slot or (classID ~= 2 and classID ~= 4) then return end
        local it = itemInfo(id)
        if not it then
            out.loading = out.loading + 1
            return
        end
        if not it.usable or it.req > level then return end
        local a = scan[id]
        if type(a) == "table" and a.v then
            -- suffix versions: each priced and described on its own, the base item not at all
            for bonus, v in pairs(a.v) do
                local var = DB.variants and DB.variants[id .. ":" .. bonus]
                if var then
                    local values = valuesOf(var[2], order)
                    if values[first] > 0 then
                        add(slot, { name = var[1], id = id, link = var[3], icon = it.icon, q = it.q, req = it.req, price = v[1],
                            when = DB.lastScan.t, values = values })
                    end
                end
            end
            return
        end
        local values = valuesOf(it.stats, order)
        if values[first] <= 0 then return end
        local p = ns.latestPrice(id)
        if not p then return end
        add(slot, { name = it.name, id = id, link = it.link, icon = it.icon, q = it.q, req = it.req, price = p.min, when = p.t, values = values })
    end
    for id in pairs(scan) do if type(id) == "number" then consider(id) end end
    for id in pairs(pool) do if type(id) == "number" then consider(id) end end
    for _, slot in ipairs(ns.GEAR_SLOTS) do
        local list = bySlot[slot[1]] or {}
        table.sort(list, function(a, b)
            local r = above(a.values, b.values, order)
            if r ~= nil then return r end
            return a.price < b.price
        end)
        local mine = wearing(slot[3], order)
        for _, x in ipairs(list) do x.better = above(x.values, mine.values, order) == true end
        table.insert(out.slots, { name = slot[1], mine = mine, list = list })
    end
    viewCache = out.loading == 0 and { key = key, view = out } or nil
    return out
end

-- What the Gear tab keeps while it's open (27 Nov): what the game said of each item it ranked, thousands
-- of small tables, and the ranked lists. The window drops them when it closes or you leave the tab;
-- coming back reads the items again from the game's own cache, which is quick.
function ns.gearForget()
    info, viewCache = {}, nil
end

-- ---------------------------------------------------------------- events

local f = CreateFrame("Frame")
f:RegisterEvent("ITEM_DATA_LOAD_RESULT")
local redrawQueued
f:SetScript("OnEvent", function(_, _, id, success)
    if sweep and pending[id] then settle(id, success) end
    -- an item the Gear tab asked for arrived: draw again once a batch is in
    if asked[id] and not redrawQueued and ns.refresh then
        redrawQueued = true
        C_Timer.After(1, function() redrawQueued = false; ns.refresh() end)
    end
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
