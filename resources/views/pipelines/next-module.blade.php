<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-2xl text-white leading-tight">
                ⏳ Creating your module
            </h2>
        </div>
    </x-slot>

    <div class="bg-gray-900 text-gray-200 min-h-screen py-10">
        <div class="max-w-3xl mx-auto px-6 lg:px-8 space-y-8">

            <!-- STATUS CARD -->
            <div class="bg-gray-800 rounded-2xl p-6 shadow hover:shadow-blue-500/20 transition">
                <p class="text-lg font-semibold">
                    Status:
                    <span class="
                        @if($pipeline->status === 'completed') text-green-400
                        @elseif($pipeline->status === 'failed') text-red-400
                        @else text-blue-400
                        @endif
                    ">
                        {{ ucfirst($pipeline->status) }}
                    </span>
                </p>
            </div>

            <!-- PIPELINE STEPS -->
            <div class="space-y-4">
                @foreach ($pipeline->steps as $step)
                    <div class="bg-gray-800 rounded-2xl p-5 shadow flex items-center justify-between hover:bg-gray-700 transition">

                        <div>
                            <p class="font-semibold text-gray-100">
                                {{ $step->name }}
                            </p>

                            <p class="text-sm text-gray-400">
                                {{ ucfirst($step->status) }}
                            </p>

                            @if($step->error_message)
                                <p class="text-sm text-red-400 mt-2">
                                    {{ $step->error_message }}
                                </p>
                            @endif
                        </div>

                        <div class="text-xl">
                            @if($step->status === 'completed')
                                ✅
                            @elseif($step->status === 'running')
                                🔄
                            @elseif($step->status === 'failed')
                                ❌
                            @else
                                ⏸
                            @endif
                        </div>

                    </div>
                @endforeach
            </div>

            <!-- SUCCESS -->
            @if($pipeline->status === 'completed')
                <div class="text-center pt-6">
                    <a href="{{ route('modules.index') }}"
                       class="inline-flex items-center justify-center bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-semibold transition">
                        Continue
                    </a>
                </div>
            @endif

            <!-- FAILURE -->
            @if($pipeline->status === 'failed')
                <div class="bg-red-900/30 border border-red-700 rounded-2xl p-6 text-center">
                    <p class="text-red-400 font-semibold">
                        Something went wrong. You can retry or contact support.
                    </p>
                </div>
            @endif

        </div>
    </div>

    <!-- AUTO REFRESH -->
    @if(in_array($pipeline->status, ['pending', 'running']))
        <script>
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        </script>
    @endif
</x-app-layout>
