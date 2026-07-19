<div>
    @if ($dimensions->isNotEmpty())
        <div class="linear-card p-6 relative overflow-hidden border border-gold/20">
            <x-ornament.corner position="tl" class="top-0 left-0 w-8 h-8 text-gold/30"/>
            <x-ornament.corner position="tr" class="top-0 right-0 w-8 h-8 text-gold/30"/>
            <x-ornament.corner position="bl" class="bottom-0 left-0 w-8 h-8 text-gold/30"/>
            <x-ornament.corner position="br" class="bottom-0 right-0 w-8 h-8 text-gold/30"/>

            <h3 class="font-display text-[18px] italic text-gold-light leading-snug mb-1">Tell us how you play</h3>
            <p class="text-[13px] text-ink-muted leading-relaxed mb-5">
                This personalises every future recommendation — it's never guessed, only what you tell us.
            </p>

            <div class="space-y-4">
                @foreach ($dimensions as $dimension)
                    @php $options = $this->optionsFor($dimension); @endphp
                    <div>
                        <label class="text-[11px] font-semibold text-ink-subtle uppercase tracking-wide mb-1.5 block">
                            {{ $dimension->name }}
                        </label>
                        <select wire:model.live="selections.{{ $dimension->id }}"
                                class="form-select w-full"
                                @if ($dimension->parent_dimension_id && $options->isEmpty()) disabled @endif>
                            <option value="">Select {{ $dimension->name }}</option>
                            @foreach ($options as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                        @if ($dimension->parent_dimension_id && $options->isEmpty())
                            <p class="text-[11px] text-ink-subtle mt-1">Choose a {{ $dimension->parentDimension?->name }} first.</p>
                        @endif
                        @error("selections.{$dimension->id}")
                            <p class="text-[11px] text-red-400 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>

            <button wire:click="save"
                    wire:loading.attr="disabled"
                    wire:target="save"
                    class="btn-primary w-full mt-5 text-[13px] inline-flex items-center justify-center gap-2 disabled:opacity-60">
                <span wire:loading.remove wire:target="save">Save</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>

            @if ($saved)
                <p class="text-[12px] text-gold text-center mt-3">Saved — this will personalise your next recommendations.</p>
            @endif
        </div>
    @endif
</div>
