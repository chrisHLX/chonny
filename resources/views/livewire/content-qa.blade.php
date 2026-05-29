<div @if($this->prompts->whereIn('status', ['pending', 'processing'])->isNotEmpty()) wire:poll.3s @endif
     class="mt-4 pt-4 border-t border-line">

    @auth
        {{-- Model selector --}}
        <div class="flex items-center gap-1.5 mb-3">
            <button wire:click="$set('selectedModel', 'gpt')"
                    class="px-3 py-1.5 text-[12px] font-medium rounded-md transition-colors
                           {{ $selectedModel === 'gpt'
                               ? 'bg-surface-3 text-ink border border-line'
                               : 'text-ink-subtle hover:text-ink-muted' }}">
                GPT
            </button>
            <button wire:click="$set('selectedModel', 'gemini')"
                    class="flex items-center gap-1.5 px-3 py-1.5 text-[12px] font-medium rounded-md transition-colors
                           {{ $selectedModel === 'gemini'
                               ? 'bg-surface-3 text-ink border border-line'
                               : 'text-ink-subtle hover:text-ink-muted' }}">
                <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                </svg>
                Gemini Flash
            </button>
        </div>
        <p class="text-[11px] text-ink-subtle mb-3 -mt-1">
            @if($selectedModel === 'gemini')
                Searches the web to verify accuracy and flag outdated information.
            @else
                Answers strictly from the content above — no web access.
            @endif
        </p>

        {{-- Question form --}}
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
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-[13px] font-medium text-ink">{{ $prompt->question }}</p>
                            @if($prompt->model === 'gemini')
                                <span class="shrink-0 flex items-center gap-1 text-[10px] text-ink-subtle bg-surface-3 rounded px-1.5 py-0.5">
                                    <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                                    </svg>
                                    Web
                                </span>
                            @endif
                        </div>

                        @if ($prompt->status === 'completed')
                            <p class="text-[13px] text-ink-muted leading-relaxed">{{ $prompt->answer }}</p>

                            @if($prompt->model === 'gemini' && !empty($prompt->sources))
                                <div class="pt-2 border-t border-line space-y-1">
                                    <p class="text-[10px] text-ink-subtle uppercase tracking-wide">Web sources</p>
                                    @foreach($prompt->sources as $source)
                                        <a href="{{ $source['uri'] }}" target="_blank" rel="noopener noreferrer"
                                           class="block text-[11px] text-accent hover:text-accent-hover truncate transition-colors">
                                            {{ $source['title'] ?? $source['uri'] }}
                                        </a>
                                    @endforeach
                                </div>
                            @endif

                        @elseif ($prompt->status === 'failed')
                            <p class="text-[12px] text-red-400">Could not generate an answer — please try again.</p>
                        @else
                            <div class="flex items-center gap-2 text-[12px] text-ink-subtle">
                                <svg class="animate-spin w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                                @if($prompt->model === 'gemini')
                                    Searching the web…
                                @else
                                    Generating answer…
                                @endif
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
