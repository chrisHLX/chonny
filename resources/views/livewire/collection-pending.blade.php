<div class="min-h-full py-8 px-6 lg:px-10">
    <div class="max-w-7xl mx-auto space-y-8">

        <div>
            <h1 class="text-[17px] font-semibold text-ink">Collection</h1>
            <p class="text-[13px] text-ink-muted mt-0.5">Your learning cards and question history.</p>
        </div>

        <div class="linear-card p-6" wire:poll.3000ms="checkStatus">
            <div class="mb-5">
                <h3 class="page-section-title">Generating your card</h3>
                <p class="text-[13px] text-ink-muted mt-1">We're processing your quiz results. This usually takes a moment.</p>
            </div>

            @if($pipeline)
                <div>
                    @foreach($pipeline->steps as $step)
                        <div class="flex items-center justify-between py-3 {{ !$loop->last ? 'border-b border-line' : '' }}">
                            <div>
                                <p class="text-[13px] font-medium text-ink">
                                    @switch($step->name)
                                        @case('Generate Card') Creating your card @break
                                        @case('Generate Suggestions') Finding your next modules @break
                                        @default {{ $step->name }} @break
                                    @endswitch
                                </p>
                                <p class="text-[11px] text-ink-subtle mt-0.5">
                                    @switch($step->status)
                                        @case('pending') Waiting... @break
                                        @case('running') In progress... @break
                                        @case('completed') Done @break
                                        @case('failed') Something went wrong @break
                                        @default {{ ucfirst($step->status) }} @break
                                    @endswitch
                                </p>
                            </div>
                            <span class="
                                @switch($step->status)
                                    @case('completed') badge-green @break
                                    @case('running') badge-blue @break
                                    @case('failed') badge-amber @break
                                    @default badge-gray @break
                                @endswitch
                            ">{{ ucfirst($step->status) }}</span>
                        </div>
                    @endforeach
                </div>

                @if($pipeline->status === 'failed')
                    <div class="mt-4 pt-4 border-t border-line">
                        <p class="text-[12px] text-amber-400">Something went wrong generating your card. Try completing the module again.</p>
                    </div>
                @endif
            @endif
        </div>

    </div>
</div>
