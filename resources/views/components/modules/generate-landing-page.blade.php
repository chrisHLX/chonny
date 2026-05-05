@props(['module', 'allQuestions'])

<h1 class="text-2xl font-bold mb-6">Generate an Introductory Guide for {{ $module->name }}</h1>

<div class="bg-white text-gray-800 shadow-sm sm:rounded-lg p-6">
    <h3 class="text-lg text-gray-800 font-semibold mb-4">Landing Page Generator</h3>

    <p class="mb-4 text-sm text-gray-600">
        Write a short description of what this guide will cover. For example:
        <em>“This module is designed to help new StarCraft players understand the fundamentals of playing Protoss, including basic build orders, unit compositions, and economy management.”</em>
    </p>

    <form action="{{ route('modules.generateLandingPage', $module) }}" method="POST" class="space-y-6">
        @csrf
        <div>
            <x-input-label for="description" :value="'Guide Summary / Prompt for AI'" />
            <textarea name="description" id="description" rows="5"
                class="block mt-1 w-full rounded-md border-gray-300 shadow-sm focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                placeholder="Explain what the guide should focus on. E.g. Introduce key Protoss strategies for beginners."></textarea>
            <x-input-error :messages="$errors->get('description')" class="mt-2" />
        </div>

        <button type="submit"
            class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white font-semibold rounded-md hover:bg-indigo-700 focus:outline-none focus:ring focus:ring-indigo-200 transition duration-150 ease-in-out">
            Create Landing Page
        </button>
    </form>
</div>
