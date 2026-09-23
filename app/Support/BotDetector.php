<?php

namespace App\Support;

/**
 * Is this request a crawler?
 *
 * WHY THIS EXISTS. Every full-page Livewire component renders server-side on a plain GET, so a
 * crawler fetching /wow/comps fires `PageViewEvent::log()` exactly like a person does. Measured
 * against nginx's own logs on 2026-09-24: of 7,766 real served pages over a fortnight, **3,559
 * (46%) came from self-declared bots** — Googlebot, bingbot, GPTBot, ClaudeBot, Amazonbot,
 * PetalBot, SemrushBot. Roughly half of every number on /admin/page-usage was crawler traffic,
 * which made a tripling of bot discovery look like a tripling of audience.
 *
 * WHAT IT CANNOT DO, AND WHY THE COLUMN IS NULLABLE. Vulnerability scanners send
 * `Mozilla/5.0 ...` and are indistinguishable here from a browser — in the same logs, requests
 * for `/.env` and `/.git/config` carried ordinary browser agents. This catches what *declares*
 * itself, which is the honest majority, and nothing more. A `false` therefore means "did not
 * declare itself a bot", not "definitely a person", and anything downstream should say so.
 *
 * Those scanners mostly 404 rather than reaching a tracked page, so they pollute the nginx log
 * far more than they pollute this table: 48,081 of 72,727 requests in that fortnight were 404s
 * that never matched a route and never logged anything.
 */
class BotDetector
{
    /**
     * Agents that name themselves. Kept as substrings rather than patterns because that is how
     * these strings actually vary — "Googlebot/2.1", "Googlebot-Image/1.0".
     */
    private const NAMED = [
        'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandexbot', 'sogou',
        'exabot', 'facebookexternalhit', 'facebookcatalog', 'twitterbot', 'linkedinbot',
        'whatsapp', 'telegrambot', 'discordbot', 'slackbot', 'redditbot', 'pinterest',
        'applebot', 'petalbot', 'bytespider', 'amazonbot', 'seznambot', 'qwantify',
        'gptbot', 'chatgpt-user', 'oai-searchbot', 'claudebot', 'claude-web', 'anthropic-ai',
        'perplexitybot', 'ccbot', 'google-extended', 'cohere-ai', 'diffbot', 'omgili',
        'ahrefsbot', 'semrushbot', 'dotbot', 'mj12bot', 'blexbot', 'dataforseobot',
        'serpstatbot', 'barkrowler', 'imagesiftbot', 'zoominfobot', 'screaming frog',
        'siteauditbot', 'megaindex', 'netcraft', 'censys', 'expanse', 'internetmeasurement',
        'masscan', 'zgrab', 'l9explore', 'paloaltonetworks', 'shodan',
        'uptimerobot', 'pingdom', 'statuscake', 'betteruptime', 'site24x7', 'newrelicpinger',
    ];

    /** Generic markers — a tool that did not bother to name itself but is plainly not a browser. */
    private const GENERIC = [
        'bot/', 'bot ', '+bot', 'crawler', 'crawling', 'spider', 'scraper', 'scrapy',
        'curl/', 'wget', 'python-requests', 'python-urllib', 'aiohttp', 'httpx',
        'go-http-client', 'java/', 'okhttp', 'apache-httpclient', 'libwww', 'lwp::',
        'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium',
        'axios/', 'node-fetch', 'got/', 'postmanruntime', 'insomnia', 'restsharp',
        'feedfetcher', 'rss', 'monitor', 'checker', 'validator',
    ];

    /**
     * True when the agent declares itself automated. A null or empty agent counts: every real
     * browser sends one, and a request without it is a script.
     */
    public static function isBot(?string $userAgent): bool
    {
        $agent = trim((string) $userAgent);

        if ($agent === '' || $agent === '-') {
            return true;
        }

        $agent = strtolower($agent);

        foreach (self::NAMED as $needle) {
            if (str_contains($agent, $needle)) {
                return true;
            }
        }

        foreach (self::GENERIC as $needle) {
            if (str_contains($agent, $needle)) {
                return true;
            }
        }

        // Anything not claiming to be a browser at all. Every mainstream browser — and every
        // scanner pretending to be one — starts "Mozilla/".
        return ! str_starts_with($agent, 'mozilla/') && ! str_starts_with($agent, 'opera/');
    }

    /**
     * The host a visit was referred from, or null for a direct hit.
     *
     * Only the host is kept, never the full URL: the path can carry a search query or a session
     * token, and none of that is needed to answer "where did they come from".
     *
     * Our own domains collapse to null, because internal navigation is not a referral and
     * counting it buries the handful of real external sources — on 2026-09-24 the top "referrer"
     * was mindcollector.com itself, at 1,616 of 4,130.
     */
    public static function referrerHost(?string $referer): ?string
    {
        if (! $referer) {
            return null;
        }

        $host = parse_url($referer, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(ltrim($host, '.'));
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        $own = parse_url((string) config('app.url'), PHP_URL_HOST);
        $own = is_string($own) ? strtolower($own) : '';
        $ownBare = str_starts_with($own, 'www.') ? substr($own, 4) : $own;

        if ($ownBare !== '' && $bare === $ownBare) {
            return null;
        }

        return substr($bare, 0, 255);
    }
}
