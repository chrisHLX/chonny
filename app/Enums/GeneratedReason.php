<?php

namespace App\Enums;

enum GeneratedReason: string
{
    case Initial = 'initial';
    case Reflection = 'reflection';
    case Expired = 'expired';
    case InsightChange = 'insight_change';
    case ModuleCompleted = 'module_completed';
}
