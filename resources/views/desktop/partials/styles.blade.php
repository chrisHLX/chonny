{{-- The desktop app's page styles: shared by the game card and the Improve page. IE11: no CSS
     variables, no grid, no flex gap. Colours are the site's tokens written out. --}}
<style>
    html, body { margin: 0; padding: 0; background: #111116; color: #F0F0F2; }
    /* IE's own scrollbar properties: the app's browser control is IE11. */
    html { scrollbar-face-color: #2C2C38; scrollbar-track-color: #111116; scrollbar-arrow-color: #8A8A9A;
        scrollbar-shadow-color: #2C2C38; scrollbar-highlight-color: #2C2C38; scrollbar-3dlight-color: #111116; scrollbar-darkshadow-color: #111116; }
    body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 13px; line-height: 1.45; padding: 14px 16px 24px; }
    table { border-collapse: collapse; }
    img { border: 0; }
    .muted { color: #8A8A9A; }
    .subtle { color: #52525F; }
    .gold { color: #C8952C; }
    .small { font-size: 11.5px; }
    .head { margin-bottom: 12px; }
    .head .title { font-size: 18px; font-weight: 600; margin: 0 8px 0 6px; vertical-align: middle; }
    .head .when { vertical-align: middle; }
    .head .mmr { float: right; margin-top: 4px; }
    .chip { display: inline-block; padding: 1px 9px; border-radius: 10px; font-size: 11.5px; font-weight: 700; letter-spacing: .04em; vertical-align: middle; }
    .won { background: #12301d; color: #86efac; }
    .lost { background: #3a1416; color: #fca5a5; }
    .good { background: #12301d; color: #86efac; }
    .bad { background: #3a1416; color: #fca5a5; }
    .warn { background: #33270c; color: #fcd34d; }
    .neutral { background: #1E1E26; color: #8A8A9A; }
    .glad { background: #1E150A; color: #E8B84B; border: 1px solid #6B4E1A; }
    .section { margin-top: 18px; }
    .label { font-size: 11px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: #8A8A9A; margin-bottom: 6px; }
    .label .aside { font-weight: 400; letter-spacing: 0; text-transform: none; color: #52525F; margin-left: 6px; }
    .box { background: #18181E; border: 1px solid #2C2C38; border-radius: 8px; padding: 10px 12px; }
    .teams { width: 100%; }
    .teams td.col { width: 50%; vertical-align: top; padding: 0; }
    .teams td.col.left { padding-right: 6px; }
    .teams td.col.right { padding-left: 6px; }
    .roster { width: 100%; }
    .roster td { padding: 4px 0; vertical-align: middle; }
    .roster tr + tr td { border-top: 1px solid #1E1E26; }
    .roster .ic { width: 30px; }
    .roster .xp { text-align: right; white-space: nowrap; }
    .spec-ic { width: 24px; height: 24px; border-radius: 5px; vertical-align: middle; }
    .spell-ic { width: 20px; height: 20px; border-radius: 4px; vertical-align: middle; margin-right: 6px; }
    .ph { display: inline-block; width: 24px; height: 24px; border-radius: 5px; background: #1E1E26; vertical-align: middle; }
    .ph-s { display: inline-block; width: 20px; height: 20px; border-radius: 4px; background: #1E1E26; vertical-align: middle; margin-right: 6px; }
    .name { font-weight: 600; }
    .you { font-size: 10.5px; color: #C8952C; margin-left: 4px; font-weight: 600; }
    .death { margin-bottom: 8px; }
    .death .who { font-size: 14px; }
    .line { margin-top: 6px; }
    .bar { width: 100%; height: 8px; border-radius: 4px; background: #1E1E26; overflow: hidden; font-size: 0; margin: 4px 0 2px; white-space: nowrap; }
    .bar span { display: inline-block; height: 8px; }
    .legend span { margin-right: 10px; }
    .def { display: inline-block; margin: 2px 10px 2px 0; white-space: nowrap; }
    .stats { width: 100%; }
    .stats td { padding: 5px 0; }
    .stats tr + tr td { border-top: 1px solid #1E1E26; }
    .stats .n { width: 70px; text-align: right; font-weight: 600; }
    .stats .better { color: #86efac; }
    .stats .worse { color: #fca5a5; }
    .look td { padding: 5px 0; vertical-align: top; }
    .look .ic { width: 28px; }
    .look .count { color: #8A8A9A; white-space: nowrap; padding-left: 8px; }
    .dot { display: inline-block; width: 8px; height: 8px; border-radius: 4px; margin: 6px 6px 0 6px; }
    .note { padding: 4px 0; }
    .note .t { display: inline-block; width: 48px; color: #8A8A9A; }
    .flag { color: #C8952C; font-weight: 600; }
    .round { margin-top: 10px; }
    .round .rh { margin-bottom: 4px; }
    .round .rh b { font-size: 14px; margin-right: 8px; }
    .foot { margin-top: 20px; color: #52525F; font-size: 11.5px; }
    .tabs { margin: 0 0 12px; border-bottom: 1px solid #2C2C38; }
    .tabs a { display: inline-block; padding: 6px 12px; margin-right: 2px; color: #8A8A9A; text-decoration: none; font-weight: 600; border-bottom: 2px solid #111116; cursor: pointer; }
    .tabs a.on { color: #C8952C; border-bottom-color: #C8952C; }
    .tabs a .cnt { color: #52525F; font-weight: 400; }
    .tab .section:first-child { margin-top: 0; }
    .bd tr.pl td { cursor: pointer; }
    .bd tr.pl:hover td { background: #1E1E26; }
    .bd td.abil { padding: 2px 0 10px 26px; }
    .ab { width: 100%; }
    .ab td { padding: 2px 0; }
    .ab .sp { white-space: nowrap; padding-right: 10px; }
    .ab .sh { width: 30%; }
    .ab .n { width: 64px; text-align: right; padding-left: 8px; white-space: nowrap; }
</style>
