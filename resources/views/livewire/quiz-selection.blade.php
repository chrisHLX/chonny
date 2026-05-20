<div class="text-center space-y-6" x-transition>

    <h2 class="text-3xl font-bold text-gray-800">🧠 Ready to Test Your Knowledge?</h2>
    <p class="text-gray-600">Choose a module from your favorite subject below.</p>

    @if($subjects->count() > 1)
        <div class="flex flex-wrap justify-center gap-3">
            @foreach($subjects as $subject)
                <button wire:click="$set('selectedSubject', {{ $subject->id }})"
                        class="px-4 py-2 rounded-full font-medium transition
                            {{ $selectedSubject == $subject->id
                                ? 'bg-indigo-600 text-white shadow-md'
                                : 'bg-gray-200 text-gray-700 hover:bg-gray-300' }}">
                    {{ $subject->name }}
                </button>
            @endforeach
        </div>
    @endif

    <div class="space-y-8 mt-8">
        @foreach($subjects as $subject)
            @if(!$selectedSubject || $selectedSubject == $subject->id)
                <div class="border border-gray-200 rounded-2xl shadow-sm p-6 bg-white">
                    <h3 class="text-2xl font-semibold text-indigo-700 mb-4">
                        🎮 {{ $subject->name }}
                    </h3>
                    <h2 class="text-lg text-gray-600 mb-6">Select a module to start the quiz:</h2>

                    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                        @foreach($this->modules->where('subject_id', $subject->id) as $module)
                            <div wire:click="$set('selectedModule', {{ $module->id }})"
                                 class="cursor-pointer border rounded-xl p-5 text-left transition-all
                                     {{ $selectedModule == $module->id
                                         ? 'bg-green-50 border-green-400 shadow-md'
                                         : 'hover:bg-gray-50 border-gray-200 hover:shadow' }}">
                                <h4 class="font-semibold text-gray-800">{{ $module->name }}</h4>
                                <p class="text-sm text-gray-600 mt-1">
                                    {{ $module->proficiencies->first()->name ?? '—' }}
                                </p>
                                @if(optional($module->pivot)->status === 'completed')
                                    <p class="text-sm text-green-600 mt-2 font-medium">✅ Completed</p>
                                @elseif(optional($module->pivot)->status === 'in_progress')
                                    <p class="text-sm text-yellow-600 mt-2 font-medium">⏳ In Progress</p>
                                @else
                                    <p class="text-sm text-gray-500 mt-2 font-medium">Not Started</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    <div class="mt-6">
        @if($selectedModule && $this->modules->where('id', $selectedModule)->first()?->pivot->status === 'completed')
            <p class="text-green-600 font-medium">
                You've already completed this module. You can retake the quiz to improve your score or reinforce your knowledge!
            </p>
        @else
                <button
                    wire:click="startQuiz"
                    class="w-full py-3 bg-green-600 text-white rounded-lg font-semibold hover:bg-green-700 shadow transition-transform hover:scale-105 disabled:opacity-50"
                    @disabled(!$selectedModule)>
                    ▶️ Start Quiz
                </button>
        @endif
            </div>
        
</div>