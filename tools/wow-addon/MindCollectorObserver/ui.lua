-- The MindCollector Observer window: tabs by kind, a list on the left, the selected thing's details
-- on the right, and a box to say why you were looking. Black and gold, like the site.

local _, ns = ...

local GOLD = { 0.83, 0.69, 0.22 }
local GOLD_HEX = "ffd4af37"
local W, H, LIST_W, ROW_H = 920, 540, 570, 38
local BODY_W = W - LIST_W - 76 -- the details' scrolling text, inside its scroll bar

local frame, list, detail, search
local tab, selectedKey, query = "item", nil, ""
local rows, histLines = {}, {}
local moversCache -- the Market's walk over every scanned item, kept between redraws (see movers)

-- Items and professions only since 2026-11-10 (Chriso): talents, spells, players and the rest were noise.
local TABS = {
    { key = "item", label = "Tracked" },
    { key = "prof", label = "Professions" },
    { key = "movers", label = "Market" },
    { key = "ledger", label = "Ledger" },
    { key = "ask", label = "Ask" },
}
local selProf -- the profession open in the Professions tab

-- Where Wowhead's Forever data has been read (Data.lua ns.wh), its exact numbers replace the client
-- data's estimates: the skill a recipe is learned at, its colours, the trainer's fee, how many a craft
-- makes, and how it's obtained (2 drop, 4 quest, 5 vendor, 6 trainer).
for _, list in pairs(ns.recipes or {}) do
    for _, r in ipairs(list) do
        -- how many one craft makes, from the game data (field 12, 2026-11-12): Fire Oil 2, ammo 200
        if r[12] and r[12] > 1 then r.yield = r[12] end
        local w = ns.wh and ns.wh[r[1]]
        if w then
            if w[1] > 0 and w[1] < 9999 then r[3], r[9] = w[1], 1 end
            if w[3] > 0 then r[4] = w[3] end
            if w[5] > 0 then r[5] = w[5] end
            if w[4] > 0 then r.green = w[4] end
            r.fee, r.src = w[7], w[6]
            r.yield = (w[8] + w[9]) / 2
        end
    end
end

-- What each item goes into: every recipe, in any profession, that takes it as a reagent. Feeds the
-- "Used in" sections under an item's details (2026-11-11, Chriso: what a consumable can be used in).
local USES = {}
for pid, rl in pairs(ns.recipes or {}) do
    for idx, r in ipairs(rl) do
        for i = 1, #r[8], 2 do
            local id = r[8][i]
            USES[id] = USES[id] or {}
            table.insert(USES[id], { pid = pid, idx = idx, r = r, n = r[8][i + 1] })
        end
    end
end

-- ---------------------------------------------------------------- small helpers

local function when(t)
    if not t then return "" end
    local d = time() - t
    if d < 90 then return "just now" end
    if d < 3600 then return math.floor(d / 60) .. " min ago" end
    if date("%Y-%m-%d", t) == date("%Y-%m-%d") then return "today " .. date("%H:%M", t) end
    if date("%Y-%m-%d", t) == date("%Y-%m-%d", time() - 86400) then return "yesterday " .. date("%H:%M", t) end
    if d < 7 * 86400 then return math.floor(d / 86400 + 0.5) .. " days ago" end
    return date("%d %b", t)
end

local function color(hex, s) return "|c" .. hex .. s .. "|r" end
local function grey(s) return color("ff9a9a9a", s) end

-- An item's name. The game knows it only once it has loaded the item, and a full scan holds thousands
-- it never has, so their names came back empty (Chriso's screenshot, 2026-11-12). This asks the game
-- to load the item and redraws the window when names arrive; until then the scan's own name,
-- Data.lua's, or "item N". Only call it for what's on screen: each unknown id is one request.
local pendingNames, nameRedraw = {}, false
local function itemName(id, known)
    local n = C_Item.GetItemNameByID(id)
    if n and n ~= "" then return n end
    if known and known ~= "" then return known end
    if not pendingNames[id] and C_Item.RequestLoadItemDataByID then
        pendingNames[id] = true
        pcall(C_Item.RequestLoadItemDataByID, id)
    end
    return (ns.itemNames and ns.itemNames[id]) or ("item " .. id)
end
local nameEvents = CreateFrame("Frame")
nameEvents:RegisterEvent("GET_ITEM_INFO_RECEIVED")
nameEvents:SetScript("OnEvent", function(_, _, id)
    if not (id and pendingNames[id]) then return end
    pendingNames[id] = nil
    moversCache = nil -- a name arrived: the Market's rows and the search over them are built again
    -- kept in the scan too, so the app and later sessions have it
    local sc = ns.DB and ns.DB.lastScan and ns.DB.lastScan.prices and ns.DB.lastScan.prices[id]
    if sc and (not sc[3] or sc[3] == "") then sc[3] = C_Item.GetItemNameByID(id) end
    if not nameRedraw and frame and frame:IsShown() then
        nameRedraw = true
        C_Timer.After(0.5, function()
            nameRedraw = false
            if frame and frame:IsShown() and ns.refresh then ns.refresh() end
        end)
    end
end)

local function nameColor(s)
    if s.kind == "item" then
        local q = C_Item.GetItemQualityByID(s.id)
        if q then
            local _, _, _, hex = C_Item.GetItemQualityColor(q)
            if hex then return hex end
        end
        return "ffffffff"
    end
    if s.kind == "player" then
        local class = s.class or (s.name and s.name:match("%(%d+ (%u+)%)"))
        local c = class and RAID_CLASS_COLORS and RAID_CLASS_COLORS[class]
        if c and c.colorStr then return c.colorStr end
    end
    if s.kind == "talent" then return GOLD_HEX end
    return "ffffffff"
end

local function iconOf(kind, id)
    if kind == "item" then return C_Item.GetItemIconByID(id) end
    if kind == "talent" or kind == "spell" then return C_Spell.GetSpellTexture(id) end
    if kind == "player" then return "Interface\\Icons\\Achievement_Character_Human_Male" end
    return "Interface\\Icons\\INV_Misc_QuestionMark"
end

local function displayName(s)
    local n = s.name or (s.kind == "item" and itemName(s.id)) or (s.kind .. " " .. tostring(s.id))
    if s.kind == "player" then n = n:gsub(" %(.*%)$", "") end
    return n
end

-- price the first time you looked with a price, and the newest one we have
local function priceTrail(s)
    if s.kind ~= "item" then return end
    local first
    for _, e in ipairs(s.events) do
        if e.p then first = e break end
    end
    return first, ns.latestPrice(s.id)
end

local function changeText(was, now)
    if not (was and now) or was <= 0 then return "" end
    local p = (now - was) / was
    if math.abs(p) < 0.005 then return grey("no change") end
    return ns.pctText(p)
end

-- ---------------------------------------------------------------- an item's price over time

-- Every reading of an item's cheapest price and how many are listed, oldest first (13 Nov, Chriso:
-- "$4 at 12pm, $6 at 2pm, $3 at 8pm" — the Market compares only the last two scans, so up, down and
-- up again shows as the last move alone). From every full scan kept (DB.history, 30 days, whether or
-- not you tracked it then) and, for a tracked item, each time the auction house showed it (DB.track).
local function priceHistory(id)
    local out = {}
    local needle = ";" .. id .. ":"
    for _, h in ipairs(ns.DB.history or {}) do
        local at = h.s and h.s:find(needle, 1, true)
        if at then
            local price, q = h.s:match("^;%d+:(%d+):(%d+)", at)
            if price then table.insert(out, { t = h.t, min = tonumber(price), q = tonumber(q), scan = true }) end
        end
    end
    local function nearScan(t)
        for _, x in ipairs(out) do if x.scan and math.abs(x.t - t) < 300 then return true end end
        return false
    end
    for _, r in ipairs((ns.DB.track and ns.DB.track[id]) or {}) do
        if r.min and not nearScan(r.t) then table.insert(out, { t = r.t, min = r.min, q = r.q, scan = r.src == "scan" }) end
    end
    table.sort(out, function(a, b) return a.t < b.t end)
    return out
end

