<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\PageViewEvent;
use App\Models\Training\Claim;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Examine a claim: the four questions applied to something read, heard or generated, with a
 * confidence before and after, and a date to come back and record what turned out to be true.
 * The recorded outcomes are what Progress's calibration reads.
 */
class ClaimController extends Controller
{
    public function index(Request $request)
    {
        PageViewEvent::log('training_claims');
        $claims = $request->user()->trainingClaims()->latest()->get();

        return view('training.claims.index', [
            'due' => $claims->filter->isDue(),
            'open' => $claims->filter(fn ($c) => $c->outcome === null && ! $c->isDue()),
            'closed' => $claims->whereNotNull('outcome'),
        ]);
    }

    public function create()
    {
        PageViewEvent::log('training_claim_new');

        return view('training.claims.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'claim' => ['required', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:255'],
            'confidence_before' => ['required', 'integer', 'between:0,100'],
            'known' => ['required', 'string', 'max:5000'],
            'alternatives' => ['required', 'string', 'max:5000'],
            'would_change' => ['required', 'string', 'max:5000'],
            'if_acted' => ['nullable', 'string', 'max:5000'],
            'confidence_after' => ['required', 'integer', 'between:0,100'],
            'revisit_days' => ['required', 'integer', Rule::in([7, 14, 30, 90])],
        ]);
        $days = (int) $data['revisit_days'];
        unset($data['revisit_days']);

        $claim = $request->user()->trainingClaims()->create($data + ['revisit_on' => today()->addDays($days)]);

        return redirect()->route('training.claims.show', $claim)
            ->with('training_status', 'Saved. It comes back on '.$claim->revisit_on->format('j M Y').' so you can record what turned out to be true.');
    }

    public function show(Request $request, Claim $claim)
    {
        abort_unless($claim->user_id === $request->user()->id, 403);
        PageViewEvent::log('training_claim');

        return view('training.claims.show', ['claim' => $claim]);
    }

    public function update(Request $request, Claim $claim)
    {
        abort_unless($claim->user_id === $request->user()->id, 403);
        $claim->update($request->validate([
            'outcome' => ['required', Rule::in(array_keys(Claim::OUTCOMES))],
            'outcome_note' => ['nullable', 'string', 'max:5000'],
        ]));

        return redirect()->route('training.claims.show', $claim)->with('training_status', 'Outcome recorded.');
    }
}
