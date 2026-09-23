-- MindCollector Arena Log
--
-- Turns combat logging on when you zone into an arena and off when you leave, so
-- WoWCombatLog.txt fills up with your arena games and nothing else. That file is what
-- `php artisan wow:ingest-combatlog` reads.
--
-- WHY THIS EXISTS. Combat logging is off by default, `/combatlog` has to be typed every
-- session, and if you leave it on all the time the log grows by gigabytes of open-world and
-- dungeon events that nothing here wants. Scoping it to arenas keeps the file small enough to
-- import in seconds.
--
-- ADVANCED COMBAT LOGGING IS THE PART THAT MATTERS. Without it the log has no COMBATANT_INFO
-- lines, which means no specs, which means the ingester cannot use the match at all. It is a
-- CVar rather than a logging toggle, so this addon sets it and says so, rather than assuming.

local ADDON = "MindCollectorArenaLog"

local frame = CreateFrame("Frame")
local logging = false

local function say(msg)
    DEFAULT_CHAT_FRAME:AddMessage("|cff00ff88MindCollector|r " .. msg)
end

local function inArena()
    local _, instanceType = IsInInstance()
    return instanceType == "arena"
end

local function startLogging()
    if logging then
        return
    end

    -- Without this the log carries no COMBATANT_INFO and therefore no specs. Set every time
    -- rather than once: it is a per-account CVar a UI reset or another addon can clear.
    if GetCVar("advancedCombatLogging") ~= "1" then
        SetCVar("advancedCombatLogging", 1)
        say("turned on Advanced Combat Logging (needed for specs).")
    end

    LoggingCombat(true)
    logging = true
    say("combat logging |cff00ff00on|r for this arena.")
end

local function stopLogging()
    if not logging then
        return
    end

    LoggingCombat(false)
    logging = false

    MindCollectorArenaLogDB = MindCollectorArenaLogDB or { matches = 0 }
    MindCollectorArenaLogDB.matches = (MindCollectorArenaLogDB.matches or 0) + 1
    MindCollectorArenaLogDB.lastLoggedAt = date("%Y-%m-%d %H:%M:%S")

    say("logged. " .. MindCollectorArenaLogDB.matches .. " match(es) this install — import with |cffffff00wow:ingest-combatlog|r.")
end

frame:RegisterEvent("PLAYER_ENTERING_WORLD")
frame:RegisterEvent("ZONE_CHANGED_NEW_AREA")
-- Fires as the gates open. PLAYER_ENTERING_WORLD alone is enough to start logging, but this
-- one is the reliable signal that a match is actually beginning rather than that you are
-- standing in the pre-match room.
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
    else
        stopLogging()
    end
end)

SLASH_MCARENALOG1 = "/mcarenalog"
SlashCmdList["MCARENALOG"] = function()
    say("logging is " .. (logging and "|cff00ff00on|r" or "|cffff5555off|r")
        .. ", advanced logging is " .. (GetCVar("advancedCombatLogging") == "1" and "|cff00ff00on|r" or "|cffff5555off|r")
        .. ", " .. ((MindCollectorArenaLogDB and MindCollectorArenaLogDB.matches) or 0) .. " match(es) logged.")
    say("Log file: |cffffff00<WoW>/_retail_/Logs/WoWCombatLog.txt|r")
end