-- The readings as lines for an item's page, newest first, each with its change from the one before.
local function historyLines(id, lines, most)
    local h = priceHistory(id)
    if #h == 0 then return end
    table.insert(lines, "")
    table.insert(lines, color(GOLD_HEX, string.format("Every reading (%d): the cheapest price, how many listed, and the change", #h)))
    if #h > 1 then
        local lo, hi = h[1], h[1]
        for _, x in ipairs(h) do
            if x.min < lo.min then lo = x end
            if x.min > hi.min then hi = x end
        end
        table.insert(lines, grey(string.format("lowest %s on %s  ·  highest %s on %s", ns.money(lo.min), date("%d %b %H:%M", lo.t),
            ns.money(hi.min), date("%d %b %H:%M", hi.t))))
    end
    for i = #h, math.max(1, #h - (most or 40) + 1), -1 do
        local x, before = h[i], h[i - 1]
        local bits = { date("%a %d %b %H:%M", x.t), ns.money(x.min), (x.q or "?") .. " listed" }
        if before then
            local change = changeText(before.min, x.min)
            if change ~= "" then table.insert(bits, change) end
            if x.q and before.q then
                if x.q < before.q and x.min >= before.min then table.insert(bits, grey("fewer listed, price held"))
                elseif x.q > before.q and x.min <= before.min then table.insert(bits, grey("more listed, price no higher")) end
            end
        end
        if not x.scan then table.insert(bits, grey("seen at the auction house")) end
        table.insert(lines, table.concat(bits, "  ·  "))
    end
    if #h > (most or 40) then table.insert(lines, grey(string.format("and %d older", #h - (most or 40)))) end
end

local function matches(name)
    if query == "" then return true end
    return name and name:lower():find(query, 1, true)
end

-- ---------------------------------------------------------------- data for each tab

-- The Tracked tab (13 Nov, Chriso): the items you chose to track, from Market, Professions or an
-- item's page, newest first, not every item you held the mouse over. What you look at is still
-- recorded, for the app's "Looked at" tab and for Ask.
local function trackedItems()
    local out = {}
    for id, since in pairs(ns.DB.tracked or {}) do
        local key = "item:" .. id
        local s = ns.DB.subjects[key]
        if not s then   -- tracked before tracking made its row: made now, so its page opens
            s = { kind = "item", id = id, name = C_Item.GetItemNameByID(id) or (ns.itemNames and ns.itemNames[id]),
                first = since, last = since, looks = 0, secs = 0, events = {} }
            ns.DB.subjects[key] = s
        end
        if matches(s.name or itemName(id)) then table.insert(out, { key = key, s = s, since = since }) end
    end
    table.sort(out, function(a, b) return (a.since or 0) > (b.since or 0) end)
    return out
end

local function movers()
    local a, b = ns.DB.lastScan, ns.DB.prevScan
    if not (a and b) then return {}, nil end
    -- The walk over every scanned item (thousands, a name lookup each) is kept between redraws (14 Nov):
    -- a redraw came with every tooltip and every scan tick, and this walk ran each time. It only
    -- changes with a new scan, the search text, or a name arriving (the event above clears it).
    local cacheKey = (a.t or 0) .. ":" .. (b.t or 0) .. ":" .. query
    local out
    if moversCache and moversCache.key == cacheKey then
        out = moversCache.rows
    else
        out = {}
        for id, v in pairs(a.prices) do
            local old = b.prices[id]
            if old and old[1] > 0 and v[2] >= 5 then
                local name = C_Item.GetItemNameByID(id)
                if not name or name == "" then name = (v[3] ~= "" and v[3]) or (ns.itemNames and ns.itemNames[id]) end
                if matches(name or "") then
                    -- at least how many sold: fewer listed at a held price, past what could have run out
                    -- (12 Nov: Chriso's older scan had no time-left data, so Selling showed nothing at all.
                    -- Without it, the whole drop counts, said as "sold or ran out", not as sales.)
                    local sold, unsure = 0, false
                    local drop = old[2] - v[2]
                    if drop > 0 and v[1] >= old[1] then
                        if b.bands and old.tl then
                            local could = 0
                            for band, q in pairs(old.tl) do
                                local t = b.bands[band]
                                if not t or t[1] < a.t - b.t then could = could + q end
                            end
                            sold = math.max(0, drop - could)
                        end
                        -- Nothing proven (scans a day apart: every listing could have run out, which left
                        -- Selling empty on 12 Nov): the drop still shows, said as "sold or ran out".
                        if sold == 0 then sold, unsure = drop, true end
                    end
                    table.insert(out, { id = id, name = name, was = old[1], now = v[1], q = v[2], oq = old[2],
                        p = (v[1] - old[1]) / old[1], sold = sold, unsure = unsure })
                end
            end
        end
    moversCache = { key = cacheKey, rows = out }
    end
    -- the Sort menu (DB.marketSort): what each keeps, and its order
    local mode = ns.DB.marketSort or "move"
    local keep = {
        up = function(m) return m.p > 0 end,
        down = function(m) return m.p < 0 end,
        selling = function(m) return m.sold > 0 end,
        flood = function(m) return m.q > m.oq and m.now <= m.was end,
    }
    local key = {
        move = function(m) return math.abs(m.p) end,
        up = function(m) return m.p end,
        down = function(m) return -m.p end,
        selling = function(m) return (m.unsure and 0 or 1e9) + m.sold end,   -- proven sales first
        flood = function(m) return m.q - m.oq end,
        listed = function(m) return m.q end,
        gold = function(m) return m.now * m.q end,
    }
    local everything = out
    if keep[mode] then
        local kept = {}
        for _, m in ipairs(out) do if keep[mode](m) then table.insert(kept, m) end end
        out = kept
    end
    local k = key[mode] or key.move
    table.sort(out, function(x, y) return k(x) > k(y) end)
    return out, string.format("%s compared with %s", ns.shortDate(a.t), ns.shortDate(b.t)), everything
end

local function counts()
    local c = { item = 0 }
    for _ in pairs(ns.DB.tracked or {}) do c.item = c.item + 1 end
    c.ask = #(ns.DB.asks or {})
    return c
end

-- ---------------------------------------------------------------- the ledger

local LEDGER_KIND = { craft = "made", post = "posted on the auction house", ahbuy = "bought at the auction house", ahsale = "sold at the auction house",
    vbuy = "bought from a vendor", vsell = "sold to a vendor" }

local function signedMoney(c)
    if c == 0 then return "0" end
    return (c > 0 and color("ff40d060", "+" .. ns.money(c))) or color("ffe05050", "-" .. ns.money(-c))
end

-- Totals since a time: crafts, spent (negative copper), earned (positive copper).
local function ledgerTotals(since)
    local t = { made = 0, spent = 0, earned = 0, value = 0 }
    for _, e in ipairs(ns.DB.ledger or {}) do
        if e.t >= since then
            if e.k == "craft" then t.made = t.made + (e.q or 1); t.value = t.value + (e.v or 0)
            elseif e.c < 0 then t.spent = t.spent - e.c
            else t.earned = t.earned + e.c end
        end
    end
    return t
end

-- Per item: made, bought, sold, and the money each way, newest activity first.
local function ledgerItems()
    local by, order = {}, {}
    for _, e in ipairs(ns.DB.ledger or {}) do
        local key = e.n or ("item " .. (e.i or 0))
        local x = by[key]
        if not x then
            x = { name = key, i = e.i, made = 0, value = 0, bought = 0, spent = 0, sold = 0, earned = 0, posted = 0, last = 0, entries = {} }
            by[key] = x
            table.insert(order, x)
        end
        if (e.i or 0) > 0 then x.i = e.i end
        if e.k == "craft" then x.made = x.made + (e.q or 1); x.value = x.value + (e.v or 0)
        elseif e.k == "post" then x.posted = x.posted + (e.q or 1)
        elseif e.k == "ahbuy" or e.k == "vbuy" then x.bought = x.bought + (e.q or 1); x.spent = x.spent - e.c
        elseif e.k == "ahsale" or e.k == "vsell" then x.sold = x.sold + (e.q or 1); x.earned = x.earned + e.c end
        x.last = math.max(x.last, e.t)
        table.insert(x.entries, e)
    end
    table.sort(order, function(a, b) return a.last > b.last end)
    return order, by
end

local function startOfToday()
    local d = date("*t")
    d.hour, d.min, d.sec = 0, 0, 0
    return time(d)
end

-- ---------------------------------------------------------------- professions

local PROF_ICONS = {
    [171] = "Interface\\Icons\\Trade_Alchemy", [164] = "Interface\\Icons\\Trade_BlackSmithing",
    [333] = "Interface\\Icons\\Trade_Engraving", [202] = "Interface\\Icons\\Trade_Engineering",
    [165] = "Interface\\Icons\\Trade_LeatherWorking", [197] = "Interface\\Icons\\Trade_Tailoring",
    [185] = "Interface\\Icons\\INV_Misc_Food_15", [129] = "Interface\\Icons\\Spell_Holy_SealOfSacrifice",
    [182] = "Interface\\Icons\\Trade_Herbalism", [186] = "Interface\\Icons\\Trade_Mining",
    [393] = "Interface\\Icons\\INV_Misc_Pelt_Wolf_01", [356] = "Interface\\Icons\\Trade_Fishing",
}

-- The colour a recipe shows at your skill, and the chance it gives a point (the game's own rule:
-- orange always, then falling to nothing as it goes from yellow to grey).
local function skillColor(r, rank)
    local yellow, grey = r[4], r[5]
    if rank < yellow then return "ffff8040", 1, "orange" end
    if rank >= grey then return "ff808080", 0, "grey" end
    local chance = (grey - rank) / math.max(grey - yellow, 1)
    if rank < (r.green or (yellow + grey) / 2) then return "ffffff00", chance, "yellow" end
    return "ff40c040", chance, "green"
end


-- An item's price: the newest the auction house showed (any age), else what a vendor sells it for.
local function unitPrice(id)
    local p = ns.latestPrice(id)
    local v = (ns.DB.vendorPrices and ns.DB.vendorPrices[id]) or (ns.vendor and ns.vendor[id])
    if v and (not p or v <= p.min) then return v, "vendor" end
    if p then return p.min, "auction", p.t end
end

-- Which profession's recipe makes an item, if any: {pid, recipe}. Built once.
local craftedByIndex
local function craftedBy(item)
    if not craftedByIndex then
        craftedByIndex = {}
        for pid, rl in pairs(ns.recipes or {}) do
            for _, r in ipairs(rl) do
                if r[7] > 0 and not craftedByIndex[r[7]] then craftedByIndex[r[7]] = { pid, r } end
            end
        end
    end
    return craftedByIndex[item]
end

-- Where a recipe comes from, from what you've seen: the trainer (skill, level, cost, where they
-- stand) or the vendor selling its recipe item. Returns a short text for the list, lines for the
-- details, and a map point when a place is known.
local function sourceOf(r, profName)
    local lines, point, short = {}, nil, nil
    local t = ns.DB.trainers and ns.DB.trainers[r[2]:lower()]
    if t then
        short = string.format("trainer: %s %d, level %d", profName or "skill", t.skill or 0, t.level or 0)
        table.insert(lines, string.format("Trainer: needs %s %d and level %d%s", profName or "skill", t.skill or 0, t.level or 0,
            (t.cost and t.cost > 0) and (", costs " .. ns.money(t.cost)) or ""))
        for key in pairs(t.at or {}) do
            local w = ns.DB.trainerSeen and ns.DB.trainerSeen[key]
            if w then
                table.insert(lines, grey(string.format("  %s, %s%s%s", w.name or "?", w.zone or "?",
                    (w.sub and w.sub ~= "" and w.sub ~= w.zone) and (", " .. w.sub) or "", w.x and string.format(" (%.0f, %.0f)", w.x, w.y) or "")))
                if w.x and not point then point = { zone = w.zone, x = w.x, y = w.y, label = (w.name or "Trainer") .. " (trainer)" } end
            end
        end
    end
    if not t and r.src and r.src:find("6") then
        short = string.format("trainer: %s %d", profName or "skill", r[3])
        table.insert(lines, string.format("Trainer: learn at %s %d%s", profName or "skill", r[3], (r.fee and r.fee > 0) and (", costs " .. ns.money(r.fee)) or ""))
        table.insert(lines, grey("  Any trainer of your profession who teaches that level. Open one and its name and spot are saved here."))
    end
    local known = r[10] and r[10] > 0 and ns.recipeSources and ns.recipeSources[r[10]]
    if known then
        local mine = UnitFactionGroup("player") == "Alliance" and 3 or 4
        local friendly, other = {}, {}
        for _, v in ipairs(known.v or {}) do
            local stock = (v[5] and v[5] > 0) and string.format(", %d at a time", v[5]) or ""
            local text = string.format("  %s, %s, %s%s", v[1], v[2] ~= "" and v[2] or "?", ns.money(v[6] or 0), stock)
            if (v[mine] or 0) >= 0 then table.insert(friendly, text) else table.insert(other, text) end
        end
        table.insert(lines, string.format("Recipe item: %s, needs %s %d", itemName(r[10]), profName or "skill", r[3]))
        if #friendly > 0 then
            short = short or ("recipe: vendor in " .. (known.v[1] and known.v[1][2] or "?"))
            table.insert(lines, "Sold by:")
            for _, l in ipairs(friendly) do table.insert(lines, grey(l)) end
        end
        if #other > 0 then
            table.insert(lines, "Sold to the other faction only by:")
            for _, l in ipairs(other) do table.insert(lines, grey(l)) end
        end
        if known.d and #known.d > 0 then
            short = short or "recipe: drops"
            table.insert(lines, string.format("Drops from%s:", (known.nd or 0) > #known.d and string.format(" (best of %d)", known.nd) or ""))
            for _, d in ipairs(known.d) do
                table.insert(lines, grey(string.format("  %s, %s, %s%%%s", d[1], d[2] ~= "" and d[2] or "?", d[3], (d[4] and d[4] > 0 and d[4] < 9999) and (", level " .. d[4]) or "")))
            end
        end
        if known.q and #known.q > 0 then
            short = short or "recipe: quest"
            table.insert(lines, "Quest reward: " .. table.concat(known.q, ", "))
        end
        -- Never nothing (2026-11-12): Recipe: Goblin Rocket Fuel has no vendor, drop or quest on Wowhead,
        -- and the empty answer broke the Professions list for Chriso at Alchemy 225.
        if not short and #other > 0 then short = "recipe: other faction's vendor only" end
        if not short then
            local by = craftedBy(r[10])
            if by then
                short = "recipe: made by " .. (ns.PROF_NAMES[by[1]] or "a profession")
                table.insert(lines, string.format("Made by %s: %s, learned at %d", ns.PROF_NAMES[by[1]] or "a profession", by[2][2], by[2][3]))
                table.insert(lines, grey("  Ask someone with it, or look for it on the auction house."))
            else
                short = "recipe: source unknown"
                table.insert(lines, grey("  Wowhead lists no vendor, drop or quest for it yet: ask, or look on the auction house."))
            end
        end
        if #lines > 0 and not (ns.DB.vendors and ns.DB.vendors[r[10]]) then return short, lines, point end
    elseif r.src and not t and (r.src:find("5") or r.src:find("2") or r.src:find("4")) then
        local how = {}
        if r.src:find("5") then table.insert(how, "sold by a vendor") end
        if r.src:find("2") then table.insert(how, "drops") end
        if r.src:find("4") then table.insert(how, "a quest reward") end
        if #how > 0 then
            table.insert(lines, (r[10] and r[10] > 0) and string.format("Recipe item: %s, needs %s %d", itemName(r[10]), profName or "skill", r[3])
                or string.format("Recipe: needs %s %d", profName or "skill", r[3]))
            table.insert(lines, grey("  Wowhead says it's " .. table.concat(how, " or ") .. "; ask where, or open the vendor when you find it."))
            return short or ("recipe: " .. how[1]), lines, point
        end
    end
    if r[6] == 1 and r[10] and r[10] > 0 then
        local item = r[10]
        local v = ns.DB.vendors and ns.DB.vendors[item]
        local itemLine = string.format("Recipe item: %s, needs %s %d", itemName(item), profName or "skill", r[3])
        if v then
            short = short or ("recipe: sold in " .. (v.zone or "?"))
            if not known then table.insert(lines, itemLine) end
            table.insert(lines, "You've seen it at:")
            table.insert(lines, grey(string.format("  sold by %s, %s%s%s, %s%s", v.by or "?", v.zone or "?",
                (v.sub and v.sub ~= "" and v.sub ~= v.zone) and (", " .. v.sub) or "", v.x and string.format(" (%.0f, %.0f)", v.x, v.y) or "",
                v.currency and "for a currency" or ns.money(v.price or 0), v.limited and string.format(", limited (%d left when seen)", v.limited) or "")))
            if v.x and not point then point = { zone = v.zone, x = v.x, y = v.y, label = (v.by or "Vendor") .. " (" .. itemName(item) .. ")" } end
        else
            short = short or "recipe item"
            table.insert(lines, itemLine)
            table.insert(lines, grey("  Not seen at a vendor yet: it may drop, come from a quest, or be sold for Merchant's Favor."))
        end
    elseif not t and r.src == "" then
        short = "source unknown"
        table.insert(lines, grey("Wowhead doesn't list how to get this one yet. It may be new to Forever: ask, or open a trainer or vendor that has it."))
    elseif not t and not (r.src and r.src:find("6")) then
        short = "trainer"
        table.insert(lines, string.format("Trainer: needs %s %s%d", profName or "skill", r[9] == 1 and "" or "about ", r[3]))
        table.insert(lines, grey("  Open your profession trainer once and the exact skill, level and cost show here."))
    end
    return short, lines, point
end

-- Which recipe of a profession makes an item (Blackmouth Oil from Oily Blackmouth, a bar from ore).
local makers = {}
local function makerOf(pid, item)
    local m = makers[pid]
    if not m then
        m = {}
        for _, r in ipairs(ns.recipes and ns.recipes[pid] or {}) do
            if r[7] > 0 and not m[r[7]] then m[r[7]] = r end
        end
        makers[pid] = m
    end
    return m[item]
end

local function buyCost(r)
    local total = 0
    for i = 1, #r[8], 2 do
        local p = unitPrice(r[8][i])
        if not p then return nil end
        total = total + p * r[8][i + 1]
    end
    -- per item made: a craft that makes 2 (Fire Oil) costs half each
    return total / (r.yield or 1)
end

-- Your professions and skill, read at most once a second: recipeCost asks for every reagent.
local profsNow, profsAt = {}, 0
local function myProfs()
    if GetTime() - profsAt > 1 then
        profsNow, profsAt = ns.myProfessions and ns.myProfessions() or {}, GetTime()
    end
    return profsNow
end

-- What a recipe costs to make: each reagent bought, or made yourself when you can and that's cheaper
-- (one step down: the oil from the fish, not the fish). made[item] = that cost, when it was used.
-- "Yourself" is any of your professions at the skill it needs (2026-11-12): a Blacksmith who mines
-- can smelt their own bars.
local function recipeCost(r, pid, rank)
    local total, missing, made = 0, 0, {}
    for i = 1, #r[8], 2 do
        local id, n = r[8][i], r[8][i + 1]
        local p = unitPrice(id)
        local c
        for opid, op in pairs(myProfs()) do
            local m = makerOf(opid, id)
            local mc = m and m[3] <= (op.rank or 0) and buyCost(m)
            if mc and (not c or mc < c) then c = mc end
        end
        if c and (not p or c < p) then
            made[id] = c
            p = c
        end
        if p then total = total + p * n else missing = missing + 1 end
    end
    return total, missing, made
end

local AH_CUT = 0.05 -- the auction house keeps 5% of a sale

-- What the thing you make sells for after the cut, or nil when it has no price.
local function sellValue(r)
    if r[7] == 0 then return nil end
    local p = ns.latestPrice(r[7])
    return p and p.min * (1 - AH_CUT) * (r.yield or 1), p
end

local function sortBySelling() return ns.DB and ns.DB.profSort == "sell" end
-- Most profit (2026-11-10, Chriso: "things that dont level us up too but make profit"): every recipe at
-- or under your skill, whatever its colour, by what one craft earns after the auction house's cut.
local function sortByProfit() return ns.DB and ns.DB.profSort == "profit" end
local NEXT_SORT = { make = "sell", sell = "profit", profit = "make" }

local function reagentText(r)
    local bits = {}
    for i = 1, #r[8], 2 do table.insert(bits, r[8][i + 1] .. " " .. itemName(r[8][i])) end
    return table.concat(bits, ", ")
end

local function knownRecipe(spell)
    if not (C_TradeSkillUI and C_TradeSkillUI.GetRecipeInfo) then return nil end
    local ok, info = pcall(C_TradeSkillUI.GetRecipeInfo, spell)
    if ok and info then return info.learned end
end

-- The recipes that give a point at your skill, cheapest per point first; and the ones a little above it.
local function recipesFor(pid, rank)
    local now, soon = {}, {}
    for idx, r in ipairs(ns.recipes and ns.recipes[pid] or {}) do
        if matches(r[2]) then
            local _, chance = skillColor(r, rank)
            if r[3] <= rank and chance > 0 then
                local cost, missing, made = recipeCost(r, pid, rank)
                local sell = sellValue(r)
                local perPoint = missing == 0 and cost / chance or nil
                table.insert(now, { idx = idx, r = r, chance = chance, cost = cost, missing = missing, made = made, sell = sell,
                    perPoint = perPoint, netPerPoint = perPoint and sell and (cost - sell) / chance or nil })
            elseif r[3] > rank and r[3] <= rank + 30 then
                table.insert(soon, { idx = idx, r = r })
            end
        end
    end
    local selling = sortBySelling()
    local function key(x) return selling and (x.netPerPoint or x.perPoint) or x.perPoint end
    table.sort(now, function(a, b)
        local ka, kb = key(a), key(b)
        if (ka ~= nil) ~= (kb ~= nil) then return ka ~= nil end
        if ka and kb and ka ~= kb then return ka < kb end
        return a.chance > b.chance
    end)
    table.sort(soon, function(a, b) return a.r[3] < b.r[3] end)
    return now, soon
end

-- Every recipe you have the skill for that makes something, most profit per craft first. Profit is the
-- product's cheapest listing after the 5% cut, less the reagents; nil when a price is missing.
local function profitsFor(pid, rank)
    local out = {}
    for idx, r in ipairs(ns.recipes and ns.recipes[pid] or {}) do
        if r[7] > 0 and r[3] <= rank and matches(r[2]) then
            local cost, missing, made = recipeCost(r, pid, rank)
            local sell, p = sellValue(r)
            table.insert(out, { idx = idx, r = r, cost = cost, missing = missing, made = made, sell = sell, p = p,
                profit = missing == 0 and sell and sell - cost or nil })
        end
    end
    table.sort(out, function(a, b)
        if (a.profit ~= nil) ~= (b.profit ~= nil) then return a.profit ~= nil end
        if a.profit and b.profit and a.profit ~= b.profit then return a.profit > b.profit end
        return a.r[2] < b.r[2]
    end)
    return out
end

-- The two readings to compare for an item: your last two full scans, or the pooled pair (everyone's
-- scans, Market.lua) when that's newer. Returns now {min, listed}, was {min, listed}, the seconds
-- between them, and how much of "was" sat in each time-left band as {{shortest seconds, listed}, ...}
-- (nil when not recorded).
local POOL_BANDS = { 0, 1800, 7200, 43200 } -- the pool's four bands, shortest first: where each starts
local function readingPair(id)
    local a, b = ns.DB.lastScan, ns.DB.prevScan
    local now, was = a and a.prices[id], b and b.prices[id]
    local pl = ns.pool and ns.pool.items and ns.pool.items[id]
    if pl and pl[4] and pl[4] > 0 and (not (now and was) or pl[1] > a.t) then
        local bands
        if pl[7] then bands = {} for k = 1, 4 do table.insert(bands, { POOL_BANDS[k], pl[6 + k] or 0 }) end end
        return { pl[2], pl[3] }, { pl[5], pl[6] }, pl[1] - pl[4], bands, true
    end
    if not (now and was) then return nil end
    local bands
    if b.bands and was.tl then
        bands = {}
        for band, q in pairs(was.tl) do table.insert(bands, { b.bands[band] and b.bands[band][1] or 0, q }) end
    end
    return now, was, a.t - b.t, bands, false
end

-- How an item's listings moved between the last two scans: whether it is actually selling.
local function listingTrend(id)
    local now, was, gap, bands, pooled = readingPair(id)
    if not now then return nil end
    local text = string.format("listed %d > %d", was[2], now[2]) .. (pooled and " (all players)" or "")
    -- under a 10% change in listings is noise: a few posted or bought between scans says nothing
    if math.abs(now[2] - was[2]) < math.max(was[2], 1) * 0.1 then return grey(text) end
    if now[2] < was[2] and now[1] >= was[1] then
        -- Fewer listed at the same price is selling only past what could simply have run out: listings
        -- in the earlier scan whose time-left band starts before the gap between the scans may have
        -- expired (Chriso's Rage Potions did, 2026-11-11). New posts in between hide more, so "at least".
        local drop = was[2] - now[2]
        if not bands then return grey(text .. ", price held: sold or ran out") end
        local could = 0
        for _, bq in ipairs(bands) do if bq[1] < gap then could = could + bq[2] end end
        if drop > could then
            return color("ff40d060", string.format("%s, price held: at least %d sold", text, drop - could))
        end
        return grey(string.format("%s, but %d could have run out: can't tell", text, could))
    end
    if now[2] > was[2] and now[1] <= was[1] then return grey(text .. ", price no higher: not much selling") end
    return grey(text)
end

local function button(b, label, fn)
    b:SetText(label)
    b:SetWidth(b:GetFontString():GetStringWidth() + 24)
    b:SetScript("OnClick", fn)
    b:Show()
end

local function recipeMaking(id)
    for pid, list in pairs(ns.recipes or {}) do
        for _, r in ipairs(list) do if r[7] == id then return pid, r end end
    end
end

-- Sends the item to Claude with what the addon has seen of it: price, listings, their history, its
-- cost to make, and how your own listings did.
local function researchItem(id)
    local name = itemName(id)
    local bits = {}
    local p = ns.latestPrice(id)
    if p then table.insert(bits, string.format("cheapest listing %s with %s listed (%s)", ns.money(p.min), tostring(p.q or "?"), date("%d %b %H:%M", p.t))) end
    local h = ns.DB.prices[id]
    if h and #h > 1 then
        local hb = {}
        for i = math.max(1, #h - 6), #h do table.insert(hb, string.format("%s %s, %s listed", h[i].d, ns.money(h[i].min), tostring(h[i].q or "?"))) end
        table.insert(bits, "by day: " .. table.concat(hb, "; "))
    end
    local pid, r = recipeMaking(id)
    if r then
        local mine = ns.myProfessions and ns.myProfessions()[pid]
        local cost, missing = recipeCost(r, pid, mine and mine.rank or 0)
        if missing == 0 then table.insert(bits, "it costs about " .. ns.money(cost) .. " to make (" .. reagentText(r) .. ")") end
    end
    local posted, sold = 0, 0
    for _, e in ipairs(ns.DB.ledger or {}) do
        if e.i == id or e.n == name then
            if e.k == "post" then posted = posted + (e.q or 1) elseif e.k == "ahsale" then sold = sold + (e.q or 1) end
        end
    end
    if posted + sold > 0 then table.insert(bits, string.format("I have posted %d and sold %d so far", posted, sold)) end
    ns.ask(string.format("Is %s actually selling on the WoW Forever auction house? Who buys it and what for, and is it worth making to sell?", name),
        #bits > 0 and ("What my addon has seen: " .. table.concat(bits, ". ") .. ".") or nil)
end

local function trackButtons(id)
    if not id then return end
    button(detail.b1, ns.isTracked(id) and "Stop tracking" or "Track", function() ns.toggleTrack(id) end)
    button(detail.b2, "Research", function() researchItem(id) end)
end

local function professionsSorted()
    local out = {}
    for id, p in pairs(ns.myProfessions and ns.myProfessions() or {}) do table.insert(out, { id = id, p = p }) end
    table.sort(out, function(a, b) return (a.p.name or "") < (b.p.name or "") end)
    return out
end

-- ---------------------------------------------------------------- frame pieces

local function backdrop(f, bg, border)
    f:SetBackdrop({ bgFile = "Interface\\Buttons\\WHITE8x8", edgeFile = "Interface\\Buttons\\WHITE8x8", edgeSize = 1 })
    f:SetBackdropColor(unpack(bg or { 0.05, 0.05, 0.05, 0.96 }))
    f:SetBackdropBorderColor(unpack(border or { GOLD[1], GOLD[2], GOLD[3], 0.6 }))
end

local function text(parent, font, r, g, b)
    local fs = parent:CreateFontString(nil, "OVERLAY", font or "GameFontHighlightSmall")
    if r then fs:SetTextColor(r, g, b) end
    fs:SetJustifyH("LEFT")
    return fs
end

local refresh, redraw

local function makeRow(i)
    local r = CreateFrame("Button", nil, list.child, "BackdropTemplate")
    r:SetSize(LIST_W - 30, ROW_H)
    backdrop(r, { 0.08, 0.08, 0.08, 0.9 }, { 0, 0, 0, 0 })
    r.icon = r:CreateTexture(nil, "ARTWORK")
    r.icon:SetSize(28, 28)
    r.icon:SetPoint("LEFT", 5, 0)
    r.icon:SetTexCoord(0.07, 0.93, 0.07, 0.93)
    r.name = text(r, "GameFontNormal")
    r.name:SetPoint("TOPLEFT", r.icon, "TOPRIGHT", 8, -1)
    r.name:SetPoint("RIGHT", r, "RIGHT", -170, 0)
    r.name:SetWordWrap(false)
    r.sub = text(r, "GameFontHighlightSmall", 0.6, 0.6, 0.6)
    r.sub:SetPoint("BOTTOMLEFT", r.icon, "BOTTOMRIGHT", 8, 1)
    r.sub:SetPoint("RIGHT", r, "RIGHT", -8, 0)
    r.sub:SetWordWrap(false)
    r.value = text(r, "GameFontHighlightSmall")
    r.value:SetJustifyH("RIGHT")
    r.value:SetPoint("TOPRIGHT", r, "TOPRIGHT", -8, -5)
    r.value:SetWidth(165)
    -- the game's own tooltip for the row's item, or the recipe's spell when it makes no item (14 Nov)
    r:SetScript("OnEnter", function(self)
        if self.key ~= selectedKey then self:SetBackdropColor(0.14, 0.12, 0.06, 0.95) end
        if self.itemID or self.spellID then
            GameTooltip:SetOwner(self, "ANCHOR_NONE")
            GameTooltip:SetPoint("TOPRIGHT", self, "TOPLEFT", -4, 0)
            if self.itemID then GameTooltip:SetItemByID(self.itemID) else GameTooltip:SetSpellByID(self.spellID) end
            GameTooltip:Show()
        end
    end)
    r:SetScript("OnLeave", function(self)
        if self.key ~= selectedKey then self:SetBackdropColor(0.08, 0.08, 0.08, 0.9) end
        GameTooltip:Hide()
    end)
    r:RegisterForClicks("LeftButtonUp")
    r:SetScript("OnClick", function(self)
        if IsShiftKeyDown() and self.itemID then
            local _, link = C_Item.GetItemInfo(self.itemID)
            if link then ChatEdit_InsertLink(link) end
            return
        end
        if self.key and self.key:match("^sortmode:") then
            ns.DB.profSort = NEXT_SORT[ns.DB.profSort] or "sell"
            refresh()
            return
        end
        selectedKey = self.key
        local pid = self.key and self.key:match("^prof:(%d+)$")
        if pid then selProf = (selProf == tonumber(pid)) and nil or tonumber(pid) end
        refresh()
    end)
    rows[i] = r
    return r
end

local function fillRow(r, key, icon, name, nameHex, value, sub, itemID, spellID)
    r.key, r.itemID, r.spellID = key, itemID, spellID
    r.icon:SetTexture(icon or "Interface\\Icons\\INV_Misc_QuestionMark")
    r.name:SetText(color(nameHex or "ffffffff", name or "?"))
    r.value:SetText(value or "")
    r.sub:SetText(sub or "")
    if key == selectedKey then
        r:SetBackdropColor(0.2, 0.16, 0.05, 1)
        r:SetBackdropBorderColor(GOLD[1], GOLD[2], GOLD[3], 0.9)
    else
        r:SetBackdropColor(0.08, 0.08, 0.08, 0.9)
        r:SetBackdropBorderColor(0, 0, 0, 0)
    end
    r:Show()
end

-- ---------------------------------------------------------------- the list

local function subLine(s)
    if s.kind == "item" and ns.isTracked and ns.isTracked(s.id) and #s.events == 0 then
        return color(GOLD_HEX, "tracking") .. "  ·  price and listings saved at every scan"
    end
    local e = s.events[#s.events] or {}
    local why = e.why and ("you said: " .. e.why) or ("probably " .. (e.g or "looking"))
    local bits = { why, e.ctx or "?" }
    if s.kind == "player" and s.level then bits[2] = "level " .. s.level .. " " .. (s.class or ""):lower() .. ", " .. bits[2] end
    table.insert(bits, string.format("%d look%s, %ds", s.looks, s.looks == 1 and "" or "s", math.floor(s.secs + 0.5)))
    table.insert(bits, when(s.last))
    if s.kind == "item" and ns.isTracked and ns.isTracked(s.id) then table.insert(bits, 1, color(GOLD_HEX, "tracking")) end
    return table.concat(bits, "  ·  ")
end

local function valueText(s)
    if s.kind == "item" then
        -- The last move: the reading before against the newest (13 Nov: an item tracked from Market,
        -- never hovered, had no "price when first looked" and so showed no change at all).
        local h = priceHistory(s.id)
        if #h >= 2 then
            local was, now = h[#h - 1].min, h[#h].min
            if math.abs(now - was) < 1 then return ns.money(now) .. "  " .. grey("no change") end
            return ns.money(was) .. " > " .. ns.money(now) .. "  " .. changeText(was, now)
        end
        local first, cur = priceTrail(s)
        if first and cur then
            if math.abs(cur.min - first.p) < 1 then return ns.money(cur.min) end
            return ns.money(first.p) .. " > " .. ns.money(cur.min) .. "  " .. changeText(first.p, cur.min)
        end
        if cur then return ns.money(cur.min) end
        return grey("no price yet")
    end
    if s.kind == "talent" then
        if s.takenAt then return color("ff40d060", "taken " .. ns.shortDate(s.takenAt)) end
        if s.hadIt then return grey("you have it") end
        return grey("not taken")
    end
    return ""
end

-- ---------------------------------------------------------------- the Market tab's filter

-- Categories for the Market list (2026-11-12, Chriso: "see only material/consumables ... categories
-- to break things into"), by the item's class from the client's own data (GetItemInfoInstant needs no
-- cache). A category with items in it also offers its subclasses (Herb, Cloth, Potion, Elixir...),
-- named as the game names them. The choice is kept in DB.marketCat and DB.marketSub.
local MARKET_CATS = {
    { key = "all", label = "All" },
    { key = "mats", label = "Materials", classes = { [7] = true, [5] = true } }, -- trade goods, reagents
    { key = "cons", label = "Consumables", classes = { [0] = true } },
    { key = "gear", label = "Gear", classes = { [2] = true, [4] = true } },     -- weapons, armour
    { key = "recipes", label = "Recipes", classes = { [9] = true } },
    { key = "bags", label = "Bags", classes = { [1] = true } },
    { key = "other", label = "Other" },
}
local catCache = {}
local function marketCat(id)
    local c = catCache[id]
    if c then return c[1], c[2] end
    local _, _, subType, _, _, classID = C_Item.GetItemInfoInstant(id)
    local key = "other"
    for _, cat in ipairs(MARKET_CATS) do
        if cat.classes and classID and cat.classes[classID] then key = cat.key break end
    end
    catCache[id] = { key, subType ~= "" and subType or nil }
    return key, catCache[id][2]
end

-- The moves in the chosen category and subclass, and the counts the chips show.
-- The categories and kinds count every move, before the sort's filter: counted after it, a sort that
-- kept nothing hid every category (12 Nov, "All 0" and nothing to pick).
local function filterMarket(all, everything)
    local sel, sub = ns.DB.marketCat or "all", ns.DB.marketSub
    local cats, subs, out = { all = #(everything or all) }, {}, {}
    for _, m in ipairs(everything or all) do
        local key, st = marketCat(m.id)
        cats[key] = (cats[key] or 0) + 1
        if st and sel ~= "all" and key == sel then subs[st] = (subs[st] or 0) + 1 end
    end
    for _, m in ipairs(all) do
        local key, st = marketCat(m.id)
        if (sel == "all" or key == sel) and (not sub or st == sub) then table.insert(out, m) end
    end
    return out, cats, subs
end

local function placeList(top)
    list:ClearAllPoints()
    list:SetPoint("TOPLEFT", 12, top)
    list:SetPoint("BOTTOMLEFT", 12, 44)
    list:SetWidth(LIST_W)
end

-- The Market's sorts (DB.marketSort), each a different question about where prices are going.
local MARKET_SORTS = {
    { value = "move", label = "Biggest change" },
    { value = "up", label = "Rising most" },
    { value = "down", label = "Falling most" },
    { value = "selling", label = "Selling (fewer listed, price held)" },
    { value = "flood", label = "Flooding (more listed, price no higher)" },
    { value = "listed", label = "Most listed" },
    { value = "gold", label = "Most gold on sale" },
}

-- One small popup list, shared by the Kind and Sort buttons.
local menu
local function openMenu(anchor, options, current, onPick)
    if not menu then
        menu = CreateFrame("Frame", nil, frame, "BackdropTemplate")
        backdrop(menu, { 0.05, 0.05, 0.05, 0.98 }, { GOLD[1], GOLD[2], GOLD[3], 0.6 })
        menu:SetFrameStrata("DIALOG")
        menu:EnableMouse(true)
        menu.buttons = {}
    end
    if menu:IsShown() and menu.anchor == anchor then menu:Hide() return end
    menu.anchor = anchor
    local width = 0
    for i, o in ipairs(options) do
        local b = menu.buttons[i]
        if not b then
            b = CreateFrame("Button", nil, menu)
            b:SetHeight(18)
            b.fs = text(b, "GameFontHighlightSmall")
            b.fs:SetPoint("LEFT", 8, 0)
            local hl = b:CreateTexture(nil, "HIGHLIGHT")
            hl:SetAllPoints()
            hl:SetColorTexture(1, 1, 1, 0.08)
            menu.buttons[i] = b
        end
        b.fs:SetText(o.value == current and color(GOLD_HEX, o.label) or o.label)
        width = math.max(width, b.fs:GetStringWidth())
        b:SetScript("OnClick", function() menu:Hide() onPick(o.value) end)
        b:ClearAllPoints()
        b:SetPoint("TOPLEFT", 4, -4 - (i - 1) * 18)
        b:Show()
    end
    for i = #options + 1, #menu.buttons do menu.buttons[i]:Hide() end
    for i = 1, #options do menu.buttons[i]:SetWidth(width + 16) end
    menu:SetSize(width + 24, #options * 18 + 8)
    menu:ClearAllPoints()
    menu:SetPoint("TOPLEFT", anchor, "BOTTOMLEFT", 0, -2)
    menu:Show()
end

local function dropButton(bar, name)
    local b = CreateFrame("Button", nil, bar, "BackdropTemplate")
    backdrop(b, { 0.08, 0.08, 0.08, 1 }, { GOLD[1], GOLD[2], GOLD[3], 0.35 })
    b:SetHeight(20)
    b.label = text(b, "GameFontHighlightSmall")
    b.label:SetPoint("LEFT", 8, 0)
    b.arrow = b:CreateTexture(nil, "ARTWORK")
    b.arrow:SetTexture("Interface\\Buttons\\Arrow-Down-Up")
    b.arrow:SetSize(12, 12)
    b.arrow:SetPoint("RIGHT", -4, -2)
    bar[name] = b
    return b
end

local function marketChip(i)
    local b = frame.mktBar.chips[i]
    if b then return b end
    b = CreateFrame("Button", nil, frame.mktBar, "BackdropTemplate")
    backdrop(b, { 0.08, 0.08, 0.08, 1 }, { 0.3, 0.3, 0.3, 0.6 })
    b:SetHeight(20)
    b.label = text(b, "GameFontHighlightSmall")
    b.label:SetPoint("CENTER")
    b:SetScript("OnClick", function(self)
        ns.DB.marketCat, ns.DB.marketSub = self.value, nil
        list.scroll:SetVerticalScroll(0)
        refresh()
    end)
    frame.mktBar.chips[i] = b
    return b
end

-- Lays the bar out over the list: the categories on the first line, then a Kind menu (the chosen
-- category's kinds, as the game names them) and a Sort menu; moves the list down under it.
local function drawMarketBar(cats, subs)
    local bar = frame.mktBar
    local sel, sub = ns.DB.marketCat or "all", ns.DB.marketSub
    local x, y, n = 0, 0, 0
    for _, c in ipairs(MARKET_CATS) do
        if c.key == "all" or (cats[c.key] or 0) > 0 or c.key == sel then
            n = n + 1
            local b = marketChip(n)
            b.level, b.value = 1, c.key
            local label = c.label .. grey(" " .. (cats[c.key] or 0))
            b.label:SetText(c.key == sel and color(GOLD_HEX, c.label) .. grey(" " .. (cats[c.key] or 0)) or label)
            b:SetWidth(b.label:GetStringWidth() + 12)
            b:SetBackdropBorderColor(GOLD[1], GOLD[2], GOLD[3], c.key == sel and 0.9 or 0.15)
            if x + b:GetWidth() > LIST_W then x, y = 0, y - 24 end
            b:ClearAllPoints()
            b:SetPoint("TOPLEFT", x, y)
            b:Show()
            x = x + b:GetWidth() + 4
        end
    end
    for i = n + 1, #bar.chips do bar.chips[i]:Hide() end

    -- second line: Kind (when a category is chosen) and Sort
    y = y - 24
    x = 0
    local kind = bar.kind or dropButton(bar, "kind")
    if sel ~= "all" and next(subs) then
        local names = {}
        for st in pairs(subs) do table.insert(names, st) end
        table.sort(names, function(a, b) if subs[a] ~= subs[b] then return subs[a] > subs[b] end return a < b end)
        local options = { { value = false, label = "Every kind" } }
        for _, st in ipairs(names) do table.insert(options, { value = st, label = st .. "  " .. subs[st] }) end
        kind.label:SetText(grey("Kind: ") .. (sub or "Every kind"))
        kind:SetWidth(kind.label:GetStringWidth() + 28)
        kind:SetScript("OnClick", function(self)
            openMenu(self, options, sub or false, function(v)
                ns.DB.marketSub = v or nil
                list.scroll:SetVerticalScroll(0)
                refresh()
            end)
        end)
        kind:ClearAllPoints()
        kind:SetPoint("TOPLEFT", x, y)
        kind:Show()
        x = x + kind:GetWidth() + 6
    else
        kind:Hide()
    end
    local sort = bar.sort or dropButton(bar, "sort")
    local mode = ns.DB.marketSort or "move"
    local current = MARKET_SORTS[1].label
    for _, o in ipairs(MARKET_SORTS) do if o.value == mode then current = o.label end end
    sort.label:SetText(grey("Sort: ") .. current)
    sort:SetWidth(sort.label:GetStringWidth() + 28)
    sort:SetScript("OnClick", function(self)
        openMenu(self, MARKET_SORTS, mode, function(v)
            ns.DB.marketSort = v
            list.scroll:SetVerticalScroll(0)
            refresh()
        end)
    end)
    sort:ClearAllPoints()
    sort:SetPoint("TOPLEFT", x, y)
    sort:Show()

    local height = -y + 22
    bar:SetHeight(height)
    bar:Show()
    placeList(-72 - height - 4)
end

local function drawList()
    if tab ~= "movers" and frame.mktBar:IsShown() then
        frame.mktBar:Hide()
        if menu then menu:Hide() end
        placeList(-72)
    end
    local n, y = 0, -2
    local function place(r)
        r:ClearAllPoints()
        r:SetPoint("TOPLEFT", 2, y)
        y = y - ROW_H - 3
    end
    if tab == "ask" then
        local asks = ns.DB.asks or {}
        list.note:SetText(#asks == 0 and "No questions yet. Type one in the box on the right, or /ask in chat." or "")
        for i = #asks, math.max(1, #asks - 49), -1 do
            local a = asks[i]
            if matches(a.q) then
                n = n + 1
                local r = rows[n] or makeRow(n)
                local done = a.status == "answered"
                local sub = done and (a.answer or "") or ("sent " .. when(a.t) .. "; the answer loads on your next /reload")
                local value = done and ((a.pins and #a.pins > 0) and color(GOLD_HEX, #a.pins .. " on the map") or grey("answered"))
                    or color("ffe0a040", "waiting")
                fillRow(r, "ask:" .. a.id, "Interface\\Icons\\INV_Misc_Note_01", a.q, "ffffffff", value, sub)
                place(r)
            end
        end
    elseif tab == "prof" then
        local profs = professionsSorted()
        list.note:SetText(#profs == 0 and "No professions yet. Learn one from a trainer." or "")
        for _, pr in ipairs(profs) do
            local pid, p = pr.id, pr.p
            local hasRecipes = ns.recipes and ns.recipes[pid]
            -- worked out once per profession per redraw (each walks every recipe and reagent)
            local now, soon = {}, {}
            if hasRecipes then now, soon = recipesFor(pid, p.rank) end
            local profits = hasRecipes and sortByProfit() and profitsFor(pid, p.rank) or nil
            local cheapest = now[1] and now[1].perPoint
            n = n + 1
            local r = rows[n] or makeRow(n)
            local sub = not hasRecipes and "a gathering skill: no recipes"
                or (#now .. " recipe" .. (#now == 1 and "" or "s") .. " give a point now" .. (cheapest and ("  ·  cheapest about " .. ns.money(cheapest) .. " a point") or ""))
            if profits then
                local earning = 0
                for _, x in ipairs(profits) do if x.profit and x.profit > 0 then earning = earning + 1 end end
                sub = earning .. " recipe" .. (earning == 1 and "" or "s") .. " make a profit"
                    .. (earning > 0 and ("  ·  best about " .. ns.money(profits[1].profit) .. " a craft") or "")
            end
            fillRow(r, "prof:" .. pid, PROF_ICONS[pid] or "Interface\\Icons\\INV_Misc_QuestionMark", p.name or "?", GOLD_HEX,
                (p.rank or 0) .. " / " .. (p.max or "?"), sub)
            place(r)
            if selProf == pid and hasRecipes then
                n = n + 1
                local sw = rows[n] or makeRow(n)
                fillRow(sw, "sortmode:" .. pid, "Interface\\Icons\\INV_Misc_Coin_02",
                    "   " .. (sortByProfit() and "Most profit" or sortBySelling() and "Cheapest after selling what you make" or "Cheapest to make"), GOLD_HEX,
                    grey("click to switch"), sortByProfit()
                        and "   everything you can make, points or not: what one craft earns after the 5% cut"
                        or sortBySelling()
                        and "   less what the product sells for on the auction house, after its 5% cut; click for most profit"
                        or "   the reagents' cost per skill point; click to count what you'd sell the products for")
                place(sw)
                if profits then
                    for i = 1, math.min(#profits, 60) do
                        local x = profits[i]
                        local hex = skillColor(x.r, p.rank)
                        local value
                        if x.profit then
                            value = x.profit > 0 and color("ff40d060", "+" .. ns.money(x.profit) .. " a craft")
                                or ("loses " .. ns.money(-x.profit) .. " a craft")
                        else
                            value = grey(x.missing > 0 and (x.missing .. " price" .. (x.missing == 1 and "" or "s") .. " unknown") or "no auction price")
                        end
                        local bits = { knownRecipe(x.r[1]) == true and "known" or (sourceOf(x.r, p.name)) or "source unknown" }
                        if x.p then
                            table.insert(bits, "sells " .. ns.money(x.p.min) .. ((x.r.yield or 1) > 1 and (" (makes " .. x.r.yield .. ")") or "")
                                .. (x.p.q and (", " .. x.p.q .. " listed") or ""))
                        end
                        local trend = listingTrend(x.r[7])
                        if trend then table.insert(bits, trend) end
                        n = n + 1
                        local rr = rows[n] or makeRow(n)
                        fillRow(rr, "recipe:" .. pid .. ":" .. x.idx, C_Item.GetItemIconByID(x.r[7]) or C_Spell.GetSpellTexture(x.r[1]),
                            "   " .. x.r[2], hex, value, "   " .. table.concat(bits, "  ·  "), x.r[7] > 0 and x.r[7] or nil, x.r[1])
                        place(rr)
                    end
                end
                for i = 1, sortByProfit() and 0 or math.min(#now, 60) do
                    local x = now[i]
                    local hex = skillColor(x.r, p.rank)
                    local known = knownRecipe(x.r[1])
                    local value
                    if sortBySelling() and x.netPerPoint then
                        value = x.netPerPoint <= 0 and color("ff40d060", "earns " .. ns.money(-x.netPerPoint) .. " a point")
                            or (ns.money(x.netPerPoint) .. " a point")
                    else
                        value = x.perPoint and ("about " .. ns.money(x.perPoint) .. " a point")
                            or (x.missing > 0 and grey(x.missing .. " price" .. (x.missing == 1 and "" or "s") .. " unknown") or "")
                    end
                    local how = known == true and "known" or (sourceOf(x.r, p.name)) or "source unknown"
                    if x.sell then how = how .. "  ·  sells " .. ns.money(x.sell / (1 - AH_CUT) / (x.r.yield or 1)) .. ((x.r.yield or 1) > 1 and (" (makes " .. x.r.yield .. ")") or "") end
                    if next(x.made) then how = how .. "  ·  makes its own " .. (function()
                        local t = {} for id in pairs(x.made) do table.insert(t, itemName(id)) end return table.concat(t, ", ") end)() end
                    n = n + 1
                    local rr = rows[n] or makeRow(n)
                    fillRow(rr, "recipe:" .. pid .. ":" .. x.idx, (x.r[7] > 0 and C_Item.GetItemIconByID(x.r[7])) or C_Spell.GetSpellTexture(x.r[1]),
                        "   " .. x.r[2], hex, value, "   " .. reagentText(x.r) .. "  ·  " .. how, x.r[7] > 0 and x.r[7] or nil, x.r[1])
                    place(rr)
                end
                if #soon > 0 and not sortByProfit() then
                    n = n + 1
                    local rr = rows[n] or makeRow(n)
                    local names = {}
                    for i = 1, math.min(#soon, 6) do table.insert(names, soon[i].r[2] .. " (" .. soon[i].r[3] .. ")") end
                    fillRow(rr, "soon:" .. pid, "Interface\\Icons\\INV_Misc_Book_09", "   Coming up", "ffaaaaaa", "",
                        "   " .. table.concat(names, ", "))
                    place(rr)
                end
            end
        end
    elseif tab == "ledger" then
        local periods = { { "Today", startOfToday() }, { "Last 7 days", time() - 7 * 86400 }, { "All time", 0 } }
        list.note:SetText(#(ns.DB.ledger or {}) == 0
            and "Nothing yet. Craft something, buy or sell at the auction house, or collect a sale from the mailbox." or "")
        for _, pd in ipairs(periods) do
            local t = ledgerTotals(pd[2])
            n = n + 1
            local r = rows[n] or makeRow(n)
            fillRow(r, "lsum:" .. pd[1], "Interface\\Icons\\INV_Misc_Coin_01", pd[1], GOLD_HEX, "net " .. signedMoney(t.earned - t.spent),
                string.format("made %d  ·  spent %s  ·  earned %s", t.made, ns.money(t.spent), ns.money(t.earned)))
            place(r)
        end
        local items = ledgerItems()
        for i = 1, math.min(#items, 200) do
            local x = items[i]
            if matches(x.name) then
                local bits = {}
                if x.made > 0 then table.insert(bits, "made " .. x.made) end
                if x.bought > 0 then table.insert(bits, string.format("bought %d for %s", x.bought, ns.money(x.spent))) end
                if x.posted > 0 then table.insert(bits, "posted " .. x.posted) end
                if x.sold > 0 then table.insert(bits, string.format("sold %d for %s", x.sold, ns.money(x.earned))) end
                table.insert(bits, when(x.last))
                n = n + 1
                local r = rows[n] or makeRow(n)
                fillRow(r, "ledger:" .. x.name, (x.i and x.i > 0) and C_Item.GetItemIconByID(x.i) or "Interface\\Icons\\INV_Misc_Bag_10",
                    x.name, (x.i and x.i > 0) and nameColor({ kind = "item", id = x.i }) or "ffffffff",
                    (x.earned - x.spent ~= 0) and signedMoney(x.earned - x.spent) or "", table.concat(bits, "  ·  "), (x.i and x.i > 0) and x.i or nil)
                place(r)
            end
        end
    elseif tab == "movers" then
        local all, label, everything = movers()
        local ms, cats, subs = filterMarket(all, everything)
        drawMarketBar(cats, subs)
        local last = ns.DB.lastScan
        local shown = #ms == 0 and #(everything or {}) > 0 and "Nothing here for this sort and category. "
            or (ns.DB.marketCat or "all") ~= "all" and string.format("%d of %d moves. ", #ms, #all) or ""
        list.note:SetText(label and (shown .. "Whole auction house, " .. label .. ", at least 5 listed")
            or (last and string.format("One full scan so far (%s, %d auctions). Scan again another day and the biggest moves show here.", when(last.t), last.n or 0))
            or "No full scan yet: open the auction house and click Scan (or /mco scan). Two scans on different days show the moves.")
        local mode = ns.DB.marketSort or "move"
        for i = 1, math.min(#ms, 300) do
            local m = ms[i]
            n = n + 1
            local r = rows[n] or makeRow(n)
            local key = "mover:" .. m.id
            local bits = {}
            if ns.isTracked(m.id) then table.insert(bits, color(GOLD_HEX, "tracked")) end
            table.insert(bits, string.format("listed %d > %d", m.oq, m.q))
            if m.sold > 0 then
                table.insert(bits, m.unsure and grey(m.sold .. " fewer: sold or ran out") or color("ff40d060", "at least " .. m.sold .. " sold"))
            end
            if mode == "gold" then table.insert(bits, ns.money(m.now * m.q) .. " on sale") end
            fillRow(r, key, C_Item.GetItemIconByID(m.id), itemName(m.id, m.name),
                nameColor({ kind = "item", id = m.id }), ns.money(m.was) .. " > " .. ns.money(m.now) .. "  " .. changeText(m.was, m.now),
                table.concat(bits, "  ·  "), m.id)
            place(r)
        end
    else
        local ss = trackedItems()
        list.note:SetText(#ss == 0 and "Nothing tracked yet. Pick an item in Market or Professions and press Track on the right, and it shows here." or "")
        for i = 1, math.min(#ss, 400) do
            local key, s = ss[i].key, ss[i].s
            n = n + 1
            local r = rows[n] or makeRow(n)
            fillRow(r, key, iconOf(s.kind, s.id), displayName(s), nameColor(s), valueText(s), subLine(s),
                s.kind == "item" and s.id or nil)
            place(r)
        end
    end
    for i = n + 1, #rows do rows[i]:Hide() end
    list.child:SetHeight(math.max(-y, 10))
end

-- ---------------------------------------------------------------- the details

-- ---------------------------------------------------------------- what an item is used in

-- One section per profession that uses the item, yours first: a header that opens and closes (which
-- are open is kept in DB.usesOpen; yours start open), and under it each recipe with how many it takes,
-- the skill it's learned and goes grey at, what the craft sells for, and whether it's used at 60.
-- Clicking a recipe opens it in the Professions tab.
local useHeads, useLines = {}, {}

local function openRecipe(pid, idx)
    tab = "prof"
    if ns.myProfessions and ns.myProfessions()[pid] then selProf = pid end
    selectedKey = "recipe:" .. pid .. ":" .. idx
    refresh()
end

local function useHead(i)
    local h = useHeads[i]
    if h then return h end
    h = CreateFrame("Button", nil, detail.inner, "BackdropTemplate")
    backdrop(h, { 0.1, 0.1, 0.1, 1 }, { 0.3, 0.3, 0.3, 0.6 })
    h:SetSize(BODY_W, 22)
    h.sign = h:CreateTexture(nil, "ARTWORK")
    h.sign:SetSize(14, 14)
    h.sign:SetPoint("LEFT", 4, 0)
    h.icon = h:CreateTexture(nil, "ARTWORK")
    h.icon:SetSize(16, 16)
    h.icon:SetPoint("LEFT", h.sign, "RIGHT", 4, 0)
    h.icon:SetTexCoord(0.07, 0.93, 0.07, 0.93)
    h.label = text(h, "GameFontNormalSmall")
    h.label:SetPoint("LEFT", h.icon, "RIGHT", 6, 0)
    h.value = text(h, "GameFontHighlightSmall", 0.6, 0.6, 0.6)
    h.value:SetPoint("RIGHT", -6, 0)
    h:SetScript("OnClick", function(self)
        ns.DB.usesOpen = ns.DB.usesOpen or {}
        ns.DB.usesOpen[self.pid] = not self.open
        refresh()
    end)
    useHeads[i] = h
    return h
end

local function useLine(i)
    local b = useLines[i]
    if b then return b end
    b = CreateFrame("Button", nil, detail.inner)
    b:SetWidth(BODY_W)
    b.fs = text(b, "GameFontHighlightSmall", 0.9, 0.9, 0.9)
    b.fs:SetPoint("TOPLEFT", 24, -2)
    b.fs:SetWidth(BODY_W - 28)
    b.fs:SetSpacing(1)
    local hl = b:CreateTexture(nil, "HIGHLIGHT")
    hl:SetAllPoints()
    hl:SetColorTexture(1, 1, 1, 0.06)
    b:SetScript("OnClick", function(self) if self.pid then openRecipe(self.pid, self.idx) end end)
    useLines[i] = b
    return b
end

-- One recipe under a section: its name in your skill colour, then the facts in grey.
local function useText(u, mine)
    local r = u.r
    local hex, _, word = "ffffffff", nil, nil
    if mine then hex, _, word = skillColor(r, mine.rank) end
    local name = r[7] > 0 and itemName(r[7]) or r[2]
    local first = color(hex, name) .. grey("  ·  takes " .. u.n)
    local facts = { string.format("learn at %d, grey at %d", r[3], r[5]) }
    if r[11] == 1 then table.insert(facts, 1, color(GOLD_HEX, "used at 60") .. "|cff9a9a9a") end
    if mine then
        table.insert(facts, r[3] > mine.rank and ("you need " .. r[3]) or (word == "grey" and "no points for you" or (word .. " for you")))
    end
    if r[7] > 0 then
        local p = ns.latestPrice(r[7])
        if p then table.insert(facts, "sells " .. ns.money(p.min) .. ((r.yield or 1) > 1 and (" (makes " .. r.yield .. ")") or "") .. (p.q and (", " .. p.q .. " listed") or "")) end
    else
        table.insert(facts, "an enchant")
    end
    return first .. "\n" .. grey(table.concat(facts, "  ·  "))
end

-- A quest that asks for the item (Data.lua ns.quests, from Wowhead): whether you can take it (your
-- faction and class), its name in the game's level colour, then level, where, and who it's for.
local SIDE = { [1] = "Alliance", [2] = "Horde" }
local function questFacts(qid)
    local q = ns.quests and ns.quests[qid]
    if not q then return nil end
    local name, level, req, side, mask, where = q[1], q[2], q[3], q[4], q[5], q[6]
    local classes, mine = {}, false
    local _, _, myClass = UnitClass("player")
    if mask and mask > 0 then
        for cid = 1, 13 do
            if bit.band(mask, 2 ^ (cid - 1)) ~= 0 then
                if cid == myClass then mine = true end
                local info = C_CreatureInfo and C_CreatureInfo.GetClassInfo(cid)
                if info then table.insert(classes, info.className) end
            end
        end
    end
    local forClass = not (mask and mask > 0) or mine or #classes > 3
    local forSide = not SIDE[side] or SIDE[side] == UnitFactionGroup("player")
    return { qid = qid, name = name, level = level, req = req, where = where, ok = forClass and forSide,
        side = SIDE[side], classes = (#classes > 0 and #classes <= 3) and classes or nil }
end

local function questText(f)
    local hex = "ff808080"
    if f.ok then
        local c = GetQuestDifficultyColor and GetQuestDifficultyColor(f.level)
        hex = c and string.format("ff%02x%02x%02x", c.r * 255, c.g * 255, c.b * 255) or "ffffffff"
    end
    local facts = { string.format("level %d%s", f.level, f.req > 0 and (", from " .. f.req) or "") }
    if f.where ~= "" then table.insert(facts, f.where) end
    if f.side then table.insert(facts, f.side .. " only") end
    if f.classes then table.insert(facts, table.concat(f.classes, ", ") .. " only") end
    return color(hex, f.name) .. "\n" .. grey(table.concat(facts, "  ·  "))
end

-- Lays the sections out from y down inside the details; returns the y below them.
local function drawUses(id, label, y)
    local nh, nl = 0, 0
    if id and id > 0 then
        local by, order = {}, {}
        for _, u in ipairs(USES[id] or {}) do
            if not by[u.pid] then by[u.pid] = {}; table.insert(order, u.pid) end
            table.insert(by[u.pid], u)
        end
        local mine = ns.myProfessions and ns.myProfessions() or {}
        table.sort(order, function(a, b)
            if (mine[a] ~= nil) ~= (mine[b] ~= nil) then return mine[a] ~= nil end
            if #by[a] ~= #by[b] then return #by[a] > #by[b] end
            return (ns.PROF_NAMES[a] or "") < (ns.PROF_NAMES[b] or "")
        end)
        local total = #(USES[id] or {})
        -- the quests that ask for it, ones you can take first, then by level
        local quests = {}
        for _, qid in ipairs(ns.questUses and ns.questUses[id] or {}) do
            local f = questFacts(qid)
            if f then table.insert(quests, f) end
        end
        table.sort(quests, function(a, b)
            if a.ok ~= b.ok then return a.ok end
            if a.level ~= b.level then return a.level < b.level end
            return a.name < b.name
        end)
        if #quests > 0 then
            -- after your own professions, before the rest
            local at = 1
            while order[at] and mine[order[at]] do at = at + 1 end
            table.insert(order, at, "quests")
        end
        local bits = {}
        if total > 0 then table.insert(bits, string.format("%d recipe%s, %d profession%s", total, total == 1 and "" or "s",
            #order - (#quests > 0 and 1 or 0), #order - (#quests > 0 and 1 or 0) == 1 and "" or "s")) end
        if #quests > 0 then table.insert(bits, #quests .. " quest" .. (#quests == 1 and "" or "s")) end
        detail.usesTitle:ClearAllPoints()
        detail.usesTitle:SetPoint("TOPLEFT", 0, y)
        detail.usesTitle:SetText(color(GOLD_HEX, label or "Used in") .. "  "
            .. grey(#bits == 0 and "no Forever recipe or quest takes it" or table.concat(bits, ", ")))
        detail.usesTitle:SetWidth(BODY_W)
        detail.usesTitle:Show()
        y = y - detail.usesTitle:GetStringHeight() - 6
        for _, pid in ipairs(order) do
            local isQuests = pid == "quests"
            local my = not isQuests and mine[pid]
            local open = ns.DB.usesOpen and ns.DB.usesOpen[pid]
            if open == nil then open = isQuests or my ~= nil end
            nh = nh + 1
            local h = useHead(nh)
            h.pid, h.open = pid, open
            h.sign:SetTexture(open and "Interface\\Buttons\\UI-MinusButton-Up" or "Interface\\Buttons\\UI-PlusButton-Up")
            if isQuests then
                local yours = 0
                for _, f in ipairs(quests) do if f.ok then yours = yours + 1 end end
                h.icon:SetTexture("Interface\\GossipFrame\\AvailableQuestIcon")
                h.label:SetText(color(GOLD_HEX, "Quests") .. grey(yours < #quests and ("  " .. yours .. " you can take") or ""))
                h.value:SetText(#quests .. " quest" .. (#quests == 1 and "" or "s"))
            else
                h.icon:SetTexture(PROF_ICONS[pid] or "Interface\\Icons\\INV_Misc_QuestionMark")
                h.label:SetText(color(GOLD_HEX, ns.PROF_NAMES[pid] or "?") .. (my and grey("  yours, " .. my.rank) or ""))
                h.value:SetText(#by[pid] .. " recipe" .. (#by[pid] == 1 and "" or "s"))
            end
            h:ClearAllPoints()
            h:SetPoint("TOPLEFT", 0, y)
            h:Show()
            y = y - 24
            if open then
                for _, u in ipairs(isQuests and quests or by[pid]) do
                    nl = nl + 1
                    local b = useLine(nl)
                    if isQuests then
                        b.pid, b.idx = nil, nil -- nothing to open: a quest isn't in the window
                        b.fs:SetText(questText(u))
                    else
                        b.pid, b.idx = u.pid, u.idx
                        b.fs:SetText(useText(u, my))
                    end
                    b:SetHeight(b.fs:GetStringHeight() + 6)
                    b:ClearAllPoints()
                    b:SetPoint("TOPLEFT", 0, y)
                    b:Show()
                    y = y - b:GetHeight()
                end
                y = y - 4
            end
        end
        y = y - 8
    else
        detail.usesTitle:Hide()
    end
    for i = nh + 1, #useHeads do useHeads[i]:Hide() end
    for i = nl + 1, #useLines do useLines[i]:Hide() end
    return y
end

-- usesOf: an item id to show the "Used in" sections for (label overrides their heading).
local function setDetail(icon, title, titleHex, lines, history, canWhy, why, usesOf, usesLabel)
    detail.icon:SetTexture(icon or "Interface\\Icons\\INV_Misc_QuestionMark")
    detail.title:SetText(color(titleHex or "ffffffff", title or ""))
    detail.facts:SetText(table.concat(lines or {}, "\n"))
    local y = -(detail.facts:GetStringHeight() + 14)
    y = drawUses(usesOf, usesLabel, y)
    detail.histTitle:ClearAllPoints()
    detail.histTitle:SetPoint("TOPLEFT", 0, y)
    detail.histTitle:SetShown(history and #history > 0)
    if history and #history > 0 then y = y - 18 end
    for i = 1, 12 do
        local fs = histLines[i]
        if not fs then
            fs = text(detail.inner, "GameFontHighlightSmall", 0.8, 0.8, 0.8)
            fs:SetWidth(BODY_W)
            histLines[i] = fs
        end
        local h = history and history[i]
        if h then
            fs:ClearAllPoints()
            fs:SetPoint("TOPLEFT", 0, y)
            fs:SetText(h)
            fs:Show()
            y = y - fs:GetStringHeight() - 3
        else
            fs:Hide()
        end
    end
    detail.inner:SetHeight(math.max(-y, 10))
    -- back to the top for something new; kept when the same thing redraws (a section opening)
    if detail.shown ~= selectedKey then detail.body:SetVerticalScroll(0) end
    detail.shown = selectedKey
    detail.whyLabel:SetShown(canWhy)
    detail.why:SetShown(canWhy)
    detail.save:SetShown(canWhy)
    if canWhy and not detail.why:HasFocus() then detail.why:SetText(why or "") end
end

local function findAsk(id)
    for _, a in ipairs(ns.DB.asks or {}) do if a.id == id then return a end end
end


local function drawAsk()
    local id = selectedKey and selectedKey:match("^ask:(.+)$")
    local a = id and findAsk(id)
    if not a then
        setDetail("Interface\\Icons\\INV_Misc_Note_01", "Ask", GOLD_HEX, {
            "Type a question below, or /ask in chat. For example:", "",
            grey("where are the bears in Ashenvale? mark them on the map"),
            grey("which mobs near me drop Silk Cloth?"),
            grey("what is Briarthorn used for at 60?"), "",
            "The UI reloads to send it. The answer shows in the MindCollector app's Ask page, usually within a minute;",
            "then /reload here and it's in chat, in this tab, and on the map.", "",
            grey("Needs the MindCollector app open, with answering on (Settings > WoW Forever)."),
        }, nil, false)
    else
        local lines = {
            grey((a.status == "answered" and "Answered " .. when(a.at) or "Waiting for an answer") .. "  ·  asked " .. when(a.t)),
            grey(string.format("You were in %s%s", a.zone or "?", a.x and string.format(" at %.1f, %.1f", a.x, a.y) or "")),
            "",
        }
        if a.status == "answered" then
            table.insert(lines, a.answer or "")
            if a.pins and #a.pins > 0 then
                table.insert(lines, "")
                table.insert(lines, color(GOLD_HEX, #a.pins .. " point" .. (#a.pins == 1 and "" or "s") .. " for the map")
                    .. (ns.activeAsk == a and grey("  (showing now)") or ""))
            end
        else
            table.insert(lines, "If the MindCollector app is open, the answer is probably ready: reload to load it.")
        end
        setDetail("Interface\\Icons\\INV_Misc_Note_01", a.q, "ffffffff", lines, nil, false)
        if a.status == "answered" and a.pins and #a.pins > 0 then
            button(detail.b1, "Show on map", function() ns.showPins(a); ns.openMapFor(a); refresh() end)
            button(detail.b2, "Clear map", function() ns.clearPins(); refresh() end)
        elseif a.status ~= "answered" then
            button(detail.b1, "Reload to check", function() ReloadUI() end)
        end
    end
    -- the bottom box asks instead of noting why
    detail.whyLabel:SetText(color(GOLD_HEX, "Ask something"))
    detail.save:SetText("Ask")
    detail.whyLabel:Show(); detail.why:Show(); detail.save:Show()
    if not detail.why:HasFocus() then detail.why:SetText("") end
end

local function drawDetail()
    detail.b1:Hide(); detail.b2:Hide()
    detail.whyLabel:SetText(color(GOLD_HEX, "Why were you looking?"))
    detail.save:SetText("Save")
    if tab == "ask" then return drawAsk() end
    if tab == "ledger" and selectedKey and selectedKey:match("^lsum:") then
        local which = selectedKey:match("^lsum:(.+)$")
        local since = which == "Today" and startOfToday() or which == "Last 7 days" and (time() - 7 * 86400) or 0
        local t = ledgerTotals(since)
        setDetail("Interface\\Icons\\INV_Misc_Coin_01", which, GOLD_HEX, {
            string.format("Made %d things, with reagents worth about %s when you made them", t.made, ns.money(t.value)),
            "Spent " .. ns.money(t.spent) .. " (auction house and vendors)",
            "Earned " .. ns.money(t.earned) .. " (auction sales after the cut, and vendor sales)",
            "",
            "Net: " .. signedMoney(t.earned - t.spent),
            "",
            grey("Auction sales count when you take the gold at the mailbox. Vendor sales show as one line each, since the game doesn't say which item the gold was for."),
        }, nil, false)
        return
    end
    if tab == "ledger" and selectedKey and selectedKey:match("^ledger:") then
        local _, by = ledgerItems()
        local x = by[selectedKey:match("^ledger:(.+)$")]
        if not x then selectedKey = nil return drawDetail() end
        local lines = {}
        if x.made > 0 then table.insert(lines, string.format("Made %d, reagents worth about %s (%s each)", x.made, ns.money(x.value), ns.money(x.value / x.made))) end
        if x.bought > 0 then table.insert(lines, string.format("Bought %d for %s (%s each)", x.bought, ns.money(x.spent), ns.money(x.spent / x.bought))) end
        if x.posted > 0 then table.insert(lines, string.format("Posted %d on the auction house, %d sold so far", x.posted, x.sold)) end
        if x.sold > 0 then table.insert(lines, string.format("Sold %d for %s after the cut (%s each)", x.sold, ns.money(x.earned), ns.money(x.earned / x.sold))) end
        if x.made > 0 and x.sold > 0 and x.value > 0 then
            local each = x.earned / x.sold - x.value / x.made
            table.insert(lines, "")
            table.insert(lines, (each >= 0 and color("ff40d060", "About " .. ns.money(each) .. " profit on each one sold"))
                or color("ffe05050", "About " .. ns.money(-each) .. " lost on each one sold"))
        end
        local hist = {}
        for i = #x.entries, math.max(1, #x.entries - 11), -1 do
            local e = x.entries[i]
            table.insert(hist, string.format("%s  ·  %s %d%s", date("%d %b %H:%M", e.t), LEDGER_KIND[e.k] or e.k, e.q or 1,
                e.c ~= 0 and ("  ·  " .. signedMoney(e.c)) or (e.v and ("  ·  reagents " .. ns.money(e.v)) or "")))
        end
        setDetail((x.i and x.i > 0) and C_Item.GetItemIconByID(x.i) or "Interface\\Icons\\INV_Misc_Bag_10", x.name,
            (x.i and x.i > 0) and nameColor({ kind = "item", id = x.i }) or "ffffffff", lines, hist, false, nil, x.i)
        if x.i and x.i > 0 then trackButtons(x.i) end
        return
    end
    if not selectedKey then
        local hint = tab == "prof" and { grey("Click a profession to see the recipes that give a point at your skill, cheapest per point first."), "",
                grey("A full auction house scan (Market tab, or /mco scan) prices every reagent at once.") }
            or tab == "movers" and { grey("The biggest price moves on the whole auction house between your last two full scans.") }
            or tab == "ledger" and { grey("What you made, bought and sold. Click a line for its details; click an item to see what each one cost and earned.") }
            or { grey("The items you track. Each keeps its cheapest price and how many are listed at every scan."), "",
                 grey("To add one, pick it in Market or Professions and press Track on the right."), "",
                 grey("Shift-click an item to link it in chat.") }
        setDetail(nil, "Pick something on the left", GOLD_HEX, hint, nil, false)
        return
    end
    local profID = selectedKey:match("^prof:(%d+)$")
    local rpid, ridx = selectedKey:match("^recipe:(%d+):(%d+)$")
    if profID or rpid then
        local pid = tonumber(profID or rpid)
        local mine = ns.myProfessions and ns.myProfessions()[pid]
        local rank = mine and mine.rank or 0
        if profID then
            local now = ns.recipes and ns.recipes[pid] and recipesFor(pid, rank) or nil
            local lines = { grey("Profession"), "", string.format("Skill %d of %s", rank, mine and mine.max or "?"), "" }
            if not now then
                table.insert(lines, "A gathering skill, so there are no recipes to list. Track what you gather from Market to see its price at every scan.")
            else
                table.insert(lines, #now .. " recipes give a point at your skill. They're listed cheapest per point first, from the prices you've seen.")
                table.insert(lines, "")
                table.insert(lines, "The switch at the top of the list also sorts by Most profit: everything you can make, points or not, by what one craft earns.")
                table.insert(lines, "")
                table.insert(lines, grey("Orange always gives a point, yellow usually, green sometimes. Prices are the auction house's cheapest listing you last saw, or your last full scan; vials, thread and spices at the vendor's price."))
            end
            setDetail(PROF_ICONS[pid], mine and mine.name or "?", GOLD_HEX, lines, nil, false)
            return
        end
        local r = ns.recipes and ns.recipes[pid] and ns.recipes[pid][tonumber(ridx)]
        if not r then selectedKey = nil return drawDetail() end
        local hex, chance, word = skillColor(r, rank)
        local cost, missing, made = recipeCost(r, pid, rank)
        local lines = {
            grey((ns.PROF_NAMES[pid] or "Recipe") .. ", " .. (mine and (word .. " at your " .. rank) or "not one of your professions")),
            "",
            not mine and string.format("Learned at %s %d", ns.PROF_NAMES[pid] or "skill", r[3])
                or chance >= 1 and "Gives a point every time" or chance > 0 and string.format("Gives a point about %d%% of the time", math.floor(chance * 100 + 0.5)) or "Gives no more points",
            string.format("Turns yellow at %d, green at %d, grey at %d", r[4], math.floor((r[4] + r[5]) / 2), r[5]),
            "",
        }
        local _, sourceLines, point = sourceOf(r, mine and mine.name or ns.PROF_NAMES[pid])
        for _, l in ipairs(sourceLines) do table.insert(lines, l) end
        table.insert(lines, "")
        for i = 1, #r[8], 2 do
            local id, cnt = r[8][i], r[8][i + 1]
            local p, src, t = unitPrice(id)
            local have = C_Item.GetItemCount(id, true) or 0
            local priceText = p and (ns.money(p) .. " each (" .. (src == "vendor" and "vendor" or ("auction, " .. when(t))) .. ")") or grey("no price yet")
            if made[id] then
                priceText = color("ff40d060", "make it yourself: " .. ns.money(made[id]) .. " each") .. grey(p and ("  (buying: " .. ns.money(p) .. ")") or "")
            end
            table.insert(lines, string.format("%d x %s  ·  %s%s", cnt, itemName(id), priceText, have > 0 and ("  ·  you have " .. have) or ""))
        end
        table.insert(lines, "")
        table.insert(lines, "Cost to make: " .. ns.money(cost) .. (missing > 0 and grey("  (" .. missing .. " reagent price" .. (missing == 1 and "" or "s") .. " unknown)") or ""))
        if missing == 0 and chance > 0 then table.insert(lines, color(GOLD_HEX, "About " .. ns.money(cost / chance) .. " per skill point")) end
        if r[7] > 0 then
            local net, p = sellValue(r)
            local made = (r.yield or 1) > 1 and (r.yield .. " ") or ""
            table.insert(lines, "Makes " .. made .. itemName(r[7]) .. (p and string.format("; each sells for about %s (%s listed)", ns.money(p.min), p.q or "?") or ", with no auction price yet"))
            if net and missing == 0 then
                local profit = net - cost
                table.insert(lines, profit > 0
                    and color("ff40d060", "Each craft sold earns about " .. ns.money(profit) .. " after the 5% cut")
                    or ("Each craft sold loses about " .. ns.money(-profit) .. " after the 5% cut"))
            end
            local trend = listingTrend(r[7])
            if trend then table.insert(lines, "Between your last two scans: " .. trend) end
            if net and missing == 0 and chance > 0 then
                local per = (cost - net) / chance
                table.insert(lines, per <= 0
                    and color("ff40d060", "Sold after the 5% cut, each point earns about " .. ns.money(-per))
                    or ("Sold after the 5% cut, each point costs about " .. ns.money(per)))
                if p and p.q and p.q > 200 then table.insert(lines, grey("Lots are listed already, so it may sell slowly or for less.")) end
            end
        end
        setDetail((r[7] > 0 and C_Item.GetItemIconByID(r[7])) or C_Spell.GetSpellTexture(r[1]), r[2], hex, lines, nil, false, nil,
            r[7], r[7] > 0 and (itemName(r[7]) .. " is used in") or nil)
        if point then
            button(detail.b1, "Show on map", function()
                ns.showPins({ q = r[2], pins = { point } })
                ns.openMapFor({ pins = { point } })
            end)
        elseif r[6] == 1 and r[10] and r[10] > 0 then
            button(detail.b1, "Ask where to get it", function()
                ns.ask("Where can I get " .. itemName(r[10]) .. " in WoW Forever? Mark the vendor or the mobs that drop it on the map.")
            end)
        end
        if r[7] > 0 then
            button(detail.b2, ns.isTracked(r[7]) and "Stop tracking it" or "Track what it makes", function() ns.toggleTrack(r[7]) end)
        end
        return
    end
    if selectedKey:match("^soon:") then selectedKey = nil return drawDetail() end
    local moverID = selectedKey:match("^mover:(%d+)$")
    if moverID then
        local id = tonumber(moverID)
        local v = ns.DB.lastScan and ns.DB.lastScan.prices[id]
        local o = ns.DB.prevScan and ns.DB.prevScan.prices[id]
        local s = ns.DB.subjects["item:" .. id]
        local lines = {}
        if v then table.insert(lines, "Now: " .. ns.money(v[1]) .. "  (" .. v[2] .. " listed)") end
        if o then table.insert(lines, "Before: " .. ns.money(o[1]) .. "  (" .. o[2] .. " listed)") end
        if v and o then table.insert(lines, "Change: " .. changeText(o[1], v[1])) end
        historyLines(id, lines)
        if s and (s.looks or 0) > 0 then
            table.insert(lines, "")
            table.insert(lines, color(GOLD_HEX, "You looked at this " .. s.looks .. " times, last " .. when(s.last) .. "."))
        end
        setDetail(C_Item.GetItemIconByID(id), itemName(id, v and v[3]),
            nameColor({ kind = "item", id = id }), lines, nil, false, nil, id)
        trackButtons(id)
        return
    end
    local s = ns.DB.subjects[selectedKey]
    if not s then selectedKey = nil return drawDetail() end
    local e = s.events[#s.events] or {}
    local since = s.kind == "item" and ns.DB.tracked and ns.DB.tracked[s.id]
    local lines = { grey(s.kind == "item" and (since and "Tracked item" or "Item") or s.kind:gsub("^%l", string.upper)), "" }
    if since then table.insert(lines, "Tracking since " .. date("%d %b %H:%M", since)) end
    if (s.looks or 0) > 0 then
        table.insert(lines, "First looked: " .. date("%d %b %H:%M", s.first))
        table.insert(lines, "Last looked: " .. when(s.last))
        table.insert(lines, string.format("Looked %d time%s, %d seconds in all", s.looks, s.looks == 1 and "" or "s", math.floor(s.secs + 0.5)))
    end
    if s.kind == "item" then
        local first, cur = priceTrail(s)
        table.insert(lines, "")
        if first then table.insert(lines, "Price when you first looked: " .. ns.money(first.p) .. "  (" .. ns.shortDate(first.t) .. ")") end
        if cur then
            table.insert(lines, "Latest price: " .. ns.money(cur.min) .. (cur.q and ("  (" .. cur.q .. " listed, " .. when(cur.t) .. ")") or ""))
        else
            table.insert(lines, grey("No price yet: open it in the auction house."))
        end
        if first and cur then table.insert(lines, "Change: " .. changeText(first.p, cur.min)) end
        local have = C_Item.GetItemCount(s.id, true)
        if have and have > 0 then table.insert(lines, "You have " .. have) end
        historyLines(s.id, lines)
    elseif s.kind == "talent" then
        table.insert(lines, "")
        table.insert(lines, s.takenAt and color("ff40d060", "Taken " .. ns.shortDate(s.takenAt))
            or (s.hadIt and "You had it when you looked" or "Not taken"))
    end
    table.insert(lines, "")
    table.insert(lines, color(GOLD_HEX, "Probably: ") .. (e.g or "?"))
    if s.notes and #s.notes > 0 then
        table.insert(lines, "")
        for i = #s.notes, math.max(1, #s.notes - 2), -1 do table.insert(lines, color(GOLD_HEX, s.notes[i].text)) end
    end
    local hist = {}
    for i = #s.events, math.max(1, #s.events - 11), -1 do
        local ev = s.events[i]
        local bits = { date("%d %b %H:%M", ev.t), ev.ctx or "?" }
        if ev.s and ev.s > 0 then table.insert(bits, ev.s .. "s") end
        if ev.p then table.insert(bits, ns.money(ev.p)) end
        if ev.n and ev.n > 0 then table.insert(bits, "had " .. ev.n) end
        if ev.why then table.insert(bits, color(GOLD_HEX, "you: " .. ev.why)) end
        table.insert(hist, table.concat(bits, "  ·  "))
    end
    setDetail(iconOf(s.kind, s.id), displayName(s), nameColor(s), lines, hist, true, e.why, s.kind == "item" and s.id or nil)
    if s.kind == "item" then trackButtons(s.id) end
end

redraw = function()
    if not (frame and frame:IsShown() and ns.DB) then return end
    local c = counts()
    for _, b in ipairs(frame.tabs) do
        local label = b.def.label .. (c[b.def.key] and ("  " .. c[b.def.key]) or "")
        b.label:SetText(b.def.key == tab and color(GOLD_HEX, label) or label)
        b:SetBackdropBorderColor(GOLD[1], GOLD[2], GOLD[3], b.def.key == tab and 0.9 or 0.15)
    end
    -- Each part draws on its own: an error in one is said in chat once (file and line, to pass on)
    -- and the rest still draws, instead of a blank panel (2026-11-12).
    for _, part in ipairs({ { "the list", drawList }, { "the details", drawDetail } }) do
        local ok, err = pcall(part[2])
        if not ok then
            err = tostring(err)
            ns.shownErrors = ns.shownErrors or {}
            if not ns.shownErrors[err] then
                ns.shownErrors[err] = true
                if ns.say then ns.say("the window couldn't draw " .. part[1] .. ": " .. err) end
            end
        end
    end
    -- the footer: a scan in progress, else when the last one was
    local last = ns.DB.lastScan
    local pool = ns.pool
    -- with shared prices loaded, the footer says whose scans they are instead of the 15-minute rule
    local tail = (pool and pool.t) and string.format(" Shared prices: %d player%s, newest %s.",
        pool.players or 0, (pool.players or 0) == 1 and "" or "s", when(pool.t)) or " The server allows one every 15 minutes."
    frame.foot:SetText(ns.scanStatus and color(GOLD_HEX, "Scanning: " .. ns.scanStatus)
        or last and string.format("Last full scan %s: %d auctions, %d items." .. tail,
            when(last.t), last.n or 0, last.items or (function() local c = 0 for _ in pairs(last.prices or {}) do c = c + 1 end return c end)())
        or (pool and pool.t) and ("No full scan of yours yet." .. tail)
        or "No full scan yet. With the auction house open, Scan reads every listing at once.")
    if frame.scanBtn then
        frame.scanBtn:SetEnabled(not ns.scanStatus)
        frame.scanBtn:SetText(ns.scanStatus and "Scanning..." or "Scan auction house")
    end
end

-- Every event asks for a redraw (a look, a price, a scan tick, a ledger line), and a redraw walks
-- the open tab's data. Asked-for redraws are gathered into one a few frames later (14 Nov), and none
-- happens while the window is hidden; opening it draws at once (OnShow).
local redrawQueued = false
refresh = function()
    if redrawQueued or not (frame and frame:IsShown() and ns.DB) then return end
    redrawQueued = true
    C_Timer.After(0.05, function()
        redrawQueued = false
        redraw()
    end)
end

-- ---------------------------------------------------------------- building the window

local function makeWindow()
    frame = CreateFrame("Frame", "MindCollectorObserverFrame", UIParent, "BackdropTemplate")
    frame:SetSize(W, H)
    frame:SetPoint("CENTER")
    backdrop(frame, { 0.03, 0.03, 0.03, 0.97 }, { GOLD[1], GOLD[2], GOLD[3], 0.8 })
    frame:SetMovable(true)
    frame:EnableMouse(true)
    frame:RegisterForDrag("LeftButton")
    frame:SetScript("OnDragStart", frame.StartMoving)
    frame:SetScript("OnDragStop", frame.StopMovingOrSizing)
    frame:SetFrameStrata("HIGH")
    frame:SetClampedToScreen(true)
    table.insert(UISpecialFrames, "MindCollectorObserverFrame")

    local title = text(frame, "GameFontNormalLarge")
    title:SetPoint("TOPLEFT", 14, -12)
    title:SetText(color(GOLD_HEX, "MindCollector") .. " Observer")
    local close = CreateFrame("Button", nil, frame, "UIPanelCloseButton")
    close:SetPoint("TOPRIGHT", -2, -2)

    -- tabs
    frame.tabs = {}
    local x = 12
    for _, def in ipairs(TABS) do
        local b = CreateFrame("Button", nil, frame, "BackdropTemplate")
        backdrop(b, { 0.08, 0.08, 0.08, 1 })
        b.def = def
        b.label = text(b, "GameFontHighlight")
        b.label:SetPoint("CENTER")
        b:SetHeight(24)
        b:SetPoint("TOPLEFT", x, -40)
        b:SetScript("OnClick", function()
            tab = def.key
            selectedKey = nil
            list.scroll:SetVerticalScroll(0)
            refresh()
        end)
        b.label:SetText(def.label .. "  000")
        b:SetWidth(b.label:GetStringWidth() + 22)
        x = x + b:GetWidth() + 6
        table.insert(frame.tabs, b)
    end

    -- search
    search = CreateFrame("EditBox", nil, frame, "BackdropTemplate")
    backdrop(search, { 0.08, 0.08, 0.08, 1 }, { 0.4, 0.4, 0.4, 0.6 })
    search:SetSize(170, 24)
    search:SetPoint("TOPRIGHT", -14, -40)
    search:SetFontObject(ChatFontNormal)
    search:SetTextInsets(8, 8, 0, 0)
    search:SetAutoFocus(false)
    search.hint = text(search, "GameFontHighlightSmall", 0.5, 0.5, 0.5)
    search.hint:SetPoint("LEFT", 8, 0)
    search.hint:SetText("Search")
    search:SetScript("OnTextChanged", function(self)
        query = self:GetText():lower()
        self.hint:SetShown(query == "")
        refresh()
    end)
    search:SetScript("OnEscapePressed", function(self) self:ClearFocus() end)
    search:SetScript("OnEnterPressed", function(self) self:ClearFocus() end)

    -- list
    list = CreateFrame("Frame", nil, frame, "BackdropTemplate")
    backdrop(list, { 0.02, 0.02, 0.02, 1 }, { 0.25, 0.25, 0.25, 0.6 })
    list:SetPoint("TOPLEFT", 12, -72)
    list:SetPoint("BOTTOMLEFT", 12, 44)
    list:SetWidth(LIST_W)
    list.scroll = CreateFrame("ScrollFrame", nil, list, "UIPanelScrollFrameTemplate")
    list.scroll:SetPoint("TOPLEFT", 4, -4)
    list.scroll:SetPoint("BOTTOMRIGHT", -26, 4)
    list.child = CreateFrame("Frame", nil, list.scroll)
    list.child:SetSize(LIST_W - 30, 10)
    list.scroll:SetScrollChild(list.child)
    list.note = text(list, "GameFontHighlightSmall", 0.6, 0.6, 0.6)
    list.note:SetPoint("BOTTOMLEFT", list, "BOTTOMLEFT", 6, -16)
    frame.mktBar = CreateFrame("Frame", nil, frame)
    frame.mktBar:SetPoint("TOPLEFT", 12, -72)
    frame.mktBar:SetSize(LIST_W, 24)
    frame.mktBar.chips = {}
    frame.mktBar:Hide()
    list.note:SetWidth(LIST_W)

    -- details
    detail = CreateFrame("Frame", nil, frame, "BackdropTemplate")
    backdrop(detail, { 0.06, 0.06, 0.06, 1 }, { 0.25, 0.25, 0.25, 0.6 })
    detail:SetPoint("TOPLEFT", frame, "TOPLEFT", 12 + LIST_W + 10, -72)
    detail:SetPoint("BOTTOMRIGHT", -12, 44)
    detail.icon = detail:CreateTexture(nil, "ARTWORK")
    detail.icon:SetSize(44, 44)
    detail.icon:SetPoint("TOPLEFT", 12, -12)
    detail.icon:SetTexCoord(0.07, 0.93, 0.07, 0.93)
    detail.title = text(detail, "GameFontNormalLarge")
    detail.title:SetPoint("TOPLEFT", detail.icon, "TOPRIGHT", 10, -4)
    detail.title:SetPoint("RIGHT", -10, 0)
    detail.title:SetWordWrap(true)
    detail.body = CreateFrame("ScrollFrame", nil, detail, "UIPanelScrollFrameTemplate")
    detail.body:SetPoint("TOPLEFT", 12, -66)
    detail.body:SetPoint("BOTTOMRIGHT", -30, 100) -- clear of the buttons and the why box
    detail.inner = CreateFrame("Frame", nil, detail.body)
    detail.inner:SetSize(BODY_W, 10)
    detail.body:SetScrollChild(detail.inner)
    detail.facts = text(detail.inner, "GameFontHighlightSmall", 0.9, 0.9, 0.9)
    detail.facts:SetPoint("TOPLEFT", 0, 0)
    detail.facts:SetWidth(BODY_W)
    detail.facts:SetSpacing(2)
    detail.usesTitle = text(detail.inner, "GameFontNormal")
    detail.histTitle = text(detail.inner, "GameFontNormal")
    detail.histTitle:SetText(color(GOLD_HEX, "Each look"))
    detail.whyLabel = text(detail, "GameFontNormal")
    detail.whyLabel:SetPoint("BOTTOMLEFT", 12, 40)
    detail.whyLabel:SetText(color(GOLD_HEX, "Why were you looking?"))
    detail.why = CreateFrame("EditBox", nil, detail, "BackdropTemplate")
    backdrop(detail.why, { 0.1, 0.1, 0.1, 1 }, { 0.4, 0.4, 0.4, 0.6 })
    detail.why:SetPoint("BOTTOMLEFT", 12, 10)
    detail.why:SetPoint("BOTTOMRIGHT", -80, 10)
    detail.why:SetHeight(24)
    detail.why:SetFontObject(ChatFontNormal)
    detail.why:SetTextInsets(8, 8, 0, 0)
    detail.why:SetAutoFocus(false)
    detail.why:SetMaxLetters(120)
    local function saveWhy()
        if tab == "ask" then
            local q = detail.why:GetText()
            detail.why:ClearFocus()
            if q and q:find("%S") then detail.why:SetText(""); ns.ask(q) end
            return
        end
        local s = selectedKey and ns.DB.subjects[selectedKey]
        local e = s and s.events[#s.events]
        if not e then return end
        local v = detail.why:GetText():gsub("^%s+", ""):gsub("%s+$", "")
        e.why = v ~= "" and v or nil
        detail.why:ClearFocus()
        refresh()
    end
    detail.why:SetScript("OnEnterPressed", saveWhy)
    detail.why:SetScript("OnEscapePressed", function(self) self:ClearFocus() end)
    detail.save = CreateFrame("Button", nil, detail, "UIPanelButtonTemplate")
    detail.save:SetSize(62, 24)
    detail.save:SetPoint("BOTTOMRIGHT", -10, 10)
    detail.save:SetText("Save")
    detail.save:SetScript("OnClick", saveWhy)

    detail.b1 = CreateFrame("Button", nil, detail, "UIPanelButtonTemplate")
    detail.b1:SetSize(110, 24)
    detail.b1:SetPoint("BOTTOMLEFT", 12, 70)
    detail.b1:Hide()
    detail.b2 = CreateFrame("Button", nil, detail, "UIPanelButtonTemplate")
    detail.b2:SetSize(110, 24)
    detail.b2:SetPoint("LEFT", detail.b1, "RIGHT", 8, 0)
    detail.b2:Hide()

    -- footer
    local scanBtn = CreateFrame("Button", nil, frame, "UIPanelButtonTemplate")
    scanBtn:SetSize(150, 24)
    scanBtn:SetPoint("BOTTOMLEFT", 12, 12)
    scanBtn:SetText("Scan auction house")
    scanBtn:SetScript("OnClick", function() ns.scan() end)
    frame.scanBtn = scanBtn
    -- Reload (14 Nov): WoW writes the addon's data only at a reload, so scans and questions reach the
    -- app then, and answers and everyone's prices load then. A button, since /reload was easy to forget.
    local reloadBtn = CreateFrame("Button", nil, frame, "UIPanelButtonTemplate")
    reloadBtn:SetSize(70, 24)
    reloadBtn:SetPoint("LEFT", scanBtn, "RIGHT", 6, 0)
    reloadBtn:SetText("Reload")
    reloadBtn:SetScript("OnClick", function() ReloadUI() end)
    reloadBtn:SetScript("OnEnter", function(self)
        GameTooltip:SetOwner(self, "ANCHOR_TOP")
        GameTooltip:SetText("Reload the UI")
        GameTooltip:AddLine("WoW saves this addon's data only at a reload: your scans and questions reach the MindCollector app then, and its answers and everyone's prices load.", 1, 1, 1, true)
        GameTooltip:Show()
    end)
    reloadBtn:SetScript("OnLeave", function() GameTooltip:Hide() end)
    local foot = text(frame, "GameFontHighlightSmall", 0.55, 0.55, 0.55)
    foot:SetPoint("LEFT", reloadBtn, "RIGHT", 12, 0)
    foot:SetPoint("RIGHT", frame, "RIGHT", -14, 0)
    foot:SetText("A look is a tooltip held for " .. (ns.DB and ns.DB.minDwell or 2.5) ..
        "s or an item opened in the auction house. The reasons are guesses; tell it the real one and it keeps both.")
    frame.foot = foot

    frame:SetScript("OnShow", redraw)
end

function ns.toggle()
    if not ns.DB then return end
    if not frame then makeWindow() end
    frame:SetShown(not frame:IsShown())
end

ns.refresh = refresh

-- Tooltips the window itself shows are not looks.
function ns.owns(f)
    while f do
        if f == frame or f == ns.minimapButton then return true end
        f = f.GetParent and f:GetParent()
    end
    return false
end

-- ---------------------------------------------------------------- minimap button

function ns.makeMinimapButton()
    if ns.minimapButton or not Minimap then return end
    local b = CreateFrame("Button", "MindCollectorObserverMinimapButton", Minimap)
    b:SetSize(31, 31)
    b:SetFrameStrata("MEDIUM")
    b:SetFrameLevel(8)
    local icon = b:CreateTexture(nil, "BACKGROUND")
    icon:SetTexture("Interface\\Icons\\INV_Misc_Spyglass_03")
    icon:SetSize(20, 20)
    icon:SetPoint("CENTER", 0, 1)
    local border = b:CreateTexture(nil, "OVERLAY")
    border:SetTexture("Interface\\Minimap\\MiniMap-TrackingBorder")
    border:SetSize(53, 53)
    border:SetPoint("TOPLEFT")
    b:SetHighlightTexture("Interface\\Minimap\\UI-Minimap-ZoomButton-Highlight")

    local function place()
        local a = math.rad(ns.DB.minimapAngle or 210)
        local r = (Minimap:GetWidth() / 2) + 5
        b:ClearAllPoints()
        b:SetPoint("CENTER", Minimap, "CENTER", math.cos(a) * r, math.sin(a) * r)
    end
    place()
    b:RegisterForDrag("LeftButton")
    b:SetScript("OnDragStart", function(self)
        self:SetScript("OnUpdate", function()
            local mx, my = Minimap:GetCenter()
            local cx, cy = GetCursorPosition()
            local s = Minimap:GetEffectiveScale()
            ns.DB.minimapAngle = math.deg(math.atan2(cy / s - my, cx / s - mx))
            place()
        end)
    end)
    b:SetScript("OnDragStop", function(self) self:SetScript("OnUpdate", nil) end)
    b:SetScript("OnClick", function() ns.toggle() end)
    b:SetScript("OnEnter", function(self)
        GameTooltip:SetOwner(self, "ANCHOR_LEFT")
        GameTooltip:SetText("MindCollector Observer")
        GameTooltip:AddLine("Click to open. Drag to move.", 1, 1, 1)
        GameTooltip:Show()
    end)
    b:SetScript("OnLeave", function() GameTooltip:Hide() end)
    ns.minimapButton = b
end
