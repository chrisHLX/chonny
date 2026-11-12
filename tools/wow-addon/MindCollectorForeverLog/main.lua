-- MindCollector Forever Log
--
-- Keeps combat logging on while you play WoW Forever, so the MindCollector app can see each PvP
-- fight in WoWCombatLog-*.txt and save it from OBS's replay buffer (tools/desktop-app,
-- ForeverRecorder). The app does the deciding; this addon only makes sure the log is written.
--
-- WHAT WAS MEASURED (2026-11-06, Forever 1.60.1.70790, with a probe addon and a file watcher):
--  - LoggingCombat(true) works from an addon, at login and as combat starts (PLAYER_REGEN_DISABLED
--    fires before InCombatLockdown() is true).
--  - A /reload turns logging OFF. So it is turned back on at every PLAYER_ENTERING_WORLD.
--  - WoW writes a fight to the file 0.3-0.5s after combat ends and never during it, and turning
--    logging off and on again keeps writing to the same file. A session of questing wrote ~3 MB an hour.
--
-- OFF IN TOWN (2026-11-12, Chriso: big frame drops in a crowded Orgrimmar, WoW's main thread at 97%
-- of a core there and 33% outside it): in a city or inn (IsResting) and out of combat, logging is
-- off, since the log writes a line for every player's spells in range and the app only ever uses
-- lines where you hit a hostile player or one hits you, which always puts you in combat. Entering
-- combat turns it on at once (a duel or a raid on the city is still recorded); it goes off again
-- only LINGER seconds after combat ends, so the fight is written to the file first. Out in the
-- world nothing changed: it stays on.
--
-- ADVANCED COMBAT LOGGING is turned on too: it is what puts unit flags, positions and stats in each
-- line, and the app tells you from a hostile player by those flags.

local frame = CreateFrame("Frame")
local announced = false
local retries = 0   -- times something else turned logging off this session; capped so nothing fights forever
local LINGER = 10   -- seconds after combat before town turns it off: WoW writes the fight 0.5s after
local switching = false   -- our own LoggingCombat call, which the hook below must leave alone
local quietUntil = 0      -- the game's "Combat being logged" lines for our own switches are hidden

local function say(msg)
    DEFAULT_CHAT_FRAME:AddMessage("|cff00ff88MindCollector|r " .. msg)
end

local function isLogging()
    return LoggingCombat() and true or false
end

local function set(on)
    switching = true
    quietUntil = GetTime() + 2
    LoggingCombat(on)
    switching = false
end

-- In town, out of combat: nothing the app uses can happen there without combat starting first.
local function inTown()
    return IsResting() and not UnitAffectingCombat("player") and not InCombatLockdown()
end

local function ensure()
    if GetCVar("advancedCombatLogging") ~= "1" then
        SetCVar("advancedCombatLogging", 1)
        say("turned on Advanced Combat Logging.")
    end
    if not isLogging() then
        set(true)
    end
    if not announced and isLogging() then
        announced = true
        say("combat logging |cff00ff00on|r: the MindCollector app saves your PvP fights.")
    end
end

-- Another addon (a logger that switches by zone) turning logging off is undone, a few times a session.
-- Checked on the next frame so that addon's own work has finished; a call with no argument only reads.
hooksecurefunc("LoggingCombat", function(...)
    if select("#", ...) == 0 or switching then return end
    C_Timer.After(0, function()
        if not isLogging() and not inTown() and retries < 5 then
            retries = retries + 1
            set(true)
            if retries == 1 then say("another addon turned combat logging off. Turned it back on.") end
        end
    end)
end)

-- On out in the world and in any fight; off in town once a fight has had time to be written.
local check
local pending = 0
function check()
    if not inTown() then
        ensure()
    elseif isLogging() then
        set(false)
    end
end

local function later(seconds)
    pending = pending + 1
    local mine = pending
    C_Timer.After(seconds, function() if mine == pending then check() end end)
end

-- Only our own switches are hidden; /combatlog typed by you still says what it did.
local function quiet(_, _, msg)
    if GetTime() < quietUntil and (msg == COMBATLOGENABLED or msg == COMBATLOGDISABLED) then return true end
end
ChatFrame_AddMessageEventFilter("CHAT_MSG_SYSTEM", quiet)

frame:RegisterEvent("PLAYER_ENTERING_WORLD")
frame:RegisterEvent("PLAYER_REGEN_DISABLED")
frame:RegisterEvent("PLAYER_REGEN_ENABLED")
frame:RegisterEvent("PLAYER_UPDATE_RESTING")
frame:SetScript("OnEvent", function(_, event)
    if event == "PLAYER_REGEN_DISABLED" then
        pending = pending + 1   -- a fight: on now, and no town switch-off waiting from before
        ensure()
    elseif event == "PLAYER_ENTERING_WORLD" then
        if GetCVar("advancedCombatLogging") ~= "1" then ensure() end
        check()
        -- Zone events reach every addon in turn; one after this one may switch logging off.
        later(2)
    elseif not inTown() then
        check()   -- left town: on at once
    else
        later(LINGER)   -- combat over in town, or just walked in: off once the fight is written
    end
end)

SLASH_MCFOREVERLOG1 = "/mclog"
SlashCmdList["MCFOREVERLOG"] = function()
    say("combat logging is " .. (isLogging() and "|cff00ff00on|r" or "|cffff5555off|r")
        .. (inTown() and " (off in town out of combat, on again when a fight starts)" or "")
        .. ", advanced logging is " .. (GetCVar("advancedCombatLogging") == "1" and "|cff00ff00on|r" or "|cffff5555off|r") .. ".")
    say("Log file: |cffffff00<WoW>/_forever_/Logs/WoWCombatLog-*.txt|r")
end
