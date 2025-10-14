{{-- resources/views/dashboard.blade.php --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-2xl text-white leading-tight">
                {{ __('Dashboard') }}
            </h2>
        </div>
    </x-slot>

    <div class="bg-gray-900 text-gray-200 min-h-screen py-10">
        <div class="max-w-7xl mx-auto px-6 lg:px-8 space-y-10">

            <!-- Welcome Card -->
            <div class="bg-gradient-to-r from-blue-600 to-indigo-700 text-white rounded-2xl shadow-lg p-8 border border-blue-500/30">
                <h1 class="text-3xl font-bold mb-2">Welcome back, {{ $user->name }} 👋</h1>
                <p class="text-blue-100">
                    Ready to keep improving your StarCraft 2 knowledge? Let’s dive in.
                </p>
            </div>
            
            <!-- Subject Selection -->
            {{-- Subject Toggle --}}
            <div class="flex flex-wrap gap-2 mb-8">
                @foreach($subjects as $subject)
                    <a href="{{ route('dashboard', ['subject_id' => $subject->id]) }}"
                    class="px-4 py-2 rounded-full text-sm font-medium transition
                            {{ $subject->id == $currentSubjectId ? 'bg-blue-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600' }}">
                        {{ $subject->name }}
                    </a>
                @endforeach
            </div>


            <!-- Main Dashboard Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-8">

            <!-- Concept Mastery -->
            <div class="bg-gray-800 rounded-2xl p-6 shadow hover:shadow-blue-500/20 transition">
                <h3 class="text-lg font-semibold text-white mb-3 flex items-center gap-2">
                    📘 Your Concept Mastery
                </h3>
                <p class="text-gray-400 text-sm mb-6">
                    Track your progress and identify key areas to improve.
                </p>
                @forelse($concepts as $concept)
                    @php
                        $mastery = $concept->userMastery?->mastery_percentage ?? 0;
                        $totalQuestions = $concept->userMastery?->total_questions ?? $concept->questions->count();
                    @endphp
                    <div class="mb-5">
                        <div class="flex justify-between mb-1 text-sm">
                            <span class="font-medium text-gray-200">{{ $concept->name }}</span>
                            <span class="text-gray-400">{{ $mastery }}% ({{ $totalQuestions }} questions)</span>
                        </div>
                        <div class="w-full bg-gray-700 rounded-full h-3 overflow-hidden">
                            <div class="bg-blue-600 h-3 rounded-full transition-all duration-500 ease-in-out" 
                                style="width: {{ $mastery }}%">
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-gray-500">No mastery data yet.</p>
                @endforelse
            </div>


            <!-- Leaderboard -->
            <div class="bg-gray-800 rounded-2xl p-6 shadow hover:shadow-blue-500/20 transition">
                <h3 class="text-lg font-semibold text-white mb-3 flex items-center gap-2">
                    🏆 Leaderboard
                </h3>
                <p class="text-gray-400 text-sm mb-6">
                    See who’s mastering the most concepts this week.
                </p>

                <ul class="divide-y divide-gray-700 mb-6">
                    @foreach ($leaderboard as $index => $user)
                        <li class="flex items-center justify-between py-3">
                            <div class="flex items-center gap-3">
                                <span class="text-gray-400 text-sm w-6 text-center">
                                    {{ $index + 1 }}.
                                </span>
                                <span class="text-white font-medium">
                                    {{ $user->name }}
                                </span>
                            </div>
                            <span class="text-blue-400 font-semibold">
                                {{ round($user->total_mastery) }}%
                                <!-- {{ number_format($user->total_mastery, 1) }}% -->
                            </span>
                        </li>
                    @endforeach
                </ul>
                <!--
                <a href="{{ route('dashboard') }}" 
                class="inline-flex items-center justify-center w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded-lg transition">
                    View Full Leaderboard
                </a>
-->
            </div>


                <!-- User Modules -->
                <div class="bg-gray-800 rounded-2xl p-6 shadow hover:shadow-blue-500/20 transition">
                    <h3 class="text-lg font-semibold text-white mb-3 flex items-center gap-2">
                        🎯 Your Modules
                    </h3>
                    @if($modules->isEmpty())
                        <p class="text-gray-400 text-sm mb-4">
                            You have no active modules yet.
                        </p>
                        <a href="{{ route('modules.index') }}" 
                           class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition">
                            Browse Modules
                        </a>
                    @else
                        <ul class="space-y-3">
                            @foreach($modules as $module)
                                <li class="bg-gray-700 p-3 rounded-lg flex justify-between items-center hover:bg-gray-600 transition">
                                    <span class="font-medium text-gray-100">{{ $module->name }}</span>
                                    <span class="text-sm text-gray-400">
                                        Score: {{ $module->pivot->score }} | {{ ucfirst($module->pivot->status) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

            </div>

            <!-- Created Modules -->
            <div class="bg-gray-800 rounded-2xl p-6 shadow hover:shadow-blue-500/20 transition">
                @if ($createdModules->isEmpty())
                    <h3 class="text-lg font-semibold mb-3 text-white flex items-center gap-2">
                        🧩 Create Your Own Modules
                    </h3>
                    <p class="text-gray-400 text-sm mb-6">
                        Create custom learning modules to share or refine your skills.
                    </p>
                    <a href="{{ route('modules.create') }}" 
                       class="inline-flex items-center justify-center bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
                        ➕ Create Module
                    </a>
                @else
                    <h3 class="text-lg font-semibold mb-6 text-white flex items-center gap-2">
                        🧠 Your Created Modules
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                        @foreach($createdModules as $module)
                            <div class="bg-gray-700 rounded-xl p-4 flex flex-col justify-between hover:bg-gray-600 transition">
                                <div>
                                    <h2 class="text-md font-bold text-white">{{ $module->name }}</h2>
                                    <p class="text-gray-400 text-sm mt-1">{{ $module->description }}</p>
                                </div>
                                <div class="mt-4 flex gap-2">
                                    <form action="{{ route('modules.destroy', $module) }}" method="POST" 
                                          onsubmit="return confirm('Are you sure?');" class="flex-1">
                                        @csrf
                                        @method('DELETE')
                                        <button class="w-full bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded-md">
                                            Delete
                                        </button>
                                    </form>
                                    <a href="{{ route('modules.edit', $module) }}" 
                                       class="flex-1 text-center bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-md">
                                        Edit
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-8 text-center">
                        <a href="{{ route('modules.create') }}" 
                           class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
                            ➕ Create Another Module
                        </a>
                    </div>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
