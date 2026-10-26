<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // "Buy me a coffee" in the sidebar footer, via the tracked /support redirect. Replaced the
    // Stripe "Support Development" checkout button (2026-09-14). Unset = the link is hidden,
    // never shown broken.
    'buymeacoffee' => [
        'url' => env('BUYMEACOFFEE_URL'),
    ],

    // The app's Microsoft Store listing (2026-10-23). Unset = the Store button is hidden and the
    // page offers the plain download alone, so a half-finished listing is never linked.
    //
    // Why it matters: the direct download is an unsigned 68.7 MB exe, so Windows shows the
    // "protected your PC" box and the page has to coach people past it. That reads as malware to
    // anyone who does not already trust the author, and it is the step the funnel currently dies
    // at -- 13 sessions reached this page in a fortnight against 3 app connections ever. The Store
    // signs the package, so the warning goes away.
    //
    // Set it to the public listing URL from Partner Center (Product identity), which looks like
    // https://apps.microsoft.com/detail/9NXXXXXXXXXX
    'microsoft_store' => [
        'url' => env('MICROSOFT_STORE_URL'),
        // The Store copy does not update itself; a new version waits on Microsoft's review. Only when
        // the Store holds the same version as the download does the app page lead with it
        // (2026-10-26: the listing held 0.5.5 while the download was 0.5.9, with recording and the
        // Videos tab). Set MICROSOFT_STORE_CURRENT=true once Partner Center publishes the update.
        'current' => (bool) env('MICROSOFT_STORE_CURRENT', false),
    ],

    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
    ],

    // "Continue with Google" (Socialite). Create an OAuth client at console.cloud.google.com →
    // APIs & Services → Credentials, and register BOTH https://mindcollector.com/auth/google/callback
    // and https://www.mindcollector.com/auth/google/callback. The redirect is relative on purpose:
    // Socialite resolves it against the host the visitor is on, so www and the bare domain each
    // get their own matching callback. The button is hidden until both keys are set.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    // Battle.net OAuth (account linking) + the WoW profile API. The same client the data scripts
    // (fetch-talent-trees.php, fetch-spell-icons.php) already use — its Redirect URLs in the
    // Blizzard developer portal must include this app's /auth/battlenet/callback, exactly.
    'battlenet' => [
        'client_id' => env('BLIZZARD_CLIENT_ID'),
        'client_secret' => env('BLIZZARD_CLIENT_SECRET'),

        // Leave unset to use this app's own callback route URL. Set it when the registered URL
        // differs from what route() builds (a proxy, a different local domain).
        'redirect' => env('BATTLENET_REDIRECT_URI'),

        // China is omitted: it runs its own OAuth host and API, and nothing here is built for it.
        'regions' => ['us', 'eu', 'kr', 'tw'],

        // Characters below this level get their list entry only, no detail sync — they cannot
        // have rated PvP history, and a large account has many of them.
        'detail_min_level' => 70,

        // The current level cap. My Characters lists only characters at it by default (the nine
        // with the highest item level, when there are more). Raise it with each expansion.
        'max_level' => 90,
    ],

    'discord' => [
        'feedback_webhook_url' => env('DISCORD_FEEDBACK_WEBHOOK_URL'),
    ],

    // Where `wow:push-rounds` sends this PC's games (the desktop app's "Website key"), for /wow/coach.
    'mindcollector' => [
        'site' => env('MINDCOLLECTOR_SITE', 'https://www.mindcollector.com'),
        'key' => env('MINDCOLLECTOR_KEY'),
    ],

];
