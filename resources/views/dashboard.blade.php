{{-- resources/views/dashboard.blade.php --}}
<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10 xl:px-16">
        <div class="max-w-7xl mx-auto space-y-6">

            <!-- Page header -->
            <div>
                <h1 class="text-[17px] font-semibold text-ink">Dashboard</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">Welcome back, {{ $user->name }}</p>
            </div>

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
                        <h2 class="text-[13px] font-semibold text-ink">Your Modules</h2>
                        <a href="{{ route_with_context('modules.index') }}"
                           class="text-[11px] text-accent hover:text-accent-hover transition-colors">View all</a>
                    </div>

                    @if($modules->isEmpty())
                        <p class="text-[13px] text-ink-subtle mb-4">No active modules yet.</p>
                        <a href="{{ route_with_context('modules.index') }}"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors">
                            Browse Modules
                        </a>
                    @else
                        <div>
                            @foreach($modules as $module)
                                <div class="flex items-center justify-between py-2.5 {{ !$loop->last ? 'border-b border-line' : '' }}">
                                    <span class="text-[13px] text-ink truncate">{{ $module->name }}</span>
                                    <div class="flex items-center gap-2 shrink-0 ml-2">
                                        <span class="text-[11px] text-ink-subtle tabular-nums">{{ $module->pivot->score }}%</span>
                                        <span class="text-[10px] px-1.5 py-0.5 rounded font-medium
                                            {{ $module->pivot->status === 'completed'
                                               ? 'bg-emerald-500/10 text-emerald-400'
                                               : 'bg-amber-500/10 text-amber-400' }}">
                                            {{ ucfirst($module->pivot->status) }}
                                        </span>
                                    </div>
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
                        <form action="{{ route('credit.test2') }}" method="POST">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium text-ink-muted bg-surface-2 hover:bg-surface-3 border border-line rounded-md transition-colors">
                                Test AI Tags
                            </button>
                        </form>
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
