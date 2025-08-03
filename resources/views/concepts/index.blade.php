<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            {{ __('Concepts') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                            <td class="px-6 py-4 whitespace-pre-line">Questions</td>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach ($concepts as $concept)
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">{{ $concept->name }}</td>
                                <td class="px-6 py-4 whitespace-pre-line">{{ $concept->description ?? '—' }}</td>

                                @if ($concept->questions)
                                    <td class="px-6 py-4 whitespace-nowrap"> 
                                        @if ($concept->questions->isNotEmpty())
                                            <ul>
                                                @foreach ($concept->questions as $question)
                                                    <li>{{ $question->question }}</li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <p>No questions tagged for this concept yet.</p>
                                        @endif                  
                                    </td>
                                @else
                                    <td class="px-6 py-4 whitespace-nowrap text-gray-500">No question attached</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
