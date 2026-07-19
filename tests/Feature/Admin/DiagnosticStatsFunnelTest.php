<?php

use App\Livewire\Admin\DiagnosticStats;
use App\Models\FunnelEvent;
use App\Models\User;
use Livewire\Livewire;

test('funnel counts and rates are computed correctly from raw events', function () {
    // 4 profile views, 2 roadmap clicks (50% click-through), 1 signup started.
    FunnelEvent::log('profile_viewed', 'session-a');
    FunnelEvent::log('profile_viewed', 'session-b');
    FunnelEvent::log('profile_viewed', 'session-c');
    FunnelEvent::log('profile_viewed', 'session-d');

    FunnelEvent::log('roadmap_clicked', 'session-a');
    FunnelEvent::log('roadmap_clicked', 'session-b');

    FunnelEvent::log('signup_started', 'session-a');

    $userA = User::factory()->create();
    FunnelEvent::log('signup_completed', 'session-a', null, $userA->id);

    $funnel = Livewire::test(DiagnosticStats::class)->instance()->funnel;

    expect($funnel['profileViewed'])->toBe(4);
    expect($funnel['roadmapClicked'])->toBe(2);
    expect($funnel['signupStarted'])->toBe(1);
    expect($funnel['uniqueSignups'])->toBe(1);
    expect($funnel['clickThroughRate'])->toBe(50.0);
});

test('signups are attributed to clickers vs non-clickers via the shared guest_session_id', function () {
    $clickerUser    = User::factory()->create();
    $nonClickerUser = User::factory()->create();

    FunnelEvent::log('profile_viewed', 'session-clicker');
    FunnelEvent::log('roadmap_clicked', 'session-clicker');
    FunnelEvent::log('signup_completed', 'session-clicker', null, $clickerUser->id);

    FunnelEvent::log('profile_viewed', 'session-no-click');
    FunnelEvent::log('signup_completed', 'session-no-click', null, $nonClickerUser->id);

    $funnel = Livewire::test(DiagnosticStats::class)->instance()->funnel;

    expect($funnel['signupsAfterClick'])->toBe(1);
    expect($funnel['nonClickerSignups'])->toBe(1);
    expect($funnel['clickerSignupRate'])->toBe(100.0); // 1 of 1 clicker signed up
});

test('a guest with multiple pending diagnostics does not inflate the unique signup count', function () {
    $user = User::factory()->create();

    // claimGuestQuizResults() logs one signup_completed row per claimed module — same
    // guest_session_id and user_id, different module_id.
    FunnelEvent::log('signup_completed', 'session-multi', null, $user->id);
    FunnelEvent::log('signup_completed', 'session-multi', null, $user->id);

    $funnel = Livewire::test(DiagnosticStats::class)->instance()->funnel;

    expect($funnel['uniqueSignups'])->toBe(1);
});

test('funnel handles zero events without division-by-zero errors', function () {
    $funnel = Livewire::test(DiagnosticStats::class)->instance()->funnel;

    expect($funnel['profileViewed'])->toBe(0);
    expect($funnel['clickThroughRate'])->toBe(0.0);
    expect($funnel['clickerSignupRate'])->toBe(0.0);
});
