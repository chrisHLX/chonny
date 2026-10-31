# Design system

The site's look: tokens, component classes, icons, pickers. Moved here word for word from `CLAUDE.md`
on 2026-11-01; read it before styling a page. The desktop pages keep their own copy of the values
(`resources/views/desktop/partials/styles.blade.php`).

## Tokens (`tailwind.config.js`)

**Spotify's desktop look (2026-10-11), one set of values everywhere:** the site, the desktop pages
(`resources/views/desktop/partials/styles.blade.php`) and both apps' windows (`tools/desktop-app/Theme.cs`,
the `$C` table in `MindCollectorLogs.ps1`). Black frame around rounded `surface-0` panels, `surface-1`
cards with no border that lift on hover, pill buttons and pill tabs (the open tab white), gold for the
one thing to press. Change a colour in all four places.

Fonts: Inter only, linked from the page head (`partials/fonts.blade.php`), never `@import`ed from CSS.
`font-display` is Inter at 800 with tight tracking; no italics. A serif for headings (Marcellus) was tried
on 2026-10-29 and dropped before it shipped: at small sizes and in numbers it read as fussy, and Chriso
found it "wasn't clean and simple".

**The dashboard layout (2026-10-29)** takes the layout of a mockup Chriso sent, not its look (red fire
art, ornate emblems): a header with your side and an opponent box, then panels (`.grid2`, `.card`,
`.kicker`, `.tiles`, `.events` in the desktop styles), small gold line icons before section names
(`desktop.partials.icon`, inline SVG, no file to fetch). No embedded video on a card: it costs every
card load; the Watch page has it.

| Token | Value | Use |
|---|---|---|
| `frame` | `#000000` | The black around the panels |
| `surface-0` | `#121212` | Page / panel background |
| `surface-1` | `#181818` | Cards |
| `surface-2` | `#232323` | Hover, elevated elements |
| `surface-3` | `#2A2A2A` | Selected, modals, overlays |
| `ink` | `#FFFFFF` | Primary text |
| `ink-muted` | `#B3B3B3` | Secondary text |
| `ink-subtle` | `#6A6A6A` | Disabled/placeholder |
| `gold` | `#C8952C` | Brand/CTA primary |
| `gold-light` | `#E8B84B` | Hover gold |
| `gold-dark` | `#8B6420` | |
| `gold-muted` | `#6B4E1A` | Subtle gold fills |
| `gold-subtle` | `#1E150A` | Faint gold tint |
| `violet` | `#7B6EE8` | Secondary/quiz |
| `violet-hover` | `#8B7EF8` | |
| `violet-muted` | `#4A3FA8` | |
| `violet-subtle` | `#18163A` | Faint violet tint |
| `accent` | `#C8952C` | Alias for `gold` (compat) |
| `line` | `#282828` | Default border |
| `line-strong` | `#3E3E3E` | Stronger border |
| `line-gold` | `#6B4E1A` | Gold-tinted border |

Gradients: `bg-gold-gradient`, `bg-gold-gradient-v`, `bg-violet-gradient`.
Shadows: `shadow-gold-sm|gold|gold-lg`, `shadow-violet-sm|violet`.
Class colours: `config/wow_classes.php` (Blizzard's own `RAID_CLASS_COLORS`).

## Component classes (`resources/css/app.css`)

`.linear-card` · `.sidebar-item(.active)` · `.page-hero` / `.page-eyebrow` / `.page-title` · `.chip(-on)` ·
`.form-input|select|textarea|checkbox` ·
`.page-section(-title|-desc)` · `.badge-green|amber|gold|blue|gray` ·
`.tab-btn`/`.tab-active`/`.tab-inactive` · `.btn-primary|secondary|ghost|danger` ·
`.ordering-list`/`.ordering-item` · `.prose-guide` (scoped on purpose — user-written content
must never restyle the site).

## Icons

`<x-mc-icon name="icon-compass" class="w-6 h-6 text-gold"/>` inlines SVG from
`public/images/icons/`, so `currentColor` and Tailwind `text-*` control the colour.

**`<x-icon>` is taken by `blade-ui-kit/blade-icons` — always use `<x-mc-icon>`**, or you get
"Svg by name ... not found".

Available: `icon-complete`, `icon-compass`, `icon-scroll`, `icon-hourglass`, `icon-leaf`,
`icon-starburst`, `icon-lightning-circle`, `icon-flask`, `icon-axis-hex`, `icon-diamond`,
`icon-delta`, `badge-wow`, `badge-sc2`, `badge-lol`,
`gem-mastered|strong|developing|weak|unknown`, `bg-arch`, `bg-constellation`.

Also: `<x-spell-icon>`, `<x-class-icon>`, `<x-spec-icon>` (self-hosted, filename-keyed, with a
plain placeholder — never a broken `<img>` — when `icon_name` is null), and
`<x-ornament.corner position="tl|tr|bl|br">`.

## Choosing things

**No native `<select>` for a choice a player makes** (2026-10-30, Chriso: the Leaderboards' dropdowns
were plain phone pickers "where as on the comps page they actually have the class icons and are
styled").
- **A spec:** `<x-spec-picker :specs="$models" :selected="$value" call="setSpec"/>`, a button that opens
  a panel of every class in its colour with its specs as icons, named under each so a phone needs no
  hover, and a search. It is teleported to the body so no card's transform can trap it. `specs` are
  `Specialization` models with `gameClass` loaded; `any` sets or removes the "none" choice.
- **A handful of choices:** a pill row, the chosen one white
  (`livewire/partials/leaderboard-filters.blade.php`, the region on `/wow/players`).
- **A comp:** the boxes on the Leaderboards' Games tab, as on the 3v3 Comps page's "Common picks".

The Spell Finder is the one page still on native dropdowns.
