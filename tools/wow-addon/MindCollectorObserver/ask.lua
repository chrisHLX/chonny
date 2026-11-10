-- /ask: send a question to Claude through the MindCollector bridge (tools/forever-ask/ask_bridge.py),
-- and show what comes back: in chat, in the Ask tab, and as pins on the world map with a waypoint on
-- the nearest one.
--
-- An addon can't reach anything outside the game while it runs. So a question is saved and the UI
-- reloads (that is when WoW writes saved data to disk); the bridge answers into Answers.lua, and the
-- next reload loads it.

local _, ns = ...

-- ---------------------------------------------------------------- asking

local function professionsText()
    local out = {}
    for i = 1, select("#", GetProfessions()) do
        local idx = select(i, GetProfessions())
        if idx then
            local name, _, rank = GetProfessionInfo(idx)
            if name then table.insert(out, name .. " " .. (rank or 0)) end
        end
    end
    return table.concat(out, ", ")
end

-- ctx: what the addon has seen about the subject (prices, listings, costs), sent along so Claude
-- answers from the live market as well as the web.
function ns.ask(q, ctx)
    local DB = ns.DB
    q = (q or ""):gsub("^%s+", ""):gsub("%s+$", "")
    if q == "" then
        local last = DB.asks and DB.asks[#DB.asks]
        if last then
            ns.say("last question: " .. last.q .. (last.status == "answered" and " (answered; /mco, Ask tab)" or " (waiting; /reload in a minute)"))
        else
            ns.say("type /ask and your question, e.g. /ask where are the bears in Ashenvale? mark them on the map")
        end
        return
    end
    DB.asks = DB.asks or {}
    local mapID = C_Map.GetBestMapForUnit("player")
    local pos = mapID and C_Map.GetPlayerMapPosition(mapID, "player")
    local x, y
    if pos then x, y = pos:GetXY() end
    local info = mapID and C_Map.GetMapInfo(mapID)
    local _, class = UnitClass("player")
    table.insert(DB.asks, {
        id = string.format("%d-%04d", time(), math.random(0, 9999)), t = time(), q = q, status = "sent",
        ch = UnitName("player"), level = UnitLevel("player"), class = class, faction = UnitFactionGroup("player"),
        zone = (info and info.name) or GetRealZoneText(), subzone = GetSubZoneText(), mapID = mapID,
        x = x and math.floor(x * 1000 + 0.5) / 10, y = y and math.floor(y * 1000 + 0.5) / 10,
        profs = professionsText(), ctx = ctx,
    })
    while #DB.asks > 30 do table.remove(DB.asks, 1) end
    ns.say("asking Claude: " .. q)
    ns.say("reloading to send it. The answer takes a minute or two; it shows in the MindCollector Ask window on your desktop, then /reload here to see it.")
    C_Timer.After(1.5, ReloadUI)
end

-- ---------------------------------------------------------------- map lookup

local mapCache = {}

local function topMap(mapID)
    local info = mapID and C_Map.GetMapInfo(mapID)
    while info and info.parentMapID and info.parentMapID ~= 0 do
        local p = C_Map.GetMapInfo(info.parentMapID)
        if not p then break end
        info = p
    end
    return info and info.mapID
end

-- The uiMapID for a zone name as the game shows it, searched under the world the player is in.
function ns.findMap(name)
    if not name then return nil end
    local key = name:lower()
    if mapCache[key] ~= nil then return mapCache[key] or nil end
    local roots = { topMap(C_Map.GetBestMapForUnit("player")), 947, 946, 1414, 1415 }
    for _, root in ipairs(roots) do
        local rinfo = root and C_Map.GetMapInfo(root)
        if rinfo then
            if rinfo.name and rinfo.name:lower() == key then mapCache[key] = root return root end
            for _, m in ipairs(C_Map.GetMapChildrenInfo(root, nil, true) or {}) do
                if m.name and m.name:lower() == key then
                    mapCache[key] = m.mapID
                    return m.mapID
                end
            end
        end
    end
    mapCache[key] = false
    return nil
end

-- ---------------------------------------------------------------- pins on the world map

local provider, pinPool = nil, {}
ns.activePins = nil -- { {mapID, x, y, label}, ... } for the answer being shown
ns.activeAsk = nil

local PIN = 14

local function pinFrame(i, canvas)
    local f = pinPool[i]
    if not f then
        f = CreateFrame("Frame", nil, canvas)
        f.tex = f:CreateTexture(nil, "OVERLAY")
        f.tex:SetAllPoints()
        f.tex:SetTexture("Interface\\COMMON\\Indicator-Yellow")
        f:EnableMouse(true)
        f:SetScript("OnEnter", function(self)
            GameTooltip:SetOwner(self, "ANCHOR_RIGHT")
            GameTooltip:SetText(self.label ~= "" and self.label or "Marked by Claude")
            if ns.activeAsk then GameTooltip:AddLine(ns.activeAsk.q, 0.8, 0.8, 0.8, true) end
            GameTooltip:AddLine(string.format("%.1f, %.1f", self.x, self.y), 0.6, 0.6, 0.6)
            GameTooltip:Show()
        end)
        f:SetScript("OnLeave", function() GameTooltip:Hide() end)
        pinPool[i] = f
    end
    f:SetParent(canvas)
    return f
end

local function makeProvider()
    provider = CreateFromMixins(MapCanvasDataProviderMixin)
    function provider:RemoveAllData()
        for _, f in ipairs(pinPool) do f:Hide() end
    end
    function provider:RefreshAllData()
        self:RemoveAllData()
        local map = self:GetMap()
        local pins = ns.activePins
        if not (map and pins) then return end
        local mapID, canvas = map:GetMapID(), map:GetCanvas()
        local w, h = canvas:GetWidth(), canvas:GetHeight()
        local size = PIN / math.max(map:GetCanvasScale() or 1, 0.01)
        local n = 0
        for _, p in ipairs(pins) do
            if p.mapID == mapID then
                n = n + 1
                local f = pinFrame(n, canvas)
                f.label, f.x, f.y = p.label or "", p.x, p.y
                f:SetSize(size, size)
                f:SetFrameLevel(canvas:GetFrameLevel() + 2000)
                f:ClearAllPoints()
                f:SetPoint("CENTER", canvas, "TOPLEFT", p.x / 100 * w, -p.y / 100 * h)
                f:Show()
            end
        end
    end
    function provider:OnCanvasScaleChanged() self:RefreshAllData() end
    function provider:OnCanvasSizeChanged() self:RefreshAllData() end
    WorldMapFrame:AddDataProvider(provider)
end

local function ensureProvider()
    if provider then return true end
    if not WorldMapFrame then
        if C_AddOns and C_AddOns.LoadAddOn then pcall(C_AddOns.LoadAddOn, "Blizzard_WorldMap") end
    end
    if WorldMapFrame and WorldMapFrame.AddDataProvider then
        makeProvider()
        return true
    end
    return false
end

-- Put an answer's points on the map and a waypoint on the one nearest you.
function ns.showPins(a, quiet)
    if not (a and a.pins and #a.pins > 0) then
        if not quiet then ns.say("that answer has no map points.") end
        return
    end
    local pins, missing = {}, {}
    for _, p in ipairs(a.pins) do
        local mapID = ns.findMap(p.zone)
        if mapID then
            table.insert(pins, { mapID = mapID, x = p.x, y = p.y, label = p.label })
        else
            missing[p.zone] = true
        end
    end
    ns.activePins, ns.activeAsk = pins, a
    for zone in pairs(missing) do ns.say("couldn't find the map for \"" .. zone .. "\", so those points aren't shown.") end
    if #pins == 0 then return end
    if ensureProvider() then provider:RefreshAllData() end

    -- waypoint: the nearest point on the map you're on, else the first point
    local here = C_Map.GetBestMapForUnit("player")
    local pos = here and C_Map.GetPlayerMapPosition(here, "player")
    local best, bestD = nil, nil
    if pos then
        local px, py = pos:GetXY()
        for _, p in ipairs(pins) do
            if p.mapID == here then
                local d = (p.x / 100 - px) ^ 2 + (p.y / 100 - py) ^ 2
                if not bestD or d < bestD then best, bestD = p, d end
            end
        end
    end
    best = best or pins[1]
    if C_Map.CanSetUserWaypointOnMap(best.mapID) then
        C_Map.SetUserWaypoint(UiMapPoint.CreateFromCoordinates(best.mapID, best.x / 100, best.y / 100))
        if C_SuperTrack and C_SuperTrack.SetSuperTrackedUserWaypoint then C_SuperTrack.SetSuperTrackedUserWaypoint(true) end
    end
    if not quiet then
        local info = C_Map.GetMapInfo(best.mapID)
        ns.say(string.format("%d point%s on the map; waypoint set on the nearest (%s %.1f, %.1f).", #pins,
            #pins == 1 and "" or "s", info and info.name or "?", best.x, best.y))
    end
end

function ns.clearPins()
    ns.activePins, ns.activeAsk = nil, nil
    if provider then provider:RemoveAllData() end
    if C_Map.HasUserWaypoint and C_Map.HasUserWaypoint() then C_Map.ClearUserWaypoint() end
end

function ns.openMapFor(a)
    local p = a and a.pins and a.pins[1]
    local mapID = p and ns.findMap(p.zone)
    if not mapID then return end
    if OpenWorldMap then OpenWorldMap(mapID) elseif WorldMapFrame then ShowUIPanel(WorldMapFrame); WorldMapFrame:SetMapID(mapID) end
end

-- ---------------------------------------------------------------- answers arriving (at login or reload)

function ns.loadAnswers()
    local DB = ns.DB
    local A = MindCollectorObserverAnswers or {}
    local newest
    DB.asks = DB.asks or {}
    -- questions asked in the desktop app instead of in game: add them, answered
    local known = {}
    for _, a in ipairs(DB.asks) do known[a.id] = true end
    for id, ans in pairs(A) do
        if ans.app and not known[id] then
            table.insert(DB.asks, { id = id, t = ans.asked or ans.t, q = ans.q or "?", status = "sent", fromApp = true })
        end
    end
    table.sort(DB.asks, function(x, y) return (x.t or 0) < (y.t or 0) end)
    while #DB.asks > 30 do table.remove(DB.asks, 1) end
    for _, a in ipairs(DB.asks or {}) do
        local ans = A[a.id]
        if ans and a.status ~= "answered" then
            a.status, a.answer, a.pins, a.at = "answered", ans.answer, ans.pins, ans.t
            newest = a
        end
    end
    if newest then
        ns.say("|cffd4af37Claude:|r " .. (newest.answer or ""))
        if newest.pins and #newest.pins > 0 then
            C_Timer.After(1, function() ns.showPins(newest) end)
        end
    end
    for _, a in ipairs(DB.asks or {}) do
        if a.status == "sent" and time() - a.t < 1800 and a ~= newest then
            ns.say("still waiting for an answer to: " .. a.q .. ". Is the bridge running? /reload again in a minute.")
        end
    end
end
