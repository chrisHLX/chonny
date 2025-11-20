<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Module;
use App\Models\Question;
use App\Models\Proficiency;
use App\Models\Subject;

class ProficiencyController extends Controller
{
    //
    public function bySubject(Subject $subject)
    {
        \Log::info('Fetching proficiencies for subject: ' . $subject->proficiencies);
        return response()->json($subject->proficiencies);
    }

}
