<div class="min-h-full py-16 px-6">
    <div class="max-w-lg mx-auto space-y-8">

        @if(! $ready)

            {{-- Header --}}
            <div class="text-center space-y-3">
                <p class="text-[11px] text-ink-subtle tracking-widest uppercase">
                    {{ $module->subject->name }}
                </p>
                <h1 class="text-[24px] font-semibold text-ink leading-snug">
                    Building your module
                </h1>
                <p class="text-[14px] text-ink-muted max-w-sm mx-auto leading-relaxed">
                    We're researching your topic, writing the content, and crafting questions tailored to your level. This usually takes 30–60 seconds.
                </p>
            </div>

            {{-- Progress component --}}
            <livewire:generation-progress :pipelineId="$pipelineId" />

            {{-- Teaser copy --}}
            <div class="linear-card px-5 py-4 space-y-3">
                <p class="text-[11px] text-ink-subtle uppercase tracking-widest">What's happening</p>
                <ul class="space-y-2 text-[13px] text-ink-muted">
                    <li class="flex items-start gap-2">
                        <svg class="w-3.5 h-3.5 text-accent shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        Researching your topic with up-to-date sources
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="w-3.5 h-3.5 text-accent shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        Writing structured content for your chosen proficiency level
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="w-3.5 h-3.5 text-accent shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        Generating quiz questions across multiple formats
                    </li>
                </ul>
            </div>

        @else

            {{-- Ready state --}}
            <div class="text-center space-y-3">
                <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-green-500/10 border border-green-500/20 mb-1">
                    <svg class="w-6 h-6 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <p class="text-[11px] text-ink-subtle tracking-widest uppercase">
                    {{ $module->subject->name }}
                </p>
                <h1 class="text-[24px] font-semibold text-ink leading-snug">
                    Your module is ready
                </h1>
                <p class="text-[14px] text-ink-muted">
                    Questions have been generated and are waiting for you.
                </p>
            </div>

            {{-- Preview card --}}
            <div class="linear-card p-6 space-y-4">
                <div>
                    <h2 class="text-[17px] font-semibold text-ink leading-snug">{{ $module->name }}</h2>
                    @if($module->description)
                        <p class="text-[13px] text-ink-muted mt-1.5 leading-relaxed">{{ $module->description }}</p>
                    @endif
                </div>

                @if($module->proficiencies->first())
                    <span class="badge-gray">{{ $module->proficiencies->first()->name }}</span>
                @endif

                <div class="pt-1">
                    <a href="{{ route('modules.show', $module) }}"
                       class="inline-flex items-center justify-center w-full px-4 py-2.5 text-[13px] font-medium text-white bg-accent hover:bg-accent-hover rounded-lg transition-colors">
                        Start Learning
                    </a>
                </div>
            </div>

        @endif

    </div>
</div>
