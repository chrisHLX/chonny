<?php

/**
 * Pulls WoW talent tree and PvP talent data straight from Blizzard's official Game Data
 * API and writes it out as normalized JSON — one file per class. Sibling data source to
 * data/spelldata/ (SimulationCraft dumps), not a replacement: that folder captures the
 * mechanical spell/effect detail (Effects, Proc Chance, Base/Scaled Value, Charges,
 * Cooldown — none of which Blizzard's tooltip API exposes), this one captures the tree
 * shape (nodes, positions, connections, choice nodes) and the official PvP talent roster.
 *
 * This is now the sole source of truth for PvP talents. data/spelldata/split-by-tree.php
 * used to split SimC's PvP-tagged records into their own pvp-talents.txt per class,
 * purely to audit whether SimC's PvP-talent coverage was complete — which is how the
 * missing Mage PvP talent Snowdrift was originally found. data/pvptalents/diff-against-simc.php
 * (built to formalize that audit) confirmed the gap was much larger than one talent —
 * e.g. Hunter's SimC dump had 1 PvP-talent record vs. Blizzard's 26 — so the SimC split
 * was retired (PvP-tagged records now fall into baseline.txt like any other
 * Talent-Entry-less record) and this API pull replaced it as the authoritative source.
 *
 * Auth: OAuth2 client_credentials against oauth.battle.net, using BLIZZARD_CLIENT_ID /
 * BLIZZARD_CLIENT_SECRET from the project .env (never hardcoded). This script is a plain
 * CLI script like the rest of data/spelldata/ — it does not bootstrap Laravel — so .env is
 * parsed directly rather than via Laravel's config().
 *
 * API shape notes (confirmed against the live API while building this, not assumed from
 * docs — Blizzard's actual field names differ from the ones commonly described):
 *   - GET /data/wow/talent-tree/index returns class_talent_trees[] and spec_talent_trees[]
 *     — no bare "id" field on either; the tree/spec ids are only present in the `key.href`
 *     URL, so they're extracted with a regex.
 *   - GET /data/wow/talent-tree/{treeId} (no spec) returns the CLASS talent tree —
 *     `talent_nodes` — shared across every spec of that class. Fetched once per class.
 *     CORRECTION 2026-08-02: this endpoint's `talent_nodes` is NOT limited to the class-wide
 *     baseline the way it reads above — confirmed live (re-fetched, not a stale artifact) that
 *     it also includes nearly every one of the class's own spec nodes (Priest: 226 returned vs.
 *     208 total real spec nodes, with all 208 duplicated inside it, identical embedded
 *     spell_name/talent_name content — not a coincidental id collision). See the
 *     "Blizzard's bare class-tree endpoint..." comment below, where this is filtered back out
 *     before writing the output file. Whether this is a Midnight-expansion API/data-model change
 *     or the endpoint always behaved this way is unconfirmed.
 *   - GET /data/wow/talent-tree/{treeId}/playable-specialization/{specId} returns that
 *     spec's own `spec_talent_nodes` plus `hero_talent_trees[]` (each with its own
 *     `hero_talent_nodes`). It also echoes `class_talent_nodes`, which is intentionally
 *     NOT used here — it's identical to the bare class-tree fetch above and re-storing it
 *     per spec would just duplicate the same nodes 3-4x per class.
 *   - A hero talent tree is frequently shared by more than one spec of a class (e.g. two
 *     Hunter specs both offering "Sentinel"). Deduplicated per class, keyed by hero tree
 *     id; each spec stores only which hero tree ids it can pick from.
 *   - PvP talents: the task description names the field `pvp_talent_slots` on
 *     /data/wow/playable-specialization/{id} — the real field is `pvp_talents`. Used as
 *     found on the live API, not as originally described.
 *   - GET /data/wow/pvp-talent/{id} already includes a `playable_specialization` field, so
 *     each talent's owning spec is known from the detail call alone — the per-spec
 *     `pvp_talents` array is still fetched too (matching the requested step 5) and used to
 *     decide ordering/membership per spec, with the detail cache providing the full record
 *     (compatible_slots, unlock_player_level, spell id) merged in.
 *
 * Usage:
 *   php fetch-talent-trees.php [--only=Hunter,Druid] [--skip-pvp] [--skip-trees]
 *
 *   --only=A,B    Restrict to these class names (as returned by the API, e.g. "Death
 *                 Knight"). Useful for a fast partial run while iterating.
 *   --skip-pvp    Skip the PvP talent pass entirely (talent trees only).
 *   --skip-trees  Skip the talent tree pass entirely (PvP talents only).
 */

