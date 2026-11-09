# Ask Claude from inside WoW: Forever

`/ask <question>` in game; Claude answers using the web, Wowhead's Forever pages and your Chrome; the
answer comes back to the game, with map pins when it's about where something is.

## Running it

1. The **MindCollector Observer** addon must be installed (`tools/wow-addon/MindCollectorObserver`,
   copied into `_forever_\Interface\AddOns`). After adding files to an addon, restart the game once.
2. Start the bridge: double-click `start-ask-bridge.cmd`, or `python tools/forever-ask/ask_bridge.py`.
   A small always-on-top window says "Waiting for /ask in game".
3. In game: `/ask where are the bears in Ashenvale? mark them on the map` (or the Ask tab in `/mco`).
   The UI reloads to send it (that is when WoW writes saved data).
4. A minute or two later the answer appears in the bridge window. `/reload` in game: the answer goes
   to chat and the Ask tab, the points go on the world map (gold dots, hover for the label), and a
   waypoint with an arrow goes on the nearest one. `/mco clear` removes them.

## How it works, and why it takes reloads

An addon can't reach anything outside the game while WoW runs: no network, no file reads after the
UI loads, and its saved data is only written at a reload or logout. So the question goes out through
the addon's SavedVariables file and the answer comes back through `Answers.lua` in the addon folder,
which the game reads at the next reload. TradeSkillMaster's and WeakAuras' desktop apps use the same
route.

The bridge runs `claude -p` (the Claude Code CLI, found on PATH or in the VS Code extension) with
WebSearch, WebFetch and Claude in Chrome allowed, and Bash, Edit and Write refused. It runs in
`%LOCALAPPDATA%\MindCollector\ask`, which also holds `answers.json` and `bridge.log`. Default model
is Sonnet (`--model` to change). A trivial run costs about $0.25 and a browsing one more, on your
Claude account.

## Where coordinates come from

Wowhead's Forever NPC and object pages carry every spawn in `window.g_mapperData` (zone id ->
`coords` in map percent). A plain download of the page is refused by Wowhead's bot check, and the
bridge does not try to get round that; it reads the page in your own Chrome through the Claude
extension. Claude is told never to estimate coordinates: without the page data it marks nothing and
says so (tested 2026-11-09: it did exactly that).

**Open question (2026-11-09):** in the first test the background run said Chrome wasn't connected,
probably because the Claude session in VS Code was holding the extension at the time. Test with
VS Code's Claude closed. Questions that don't need coordinates ("what is Briarthorn used for",
"where is the Alchemy trainer in Orgrimmar") work without Chrome.

## Not done yet

- The desktop app could run this instead of a separate window (left alone because other work on the
  app was uncommitted at the time).
- Asking through a chat channel, which would save the first reload, needs a test of whether Forever
  writes `WoWChatLog.txt` as you chat (its combat log only writes at the end of a fight).
- Minimap pins (only the waypoint shows on the minimap).
