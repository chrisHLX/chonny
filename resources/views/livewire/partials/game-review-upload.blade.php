{{--
    Upload control for Match Review.

    THE BROWSER SPLITS THE LOG; IT DOES NOT PARSE IT. A session's WoWCombatLog.txt is 64MB after
    one evening and cannot be posted — production's nginx is on its 1MB default for this site and
    PHP allows 2MB — so this reads the file in slices, finds ARENA_MATCH_START..ARENA_MATCH_END,
    gzips each round and posts it on its own. Every piece of interpretation (field offsets, the
    feign-death filter, team derivation) stays server side, because all of it has been wrong at
    least once and a client that understood the log would have to be reshipped each time.

    It never holds the whole file in memory: File.slice() reads 8MB at a time and only lines inside
    a round are kept.
--}}
<div x-data="arenaUpload()" class="linear-card p-5 sm:p-6 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="page-section-title">Upload matches</h2>
            <p class="page-section-desc">
                Pick your <code class="text-gold">WoWCombatLog.txt</code> — it is in
                <code class="text-ink-muted">World of Warcraft/_retail_/Logs/</code>. Your browser
                finds the arena rounds in it and sends only those, so a 60MB log becomes a few
                hundred KB per round. Re-uploading the same file is safe: a round already stored is
                recognised and replaced, never duplicated.
            </p>
        </div>

        <label class="btn-primary shrink-0 cursor-pointer">
            <input type="file" class="hidden" accept=".txt,.log" multiple
                   x-on:change="start($event.target.files)" x-bind:disabled="busy">
            <span x-text="busy ? 'Uploading…' : 'Choose log file'"></span>
        </label>
    </div>

    <template x-if="status">
        <div class="rounded-lg border border-line bg-surface-2 px-4 py-3 space-y-2">
            <p class="text-sm text-ink" x-text="status"></p>

            <template x-if="total > 0">
                <div class="h-1.5 w-full rounded-full bg-surface-3 overflow-hidden">
                    <div class="h-full bg-gold-gradient transition-all"
                         x-bind:style="`width: ${Math.round((done / total) * 100)}%`"></div>
                </div>
            </template>

            <template x-if="notes.length">
                <ul class="text-xs text-ink-muted space-y-0.5 list-disc pl-4">
                    <template x-for="note in notes" :key="note">
                        <li x-text="note"></li>
                    </template>
                </ul>
            </template>
        </div>
    </template>

    <p class="text-xs text-ink-subtle">
        Solo Shuffle only for now, and Advanced Combat Logging must have been on
        (System → Network) — without it the log carries no specs and a round cannot be read. The
        MindCollector addon turns both on for you.
    </p>
</div>