// ---------------------------------------------------------------------------------------
// Config / .env
// ---------------------------------------------------------------------------------------

const REGION    = 'us';
const API_NAMESPACE = 'static-us';
const LOCALE    = 'en_US';
const API_HOST  = 'https://us.api.blizzard.com';
const OAUTH_URL = 'https://oauth.battle.net/token';

// Courtesy delay between requests (microseconds) — keeps us well under Blizzard's
// per-second limit without needing to track a request counter/window ourselves.
const REQUEST_DELAY_US = 40000; // 40ms ≈ 25 req/s

const MAX_RETRIES = 5;

function loadEnv(string $path): array
{
    if (!is_file($path)) {
        fwrite(STDERR, "Cannot find .env at {$path}\n");
        exit(1);
    }

    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        // Strip matching surrounding quotes, if present.
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        $env[$key] = $value;
    }

    return $env;
}

$projectRoot = dirname(__DIR__, 2);
$env         = loadEnv($projectRoot . '/.env');

$clientId     = $env['BLIZZARD_CLIENT_ID'] ?? null;
$clientSecret = $env['BLIZZARD_CLIENT_SECRET'] ?? null;

if (!$clientId || !$clientSecret) {
    fwrite(STDERR, "BLIZZARD_CLIENT_ID / BLIZZARD_CLIENT_SECRET missing from .env\n");
    exit(1);
}

// ---------------------------------------------------------------------------------------
// CLI args
// ---------------------------------------------------------------------------------------

$onlyClasses = null;
$skipPvp     = false;
$skipTrees   = false;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--only=(.+)$/', $arg, $m)) {
        $onlyClasses = array_map('trim', explode(',', $m[1]));
    } elseif ($arg === '--skip-pvp') {
        $skipPvp = true;
    } elseif ($arg === '--skip-trees') {
        $skipTrees = true;
    }
}

// ---------------------------------------------------------------------------------------
// HTTP layer: auth + retry/backoff on 429 (and transient 5xx)
// ---------------------------------------------------------------------------------------

/**
 * @return array{status:int, headers:array<string,string>, body:string}
 */
function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL error for {$url}: {$error}");
    }

    $status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders  = substr($raw, 0, $headerSize);
    $respBody    = substr($raw, $headerSize);
    $respHeaders = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $respHeaders[strtolower(trim($k))] = trim($v);
        }
    }

    return ['status' => $status, 'headers' => $respHeaders, 'body' => $respBody];
}

/**
 * Wraps httpRequest with retry/backoff on 429 and 5xx. Honors Retry-After when present,
 * otherwise falls back to exponential backoff (1s, 2s, 4s, ...).
 */
function httpRequestWithRetry(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $attempt = 0;

    while (true) {
        $attempt++;
        $response = httpRequest($method, $url, $headers, $body);

        if ($response['status'] === 429 || $response['status'] >= 500) {
            if ($attempt >= MAX_RETRIES) {
                throw new RuntimeException(
                    "Giving up on {$url} after {$attempt} attempts, last status {$response['status']}"
                );
            }

            $retryAfter = isset($response['headers']['retry-after'])
                ? (int) $response['headers']['retry-after']
                : (2 ** ($attempt - 1));

            fwrite(STDERR, "  [retry] {$response['status']} on {$url} — waiting {$retryAfter}s (attempt {$attempt}/" . MAX_RETRIES . ")\n");
            sleep(max(1, $retryAfter));
            continue;
        }

        if ($response['status'] >= 400) {
            throw new RuntimeException("HTTP {$response['status']} for {$url}: " . substr($response['body'], 0, 500));
        }

        return $response;
    }
}

