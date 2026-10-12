{{-- The desktop pages' styles: the game card, Improve, Comps and Classes, shown in both apps and on
     /wow/coach. Spotify's look, the same as the site (2026-10-11): #121212 page, #181818 cards that
     lift on hover, pill tabs, a coloured wash behind each page's title. IE11-safe for the Dev app: no
     CSS variables, no grid, no flex gap (gradients and rounded corners are fine). Colours are the
     site's tokens written out (tailwind.config.js). --}}
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<style>
    html, body { margin: 0; padding: 0; background: #121212; color: #FFFFFF; }
    /* IE's own scrollbar properties: the Dev app's browser control is IE11. */
    html { scrollbar-face-color: #3E3E3E; scrollbar-track-color: #121212; scrollbar-arrow-color: #B3B3B3;
        scrollbar-shadow-color: #3E3E3E; scrollbar-highlight-color: #3E3E3E; scrollbar-3dlight-color: #121212; scrollbar-darkshadow-color: #121212; }
    ::-webkit-scrollbar { width: 12px; height: 12px; }
    ::-webkit-scrollbar-thumb { background: rgba(255,255,255,.18); border: 3px solid #121212; border-radius: 8px; }
    ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,.32); }
    body { font-family: 'Inter', 'Segoe UI', Arial, sans-serif; font-size: 13px; line-height: 1.5; padding: 0 24px 32px; }
    table { border-collapse: collapse; }
    img { border: 0; }
    a { color: #FFFFFF; }
    .muted { color: #B3B3B3; }
    .subtle { color: #6A6A6A; }
    .gold { color: #C8952C; }
    .small { font-size: 12px; }

    /* The page's title, on a wash of colour that fades into the page: gold by default, green for a
       won game, red for a lost one. */
    .head { margin: 0 -24px 20px; padding: 32px 24px 22px;
        background: #2a2214; background: linear-gradient(to bottom, #3a2d12 0%, #1e1a12 60%, #121212 100%); }
    .head.head-won { background: #17301f; background: linear-gradient(to bottom, #1d4a2c 0%, #16261b 60%, #121212 100%); }
    .head.head-lost { background: #301717; background: linear-gradient(to bottom, #4a1d1d 0%, #261616 60%, #121212 100%); }
    .head .title { font-size: 30px; font-weight: 800; letter-spacing: -.02em; margin: 0 10px 0 8px; vertical-align: middle; }
    .head .when { vertical-align: middle; font-size: 13px; }
    .head .mmr { float: right; margin-top: 10px; }

    .chip { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 11.5px; font-weight: 700; letter-spacing: .03em; vertical-align: middle; }
    .won { background: #1db954; background: rgba(29,185,84,.18); color: #86efac; }
    .lost { background: #3a1416; background: rgba(239,68,68,.18); color: #fca5a5; }
    .good { background: #12301d; background: rgba(29,185,84,.18); color: #86efac; }
    .bad { background: #3a1416; background: rgba(239,68,68,.18); color: #fca5a5; }
    .warn { background: #33270c; background: rgba(245,158,11,.16); color: #fcd34d; }
    .neutral { background: #2A2A2A; color: #B3B3B3; }
    .glad { background: #2a2214; color: #E8B84B; border: 1px solid #6B4E1A; }

    .section { margin-top: 26px; }
    .label { font-size: 17px; font-weight: 700; letter-spacing: -.01em; color: #FFFFFF; margin-bottom: 10px; }
    .label .aside { font-size: 12.5px; font-weight: 400; letter-spacing: 0; color: #B3B3B3; margin-left: 8px; }
    .box { background: #181818; border-radius: 8px; padding: 12px 14px; }
    .box:hover { background: #1f1f1f; }
    .teams { width: 100%; }
    .teams td.col { width: 50%; vertical-align: top; padding: 0; }
    .teams td.col.left { padding-right: 8px; }
    .teams td.col.right { padding-left: 8px; }
    .roster { width: 100%; }
    .roster td { padding: 6px 0; vertical-align: middle; }
    .roster tr + tr td { border-top: 1px solid #282828; }
    .roster .ic { width: 36px; }
    .roster .xp { text-align: right; white-space: nowrap; }
    .spec-ic { width: 28px; height: 28px; border-radius: 4px; vertical-align: middle; }
    .spell-ic { width: 20px; height: 20px; border-radius: 4px; vertical-align: middle; margin-right: 6px; }
    .ph { display: inline-block; width: 28px; height: 28px; border-radius: 4px; background: #2A2A2A; vertical-align: middle; }
    .ph-s { display: inline-block; width: 20px; height: 20px; border-radius: 4px; background: #2A2A2A; vertical-align: middle; margin-right: 6px; }
    .name { font-weight: 700; }
    .you { font-size: 10.5px; color: #C8952C; margin-left: 4px; font-weight: 700; }
    .death { margin-bottom: 8px; }
    .death .who { font-size: 15px; }
    .line { margin-top: 6px; }
    .bar { width: 100%; height: 6px; border-radius: 3px; background: #3E3E3E; overflow: hidden; font-size: 0; margin: 6px 0 4px; white-space: nowrap; }
    .bar span { display: inline-block; height: 6px; }
    .legend span { margin-right: 10px; }
    .def { display: inline-block; margin: 2px 10px 2px 0; white-space: nowrap; }
    .stats { width: 100%; }
    .stats td { padding: 6px 0; }
    .stats tr + tr td { border-top: 1px solid #282828; }
    .stats .n { width: 70px; text-align: right; font-weight: 700; }
    .stats .better { color: #86efac; }
    .stats .worse { color: #fca5a5; }
    .look td { padding: 6px 0; vertical-align: top; }
    .look .ic { width: 28px; }
    .look .count { color: #B3B3B3; white-space: nowrap; padding-left: 8px; }
    .dot { display: inline-block; width: 8px; height: 8px; border-radius: 4px; margin: 6px 6px 0 6px; }
    .note { padding: 4px 0; }
    .note .t { display: inline-block; width: 48px; color: #B3B3B3; }
    .flag { color: #C8952C; font-weight: 700; }
    .round { margin-top: 12px; }
    .round .rh { margin-bottom: 6px; }
    .round .rh b { font-size: 15px; margin-right: 8px; }
    .foot { margin-top: 28px; color: #6A6A6A; font-size: 12px; }

    /* Tabs: pills, the open one white (Spotify's chips). */
    .tabs { margin: 0 0 18px; }
    .tabs a { display: inline-block; padding: 6px 14px; margin: 0 8px 6px 0; border-radius: 16px; background: #2A2A2A;
        color: #FFFFFF; text-decoration: none; font-weight: 600; font-size: 13px; cursor: pointer; }
    .tabs a:hover { background: #333333; }
    .tabs a.on { background: #FFFFFF; color: #000000; }
    .tabs a .cnt { color: #B3B3B3; font-weight: 400; margin-left: 2px; }
    .tabs a.on .cnt { color: #535353; }
    .tab .section:first-child { margin-top: 0; }

    .bd tr.pl td { cursor: pointer; }
    .bd tr.pl:hover td { background: #232323; }
    .bd td.abil { padding: 2px 0 10px 26px; }
    .hpline { display: block; width: 100%; height: 64px; margin: 6px 0 2px; background: #181818; border-radius: 4px; }
    .ab { width: 100%; }
    .ab td { padding: 2px 0; }
    .ab .sp { white-space: nowrap; padding-right: 10px; }
    .ab .sh { width: 30%; }
    .ab .n { width: 64px; text-align: right; padding-left: 8px; white-space: nowrap; }
</style>
