<?php

namespace App\Enums;

enum NextStepStatus: string
{
    case Pending = 'pending';
    case Attempted = 'attempted';
    case Completed = 'completed';
    case Superseded = 'superseded';
    case Expired = 'expired';

    // Terminal state for a step that concluded an investigation chain (3 completed task-type
    // steps on the same insight_id+concept_id) rather than being followed by a 4th task. Distinct
    // from Completed so it can never be confused with a step whose successor generation merely
    // failed transiently (see NextStepService::regenerateAfterReflection) — Concluded is only ever
    // set deliberately, on purpose, by the threshold check.
    case Concluded = 'concluded';
}
