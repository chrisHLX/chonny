<?php

/*
 * The games on MindCollector (2026-10-17): the platform home's cards, the sidebar's game switcher
 * and each coming game's page all read this one list, so adding a game is one entry here plus its
 * own route group.
 *
 * `live` games have pages; `soon` games have a page that says what is coming and lets a player ask
 * to be told (game_interests). Each game's colour is its identity colour, used the way class colours
 * are: for the game's own marks, never for text.
 */

return [
    'games' => [
        // Retail WoW goes by its expansion since 2026-11-19 (Chriso), beside Forever as a tab of its
        // own: the two are different games to the player, with different menus. `label` heads the
        // game's menu in the sidebar; `image` is the home card's picture.
        'wow' => [
            'name' => 'World of Warcraft: Midnight',
            'short' => 'Midnight',
            'label' => 'WoW: Midnight',
            'status' => 'live',
            'route' => 'wow.home',
            'icon' => 'badge-wow',
            'color' => '#C8952C',
            'tagline' => 'In-depth arena analysis for every game you play. Find out why you lost, watch each GCD on the timeline, and plan with PvP guides, 3v3 comps and the Matchup Lab.',
            'image' => '/images/app/card.webp',
            'cta' => 'Open Midnight',
        ],
        'forever' => [
            'name' => 'WoW Forever',
            'short' => 'Forever',
            'label' => 'WoW Forever',
            'status' => 'live',
            'route' => 'wow.forever',
            'icon' => 'badge-forever',
            'color' => '#34C38F',
            'tagline' => 'MindCollector Auction House scans the AH for the cheapest recipes to level, the most profitable crafts and what\'s selling. With OBS it records every PvP fight too.',
            'image' => '/images/forever/professions.webp',
            'cta' => 'Open Forever',
            'new' => true,
        ],
        'sc2' => [
            'name' => 'StarCraft II',
            'short' => 'SC2',
            'status' => 'soon',
            'route' => 'sc2',
            'icon' => 'badge-sc2',
            'color' => '#3FA7F5',
            'tagline' => 'Ladder replay analysis. Find out why you lost, check your build against the clock, and look up every unit, building and upgrade.',
            // Open already, before the rest (2026-10-18), each at a route of the game's own.
            'now' => [
                ['title' => 'Unit and tech quizzes', 'body' => 'Terran, Protoss and Zerg: costs, supply, build times, the tech tree and what each unit can hit, from the game\'s own data.', 'route' => 'sc2-quiz', 'cta' => 'Take a quiz'],
                ['title' => 'How do you play?', 'body' => 'Thirteen questions about the calls you make on the ladder, and a read on your style. No right answers.', 'route' => 'sc2-diagnostic', 'cta' => 'Start'],
            ],
            'coming' => [
                ['title' => 'Replay reviews', 'body' => 'Drop in your replays and get each game broken down: your build against the clock, workers and army over time, supply blocks, and where the game was won or lost.'],
                ['title' => 'Unit and build reference', 'body' => 'Every unit, building and upgrade with its cost, build time and what it beats, from the game\'s own data.'],
                ['title' => 'See what to improve', 'body' => 'Your games compared with the players you face, and each session against the ones before.'],
            ],
        ],
        'lol' => [
            'name' => 'League of Legends',
            'short' => 'LoL',
            'status' => 'soon',
            'route' => 'lol',
            // Off the home and the switcher until it has something to open (2026-10-18): a card with
            // nothing behind it made the platform look thin. /lol still answers, for links already out.
            'listed' => false,
            'icon' => 'badge-lol',
            'color' => '#0AC8B9',
            'tagline' => 'Ranked game analysis. Find out why you lost and what you need to improve.',
            'coming' => [
                ['title' => 'Game reviews', 'body' => 'Every ranked game broken down: what won or lost it, compared with the players in your match.'],
                ['title' => 'See what to improve', 'body' => 'Your play compared with the players in your matches, session by session.'],
            ],
        ],
    ],
];
