-- The MindCollector Observer window: tabs by kind, a list on the left, the selected thing's details
-- on the right, and a box to say why you were looking. Black and gold, like the site.

local _, ns = ...

local GOLD = { 0.83, 0.69, 0.22 }
local GOLD_HEX = "ffd4af37"
local W, H, LIST_W, ROW_H = 920, 540, 570, 38

local frame, list, detail, search
local tab, selectedKey, query = "item", nil, ""
local rows, histLines = {}, {}

local TABS = {
    { key = "item", label = "Items" },
    { key = "talent", label = "Talents" },
    { key = "spell", label = "Spells" },
    { key = "player", label = "Players" },
    { key = "other", label = "Other" },
    { key = "movers", label = "Market movers" },
    { key = "ask", label = "Ask" },
}

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
    local n = s.name or (s.kind .. " " .. tostring(s.id))
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

local function matches(name)
    if query == "" then return true end
    return name and name:lower():find(query, 1, true)
end

-- ---------------------------------------------------------------- data for each tab

local function kindOf(s)
    if s.kind == "item" or s.kind == "talent" or s.kind == "spell" or s.kind == "player" then return s.kind end
    return "other"
end

local function subjectsFor(k)
    local out = {}
    for key, s in pairs(ns.DB.subjects) do
        if kindOf(s) == k and matches(s.name) then table.insert(out, { key = key, s = s }) end
    end
    table.sort(out, function(a, b) return (a.s.last or 0) > (b.s.last or 0) end)
    return out
end

local function movers()
    local a, b = ns.DB.lastScan, ns.DB.prevScan
    if not (a and b) then return {}, nil end
    local out = {}
    for id, v in pairs(a.prices) do
        local old = b.prices[id]
        if old and old[1] > 0 and v[2] >= 5 then
            local name = C_Item.GetItemNameByID(id) or v[3]
            if matches(name or "") then
                table.insert(out, { id = id, name = name, was = old[1], now = v[1], q = v[2], p = (v[1] - old[1]) / old[1] })
            end
        end
    end
    table.sort(out, function(x, y) return math.abs(x.p) > math.abs(y.p) end)
    return out, string.format("%s compared with %s", ns.shortDate(a.t), ns.shortDate(b.t))
end

local function counts()
    local c = { item = 0, talent = 0, spell = 0, player = 0, other = 0 }
    for _, s in pairs(ns.DB.subjects) do c[kindOf(s)] = c[kindOf(s)] + 1 end
    c.ask = #(ns.DB.asks or {})
    return c
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

local refresh

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
    r:SetScript("OnEnter", function(self) if self.key ~= selectedKey then self:SetBackdropColor(0.14, 0.12, 0.06, 0.95) end end)
    r:SetScript("OnLeave", function(self) if self.key ~= selectedKey then self:SetBackdropColor(0.08, 0.08, 0.08, 0.9) end end)
    r:RegisterForClicks("LeftButtonUp")
    r:SetScript("OnClick", function(self)
        if IsShiftKeyDown() and self.itemID then
            local _, link = C_Item.GetItemInfo(self.itemID)
            if link then ChatEdit_InsertLink(link) end
            return
        end
        selectedKey = self.key
        refresh()
    end)
    rows[i] = r
    return r
end

