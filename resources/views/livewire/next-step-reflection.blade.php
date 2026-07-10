<div @if ($submitted) wire:poll.3s="checkCompletion" @endif>
    @if ($submitted)
        <p class="text-[12px] text-ink-muted italic">Reflection submitted — Mindcollector is thinking it over. Your next task will appear here soon.</p>
    @else
        <form wire:submit.prevent="submit" class="space-y-3">
            <div>
                <label class="block text-[10px] font-semibold text-ink-muted uppercase tracking-widest mb-1">Did you try it?</label>
                <select wire:model="didTry" class="form-select w-full">
                    <option value="">Select an answer</option>
                    <option value="yes">Yes</option>
                    <option value="partially">Partially</option>
                    <option value="no">No</option>
                </select>
                @error('didTry') <p class="text-[11px] text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-[10px] font-semibold text-ink-muted uppercase tracking-widest mb-1">How did it go?</label>
                <textarea wire:model="howItWent" rows="2" class="form-textarea w-full" placeholder="What happened when you tried it?"></textarea>
                @error('howItWent') <p class="text-[11px] text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-[10px] font-semibold text-ink-muted uppercase tracking-widest mb-1">Why do you think that happened?</label>
                <textarea wire:model="whyReasoning" rows="2" class="form-textarea w-full" placeholder="Your reasoning about the outcome"></textarea>
                @error('whyReasoning') <p class="text-[11px] text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn-primary w-full text-[12px]" wire:loading.attr="disabled" wire:target="submit">
                <span wire:loading.remove wire:target="submit">Submit Reflection</span>
                <span wire:loading wire:target="submit">Submitting…</span>
            </button>
        </form>
    @endif
</div>
