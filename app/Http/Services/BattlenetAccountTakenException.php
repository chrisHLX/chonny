<?php

namespace App\Http\Services;

/** The Battle.net account is already linked to a different MindCollector account. */
class BattlenetAccountTakenException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('That Battle.net account is already linked to a different MindCollector account.');
    }
}
