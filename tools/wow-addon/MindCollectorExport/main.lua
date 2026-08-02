-- MindCollectorExport
--
-- Exports the logged-in character's spellbook + talent state to SavedVariables so it can be
-- imported by `php artisan wow:import-spellbook` and diffed against the imported spell data.
-- See spellbook-verifier.md in the main repo for the full plan this addon is Part 1 of.
--
-- ============================================================================================
-- CONFIRMED live, 2026-08-02 (Discipline Priest, patch 12.0.7.68887, Interface 120007). Every
-- API call below was checked against a real client, including two real bugs found and fixed in
-- the process (loadout string / PvP talent slot enumeration — see README.md for detail). If a
-- future patch changes these APIs, re-run /mcexport and sanity-check the printed counts.
-- ============================================================================================

local ADDON_NAME = ...

MindCollectorExportDB = MindCollectorExportDB or {}

local pendingDescriptionRequests = 0
local descriptionMisses = 0

-- CONFIRMED: C_Spell.GetSpellDescription(spellID) — synchronous read of whatever description
-- text the client currently has cached for spellID, with spec/talent conditionals resolved for
-- the character's current state. May return an empty string if the spell's data hasn't been
-- requested/cached yet, which is what the continuation below exists to avoid.
local function getCachedDescription(spellID)
    local ok, desc = pcall(C_Spell.GetSpellDescription, spellID)
    if ok and desc and desc ~= "" then
        return desc
    end
    return nil
end

-- CONFIRMED: Spell:CreateFromSpellID(spellID):ContinueOnSpellLoad(callback) — the documented
-- continuation-object pattern for spell data that may not be loaded yet. callback runs once the
-- client has the data cached, after which C_Spell.GetSpellDescription(spellID) should resolve.
-- entry.desc is written in place once the callback fires; entry.desc starts nil.
local function requestDescription(entry)
    local cached = getCachedDescription(entry.id)
    if cached then
        entry.desc = cached
        return
    end

    pendingDescriptionRequests = pendingDescriptionRequests + 1

    local ok = pcall(function()
        local spell = Spell:CreateFromSpellID(entry.id)
        spell:ContinueOnSpellLoad(function()
            pendingDescriptionRequests = pendingDescriptionRequests - 1
            local desc = getCachedDescription(entry.id)
            if desc then
                entry.desc = desc
            else
                descriptionMisses = descriptionMisses + 1
            end
        end)
    end)

    if not ok then
        pendingDescriptionRequests = pendingDescriptionRequests - 1
        descriptionMisses = descriptionMisses + 1
    end
end

-- CONFIRMED: C_SpellBook namespace (replaced the older GetSpellBookItemInfo-style globals in
-- 11.x). Enumerates every skill line (tab) then every item slot within it, skipping anything
-- that isn't a real, currently-known spell (flyouts, "will learn later" future spells).
local function collectSpellbook()
    local entries = {}

    -- CONFIRMED: C_SpellBook.GetNumSpellBookSkillLines()
    local numSkillLines = C_SpellBook.GetNumSpellBookSkillLines()

    for lineIndex = 1, numSkillLines do
        -- CONFIRMED: C_SpellBook.GetSpellBookSkillLineInfo(lineIndex) — expected fields:
        -- name, itemIndexOffset, numSpellBookItems, isGuild, offSpecID, shouldHide, specID
        local lineInfo = C_SpellBook.GetSpellBookSkillLineInfo(lineIndex)
        if lineInfo and not lineInfo.shouldHide then
            for slot = 1, (lineInfo.numSpellBookItems or 0) do
                local index = lineInfo.itemIndexOffset + slot

                -- CONFIRMED: C_SpellBook.GetSpellBookItemInfo(index, Enum.SpellBookSpellBank.Player)
                -- expected fields: name, itemType (Enum.SpellBookItemType), actionID / spellID
                local itemInfo = C_SpellBook.GetSpellBookItemInfo(index, Enum.SpellBookSpellBank.Player)

                -- CONFIRMED: Enum.SpellBookItemType.Spell — filters out flyouts/futures/etc.
                if itemInfo and itemInfo.itemType == Enum.SpellBookItemType.Spell and itemInfo.spellID then
                    local entry = {
                        id = itemInfo.spellID,
                        name = itemInfo.name,
                        tab = lineInfo.name,
                        desc = nil,
                    }
                    table.insert(entries, entry)
                    requestDescription(entry)
                end
            end
        end
    end

    return entries
end

