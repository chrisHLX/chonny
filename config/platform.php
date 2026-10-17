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
        'wow' => [
            'name' => 'World of Warcraft',
            'short' => 'WoW',
            'status' => 'live',
            'route' => 'wow.home',
            'icon' => 'badge-wow',
            'color' => '#C8952C',
            'tagline' => 'Arena: every game you play reviewed, PvP guides for every spec, 3v3 comps and the Matchup Lab.',
        ],
        'sc2' => [
            'name' => 'StarCraft II',
            'short' => 'SC2',
            'status' => 'soon',
            'route' => 'sc2',
            'icon' => 'badge-sc2',
            'color' => '#3FA7F5',
            'tagline' => 'Ladder: your replays read game by game, and every unit, building and upgrade from the game\'s own data.',
            // Open already, before the rest (2026-10-18), each at a route of the game's own.
            'now' => [
                ['title' => 'Unit and tech quizzes', 'body' => 'Terran, Protoss and Zerg: costs, supply, build times, the tech tree and what each unit can hit, from the game\'s own data.', 'route' => 'sc2-quiz', 'cta' => 'Take a quiz'],
                ['title' => 'How do you play?', 'body' => 'Thirteen questions about the calls you make on the ladder, and a read on your style. No right answers.', 'route' => 'sc2-diagnostic', 'cta' => 'Start'],
            ],
            'coming' => [
                ['title' => 'Replay reviews', 'body' => 'Drop in your replays and each game comes back read: your build against the clock, workers and army over time, supply blocks, and where the game was won or lost.'],
                ['title' => 'Unit and build reference', 'body' => 'Every unit, building and upgrade with its cost, build time and what it beats, taken from the game\'s data rather than written from memory.'],
                ['title' => 'Where you stand', 'body' => 'As for WoW: your games set against the players you actually meet, and how each session compares with the ones before.'],
            ],
        ],
        'lol' => [
            'name' => 'League of Legends',
            'short' => 'LoL',
            'status' => 'soon',
            'route' => 'lol',
            'icon' => 'badge-lol',
            'color' => '#0AC8B9',
            'tagline' => 'Ranked: your own games read the same way, against the players in your matches.',
            'coming' => [
                ['title' => 'Your games, reviewed', 'body' => 'Each ranked game read for what decided it, measured against the players you actually met.'],
                ['title' => 'Where you stand', 'body' => 'Each game set against the players you actually met, session by session.'],
            ],
        ],
    ],
];
