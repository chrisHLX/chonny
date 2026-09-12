<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser security headers on every web response. Before this, production sent none
 * (checked 2026-09-12).
 *
 * - X-Frame-Options: another site could load /login or /register in an invisible frame and trick a
 *   visitor into typing into it (clickjacking). SAMEORIGIN still lets the site frame itself.
 * - X-Content-Type-Options: stops browsers guessing a file's type — a user-supplied upload served
 *   as text cannot be run as a script.
 * - Referrer-Policy: other sites see our origin when a visitor clicks away, never the full URL
 *   (which can carry a private guide's slug or a reset token).
 * - Strict-Transport-Security, production over HTTPS only: browsers stop even attempting plain
 *   HTTP, which closes the window where a first http:// request could be intercepted before the
 *   server's redirect. Deliberately without includeSubDomains or preload — both are hard to walk
 *   back, and nothing here needs them.
 *
 * No Content-Security-Policy: reCAPTCHA, Google Fonts, the Alpine/Livewire runtime and inline
 * styles would all need allow-listing, and a wrong CSP silently breaks the page for users. Worth
 * doing as its own tested change.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);

        if ($request->isSecure() && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000', false);
        }

        return $response;
    }
}