{{--
    A plain global factory rather than @script + Alpine.data(): a global assigned by an inline
    script is defined by the time Alpine processes x-data on this element, whereas Alpine.data()
    registered from @script races with the component already being in the DOM. Re-assigning on a
    wire:navigate visit is harmless.
--}}
<script>
    window.arenaUpload = () => ({
        busy: false,
        status: '',
        notes: [],
        done: 0,
        total: 0,

        async start(files) {
            if (!files || !files.length) return;

            this.busy = true;
            this.notes = [];
            this.done = 0;
            this.total = 0;
            let stored = 0;
            let skipped = 0;

            try {
                for (const file of files) {
                    this.status = `Scanning ${file.name}…`;
                    const rounds = await this.extractRounds(file);

                    if (!rounds.length) {
                        this.notes.push(`${file.name}: no arena rounds found in it.`);
                        continue;
                    }

                    this.total += rounds.length;
                    this.status = `Found ${rounds.length} round(s) in ${file.name}. Uploading…`;

                    for (const round of rounds) {
                        const result = await this.send(round);
                        this.done++;

                        if (result?.status === 'stored') stored++;
                        else if (result?.status === 'skipped') skipped++;
                        else if (result?.reason) this.notes.push(result.reason);
                    }
                }

                this.status = 'Assembling…';
                const assembled = await this.finish();

                const games = assembled?.assembled ?? [];
                this.status = games.length
                    ? `Done — ${games.length} game(s) from ${stored} round(s).`
                    : `Done — ${stored} round(s) uploaded, no complete game to assemble yet.`;

                for (const g of games) {
                    this.notes.push(`${g.record} over ${g.rounds} round(s) on ${g.character ?? 'an unknown character'}`
                        + (g.linked ? '' : ' — not matched to a linked character'));
                }

                if (skipped) this.notes.push(`${skipped} round(s) skipped (not Solo Shuffle, or no combatant info).`);

                // `this.$wire` — Livewire's magic is on the Alpine component, and a bare
                // `$wire` does not resolve inside a method of a factory defined out here.
                this.$wire.refreshAfterUpload();
            } catch (e) {
                this.status = `Upload failed: ${e.message}`;
            } finally {
                this.busy = false;
            }
        },

        /**
         * Reads the file in slices and returns each arena round's lines as one string.
         *
         * A Solo Shuffle lobby writes one ARENA_MATCH_START per round and a single
         * ARENA_MATCH_END, so a new START closes the round in progress — the same rule the server
         * uses. Nothing outside a round is kept, which is what makes a 60MB file cheap to scan.
         */
        async extractRounds(file) {
            const CHUNK = 8 * 1024 * 1024;
            const decoder = new TextDecoder('utf-8');
            const rounds = [];

            let carry = '';
            let current = null;

            for (let offset = 0; offset < file.size; offset += CHUNK) {
                const buf = await file.slice(offset, offset + CHUNK).arrayBuffer();
                const text = carry + decoder.decode(buf, { stream: true });
                const lines = text.split('\n');

                // The last piece may be a partial line; carry it into the next slice.
                carry = lines.pop() ?? '';

                for (const line of lines) {
                    if (line.includes('ARENA_MATCH_START,')) {
                        if (current) rounds.push(current.join('\n'));
                        current = [line];
                        continue;
                    }

                    if (!current) continue;

                    current.push(line);

                    if (line.includes('ARENA_MATCH_END,')) {
                        rounds.push(current.join('\n'));
                        current = null;
                    }
                }
            }

            if (carry && current) current.push(carry);
            if (current) rounds.push(current.join('\n'));

            return rounds;
        },

        async send(roundText) {
            const body = new FormData();
            body.append('round', await this.gzip(roundText), 'round.log.gz');

            const res = await fetch(@js(route('game-review.upload-round')), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'Accept': 'application/json',
                },
                body,
            });

            if (res.status === 429) {
                const slow = await res.json();
                this.notes.push(slow.reason ?? 'Rate limited.');
                await new Promise((r) => setTimeout(r, 5000));
                return this.send(roundText);
            }

            // 413 is the web server's body limit, not anything about the round. A long round
            // gzips to roughly a megabyte, which is exactly where a default nginx sits, so say
            // what it is rather than leaving a bare status code.
            if (res.status === 413) {
                return {
                    status: 'failed',
                    reason: 'One round was too large for the server to accept (its upload limit needs raising).',
                };
            }

            return res.ok ? res.json() : { status: 'failed', reason: `Server said ${res.status}.` };
        },

        /** A round is mostly repeated names and spell ids, so it compresses about eight to one. */
        async gzip(text) {
            const bytes = new TextEncoder().encode(text);

            if (typeof CompressionStream === 'undefined') {
                return new Blob([bytes]);
            }

            const stream = new Blob([bytes]).stream().pipeThrough(new CompressionStream('gzip'));

            return new Response(stream).blob();
        },

        async finish() {
            const res = await fetch(@js(route('game-review.assemble')), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'Accept': 'application/json',
                },
            });

            return res.ok ? res.json() : null;
        },
    });
</script>
