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
--    logging off and on again keeps writing to the same file. So there is nothing to gain from
--    switching it on and off around fights: it stays on. A session of questing wrote ~3 MB an hour.
--
-- ADVANCED COMBAT LOGGING is turned on too: it is what puts unit flags, positions and stats in each
-- line, and the app tells you from a hostile player by those flags.

local frame = CreateFrame("Frame")
local announced = false
local retries = 0   -- times something else turned logging off this session; capped so nothing fights forever

local function say(msg)
    DEFAULT_CHAT_FRAME:AddMessage("|cff00ff88MindCollector|r " .. msg)
end

local function isLogging()
    return LoggingCombat() and true or false
end

local function ensure()
    if GetCVar("advancedCombatLogging") ~= "1" then
        SetCVar("advancedCombatLogging", 1)
        say("turned on Advanced Combat Logging.")
    end
    if not isLogging() then
        LoggingCombat(true)
    end
    if not announced and isLogging() then
        announced = true
        say("combat logging |cff00ff00on|r: the MindCollector app saves your PvP fights.")
    end
end

-- Another addon (a logger that switches by zone) turning logging off is undone, a few times a session.
-- Checked on the next frame so that addon's own work has finished; a call with no argument only reads.
hooksecurefunc("LoggingCombat", function(...)
    if select("#", ...) == 0 then return end
    C_Timer.After(0, function()
        if not isLogging() and retries < 5 then
            retries = retries + 1
            LoggingCombat(true)
            if retries == 1 then say("another addon turned combat logging off. Turned it back on.") end
        end
    end)
end)

frame:RegisterEvent("PLAYER_ENTERING_WORLD")
frame:RegisterEvent("PLAYER_REGEN_DISABLED")
frame:SetScript("OnEvent", function(_, event)
    ensure()
    if event == "PLAYER_ENTERING_WORLD" then
        -- Zone events reach every addon in turn; one after this one may switch logging off.
        C_Timer.After(2, ensure)
    end
end)

SLASH_MCFOREVERLOG1 = "/mclog"
SlashCmdList["MCFOREVERLOG"] = function()
    say("combat logging is " .. (isLogging() and "|cff00ff00on|r" or "|cffff5555off|r")
        .. ", advanced logging is " .. (GetCVar("advancedCombatLogging") == "1" and "|cff00ff00on|r" or "|cffff5555off|r") .. ".")
    say("Log file: |cffffff00<WoW>/_forever_/Logs/WoWCombatLog-*.txt|r")
end
