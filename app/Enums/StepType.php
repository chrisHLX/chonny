<?php

namespace App\Enums;

enum StepType: string
{
    case Task = 'task';
    case Module = 'module';
    case KnowledgeCheck = 'knowledge_check';
}
