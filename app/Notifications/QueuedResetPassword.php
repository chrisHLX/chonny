<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The password-reset email, sent from a queue worker instead of inside the request — the same
 * reason as QueuedVerifyEmail. Sent synchronously, a mail-host hiccup (production's log has
 * several "451 queue file write error" responses from it) turns "Forgot password" into an error
 * page; queued, the page always answers and a failed send can simply be requested again.
 */
class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
