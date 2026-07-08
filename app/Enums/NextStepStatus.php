<?php

namespace App\Enums;

enum NextStepStatus: string
{
    case Pending = 'pending';
    case Attempted = 'attempted';
    case Completed = 'completed';
    case Superseded = 'superseded';
    case Expired = 'expired';
}
