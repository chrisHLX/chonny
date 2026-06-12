<x-app-layout>
    <div class="min-h-full py-8 px-6 lg:px-10 xl:px-16">
        <div class="max-w-5xl mx-auto space-y-6">

            <div>
                <h1 class="font-display text-[20px] font-bold text-ink">Learning Collection</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">Your cards, modules, and question history</p>
            </div>

            {{-- Cards --}}
            @if(isset($cards) && $cards->isNotEmpty())
                <div>
                    <div class="flex items-center gap-4 mb-5">
                        <div class="flex-1 h-px bg-gold/20"></div>
                        <span class="text-[11px] font-semibold text-gold uppercase tracking-[0.2em]">Your Cards</span>
                        <div class="flex-1 h-px bg-gold/20"></div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach($cards as $card)
                            <x-card :card="$card" />
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Module Progress --}}
            <div class="linear-card p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-[14px] font-semibold text-ink">Module Progress</h2>
                </div>

                @forelse ($modules as $module)
                    @php $score = $module->pivot->score ?? 0; @endphp
                    <div class="py-4 {{ !$loop->last ? 'border-b border-line' : '' }}">
                        <div class="flex items-start justify-between gap-3 mb-2">
                            <div class="min-w-0">
                                <p class="text-[13px] font-semibold text-ink truncate">{{ $module->name }}</p>
                                @if($module->description)
                                    <p class="text-[11px] text-ink-muted mt-0.5 line-clamp-1">{{ $module->description }}</p>
                                @endif
                            </div>
                            <span class="shrink-0 {{ $module->pivot->score >= 80 ? 'badge-green' : 'badge-amber' }}">
                                {{ ucfirst($module->pivot->status) }}
                            </span>
                        </div>
                        <div class="w-full bg-surface-3 rounded-full h-1.5 overflow-hidden mb-1">
                            <div class="h-1.5 rounded-full transition-all duration-700"
                                 style="width: {{ $score }}%;
                                        background: linear-gradient(90deg, #C8952C, #E8B84B);
                                        box-shadow: {{ $score > 5 ? '0 0 6px rgba(200,149,44,0.4)' : 'none' }};">
                            </div>
                        </div>
                        <p class="text-[11px] text-ink-subtle">Score: <span class="text-gold font-semibold">{{ $score }}%</span></p>
                    </div>
                @empty
                    <p class="text-[13px] text-ink-subtle py-4">You haven't started any modules yet.</p>
                @endforelse
            </div>

            {{-- Question History --}}
            @if(isset($answeredQuestions) && $answeredQuestions->isNotEmpty())
                <div class="linear-card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-[14px] font-semibold text-ink">Question History</h2>
                    </div>

                    @foreach ($answeredQuestions as $question)
                        @php
                            $attempts  = $question->pivot->attempts ?? 0;
                            $correct   = $question->pivot->correct_count ?? 0;
                            $accuracy  = $attempts > 0 ? round(($correct / $attempts) * 100, 1) : 0;
                        @endphp
                        <div class="py-3.5 {{ !$loop->last ? 'border-b border-line' : '' }}">
                            <p class="text-[13px] font-medium text-ink mb-2">{{ $question->question }}</p>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="badge-gray">{{ $attempts }} attempts</span>
                                <span class="badge-green">{{ $correct }} correct</span>
                                <span class="badge-gold">{{ $accuracy }}% accuracy</span>
                                @if($question->pivot->total_time_spent ?? 0)
                                    <span class="badge-gray">{{ $question->pivot->total_time_spent }}s</span>
                                @endif
                            </div>
                            @if(isset($question->concepts) && $question->concepts->isNotEmpty())
                                <p class="text-[11px] text-ink-subtle mt-1.5">
                                    Concepts: {{ $question->concepts->pluck('name')->join(', ') }}
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- AI Feedback on Wrong Answers --}}
            @if(isset($wrongQuestions) && $wrongQuestions->isNotEmpty())
                <div class="linear-card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-[14px] font-semibold text-ink">AI Feedback on Incorrect Answers</h2>
                    </div>

                    @foreach ($wrongQuestions as $question)
                        <div class="py-3.5 {{ !$loop->last ? 'border-b border-line' : '' }}">
                            <p class="text-[13px] font-semibold text-ink mb-1.5">{{ $question->question }}</p>
                            @if($question->contents->first()?->content)
                                <p class="text-[12px] text-ink-muted leading-relaxed mb-1.5">
                                    {{ $question->contents->first()->content }}
                                </p>
                            @endif
                            @if($question->contents->first()?->source)
                                <p class="text-[11px] text-ink-subtle">Source: {{ $question->contents->first()->source }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
