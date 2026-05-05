<div x-data="{ selected: 'gpt-4o-mini' }">
    <x-input-label value="AI Model" />
    <p class="text-[11px] text-ink-muted mb-3 mt-0.5">
        Higher-quality models produce better questions but cost more credits.
    </p>

    <input type="hidden" name="model" :value="selected">

    <div class="flex flex-wrap gap-2">
        @foreach(config('ai_models') as $modelKey => $model)
            <button
                type="button"
                @click="selected = '{{ $modelKey }}'"
                :class="selected === '{{ $modelKey }}'
                    ? 'border-accent text-accent bg-accent/5'
                    : 'border-border text-ink-muted hover:border-accent/40'"
                class="flex flex-col items-start gap-0.5 px-3 py-2 rounded-md border text-left transition-colors"
            >
                <span class="text-[12px] font-medium leading-none">{{ $model['label'] }}</span>
                <span class="text-[11px] leading-snug opacity-70">{{ $model['description'] }}</span>
            </button>
        @endforeach
    </div>
</div>
