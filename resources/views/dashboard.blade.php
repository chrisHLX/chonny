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
                        <p class="text-[12px] text-ink-muted mt-0.5">Browse the library or generate a module from any topic.</p>
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
                        <h2 class="text-[13px] font-semibold text-ink">Concept Mastery</h2>
                        <span class="text-[11px] text-ink-subtle">{{ $concepts->count() }} concepts</span>
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
                        <h2 class="text-[13px] font-semibold text-ink">Active Modules</h2>
                        <a href="{{ route_with_context('collection.index') }}"
                           class="text-[11px] text-accent hover:text-accent-hover transition-colors">View all</a>
                    </div>

                    @if($modules->isEmpty())
                        <p class="text-[13px] text-ink-subtle mb-4">No active modules yet.</p>
                        <a href="{{ route_with_context('modules.index') }}"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors">
                            Discover Modules
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

            <!-- Credits / Billing -->
            <div class="linear-card p-5">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-[13px] font-semibold text-ink">Credits</h2>
                        <p class="text-[12px] text-ink-subtle mt-0.5">Purchase AI credits to generate questions and content.</p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button id="buy-credits"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition-colors">
                            Add Credits
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