function getAccessToken(string $clientId, string $clientSecret): string
{
    $response = httpRequestWithRetry('POST', OAUTH_URL, [
        'Authorization: Basic ' . base64_encode("{$clientId}:{$clientSecret}"),
        'Content-Type: application/x-www-form-urlencoded',
    ], 'grant_type=client_credentials');

    $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);

    if (empty($decoded['access_token'])) {
        throw new RuntimeException('Blizzard OAuth response had no access_token: ' . $response['body']);
    }

    return $decoded['access_token'];
}

/** Running counter purely for the end-of-run summary. */
$requestCount = 0;

function apiGet(string $token, string $path, array $extraParams = []): array
{
    global $requestCount;

    $params = array_merge([
        'namespace' => API_NAMESPACE,
        'locale'    => LOCALE,
    ], $extraParams);

    $separator = str_contains($path, '?') ? '&' : '?';
    $url       = (str_starts_with($path, 'http') ? $path : API_HOST . $path) . $separator . http_build_query($params);

    usleep(REQUEST_DELAY_US);
    $requestCount++;

    $response = httpRequestWithRetry('GET', $url, ["Authorization: Bearer {$token}"]);

    return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Blizzard hrefs embed the numeric ids we actually need (the JSON bodies of index/list
 * endpoints don't carry a bare "id" field on their entries) — pull them out with a regex
 * keyed to the resource path shape rather than guessing positionally.
 */
function idsFromHref(string $href, string $pattern): array
{
    if (!preg_match($pattern, $href, $m)) {
        throw new RuntimeException("Could not parse ids from href: {$href}");
    }
    return array_slice($m, 1);
}

function slug(string $name): string
{
    $s = strtolower($name);
    $s = preg_replace("/['\x{2019}]/u", '', $s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

// ---------------------------------------------------------------------------------------
// Node normalization
// ---------------------------------------------------------------------------------------

/**
 * A single tooltip (talent + the spell it casts + its rendered description).
 */
function normalizeTooltip(array $tooltip): array
{
    return [
        'talent_id'   => $tooltip['talent']['id'] ?? null,
        'talent_name' => $tooltip['talent']['name'] ?? null,
        'spell_id'    => $tooltip['spell_tooltip']['spell']['id'] ?? null,
        'spell_name'  => $tooltip['spell_tooltip']['spell']['name'] ?? null,
        'description' => $tooltip['spell_tooltip']['description'] ?? null,
        'cast_time'   => $tooltip['spell_tooltip']['cast_time'] ?? null,
        'cooldown'    => $tooltip['spell_tooltip']['cooldown'] ?? null,
        'range'       => $tooltip['spell_tooltip']['range'] ?? null,
    ];
}

/**
 * Normalizes one talent-tree node (class, spec, or hero). CHOICE nodes carry multiple
 * tooltips per rank (`choice_of_tooltips`); everything else carries a single `tooltip`.
 * Some nodes (e.g. certain passives) have neither — ranks then only carry a rank number.
 */
function normalizeNode(array $node): array
{
    $ranks = [];
    foreach ($node['ranks'] ?? [] as $rank) {
        if (isset($rank['choice_of_tooltips'])) {
            $ranks[] = [
                'rank'    => $rank['rank'] ?? null,
                'choices' => array_map('normalizeTooltip', $rank['choice_of_tooltips']),
            ];
        } elseif (isset($rank['tooltip'])) {
            $ranks[] = array_merge(['rank' => $rank['rank'] ?? null], normalizeTooltip($rank['tooltip']));
        } else {
            $ranks[] = ['rank' => $rank['rank'] ?? null];
        }
    }

    return [
        'id'               => $node['id'] ?? null,
        'node_type'        => $node['node_type']['type'] ?? null,
        'display_row'      => $node['display_row'] ?? null,
        'display_col'      => $node['display_col'] ?? null,
        'raw_position_x'   => $node['raw_position_x'] ?? null,
        'raw_position_y'   => $node['raw_position_y'] ?? null,
        'locked_by'        => $node['locked_by'] ?? [],
        'unlocks'          => $node['unlocks'] ?? [],
        'max_ranks'        => $node['max_ranks'] ?? count($ranks),
        'ranks'            => $ranks,
    ];
}

// ---------------------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------------------

fwrite(STDOUT, "Authenticating with Blizzard OAuth...\n");
$token = getAccessToken($clientId, $clientSecret);
fwrite(STDOUT, "OK.\n\n");

$talentTreesDir = __DIR__;
$pvpTalentsDir  = dirname(__DIR__) . '/pvptalents';
@mkdir($talentTreesDir, 0777, true);
@mkdir($pvpTalentsDir, 0777, true);

$summary = []; // class name => stats

// --- Talent trees --------------------------------------------------------------------

if (!$skipTrees) {
    fwrite(STDOUT, "Fetching talent-tree/index...\n");
    $treeIndex = apiGet($token, '/data/wow/talent-tree/index');

    // class name => class tree id
    $classTrees = [];
    foreach ($treeIndex['class_talent_trees'] ?? [] as $entry) {
        [$treeId] = idsFromHref($entry['key']['href'], '#/talent-tree/(\d+)\?#');
        $classTrees[$entry['name']] = (int) $treeId;
    }

    // class name => [ [treeId, specId, specName], ... ]
    $specsByClass = [];
    foreach ($treeIndex['spec_talent_trees'] ?? [] as $entry) {
        [$treeId, $specId] = idsFromHref(
            $entry['key']['href'],
            '#/talent-tree/(\d+)/playable-specialization/(\d+)\?#'
        );
        $treeId = (int) $treeId;
        $className = array_search($treeId, $classTrees, true);
        if ($className === false) {
            fwrite(STDERR, "  [warn] spec '{$entry['name']}' references unknown tree id {$treeId}, skipping\n");
            continue;
        }
        $specsByClass[$className][] = ['tree_id' => $treeId, 'spec_id' => (int) $specId, 'name' => $entry['name']];
    }

    foreach ($classTrees as $className => $treeId) {
        if ($onlyClasses !== null && !in_array($className, $onlyClasses, true)) {
            continue;
        }

        fwrite(STDOUT, "Fetching class talent tree: {$className} (tree {$treeId})...\n");
        $classTreeResponse = apiGet($token, "/data/wow/talent-tree/{$treeId}");
        $classNodes        = array_map('normalizeNode', $classTreeResponse['talent_nodes'] ?? []);

        $heroTrees = []; // hero tree id => normalized hero tree (deduped across specs)
        $specs     = [];
        $specNodeCount = 0;
        $heroNodeCount = 0;

        foreach ($specsByClass[$className] ?? [] as $specRef) {
            fwrite(STDOUT, "  Fetching spec: {$specRef['name']} (spec {$specRef['spec_id']})...\n");
            $specResponse = apiGet(
                $token,
                "/data/wow/talent-tree/{$specRef['tree_id']}/playable-specialization/{$specRef['spec_id']}"
            );

            $specNodes = array_map('normalizeNode', $specResponse['spec_talent_nodes'] ?? []);
            $specNodeCount += count($specNodes);

            $heroTreeIds = [];
            foreach ($specResponse['hero_talent_trees'] ?? [] as $heroTree) {
                $heroTreeId    = $heroTree['id'];
                $heroTreeIds[] = $heroTreeId;

                if (!isset($heroTrees[$heroTreeId])) {
                    $nodes = array_map('normalizeNode', $heroTree['hero_talent_nodes'] ?? []);
                    $heroNodeCount += count($nodes);
                    $heroTrees[$heroTreeId] = [
                        'id'    => $heroTreeId,
                        'name'  => $heroTree['name'],
                        'nodes' => $nodes,
                    ];
                }
            }

            $specs[$specRef['name']] = [
                'spec_id'            => $specRef['spec_id'],
                'nodes'              => $specNodes,
                'hero_talent_tree_ids' => $heroTreeIds,
            ];
        }

        // Blizzard's bare class-tree endpoint (fetched above, GET /data/wow/talent-tree/{treeId}
        // with no spec segment) does NOT return only the class-wide baseline the way the docblock
        // above assumed when this script was written — confirmed 2026-08-02 by cross-referencing
        // a real character's exported spellbook against an imported Discipline Priest build: for
        // every one of 13 classes checked, nearly every spec node is ALSO present in
        // class_talents.nodes, not as a coincidental id collision but with identical embedded
        // spell_name/talent_name content (e.g. Priest: 226 class nodes vs 208 total spec nodes,
        // with all 208 duplicated inside the class list). A node's appearance in a spec's own
        // fetch is authoritative over its appearance in this bloated class-tree response, so
        // anything already claimed by a spec is filtered back out here. Whether this is a
        // Midnight-expansion API/data-model change or always worked this way isn't known — this
        // filter is defensive regardless of which.
        $specNodeIds = [];
        foreach ($specs as $spec) {
            foreach ($spec['nodes'] as $node) {
                $specNodeIds[$node['id']] = true;
            }
        }
        $classNodes = array_values(array_filter(
            $classNodes,
            fn (array $node) => !isset($specNodeIds[$node['id']])
        ));

        $output = [
            'class'             => $className,
            'talent_tree_id'    => $treeId,
            'fetched_at'        => date('c'),
            'source'            => 'Blizzard Game Data API (' . API_NAMESPACE . ', ' . LOCALE . ')',
            'class_talents'     => ['nodes' => $classNodes],
            'hero_talent_trees' => array_values($heroTrees),
            'specs'             => $specs,
        ];

        $outFile = $talentTreesDir . '/' . slug($className) . '.json';
        file_put_contents($outFile, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        $summary[$className]['tree_count']      = 1 + count($specs); // class tree + each spec tree
        $summary[$className]['class_node_count'] = count($classNodes);
        $summary[$className]['spec_node_count']  = $specNodeCount;
        $summary[$className]['hero_tree_count']  = count($heroTrees);
        $summary[$className]['hero_node_count']  = $heroNodeCount;
    }
}

// --- PvP talents -----------------------------------------------------------------------

if (!$skipPvp) {
    fwrite(STDOUT, "\nFetching pvp-talent/index...\n");
    $pvpIndex = apiGet($token, '/data/wow/pvp-talent/index');
    $pvpTalentRefs = $pvpIndex['pvp_talents'] ?? [];
    fwrite(STDOUT, 'Found ' . count($pvpTalentRefs) . " PvP talents. Fetching full detail for each...\n");

    // id => full detail (spell, description, compatible_slots, unlock_player_level, owning spec)
    $pvpDetails = [];
    foreach ($pvpTalentRefs as $i => $ref) {
        $id = $ref['id'];
        $detail = apiGet($token, "/data/wow/pvp-talent/{$id}");
        $pvpDetails[$id] = [
            'id'                  => $id,
            'name'                => $ref['name'],
            'spell_id'            => $detail['spell']['id'] ?? null,
            'spell_name'          => $detail['spell']['name'] ?? null,
            'description'         => $detail['description'] ?? null,
            'unlock_player_level' => $detail['unlock_player_level'] ?? null,
            'compatible_slots'    => $detail['compatible_slots'] ?? [],
            'playable_spec_id'    => $detail['playable_specialization']['id'] ?? null,
            'playable_spec_name'  => $detail['playable_specialization']['name'] ?? null,
        ];

        if (($i + 1) % 50 === 0) {
            fwrite(STDOUT, '  ' . ($i + 1) . '/' . count($pvpTalentRefs) . " fetched...\n");
        }
    }

    // Regroup by class using the same spec->class list gathered above if available,
    // otherwise (e.g. --skip-trees was passed) derive it fresh from talent-tree/index.
    if ($skipTrees) {
        $treeIndex = apiGet($token, '/data/wow/talent-tree/index');
        $classTrees = [];
        foreach ($treeIndex['class_talent_trees'] ?? [] as $entry) {
            [$treeId] = idsFromHref($entry['key']['href'], '#/talent-tree/(\d+)\?#');
            $classTrees[$entry['name']] = (int) $treeId;
        }
        $specsByClass = [];
        foreach ($treeIndex['spec_talent_trees'] ?? [] as $entry) {
            [$treeId, $specId] = idsFromHref($entry['key']['href'], '#/talent-tree/(\d+)/playable-specialization/(\d+)\?#');
            $treeId = (int) $treeId;
            $className = array_search($treeId, $classTrees, true);
            if ($className !== false) {
                $specsByClass[$className][] = ['tree_id' => $treeId, 'spec_id' => (int) $specId, 'name' => $entry['name']];
            }
        }
    }

    foreach ($specsByClass as $className => $specRefs) {
        if ($onlyClasses !== null && !in_array($className, $onlyClasses, true)) {
            continue;
        }

        fwrite(STDOUT, "Mapping PvP talents to specs for {$className}...\n");
        $specsOut = [];
        $classPvpCount = 0;

        foreach ($specRefs as $specRef) {
            $specDetail = apiGet($token, "/data/wow/playable-specialization/{$specRef['spec_id']}");
            $talents = [];

            foreach ($specDetail['pvp_talents'] ?? [] as $entry) {
                $talentId = $entry['talent']['id'] ?? null;
                if ($talentId === null) {
                    continue;
                }
                // Prefer the richer cached detail (compatible_slots, unlock level); fall
                // back to what's embedded directly on the spec response if a talent was
                // somehow absent from the index pass.
                $talents[] = $pvpDetails[$talentId] ?? [
                    'id'                  => $talentId,
                    'name'                => $entry['talent']['name'] ?? null,
                    'spell_id'            => $entry['spell_tooltip']['spell']['id'] ?? null,
                    'spell_name'          => $entry['spell_tooltip']['spell']['name'] ?? null,
                    'description'         => $entry['spell_tooltip']['description'] ?? null,
                    'unlock_player_level' => null,
                    'compatible_slots'    => [],
                    'playable_spec_id'    => $specRef['spec_id'],
                    'playable_spec_name'  => $specRef['name'],
                ];
            }

            $specsOut[$specRef['name']] = [
                'spec_id'     => $specRef['spec_id'],
                'pvp_talents' => $talents,
            ];
            $classPvpCount += count($talents);
        }

        $output = [
            'class'      => $className,
            'fetched_at' => date('c'),
            'source'     => 'Blizzard Game Data API (' . API_NAMESPACE . ', ' . LOCALE . ')',
            'specs'      => $specsOut,
        ];

        $outFile = $pvpTalentsDir . '/' . slug($className) . '.json';
        file_put_contents($outFile, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        $summary[$className]['pvp_talent_count'] = $classPvpCount;
    }
}

// ---------------------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------------------

fwrite(STDOUT, "\n" . str_repeat('=', 60) . "\n");
fwrite(STDOUT, "Summary\n");
fwrite(STDOUT, str_repeat('=', 60) . "\n");

ksort($summary, SORT_FLAG_CASE | SORT_STRING);
foreach ($summary as $className => $stats) {
    fwrite(STDOUT, "\n{$className}\n");
    if (isset($stats['tree_count'])) {
        fwrite(STDOUT, "  Trees:       {$stats['tree_count']} (1 class + " . ($stats['tree_count'] - 1) . " spec)\n");
        fwrite(STDOUT, "  Nodes:       {$stats['class_node_count']} class + {$stats['spec_node_count']} spec + {$stats['hero_node_count']} hero ({$stats['hero_tree_count']} hero trees)\n");
    }
    if (isset($stats['pvp_talent_count'])) {
        fwrite(STDOUT, "  PvP talents: {$stats['pvp_talent_count']}\n");
    }
}

fwrite(STDOUT, "\n" . str_repeat('-', 60) . "\n");
fwrite(STDOUT, "Total classes processed: " . count($summary) . "\n");
fwrite(STDOUT, "Total API requests made: {$requestCount}\n");
fwrite(STDOUT, "Output: " . $talentTreesDir . " and " . $pvpTalentsDir . "\n");
