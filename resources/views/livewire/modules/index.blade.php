
<div class="max-w-4xl mx-auto p-4">
    <h1 class="text-3xl font-bold">{{ $this->category->name }}</h1>
    <p class="text-blue-100">Explore the available modules below.</p>
    
    {{-- Subject Toggle Component --}}
    <x-context-bar-livewire :categoryId="$categoryId" :currentSubjectId="$currentSubjectId" routeName="modules.index"/>

    <div class="mt-6 bg-gray-900 p-4 rounded-xl shadow-lg space-y-4">

    <!-- Status Filter Buttons -->
    <div class="flex flex-wrap gap-3">
        @foreach ([
            'all' => 'All',
            'completed' => 'Completed',
            'in_progress' => 'In Progress',
        ] as $value => $label)
            <button
                wire:click="$set('statusFilter', '{{ $value }}')"
                class="px-4 py-2 rounded-lg text-sm font-semibold transition
                    {{ $statusFilter === $value
                        ? 'bg-blue-600 text-white shadow-md'
                        : 'bg-gray-800 text-gray-300 hover:bg-gray-700' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <!-- Proficiency Dropdown -->
    <div class="relative w-64">
        <select
            wire:model.live="proficiencyFilter"
            class="w-full bg-gray-800 text-gray-200 border border-gray-700 
                   rounded-lg px-4 py-2 appearance-none
                   focus:outline-none focus:ring-2 focus:ring-blue-500
                   transition">

            <option value="">All Proficiencies</option>

            @foreach($this->proficiencies as $prof)
                <option value="{{ $prof->id }}">
                    {{ $prof->name }}
                </option>
            @endforeach
        </select>

        <!-- Dropdown Arrow -->
        <div class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-gray-400">
            ▼
        </div>
    </div>

</div>
    @if (!$this->modules->isEmpty())
        
        <div class="mt-8 space-y-6">
            @foreach ($this->modules as $module)
                <div class="p-4 bg-gray-800 shadow rounded">
                    <h2 class="text-xl font-semibold mb-2">{{ $module->name }} </h2>
                    <h2 class="text-l font-semibold">Proficiency Level: {{ $module->proficiencies->first()->name ?? 'None' }}</h2>
                    <p class="text-gray-400">{{ $module->description }}</p>
                    

                    {{-- Check if user has this module assigned --}}
                    @php
                        $userModule = $module->users->first(); // because you already filtered users to auth user
                    @endphp

                    {{-- USER DOES NOT HAVE MODULE --}}
                    @if (!$userModule && $module->status === 'ready')

                        <form action="{{ route('modules.assign', $module->id) }}" method="POST" class="mt-4">
                            @csrf
                            <button type="submit"
                                class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600">
                                Add Module
                            </button>

                            @if ($module->created_by === Auth::id())
                                <button class="bg-gray-600 text-white px-4 py-2 rounded">
                                    Delete
                                </button>
                            @endif
                        </form>

                    {{-- MODULE IS PREPARING --}}
                    @elseif ($module->status === 'preparing')
                    <div wire:poll.3s>
                        <div class="flex items-center gap-2 mt-4 text-yellow-400">
                            <svg class="animate-spin h-5 w-5" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="10"
                                    stroke="currentColor"
                                    stroke-width="4"
                                    fill="none"/>
                            </svg>
                            Preparing module...
                        </div>
                    </div>

                    {{-- MODULE READY / NORMAL STATE --}}
                    @else

                        <span class="inline-block bg-blue-100 text-blue-800 text-xs font-semibold mr-2 px-2.5 py-0.5 rounded">
                            Score: {{ $userModule->pivot->score }}%
                        </span>

                    @endif
                    
                    {{-- lets try show something if the user has completed this module --}}
                    @if ($module->users->isNotEmpty())
                        @php
                            $userModule = $module->users->first();
                            $status = $userModule->pivot->status;
                        @endphp

                        @if ($status === 'completed')
                            
                            <p class="mt-2 text-green-400 font-semibold">Module Completed!</p>
                            <a href="{{ route('modules.next-module', ['moduleId' => $module->id]) }}"
                                class="bg-blue-500 text-white mt-4 px-4 py-2 rounded hover:bg-blue-600 inline-block">
                                    Next Modules
                            </a>
                        @elseif ($status === 'not_started')
                            <p class="mt-2 text-gray-400 font-semibold">Module Not Started</p>
                            <a href="{{ route('questions.quiz.index', ['module_id' => $module->id]) }}"
                                class="bg-blue-500 text-white mt-4 px-4 py-2 rounded hover:bg-blue-600 inline-block">
                                    Start Quiz
                            </a>    
                        @elseif ($status === 'in_progress')
                            <p class="mt-2 text-yellow-400 font-semibold">Module In Progress</p>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <p class="text-gray-400 mt-6">No modules found for the selected filters.</p>
    @endif
</div>

