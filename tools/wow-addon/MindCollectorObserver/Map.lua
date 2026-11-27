-- Points on the world map, with a waypoint on the nearest: a recipe's vendor, from the addon's own data
-- (Data.lua's Wowhead reads). Moved out of ask.lua when /ask was removed (2026-11-27, Chriso: the
-- built-in features cover it, and the AI bridge was complexity for little).

local _, ns = ...

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
ns.activePins = nil -- { {mapID, x, y, label}, ... } being shown
local activeTitle -- what they are for, on each pin's tooltip

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
            GameTooltip:SetText(self.label ~= "" and self.label or "Marked for you")
            if activeTitle then GameTooltip:AddLine(activeTitle, 0.8, 0.8, 0.8, true) end
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

-- Put the points on the map and a waypoint on the one nearest you. a = { q = what they are for, pins =
-- { {zone, x, y, label}, ... } }.
function ns.showPins(a, quiet)
    if not (a and a.pins and #a.pins > 0) then
        if not quiet then ns.say("there are no map points for that.") end
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
    ns.activePins, activeTitle = pins, a.q
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
    ns.activePins, activeTitle = nil, nil
    if provider then provider:RemoveAllData() end
    if C_Map.HasUserWaypoint and C_Map.HasUserWaypoint() then C_Map.ClearUserWaypoint() end
end

function ns.openMapFor(a)
    local p = a and a.pins and a.pins[1]
    local mapID = p and ns.findMap(p.zone)
    if not mapID then return end
    if OpenWorldMap then OpenWorldMap(mapID) elseif WorldMapFrame then ShowUIPanel(WorldMapFrame); WorldMapFrame:SetMapID(mapID) end
end
