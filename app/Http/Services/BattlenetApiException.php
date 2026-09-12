<?php

namespace App\Http\Services;

/**
 * Blizzard said no, in a way worth showing the player ("rejected the sign-in (400: invalid
 * redirect_uri)"). Its own type so a caller can surface these messages and keep every other
 * exception's — a SQL error, say — out of the UI.
 */
class BattlenetApiException extends \RuntimeException {}
