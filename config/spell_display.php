<?php

/**
 * Display vocabulary for spell facts — the single definition of how each categorical spell value
 * is rendered.
 *
 * Before this file, the category badge map was copy-pasted into SIX blade templates
 * (components/spells/table, burst-guide-class-block, cc-review, claudes-guides,
 * spell-detail-modal, wow-comps) and the DR-category map into THREE, each free to drift from the
 * others. They are data, not markup, so they live here and every template reads the same one.
 *
 * Deliberately a config file rather than constants on SpellProfile: these are presentation
 * choices, and the value object should not know about CSS classes.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Category badges
    |--------------------------------------------------------------------------
    |
    | Keys are the exact values ModuleSpellReferenceService::categorize() can return, which is
    | also what spells.category stores. Anything unmapped falls back to badge-gray.
    |
    */
    'category_badges' => [
        'Crowd Control' => 'badge-blue',
        'Defensive' => 'badge-red',
        'Mobility' => 'badge-green',
        'Utility' => 'badge-amber',
        'Offensive' => 'badge-orange',
        'Other' => 'badge-gray',
    ],

    /*
    |--------------------------------------------------------------------------
    | Diminishing-returns category badges
    |--------------------------------------------------------------------------
    |
    | The eight hand-authored dr_category values (see CLAUDE.md's DR taxonomy work). These are a
    | genuinely different axis from category above — a spell is Crowd Control AND a Stun, not one
    | or the other — which is why both are shown together on a spell rather than one replacing
    | the other.
    |
    */
    'dr_badges' => [
        'Stun' => 'badge-red',
        'Disorient' => 'badge-blue',
        'Incapacitate' => 'badge-amber',
        'Root' => 'badge-green',
        'Silence' => 'badge-gray',
        'Knockback' => 'badge-orange',
        'Disarm' => 'badge-gold',
        'Slow' => 'badge-gray',
    ],

    /*
    |--------------------------------------------------------------------------
    | usable_while_cc token labels
    |--------------------------------------------------------------------------
    |
    | Human labels for the comma-separated tokens in spells.usable_while_cc. Each token maps back
    | to one exact Blizzard Attribute code — see SpellDataFileParser's $ccMap for which.
    |
    */
    'cc_token_labels' => [
        'stun' => 'Stunned',
        'fear' => 'Feared',
        'flee' => 'Fleeing',
        'confuse' => 'Confused',
        'charm' => 'Charmed',
        'horror' => 'Horror-stunned',
    ],

];
