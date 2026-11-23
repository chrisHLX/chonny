<?php

namespace App\Http\Controllers;

use App\Models\PageViewEvent;
use App\Support\ForeverAbilities;

/**
 * WoW Forever's PvP Guides (/wow/forever/pvp/{class}, 2026-11-23): each class's abilities, sorted
 * the way retail's Spells table sorts them, with no talent builds yet. The data is the committed
 * data/forever/abilities.json (`forever:abilities`; docs/forever/pvp-guides.md).
 */
class ForeverPvpController extends Controller
{
    /** The class a bare /wow/forever/pvp opens on. */
    private const DEFAULT_CLASS = 'warrior';

    public function show(?string $classSlug = null)
    {
        $classes = ForeverAbilities::classes();
        abort_if($classes === [], 404);

        $class = ForeverAbilities::find($classSlug ?? self::DEFAULT_CLASS) ?? ($classSlug === null ? $classes[0] : null);
        abort_if($class === null, 404);

        PageViewEvent::log('forever_pvp', slot: $class['slug']);

        $abilities = collect($class['abilities']);
        $groups = [];
        foreach (['active' => false, 'passive' => true] as $key => $passive) {
            $inGroup = $abilities->where('passive', $passive);
            foreach (ForeverAbilities::CATEGORIES as $category) {
                $rows = $inGroup->where('category', $category)->sortBy('name')->values();
                if ($rows->isNotEmpty()) {
                    $groups[$key][$category] = $rows->all();
                }
            }
        }

        return view('forever.pvp', [
            'classes' => $classes,
            'class' => $class,
            'groups' => $groups,
            'counts' => $abilities->where('passive', false)->countBy('category')->all(),
            'build' => ForeverAbilities::build(),
            'color' => config('wow_classes.colors')[$class['slug']] ?? '#8A8A9A',
        ]);
    }
}
