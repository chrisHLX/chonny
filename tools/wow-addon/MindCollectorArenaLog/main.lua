-- MindCollector Arena Log
--
-- Turns combat logging on when you zone into an arena and off when you leave, so
-- WoWCombatLog.txt fills up with your arena games and nothing else. The MindCollector app
-- reads that file (and `php artisan wow:ingest-combatlog` on a dev machine).
--
-- WHY THIS EXISTS. Combat logging is off by default, `/combatlog` has to be typed every
-- session, and if you leave it on all the time the log grows by gigabytes of open-world and
-- dungeon events that nothing here wants. Scoping it to arenas keeps the file small enough to
-- import in seconds.
--
-- ADVANCED COMBAT LOGGING IS THE PART THAT MATTERS. Without it the log has no COMBATANT_INFO
-- lines, which means no specs, which means the ingester cannot use the match at all. It is a
-- CVar rather than a logging toggle, so this addon sets it and says so, rather than assuming.
--
-- OTHER LOGGING ADDONS (LoggerHead, 2026-10-14). LoggerHead switches logging on or off by zone
-- type, and with its arena setting off it switches logging OFF as you zone in, often just after
-- this addon switched it on. This addon used to keep its own "I turned it on" flag, so it never
-- noticed, never turned it back on, and still counted the game as logged: a tester saw "3
-- match(es) logged" while his log held no arena at all. So it now asks WoW (LoggingCombat()
-- with no argument returns the real state), turns logging back on whenever anything turns it off
-- during an arena, and only turns it off afterwards if it was the one that turned it on.

local ADDON = "MindCollectorArenaLog"

local frame = CreateFrame("Frame")
local inGame = false      -- from zoning into an arena until the match completes or you leave
local ours = false        -- logging is on because this addon turned it on (so it turns it off)
local announced = false   -- the "on for this arena" line, once per arena
local overridden = false  -- something turned logging off during this arena and it was turned back on
local retries = 0         -- times logging was turned back on this arena; capped so nothing can fight forever

local function say(msg)
    DEFAULT_CHAT_FRAME:AddMessage("|cff00ff88MindCollector|r " .. msg)
end

local function isLogging()
    return LoggingCombat() and true or false
end

local function inArena()
    local _, instanceType = IsInInstance()
    return instanceType == "arena"
end

local function startLogging()
    inGame = true

    -- Without this the log carries no COMBATANT_INFO and therefore no specs. Set every time
    -- rather than once: it is a per-account CVar a UI reset or another addon can clear.
    if GetCVar("advancedCombatLogging") ~= "1" then
        SetCVar("advancedCombatLogging", 1)
        say("turned on Advanced Combat Logging (needed for specs).")
    end

    if not isLogging() then
        LoggingCombat(true)
        ours = true
    end

    if not announced then
        announced = true
        say("combat logging |cff00ff00on|r for this arena.")
    end
end

local function stopLogging()
    if not inGame then
        return
    end
    inGame = false
    announced = false

    local recorded = isLogging()
    if ours and recorded then
        LoggingCombat(false)
    end
    ours = false

    if not recorded then
        say("|cffff5555this game was not recorded|r: combat logging was off when it ended. Type /mcarenalog to see why.")
        return
    end

    MindCollectorArenaLogDB = MindCollectorArenaLogDB or { matches = 0 }
    MindCollectorArenaLogDB.matches = (MindCollectorArenaLogDB.matches or 0) + 1
    MindCollectorArenaLogDB.lastLoggedAt = date("%Y-%m-%d %H:%M:%S")

    say("game recorded (" .. MindCollectorArenaLogDB.matches .. " so far). The MindCollector app sends it in a minute or so.")
end

-- Anything that turns logging off during an arena (LoggerHead on zoning in, mostly) gets undone.
-- Checked on the next frame so this addon's own LoggingCombat calls, and the other addon's
-- remaining work, have finished; it acts on the real state, so it cannot loop.
-- A call with no argument only reads the state (isLogging() does that), so it is ignored: acting on
-- it would re-arm this check from inside itself every frame.
hooksecurefunc("LoggingCombat", function(...)
    if not inGame or select("#", ...) == 0 then
        return
    end
    C_Timer.After(0, function()
        if inGame and not isLogging() and retries < 5 then
            retries = retries + 1
            LoggingCombat(true)
            ours = true
            if not overridden then
                overridden = true
                say("another addon turned combat logging off for this arena (LoggerHead does this when its arena setting is off). Turned it back on.")
            end
        end
    end)
end)

frame:RegisterEvent("PLAYER_ENTERING_WORLD")
frame:RegisterEvent("ZONE_CHANGED_NEW_AREA")
-- Fires as the gates open. PLAYER_ENTERING_WORLD alone is enough to start logging, but this
-- one is the reliable signal that a match is actually beginning rather than that you are
-- standing in the pre-match room. It is also a second chance after any addon that switched
-- logging off on the way in.
frame:RegisterEvent("PVP_MATCH_ACTIVE")
frame:RegisterEvent("PVP_MATCH_COMPLETE")

frame:SetScript("OnEvent", function(_, event)
    if event == "PVP_MATCH_COMPLETE" then
        -- Deliberately not immediate: ARENA_MATCH_END and the last few events are still being
        -- written when this fires, and cutting the log here would truncate the match the
        -- ingester needs the end of. One second is far longer than the gap in practice.
        C_Timer.After(1, stopLogging)
        return
    end

    if inArena() then
        startLogging()
        -- Zone events reach every addon in turn, and one that runs after this one may switch
        -- logging off without going through the hook's timing; look again shortly.
        C_Timer.After(2, function()
            if inGame and inArena() then startLogging() end
        end)
    else
        stopLogging()
        overridden = false
        retries = 0
    end
end)

SLASH_MCARENALOG1 = "/mcarenalog"
SlashCmdList["MCARENALOG"] = function()
    say("combat logging is " .. (isLogging() and "|cff00ff00on|r" or "|cffff5555off|r")
        .. ", advanced logging is " .. (GetCVar("advancedCombatLogging") == "1" and "|cff00ff00on|r" or "|cffff5555off|r")
        .. ", " .. ((MindCollectorArenaLogDB and MindCollectorArenaLogDB.matches) or 0) .. " game(s) recorded.")
    local loaded = C_AddOns and C_AddOns.IsAddOnLoaded and C_AddOns.IsAddOnLoaded("LoggerHead")
    if loaded then
        say("LoggerHead is on too. That's fine: in arenas this addon turns logging back on if LoggerHead turns it off.")
    end
    say("Log file: |cffffff00<WoW>/_retail_/Logs/WoWCombatLog-*.txt|r")
end
