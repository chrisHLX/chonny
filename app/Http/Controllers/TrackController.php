<?php

namespace App\Http\Controllers;

use App\Models\PageViewEvent;
use Illuminate\Http\Request;

/**
 * Lightweight, fire-and-forget client-side usage beacons that must NOT go through Livewire.
 *
 * WoW Comps' tab bar is pure Alpine (see wow-comps.blade.php) precisely so switching tabs
 * never round-trips and re-renders that heavy component. To learn which tabs actually get
 * used (e.g. to decide which are safe to lazy-load) we still need a signal, so the tab
 * buttons fire a `fetch(..., { keepalive: true })` here instead. The response is ignored by
 * the client; a failure here must never surface to the user, matching PageViewEvent::log()'s
 * own swallow-and-log discipline.
 */
class TrackController extends Controller
{
    /**
     * Tab keys must match the `@click="tab = '...'"` values in wow-comps.blade.php's tab bar.
     * Validated against this allowlist so an unauthenticated public endpoint can't write
     * arbitrary strings into `page_view_events.slot`.
     */
    private const WOW_COMPS_TABS = [
        'offensive', 'defensive', 'synergies', 'pvptalents',
        'active', 'passive', 'rotation',
    ];

    public function wowCompsTab(Request $request)
    {
        $tab = (string) $request->input('tab');

        if (in_array($tab, self::WOW_COMPS_TABS, true)) {
            // Distinct `page` value ('wow_comps_tab', not 'wow_comps') keeps these rows out of
            // every existing wow_comps query; the tab name rides in `slot` (a nullable string
            // that's already "a page-scoped sub-identifier"). Surfaced by
            // Admin\PageUsage::getTabBreakdownProperty().
            PageViewEvent::log('wow_comps_tab', slot: $tab);
        }

        return response()->noContent();
    }
}
