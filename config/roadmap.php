<?php

// Guest-facing "learning path" milestone definitions — see RoadmapService::buildGuestRoadmap().
// Keyed by Subject.name (Subject has no slug column). Stages are deliberately hardcoded copy,
// not AI output — they describe the product's training methodology, not a per-user diagnosis.
// The 'first_module' stage is a special key: RoadmapService replaces its title/detail at
// runtime with a real, concept-matched Module (or a generic fallback if no match exists) —
// never AI-generated, never left as a placeholder that could contradict what's actually in
// the content bank. Every other stage's copy is static and subject-scoped only.
//
// Statuses are assigned positionally by RoadmapService: index 0 = complete (the diagnostic
// just finished), index 1 = next, everything after = future. Do not put a non-'first_module'
// stage at index 1 unless you also want it to inherit the deterministic-module treatment.
//
// 'context_dimensions' is another special key, like 'first_module': RoadmapService replaces its
// title/detail at runtime from the subject's real SubjectContextDimension rows (e.g. "Race" for
// SC2, "Class"+"Spec" for WoW) rather than the hardcoded copy below, which exists only as a
// fallback if dimension data is somehow missing. A subject with zero seeded dimensions (Poker,
// deliberately) has this stage omitted from the path entirely — see resolveStages().
//
// 'context_dimensions' sits right after 'diagnostic' (ahead of 'first_module') as of the
// context-aware-diagnostic work — declaring class/race/role now happens as part of the
// diagnostic itself (see DiagnosticQuizRunner::shouldShowContextStep()), so by the time a user
// reaches this list it's often already complete, not still "next." Collection::getLearningPathProperty()
// computes each stage's status from real state keyed on stage_key, not array position, so this
// reordering only changes display order and the *guest preview's* index-1 "next" slot
// (RoadmapService::assignPositionalStatus()) — both intended effects, not a correctness risk.

return [

    'default' => [
        ['key' => 'diagnostic', 'title' => 'Diagnostic Assessment', 'detail' => "We've identified how you naturally approach this subject."],
        ['key' => 'first_module', 'title' => null, 'detail' => null],
        ['key' => 'skill_breakdown', 'title' => 'Personalised Skill Breakdown', 'detail' => 'We\'ll dig into how your strengths and weaknesses actually show up when you practice.'],
        ['key' => 'practice_drills', 'title' => 'Targeted Practice', 'detail' => 'Focused practice built around your biggest opportunities.'],
        ['key' => 'reassessment', 'title' => 'Reassessment', 'detail' => 'Measure how your decision-making has changed.'],
    ],

    'World of Warcraft: The War Within' => [
        ['key' => 'diagnostic', 'title' => 'Diagnostic Assessment', 'detail' => "We've identified how you naturally approach arena."],
        ['key' => 'context_dimensions', 'title' => 'Class Breakdown', 'detail' => "We'll learn how your class changes the way your strengths and weaknesses show up in game."],
        ['key' => 'first_module', 'title' => null, 'detail' => null],
        ['key' => 'win_conditions', 'title' => 'Personalised Win Conditions', 'detail' => "We'll identify the situations that matter most for your class and playstyle."],
        ['key' => 'practice_drills', 'title' => 'In-Game Practice Drills', 'detail' => "Drills built around your biggest opportunities."],
        ['key' => 'comp_prep', 'title' => 'Comp & Matchup Preparation', 'detail' => "Focus on the compositions and opponents that matter most for your climb."],
        ['key' => 'reassessment', 'title' => 'Reassessment', 'detail' => "Measure how your decision-making has changed."],
    ],

    'League of Legends' => [
        ['key' => 'diagnostic', 'title' => 'Diagnostic Assessment', 'detail' => "We've identified how you naturally approach the game."],
        ['key' => 'context_dimensions', 'title' => 'Champion & Role Breakdown', 'detail' => "We'll learn how your role and champion pool change the way your strengths and weaknesses show up."],
        ['key' => 'first_module', 'title' => null, 'detail' => null],
        ['key' => 'win_conditions', 'title' => 'Personalised Win Conditions', 'detail' => "We'll identify the situations that matter most for your playstyle."],
        ['key' => 'practice_drills', 'title' => 'In-Game Practice Drills', 'detail' => "Drills built around your biggest opportunities."],
        ['key' => 'matchup_prep', 'title' => 'Matchup Preparation', 'detail' => "Focus on the matchups that matter most for your climb."],
        ['key' => 'reassessment', 'title' => 'Reassessment', 'detail' => "Measure how your decision-making has changed."],
    ],

    'StarCraft 2' => [
        ['key' => 'diagnostic', 'title' => 'Diagnostic Assessment', 'detail' => "We've identified how you naturally approach the game."],
        ['key' => 'context_dimensions', 'title' => 'Race Breakdown', 'detail' => "We'll learn how your race changes the way your strengths and weaknesses show up."],
        ['key' => 'first_module', 'title' => null, 'detail' => null],
        ['key' => 'win_conditions', 'title' => 'Personalised Win Conditions', 'detail' => "We'll identify the build orders and situations that matter most for your playstyle."],
        ['key' => 'practice_drills', 'title' => 'In-Game Practice Drills', 'detail' => "Drills built around your biggest opportunities."],
        ['key' => 'matchup_prep', 'title' => 'Matchup Preparation', 'detail' => "Focus on the matchups that matter most for your climb."],
        ['key' => 'reassessment', 'title' => 'Reassessment', 'detail' => "Measure how your decision-making has changed."],
    ],

    'Poker' => [
        ['key' => 'diagnostic', 'title' => 'Diagnostic Assessment', 'detail' => "We've identified how you naturally approach decisions at the table."],
        ['key' => 'context_dimensions', 'title' => 'Playstyle Breakdown', 'detail' => "We'll learn how your style changes the way your strengths and weaknesses show up in hands."],
        ['key' => 'first_module', 'title' => null, 'detail' => null],
        ['key' => 'win_conditions', 'title' => 'Personalised Decision Points', 'detail' => "We'll identify the spots that matter most for your win rate."],
        ['key' => 'practice_drills', 'title' => 'Hand-History Drills', 'detail' => "Drills built around your biggest opportunities."],
        ['key' => 'opponent_prep', 'title' => 'Opponent-Read Preparation', 'detail' => "Focus on the reads that matter most for moving up in stakes."],
        ['key' => 'reassessment', 'title' => 'Reassessment', 'detail' => "Measure how your decision-making has changed."],
    ],

];
