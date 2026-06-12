<x-app-layout>
    <div class="min-h-full py-10 px-6">
        <div class="max-w-2xl mx-auto space-y-4">

            <div>
                <h1 class="font-display text-[20px] font-bold text-ink">Creating Your Module</h1>
                <p class="text-[13px] text-ink-muted mt-0.5">AI is generating your learning content…</p>
            </div>

            {{-- Status card --}}
            <div class="linear-card p-5 flex items-center gap-3">
                <div class="w-2 h-2 rounded-full shrink-0
                    @if($pipeline->status === 'completed') bg-emerald-400
                    @elseif($pipeline->status === 'failed') bg-red-400
                    @else bg-gold animate-pulse
                    @endif">
                </div>
                <span class="text-[13px] font-medium
                    @if($pipeline->status === 'completed') text-emerald-400
                    @elseif($pipeline->status === 'failed') text-red-400
                    @else text-gold
                    @endif">
                    {{ ucfirst($pipeline->status) }}
                </span>
            </div>

            {{-- Pipeline steps --}}
            <div class="space-y-2">
                @foreach ($pipeline->steps as $step)
                    <div class="linear-card p-4 flex items-center justify-between">
                        <div class="min-w-0">
                            <p class="text-[13px] font-medium text-ink">{{ $step->name }}</p>
                            <p class="text-[11px] text-ink-subtle mt-0.5">{{ ucfirst($step->status) }}</p>
                            @if($step->error_message)
                                <p class="text-[11px] text-red-400 mt-1">{{ $step->error_message }}</p>
                            @endif
                        </div>
                        <div class="shrink-0 ml-3">
                            @if($step->status === 'completed')
                                <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                            @elseif($step->status === 'running')
                                <svg class="w-4 h-4 text-gold animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                                </svg>
                            @elseif($step->status === 'failed')
                                <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            @else
                                <div class="w-4 h-4 rounded-full border border-line-strong"></div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Success CTA --}}
            @if($pipeline->status === 'completed')
                <div class="text-center pt-2">
                    <a href="{{ route('modules.index') }}" class="btn-primary">
                        Continue to Modules
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                </div>
            @endif

            {{-- Failure state --}}
            @if($pipeline->status === 'failed')
                <div class="linear-card border-red-500/30 bg-red-900/10 p-5 text-center">
                    <p class="text-[13px] text-red-400 font-medium">Something went wrong. Please retry or contact support.</p>
                </div>
            @endif

        </div>
    </div>

    @if(in_array($pipeline->status, ['pending', 'running']))
        <script>
            setTimeout(() => { window.location.reload(); }, 2000);
        </script>
    @endif
</x-app-layout>