-- CONFIRMED: C_ClassTalents / C_Traits namespaces for the active loadout's selected nodes.
-- This addon only needs the resolved spell IDs of currently-selected talents, not tree topology
-- (see spellbook-verifier.md's Out of Scope) — GetTreeNodes + GetNodeInfo per node, confirmed
-- live (returned 81 talents for a real Discipline Priest build).
local function collectTalents()
    local selected = {}

    -- CONFIRMED: C_ClassTalents.GetActiveConfigID()
    local configID = C_ClassTalents.GetActiveConfigID()
    if not configID then
        return selected
    end

    -- CONFIRMED: C_Traits.GetConfigInfo(configID) — expected to expose treeIDs
    local configInfo = C_Traits.GetConfigInfo(configID)
    if not configInfo or not configInfo.treeIDs then
        return selected
    end

    for _, treeID in ipairs(configInfo.treeIDs) do
        -- CONFIRMED: C_Traits.GetTreeNodes(treeID) — returns a list of nodeIDs
        local nodeIDs = C_Traits.GetTreeNodes(treeID)
        for _, nodeID in ipairs(nodeIDs or {}) do
            -- CONFIRMED: C_Traits.GetNodeInfo(configID, nodeID) — expected fields:
            -- activeEntry = { entryID }, ranksPurchased
            local nodeInfo = C_Traits.GetNodeInfo(configID, nodeID)
            if nodeInfo and nodeInfo.activeEntry and (nodeInfo.ranksPurchased or 0) > 0 then
                -- CONFIRMED: C_Traits.GetEntryInfo(configID, entryID) — expected to expose
                -- definitionID -> spellID via C_Traits.GetDefinitionInfo(definitionID)
                local entryInfo = C_Traits.GetEntryInfo(configID, nodeInfo.activeEntry.entryID)
                if entryInfo and entryInfo.definitionID then
                    local defInfo = C_Traits.GetDefinitionInfo(entryInfo.definitionID)
                    if defInfo and defInfo.spellID then
                        local entry = {
                            id = defInfo.spellID,
                            name = C_Spell.GetSpellName(defInfo.spellID),
                            desc = nil,
                        }
                        table.insert(selected, entry)
                        requestDescription(entry)
                    end
                end
            end
        end
    end

    return selected
end

-- CONFIRMED 2026-08-02 via warcraft.wiki.gg (found live output was silently 0 talents even
-- though the character had PvP talents selected — root cause was the first bug below):
-- 1. GetNumPvpTalentSlots does not exist as a global. There is no documented "how many slots
--    exist" API at all — slot count varies by level (up to 4). Loop a fixed range and check
--    each slot's own `enabled` field instead of trusting a slot-count function.
-- 2. C_SpecializationInfo.GetPvpTalentSlotInfo(slotIndex) IS real and returns a table
--    { enabled, level, selectedTalentID, availableTalentIDs } — this part was correct.
-- 3. GetPvpTalentInfoByID is a GLOBAL function, NOT namespaced under C_SpecializationInfo, and
--    returns 11 POSITIONAL values (talentID, name, icon, selected, available, spellID, unlocked,
--    row, column, known, grantedByAura) — not a table. The original code called a namespaced
--    version that doesn't exist and destructured it as a table; both were wrong.
local function collectPvpTalents()
    local known = {}

    for slotIndex = 1, 4 do
        local slotInfo = C_SpecializationInfo.GetPvpTalentSlotInfo(slotIndex)
        if slotInfo and slotInfo.enabled and slotInfo.selectedTalentID and slotInfo.selectedTalentID > 0 then
            local _, name, _, _, _, spellID = GetPvpTalentInfoByID(slotInfo.selectedTalentID)
            if spellID then
                local entry = {
                    id = spellID,
                    name = name,
                    desc = nil,
                }
                table.insert(known, entry)
                requestDescription(entry)
            end
        end
    end

    return known
end

local function doExport()
    -- CONFIRMED: UnitClass("player") — 2nd return is the English class token (e.g. "PRIEST").
    local _, classToken = UnitClass("player")

    -- CONFIRMED: GetSpecialization() / GetSpecializationInfo(specIndex) — specID (2nd return)
    -- is Blizzard's numeric specialization id, matching specializations.external_spec_id.
    local specIndex = GetSpecialization()
    local specID, specName = nil, nil
    if specIndex then
        specID, specName = select(1, GetSpecializationInfo(specIndex)), select(2, GetSpecializationInfo(specIndex))
    end

    if not specID then
        print("MindCollector: could not determine current specialization — aborting export.")
        return
    end

    -- CONFIRMED 2026-08-02 via warcraft.wiki.gg: GenerateInspectImportString is for an
    -- *inspected* player (requires targeting/inspecting someone else first) — called on the
    -- player's own configID it silently returned "" (no error, wrong tool for the job). The
    -- player's own loadout uses C_Traits.GenerateImportString(configID) instead — confirmed
    -- correct signature and purpose ("retrieve Talent Build Strings for the current player
    -- character").
    local loadoutString = ""
    local configID = C_ClassTalents.GetActiveConfigID()
    if configID and C_Traits.GenerateImportString then
        local ok, str = pcall(C_Traits.GenerateImportString, configID)
        if ok and str then
            loadoutString = str
        end
    end

    -- CONFIRMED: GetBuildInfo() — 2nd return is the build number string.
    local _, build = GetBuildInfo()

    local spellbook = collectSpellbook()
    local talents = collectTalents()
    local pvpTalents = collectPvpTalents()

    MindCollectorExportDB = {
        exported_at = time(),
        build = build,
        class = classToken,
        spec_id = specID,
        spec_name = specName,
        loadout_string = loadoutString,
        spellbook = spellbook,
        talents = {
            selected = talents,
            known_pvp = pvpTalents,
        },
    }

    print(string.format(
        "MindCollector: exported %d spellbook entries, %d talents, %d pvp talents, %d description(s) pending (%d already missed).",
        #spellbook, #talents, #pvpTalents, pendingDescriptionRequests, descriptionMisses
    ))
    print("MindCollector: /reload to flush SavedVariables to disk. Re-run /mcexport after descriptions finish loading if any are pending.")
end

SLASH_MINDCOLLECTOREXPORT1 = "/mcexport"
SlashCmdList["MINDCOLLECTOREXPORT"] = function()
    pendingDescriptionRequests = 0
    descriptionMisses = 0
    doExport()
end