local function fillRow(r, key, icon, name, nameHex, value, sub, itemID)
    r.key, r.itemID = key, itemID
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
    local e = s.events[#s.events] or {}
    local why = e.why and ("you said: " .. e.why) or ("probably " .. (e.g or "looking"))
    local bits = { why, e.ctx or "?" }
    if s.kind == "player" and s.level then bits[2] = "level " .. s.level .. " " .. (s.class or ""):lower() .. ", " .. bits[2] end
    table.insert(bits, string.format("%d look%s, %ds", s.looks, s.looks == 1 and "" or "s", math.floor(s.secs + 0.5)))
    table.insert(bits, when(s.last))
    return table.concat(bits, "  ·  ")
end

local function valueText(s)
    if s.kind == "item" then
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

local function drawList()
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
    elseif tab == "movers" then
        local ms, label = movers()
        list.note:SetText(label and ("Whole auction house, " .. label .. ", at least 5 listed")
            or "Needs two full scans: open the auction house and click Scan (or /mco scan), then again another day.")
        for i = 1, math.min(#ms, 300) do
            local m = ms[i]
            n = n + 1
            local r = rows[n] or makeRow(n)
            local key = "mover:" .. m.id
            local looked = ns.DB.subjects["item:" .. m.id] and color(GOLD_HEX, "you looked at this  ·  ") or ""
            fillRow(r, key, C_Item.GetItemIconByID(m.id), m.name or ("item " .. m.id),
                nameColor({ kind = "item", id = m.id }), ns.money(m.was) .. " > " .. ns.money(m.now) .. "  " .. changeText(m.was, m.now),
                looked .. m.q .. " listed", m.id)
            place(r)
        end
    else
        local ss = subjectsFor(tab)
        list.note:SetText(#ss == 0 and "Nothing here yet. Hold your mouse over something for a few seconds." or "")
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

local function setDetail(icon, title, titleHex, lines, history, canWhy, why)
    detail.icon:SetTexture(icon or "Interface\\Icons\\INV_Misc_QuestionMark")
    detail.title:SetText(color(titleHex or "ffffffff", title or ""))
    detail.facts:SetText(table.concat(lines or {}, "\n"))
    local y = -(detail.facts:GetStringHeight() + 96)
    detail.histTitle:ClearAllPoints()
    detail.histTitle:SetPoint("TOPLEFT", 12, y)
    detail.histTitle:SetShown(history and #history > 0)
    y = y - 18
    local floor = -(detail:GetHeight() - 74) -- keep clear of the why box
    for i = 1, 12 do
        local fs = histLines[i]
        if not fs then
            fs = text(detail, "GameFontHighlightSmall", 0.8, 0.8, 0.8)
            fs:SetWidth(W - LIST_W - 50)
            histLines[i] = fs
        end
        local h = history and history[i]
        if h and y - 14 > floor then
            fs:ClearAllPoints()
            fs:SetPoint("TOPLEFT", 12, y)
            fs:SetText(h)
            fs:Show()
            y = y - fs:GetStringHeight() - 3
        else
            fs:Hide()
        end
    end
    detail.whyLabel:SetShown(canWhy)
    detail.why:SetShown(canWhy)
    detail.save:SetShown(canWhy)
    if canWhy and not detail.why:HasFocus() then detail.why:SetText(why or "") end
end

local function findAsk(id)
    for _, a in ipairs(ns.DB.asks or {}) do if a.id == id then return a end end
end

local function button(b, label, fn)
    b:SetText(label)
    b:SetWidth(b:GetFontString():GetStringWidth() + 24)
    b:SetScript("OnClick", fn)
    b:Show()
end

local function drawAsk()
    local id = selectedKey and selectedKey:match("^ask:(.+)$")
    local a = id and findAsk(id)
    if not a then
        setDetail("Interface\\Icons\\INV_Misc_Note_01", "Ask Claude", GOLD_HEX, {
            "Type a question below, or /ask in chat. For example:", "",
            grey("where are the bears in Ashenvale? mark them on the map"),
            grey("which mobs near me drop Silk Cloth?"),
            grey("what is Briarthorn used for at 60?"), "",
            "The UI reloads to send it. The answer shows in the MindCollector Ask window on your desktop in a minute or two;",
            "then /reload here and it's in chat, in this tab, and on the map.", "",
            grey("Needs the bridge running on your PC: tools/forever-ask/ask_bridge.py"),
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
            table.insert(lines, "If the bridge is running, the answer is probably ready: reload to load it.")
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
    detail.whyLabel:SetText(color(GOLD_HEX, "Ask Claude something"))
    detail.save:SetText("Ask")
    detail.whyLabel:Show(); detail.why:Show(); detail.save:Show()
    if not detail.why:HasFocus() then detail.why:SetText("") end
end

local function drawDetail()
    detail.b1:Hide(); detail.b2:Hide()
    detail.whyLabel:SetText(color(GOLD_HEX, "Why were you looking?"))
    detail.save:SetText("Save")
    if tab == "ask" then return drawAsk() end
    if not selectedKey then
        setDetail(nil, "Pick something on the left", GOLD_HEX,
            { grey("Each row is something you held your mouse over, or opened in the auction house."), "",
              grey("Shift-click an item to link it in chat.") }, nil, false)
        return
    end
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
        table.insert(lines, "")
        table.insert(lines, s and color(GOLD_HEX, "You looked at this " .. s.looks .. " times, last " .. when(s.last) .. ".")
            or grey("You haven't looked at this."))
        setDetail(C_Item.GetItemIconByID(id), C_Item.GetItemNameByID(id) or ("item " .. id),
            nameColor({ kind = "item", id = id }), lines, nil, false)
        return
    end
    local s = ns.DB.subjects[selectedKey]
    if not s then selectedKey = nil return drawDetail() end
    local e = s.events[#s.events] or {}
    local lines = {
        grey(s.kind == "item" and "Item" or s.kind:gsub("^%l", string.upper)),
        "",
        "First looked: " .. date("%d %b %H:%M", s.first),
        "Last looked: " .. when(s.last),
        string.format("Looked %d time%s, %d seconds in all", s.looks, s.looks == 1 and "" or "s", math.floor(s.secs + 0.5)),
    }
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
    setDetail(iconOf(s.kind, s.id), displayName(s), nameColor(s), lines, hist, true, e.why)
end

refresh = function()
    if not (frame and frame:IsShown() and ns.DB) then return end
    local c = counts()
    for _, b in ipairs(frame.tabs) do
        local label = b.def.label .. (c[b.def.key] and ("  " .. c[b.def.key]) or "")
        b.label:SetText(b.def.key == tab and color(GOLD_HEX, label) or label)
        b:SetBackdropBorderColor(GOLD[1], GOLD[2], GOLD[3], b.def.key == tab and 0.9 or 0.15)
    end
    drawList()
    drawDetail()
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
    list.note:SetWidth(LIST_W)

    -- details
    detail = CreateFrame("Frame", nil, frame, "BackdropTemplate")
    backdrop(detail, { 0.06, 0.06, 0.06, 1 }, { 0.25, 0.25, 0.25, 0.6 })
    detail:SetPoint("TOPLEFT", list, "TOPRIGHT", 10, 0)
    detail:SetPoint("BOTTOMRIGHT", -12, 44)
    detail.icon = detail:CreateTexture(nil, "ARTWORK")
    detail.icon:SetSize(44, 44)
    detail.icon:SetPoint("TOPLEFT", 12, -12)
    detail.icon:SetTexCoord(0.07, 0.93, 0.07, 0.93)
    detail.title = text(detail, "GameFontNormalLarge")
    detail.title:SetPoint("TOPLEFT", detail.icon, "TOPRIGHT", 10, -4)
    detail.title:SetPoint("RIGHT", -10, 0)
    detail.title:SetWordWrap(true)
    detail.facts = text(detail, "GameFontHighlightSmall", 0.9, 0.9, 0.9)
    detail.facts:SetPoint("TOPLEFT", 12, -66)
    detail.facts:SetWidth(W - LIST_W - 50)
    detail.facts:SetSpacing(2)
    detail.histTitle = text(detail, "GameFontNormal")
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
    local foot = text(frame, "GameFontHighlightSmall", 0.55, 0.55, 0.55)
    foot:SetPoint("LEFT", scanBtn, "RIGHT", 12, 0)
    foot:SetPoint("RIGHT", frame, "RIGHT", -14, 0)
    foot:SetText("A look is a tooltip held for " .. (ns.DB and ns.DB.minDwell or 2.5) ..
        "s or an item opened in the auction house. The reasons are guesses; tell it the real one and it keeps both.")
    frame.foot = foot

    frame:SetScript("OnShow", refresh)
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
