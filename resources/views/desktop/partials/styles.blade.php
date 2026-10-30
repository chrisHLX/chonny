{{-- The desktop pages' styles: the game card, Improve, Comps and Classes, shown in both apps and on
     /wow/coach. Spotify's look, the same as the site (2026-10-11): #121212 page, #181818 cards that
     lift on hover, pill tabs, a coloured wash behind each page's title. Colours are the site's tokens
     written out (tailwind.config.js).

     2026-10-29: grid and flex are in use. The downloadable app draws these pages with WebView2 (Edge),
     and the site in any current browser; only the retired PowerShell app was IE11, and it now draws
     the newer layouts as a single column. One face, Inter: a serif for titles and numbers was tried
     the same day and read as fussy at small sizes. --}}
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
    .warn-text { color: #F28B82; }
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
    /* A section's icon, gold, before its name (desktop.partials.icon). */
    .ico { width: 16px; height: 16px; vertical-align: -3px; }
    .label .ico { color: #C8952C; margin-right: 7px; }
    /* A small caps heading inside a card: "KEY EVENTS", the way the dashboard names its panels. */
    .kicker { font-size: 11.5px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: #B3B3B3; margin-bottom: 10px; }
    .kicker .ico { color: #C8952C; margin-right: 6px; width: 15px; height: 15px; }

    /* ---- the dashboard layout (2026-10-29): a header with both sides, then panels in a grid ---- */
    .hero { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 16px; }
    .hero-main { display: flex; align-items: center; gap: 16px; min-width: 0; }
    .hero-ic { width: 64px; height: 64px; border-radius: 12px; flex-shrink: 0; border: 2px solid #3E3E3E; }
    .head-won .hero-ic { border-color: #1db954; }
    .head-lost .hero-ic { border-color: #b93a3a; }
    .hero .eyebrow { font-size: 12.5px; color: #B3B3B3; }
    .hero .title { margin: 0 10px 0 0; }
    .hero .big { font-size: 22px; font-weight: 800; letter-spacing: -.02em; vertical-align: middle; margin-left: 8px; font-variant-numeric: tabular-nums; }
    .lineup { margin-top: 6px; font-size: 13px; }
    .lineup span.p { white-space: nowrap; margin-right: 12px; }
    .lineup img { width: 18px; height: 18px; border-radius: 4px; vertical-align: -4px; margin-right: 5px; }
    .opp { background: rgba(0,0,0,.28); border: 1px solid #3E3E3E; border-radius: 10px; padding: 10px 14px; min-width: 210px; }
    .opp .k { font-size: 11.5px; color: #B3B3B3; }
    .opp .v { font-size: 20px; font-weight: 800; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
    .hero-side { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
    .hero-side .actions { margin-top: 0; text-align: right; }
    /* Both teams in the header (2026-10-30): cards dark enough to read on the result's colour. */
    .head-teams { margin-top: 18px; }
    .head-teams .card { background: rgba(0,0,0,.42); }
    .head-teams .card:hover { background: rgba(0,0,0,.5); }
    .actions { margin-top: 10px; }
    .actions a { display: inline-block; margin: 0 6px 6px 0; border-radius: 9999px; padding: 6px 14px; font-weight: 700; font-size: 12.5px; text-decoration: none; }
    .actions a.primary { background: #C8952C; color: #000000; }
    .actions a.primary:hover { background: #E8B84B; }
    .actions a.plain { background: #2A2A2A; color: #FFFFFF; }
    .actions a.plain:hover { background: #3E3E3E; }

    .grid2 { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 14px; }
    .grid2 + .grid2, .grid2 + .section, .section + .grid2 { margin-top: 14px; }
    .grid2.lead { grid-template-columns: minmax(0, 2fr) minmax(0, 3fr); }
    .grid2.top { align-items: start; }
    .card { background: #181818; border-radius: 10px; padding: 14px 16px; min-width: 0; }
    .card:hover { background: #1c1c1c; }

    /* The headline: the first death, in the face of the titles. */
    .headline .t { font-size: 21px; font-weight: 800; letter-spacing: -.02em; line-height: 1.25; }
    .headline .line { color: #B3B3B3; }
    .headline a { color: #E8B84B; font-size: 12.5px; font-weight: 600; text-decoration: none; }

    .tiles { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
    .tile { background: #121212; border-radius: 8px; padding: 10px 12px; min-width: 0; }
    .tile .k { font-size: 11.5px; color: #B3B3B3; line-height: 1.3; }
    .tile .k .ico { color: #C8952C; width: 14px; height: 14px; margin-right: 4px; vertical-align: -2px; }
    .tile .v { font-size: 22px; font-weight: 800; letter-spacing: -.02em; margin-top: 4px; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .tile .s { font-size: 11.5px; color: #6A6A6A; }

    /* Key events: one line of time down the left. */
    .events { position: relative; }
    .ev { display: flex; align-items: center; gap: 10px; padding: 5px 8px; border-radius: 8px; }
    .ev .t { width: 38px; flex-shrink: 0; color: #B3B3B3; font-variant-numeric: tabular-nums; font-size: 12.5px; }
    .ev .dot { width: 26px; height: 26px; flex-shrink: 0; border-radius: 13px; background: #232323; display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .ev .dot img { width: 26px; height: 26px; }
    .ev .dot .ico { width: 14px; height: 14px; }
    .ev.ours .dot { color: #86efac; }
    .ev.theirs .dot { color: #fca5a5; }
    .ev.death.ours, .ev.kill.theirs { background: rgba(239,68,68,.10); }
    .ev.death.theirs, .ev.kill.ours { background: rgba(200,149,44,.14); }
    .ev .x { min-width: 0; }

    @media (max-width: 860px) {
        .grid2, .grid2.lead { grid-template-columns: minmax(0, 1fr); }
    }
    .label .aside { font-size: 12.5px; font-weight: 400; letter-spacing: 0; color: #B3B3B3; margin-left: 8px; }
    .box { background: #181818; border-radius: 8px; padding: 12px 14px; }
    .box:hover { background: #1f1f1f; }
    .teams { width: 100%; }
    .teams td.col { width: 50%; vertical-align: top; padding: 0; }
    .teams td.col.left { padding-right: 8px; }
    .teams td.col.right { padding-left: 8px; }
    /* Comps' and the player page's two columns. Kept here, before the phone query, and never
       redefined in a page's own <style>: a later rule of the same weight undid the query's
       width: auto, so on a phone the columns stacked but each stayed half the screen wide. */
    .two { width: 100%; }
    .two td.col { width: 50%; vertical-align: top; padding: 0; }
    .two td.col.left { padding-right: 6px; }
    .two td.col.right { padding-left: 6px; }
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
    a.name { text-decoration: none; }
    a.name:hover { text-decoration: underline; }
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

    /* The detail a reader opens when they want it (2026-10-29): a death's full read, a round's buttons. */
    details.more { margin-top: 10px; }
    details.more > summary { cursor: pointer; color: #B3B3B3; font-size: 12.5px; font-weight: 600; list-style: none; }
    details.more > summary::-webkit-details-marker { display: none; }
    details.more > summary:before { content: '\25B8'; display: inline-block; width: 14px; }
    details.more[open] > summary:before { content: '\25BE'; }
    details.more > summary:hover { color: #FFFFFF; }

    .bd tr.pl td { cursor: pointer; }
    .bd tr.pl:hover td { background: #232323; }
    .bd td.abil { padding: 2px 0 10px 26px; }
    .hpline { display: block; width: 100%; height: 64px; margin: 6px 0 2px; background: #181818; border-radius: 4px; }
    .ab { width: 100%; }
    .ab td { padding: 2px 0; }
    .ab .sp { white-space: nowrap; padding-right: 10px; }
    .ab .sh { width: 30%; }
    .ab .n { width: 64px; text-align: right; padding-left: 8px; white-space: nowrap; }

    /* Phones (2026-10-20). These pages are read on a phone through /wow/coach's frame as well
       as in the app, and every one was laid out for a desktop window: 24px gutters, two 50%
       columns side by side, a 30px title with the MMR floated beside it. Below 560px that
       leaves each team about 150px. Narrow gets one column, smaller gutters and no float.

       A media query is IE11-safe (IE9+), and the desktop app's window never gets this narrow,
       so the app keeps the wide layout it was drawn for. */
    @media (max-width: 560px) {
        body { padding: 0 14px 28px; }
        .head { margin: 0 -14px 16px; padding: 20px 14px 16px; }
        .head .title { font-size: 24px; margin: 0 6px 0 4px; }
        .hero-ic { width: 44px; height: 44px; }
        .opp { min-width: 0; width: 100%; }
        .hero-side { width: 100%; align-items: stretch; }
        .hero-side .actions { text-align: left; }
        .tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .tile .v, .headline .t { font-size: 19px; }
        .head .mmr { float: none; display: block; margin-top: 6px; }

        /* Two columns side by side become one on top of the other. Both names are in use:
           .teams on the game card, .two on Comps and the player page. */
        /* The outer table's own rows only (the parser adds the tbody): a bare `.two tr` also
           matched the tables inside each column, and their values lost their column. */
        .teams > tbody > tr, .two > tbody > tr { display: block; }
        .teams td.col, .teams td.col.left, .teams td.col.right,
        .two td.col, .two td.col.left, .two td.col.right { display: block; width: auto; padding: 0 0 8px 0; }
        .col:empty { display: none; }

        .section { margin-top: 20px; }
        .label { font-size: 15px; }
        .label .aside { display: block; margin-left: 0; margin-top: 2px; }
        .box { padding: 10px 11px; }

        /* The numeric columns were sized for a wide table; this narrow they crowd out the name. */
        .stats .n { width: 56px; }
        .ab .sh { width: 22%; }
        .ab .n { width: 54px; }
        .note .t { width: 40px; }
        .roster .ic { width: 30px; }

        /* Basics and The moments: a GOOD / WORK ON chip in a 70px column beside the text. That
           column is a fifth of a phone's width, so the chip goes above the line it labels. */
        .look tr, .look td { display: block; }
        .look .ic { padding-bottom: 0; }
    }
</style>
