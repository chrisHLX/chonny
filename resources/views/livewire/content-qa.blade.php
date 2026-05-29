<div @if($this->prompts->whereIn('status', ['pending', 'processing'])->isNotEmpty()) wire:poll.3s @endif
     class="mt-4 pt-4 border-t border-line">

    @auth
        {{-- Question form --}}
        <h3 class="text-[11px] font-medium text-ink-subtle uppercase tracking-wide mb-3">Ask a question</h3>
        <form wire:submit.prevent="submit" class="flex gap-2">
            <input
                wire:model.defer="newQuestion"
                type="text"
                placeholder="Ask something about this content…"
                class="flex-1 bg-surface-2 border border-line rounded-lg px-3 py-2 text-[13px] text-ink placeholder-ink-subtle focus:outline-none focus:border-ink-subtle transition-colors"
            />
            <button type="submit"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                    class="px-4 py-2 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-lg transition-colors disabled:opacity-50 shrink-0">
                <span wire:loading.remove wire:target="submit">Ask</span>
                <span wire:loading wire:target="submit">…</span>
            </button>
        </form>
        @error('newQuestion')
            <p class="text-[12px] text-red-400 mt-1.5">{{ $message }}</p>
        @enderror

        {{-- Q&A list --}}
        @if ($this->prompts->isNotEmpty())
            <div class="mt-4 space-y-3">
                @foreach ($this->prompts as $prompt)
                    <div class="bg-surface-2 rounded-lg p-4 space-y-2">
                        <p class="text-[13px] font-medium text-ink">{{ $prompt->question }}</p>

                        @if ($prompt->status === 'completed')
                            <p class="text-[13px] text-ink-muted leading-relaxed">{{ $prompt->answer }}</p>
                        @elseif ($prompt->status === 'failed')
                            <p class="text-[12px] text-red-400">Could not generate an answer — please try again.</p>
                        @else
                            <div class="flex items-center gap-2 text-[12px] text-ink-subtle">
                                <svg class="animate-spin w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                                Generating answer…
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @else
        <p class="text-[12px] text-ink-subtle">
            <a href="{{ route('login') }}" class="text-accent hover:text-accent-hover transition-colors">Sign in</a> to ask questions about this content.
        </p>
    @endauth
</div>
