<?php

namespace App\Console\Commands;

use App\Http\Services\AbilityFinder;
use Illuminate\Console\Command;

/**
 * "Which abilities have property X, and who can press them" — the drafting aid for going from a
 * strategic idea to a sequence that could be an instance of it.
 *
 * Reads curated fields only, never descriptions. See AbilityFinder for why: a text search for
 * "what separates players" returned 215 rows with ~60% false positives, because prose cannot
 * carry polarity — Divine Shield matched "knockback" on the strength of being immune to it. The
 * curated `dr_category` answered the same question with 10 rows and none wrong.
 *
 *   php artisan wow:abilities --vocabulary            # what is queryable at all
 *   php artisan wow:abilities --dr=Knockback          # who can displace
 *   php artisan wow:abilities --hard-cc --cast=instant --max-cd=30
 *   php artisan wow:abilities --while-cc=fear         # what you can press while feared
 *   php artisan wow:abilities --immune-to=Fear        # what stops a fear
 *   php artisan wow:abilities --dr=Stun --class=druid --describe
 *
 * OUTPUT IS A CANDIDATE POOL, NOT A PLAN. It says what exists and who holds it. Whether a
 * sequence built from it is any good is a separate judgement, and one the data cannot make —
 * see arena-structure.md Part 0.2.
 */
class FindAbilities extends Command
{
    protected $signature = 'wow:abilities
        {--vocabulary : List every queryable field and what is in it}
        {--dr= : dr_category, comma-separated (Stun,Root,Knockback,...)}
        {--mechanic= : spells.mechanic (Bleed, Snare, Taunt, Shield, Invulnerable, Sleep, ...)}
        {--category= : Offensive|Defensive|Crowd Control|Utility|Mobility}
        {--cast= : instant|cast}
        {--while-cc= : usable while this crowd control is on you (stun, fear, horror, ...)}
        {--immune-to= : grants immunity to this mechanic (Fear, Stun, Silence, ...)}
        {--school-immunity : grants immunity to a spell school}
        {--chain-target= : healer|kill_target|both}
        {--hard-cc : denies globals (Stun, Incapacitate, Disorient, Silence)}
        {--peel} {--interrupt} {--mobility} {--stealth : requires stealth}
        {--offensive : on the arena-log-verified offensive list}
        {--defensive : on the arena-log-verified defensive list}
        {--charges : has more than one charge}
        {--max-cd= : cooldown at or below this, in seconds}
        {--min-cd= : cooldown at or above this}
        {--spec= : restrict to one spec, e.g. rogue/subtlety}
        {--class= : restrict to one class, e.g. druid}
        {--describe : print each ability\'s description, for checking by eye}
        {--limit=40}';

    protected $description = 'Find abilities by curated property, attributed to the specs that can press them';

    public function handle(AbilityFinder $finder): int
    {
        if ($this->option('vocabulary')) {
            return $this->showVocabulary($finder);
        }

        $filters = [];
        foreach (array_keys(AbilityFinder::FILTERS) as $key) {
            $filters[$key] = $this->option($key);
        }
        $filters['spec'] = $this->option('spec');
        $filters['class'] = $this->option('class');

        if (array_filter($filters) === []) {
            $this->warn('No filters given. Try --vocabulary to see what is queryable.');

            return self::INVALID;
        }

        $rows = $finder->find($filters);

        if (! $finder->propertiesAvailable()) {
            $this->warn('Could not read spell properties from the database — only the profile half is loaded.');
            $this->line('<fg=gray>Property filters (--dr, --mechanic, --cast, ...) will match nothing until it is reachable.</>');
        }

        if ($rows === []) {
            $this->line('Nothing matches.');
            $this->line('<fg=gray>Only curated fields are searchable — descriptions deliberately are not.</>');
            $this->line('<fg=gray>Some properties have no field at all; run --vocabulary to see which.</>');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $shown = array_slice($rows, 0, $limit);

        $this->newLine();
        foreach ($shown as $row) {
            $tags = array_filter([
                $row['drCategory'],
                $row['mechanic'] && $row['mechanic'] !== $row['drCategory'] ? 'mech:'.$row['mechanic'] : null,
                $row['castType'],
                $row['cooldown'] !== null ? $row['cooldown'].'s' : null,
                ($row['charges'] ?? 0) > 1 ? $row['charges'].' charges' : null,
                $row['ccImmunity'] !== []
                    ? 'immune: '.implode('/', $row['ccImmunity']).($row['ccImmunityNote'] ? ' (conditional)' : '')
                    : null,
            ]);

            $this->line(sprintf(
                '  <options=bold>%-26s</> <fg=gray>%s</>',
                $row['name'],
                implode('  ', $tags)
            ));
            $this->line('    <fg=cyan>'.implode(', ', array_values($row['specs'])).'</>');

            if ($this->option('describe') && $row['description'] !== '') {
                $this->line('    <fg=gray>'.mb_substr($row['description'], 0, 150).'</>');
            }

            // Always shown, with or without --describe: an immunity you only have with a
            // particular PvP talent is not one a plan may assume.
            if ($row['ccImmunityNote']) {
                $this->line('    <fg=yellow>conditional: '.mb_substr($row['ccImmunityNote'], 0, 150).'</>');
            }
        }

        $this->newLine();
        $this->info(count($rows).' ability(ies)'.(count($rows) > $limit ? ", showing {$limit}" : '').'.');
        $this->line('<fg=gray>A candidate pool, not a plan — whether a sequence built from these is any good is a separate judgement.</>');

        return self::SUCCESS;
    }

    private function showVocabulary(AbilityFinder $finder): int
    {
        $this->newLine();
        $this->info('Queryable — every one of these reads a field somebody curated.');
        $this->newLine();

        foreach ($finder->vocabulary() as $label => $values) {
            $pairs = [];
            foreach (array_slice($values, 0, 14, true) as $value => $count) {
                $pairs[] = "{$value} ({$count})";
            }
            $this->line(sprintf('  <options=bold>--%-14s</> %s', $label, implode(', ', $pairs)));
        }

        $this->newLine();
        $this->line('  <options=bold>--max-cd / --min-cd</>  seconds, against the talent-resolved cooldown');
        $this->line('  <options=bold>--spec / --class</>     e.g. rogue/subtlety, druid');

        $this->newLine();
        $this->error('NOT queryable — no curated field exists, so asking would return nonsense:');
        foreach (AbilityFinder::UNSUPPORTED as $key => $why) {
            $this->line(sprintf('  <fg=red>%-10s</> <fg=gray>%s</>', $key, $why));
        }

        $this->newLine();
        $this->line('<fg=gray>Those gaps are the honest limit on which strategic ideas can be drafted from data today.</>');

        return self::SUCCESS;
    }
}
