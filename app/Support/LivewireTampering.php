<?php

namespace App\Support;

use ErrorException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Mechanisms\HandleComponents\CorruptComponentPayloadException;
use RuntimeException;
use Throwable;
use TypeError;

/**
 * A /livewire/update request whose payload the server refuses to accept.
 *
 * WHY THIS EXISTS. Every public Livewire property is writable by anyone who posts to
 * /livewire/update — that is how wire:model works. Automated vulnerability scanners exploit it:
 * they load a public page, take its component snapshot, and post it back with junk written into
 * every property. On 2026-09-12 that produced every one of the 45 server errors in three days
 * on live, all from scanner IPs (they were probing WordPress and .env files in the same second),
 * each one a 90-line ERROR in laravel.log. None of it was dangerous — Livewire 3.6.4 has the fix
 * for CVE-2025-54068, the hole these probes look for — but it buried real errors in noise.
 *
 * WHAT COUNTS. Only failures that can ONLY come from a payload the page's own JavaScript would
 * never send, and only on the Livewire update route:
 *  - writing a #[Locked] property;
 *  - a snapshot whose checksum does not match (Livewire's own tamper check);
 *  - a TypeError/ErrorException raised INSIDE Livewire's hydration code (a string written into a
 *    typed bool, a snapshot that is not JSON at all). An exception raised in OUR component code
 *    is deliberately not matched — that is a real bug, and it must stay a loud 500.
 *
 * WHAT HAPPENS. A 419, the status Livewire itself uses when an open page can no longer be
 * resumed (LivewirePageExpiredBecauseNewDeploymentHasSignificantEnoughChanges). Its JavaScript
 * turns a 419 into "This page has expired. Would you like to refresh?" — the right answer for the
 * one legitimate way a real player could land here (a tab left open across a deploy that changed
 * a property's type), and harmless to a scanner. Logged as a single WARNING line with the IP, not
 * as an ERROR with a stack trace.
 */
class LivewireTampering extends RuntimeException
{
    private const HYDRATION_FILE = 'livewire/src/Mechanisms/HandleComponents/HandleComponents.php';

    public static function matches(Throwable $e, ?Request $request = null): bool
    {
        $request ??= request();

        if (! $request || ! $request->routeIs('*livewire.update')) {
            return false;
        }

        if ($e instanceof CannotUpdateLockedPropertyException || $e instanceof CorruptComponentPayloadException) {
            return true;
        }

        return ($e instanceof TypeError || $e instanceof ErrorException)
            && str_ends_with(str_replace('\\', '/', $e->getFile()), self::HYDRATION_FILE);
    }

    public static function from(Throwable $e): self
    {
        return new self($e->getMessage(), 0, $e);
    }

    /** One line, not a stack trace: the cause is the payload, not the code. */
    public function report(): void
    {
        $request = request();

        Log::warning('Rejected a tampered Livewire request', [
            'ip' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 160),
            'reason' => class_basename($this->getPrevious()).': '.mb_substr($this->getMessage(), 0, 200),
        ]);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => 'This page has expired. Please refresh.'], 419);
    }
}
