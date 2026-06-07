{{-- resources/views/dashboard.blade.php --}}
<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10 xl:px-16">
        <div class="max-w-7xl mx-auto space-y-6">

            <!-- Page header -->
            <div>
                <h1 class="text-[17px] font-semibold text-ink">Home</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">Welcome back, {{ $user->name }}</p>
            </div>

            <!-- Hero: Continue Learning -->
            @if($heroModule)
                @php
                    $heroStatus = $heroModule->pivot->status ?? 'not_started';
                    $heroScore  = $heroModule->pivot->score ?? 0;
                    $heroLabel  = $heroStatus === 'not_started' ? 'Start' : ($heroStatus === 'completed' ? 'Retake' : 'Resume');
                @endphp
                <div class="linear-card p-5 flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-1">
                            {{ $heroStatus === 'completed' ? 'Recently Completed' : 'Continue Learning' }}
                        </p>
                        <h2 class="text-[15px] font-semibold text-ink truncate">{{ $heroModule->name }}</h2>
                        <p class="text-[12px] text-ink-muted mt-0.5">
                            {{ $heroScore }}% &middot; {{ ucfirst(str_replace('_', ' ', $heroStatus)) }}
                        </p>
                    </div>
                    <a href="{{ route('questions.quiz.index', ['moduleId' => $heroModule->id]) }}"
                       class="shrink-0 inline-flex items-center gap-1.5 px-4 py-2 text-[13px] font-semibold text-white bg-accent hover:bg-accent-hover rounded-lg transition-colors">
                        {{ $heroLabel }}
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                </div>
            @else
                <div class="linear-card p-5 flex items-center justify-between gap-4">
                    <div>
                        <p class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-1">Get Started</p>
                        <h2 class="text-[15px] font-semibold text-ink">Find something to learn</h2>
                        <p class="text-[12px] text-ink-muted mt-0.5">Browse the library or request a guide on any topic.</p>
                    </div>
                    <a href="{{ route_with_context('modules.index') }}"
                       class="shrink-0 inline-flex items-center gap-1.5 px-4 py-2 text-[13px] font-semibold text-white bg-accent hover:bg-accent-hover rounded-lg transition-colors">
                        Discover
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                </div>
            @endif

            <!-- Subject context bar -->
            <x-context-bar :categoryId="$categoryId" :currentSubjectId="$currentSubjectId" />

            <!-- Main grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

                <!-- Concept Mastery -->
                <div class="linear-card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-[13px] font-semibold text-ink">Topic Progress</h2>
                        <span class="text-[11px] text-ink-subtle">{{ $concepts->count() }} topics</span>
                    </div>

                    @forelse($concepts as $concept)
                        @php
                            $userMastery = $concept->userConceptMasteries->first();
                            $mastery = $userMastery?->mastery_percentage ?? 0;
                            $totalQuestions = $userMastery?->total_questions ?? $concept->questions->count();
                        @endphp
                        <div class="mb-4 last:mb-0">
                            <div class="flex justify-between mb-1.5">
                                <span class="text-[12px] font-medium text-ink-muted">{{ $concept->name }}</span>
                                <span class="text-[11px] text-ink-subtle">{{ $mastery }}%</span>
                            </div>
                            <div class="w-full bg-surface-3 rounded-full h-1 overflow-hidden">
                                <div class="bg-accent h-1 rounded-full transition-all duration-700"
                                     style="width: {{ $mastery }}%"></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-[13px] text-ink-subtle">No concepts yet.</p>
                    @endforelse
                </div>

                <!-- Leaderboard -->
                <div class="linear-card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-[13px] font-semibold text-ink">Leaderboard</h2>
                        <span class="text-[11px] text-ink-subtle">Top users</span>
                    </div>

                    <div class="space-y-0">
                        @foreach ($leaderboard as $index => $leaderUser)
                            <div class="flex items-center justify-between py-2.5 {{ !$loop->last ? 'border-b border-line' : '' }}">
                                <div class="flex items-center gap-2.5">
                                    <span class="text-[11px] text-ink-subtle w-4 shrink-0 tabular-nums">{{ $index + 1 }}</span>
                                    <span class="text-[13px] text-ink">{{ $leaderUser->name }}</span>
                                </div>
                                <span class="text-[12px] font-medium text-accent tabular-nums">{{ round($leaderUser->total_mastery) }}%</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Modules -->
                <div class="linear-card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-[13px] font-semibold text-ink">My Guides</h2>
                        <a href="{{ route_with_context('collection.index') }}"
                           class="text-[11px] text-accent hover:text-accent-hover transition-colors">View all</a>
                    </div>

                    @if($modules->isEmpty())
                        <p class="text-[13px] text-ink-subtle mb-4">No guides yet.</p>
                        <a href="{{ route_with_context('modules.index') }}"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors">
                            Find a Guide
                        </a>
                    @else
                        <div>
                            @foreach($modules as $module)
                                @php $mStatus = $module->pivot->status ?? 'not_started'; @endphp
                                <div class="flex items-center justify-between py-2.5 {{ !$loop->last ? 'border-b border-line' : '' }}">
                                    <div class="min-w-0 flex-1 mr-3">
                                        <span class="text-[13px] text-ink truncate block">{{ $module->name }}</span>
                                        <span class="text-[11px] text-ink-subtle tabular-nums">{{ $module->pivot->score }}%</span>
                                    </div>
                                    <a href="{{ route('questions.quiz.index', ['moduleId' => $module->id]) }}"
                                       class="shrink-0 text-[11px] font-medium px-2.5 py-1 rounded
                                           {{ $mStatus === 'completed'
                                              ? 'text-ink-subtle bg-surface-2 hover:bg-surface-3 border border-line'
                                              : 'text-white bg-accent hover:bg-accent-hover' }}
                                           transition-colors">
                                        {{ $mStatus === 'not_started' ? 'Start' : ($mStatus === 'completed' ? 'Retake' : 'Resume') }}
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

            </div>

            <!-- Support Development -->
            <div class="linear-card p-5">
                <div class="flex items-center gap-2 mb-3">
                    <h2 class="text-[13px] font-semibold text-ink">Supporting Development</h2>
                    <span class="text-[10px] font-semibold uppercase tracking-wide text-accent bg-accent/10 px-1.5 py-0.5 rounded">Alpha</span>
                </div>
                <p class="text-[12px] text-ink-subtle mb-4">
                    MindCollector is currently in alpha. AI and hosting costs are being covered while the platform is tested and improved.
                </p>
                <div class="flex flex-col sm:flex-row gap-4">
                    <div>
                        <p class="text-[11px] text-ink-subtle mb-1.5">Help shape the platform:</p>
                        <a href="https://discord.gg/Bk7wEvPRt"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M20.317 4.37a19.791 19.791 0 00-4.885-1.515.074.074 0 00-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 00-5.487 0 12.64 12.64 0 00-.617-1.25.077.077 0 00-.079-.037A19.736 19.736 0 003.677 4.37a.07.07 0 00-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 00.031.057 19.9 19.9 0 005.993 3.03.078.078 0 00.084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 00-.041-.106 13.107 13.107 0 01-1.872-.892.077.077 0 01-.008-.128 10.2 10.2 0 00.372-.292.074.074 0 01.077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 01.078.01c.12.098.246.198.373.292a.077.077 0 01-.006.127 12.299 12.299 0 01-1.873.892.077.077 0 00-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 00.084.028 19.839 19.839 0 006.002-3.03.077.077 0 00.032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 00-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/>
                            </svg>
                            Join Discord
                        </a>
                    </div>
                    <div>
                        <p class="text-[11px] text-ink-subtle mb-1.5">Help cover AI and hosting costs:</p>
                        <button id="buy-credits"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors">
                            Support Development
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://js.stripe.com/v3/"></script>
    <script>
        const stripe = Stripe("{{ config('services.stripe.key') }}");
        document.getElementById('buy-credits').addEventListener('click', async () => {
            const res = await fetch("{{ route('checkout.session') }}", {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
            });
            const data = await res.json();
            await stripe.redirectToCheckout({ sessionId: data.id });
        });
    </script>
</x-app-layout>
