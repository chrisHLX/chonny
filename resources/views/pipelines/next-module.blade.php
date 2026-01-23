<x-app-layout>
    <x-slot name="header">
        <h2 class="text-2xl font-bold">
            ⏳ Creating your module
        </h2>
    </x-slot>

    <div class="max-w-3xl mx-auto py-8 space-y-6">

        <!-- PIPELINE STATUS -->
        <div class="p-4 bg-white shadow rounded">
            <p class="font-semibold">
                Status:
                <span class="
                    @if($pipeline->status === 'completed') text-green-600
                    @elseif($pipeline->status === 'failed') text-red-600
                    @else text-blue-600
                    @endif
                ">
                    {{ ucfirst($pipeline->status) }}
                </span>
            </p>
        </div>

        <!-- STEPS -->
        <div class="space-y-4">
            @foreach ($pipeline->steps as $step)
                <div class="p-4 bg-white shadow rounded flex items-center justify-between">

                    <div>
                        <p class="font-medium">{{ $step->name }}</p>
                        <p class="text-sm text-gray-500">
                            {{ ucfirst($step->status) }}
                        </p>

                        @if($step->error_message)
                            <p class="text-sm text-red-600 mt-1">
                                {{ $step->error_message }}
                            </p>
                        @endif
                    </div>

                    <div>
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

        <!-- AUTO REDIRECT ON SUCCESS -->
        @if($pipeline->status === 'completed')
            <div class="text-center pt-6">
                <a href="{{ route('modules.index') }}"
                   class="px-4 py-2 bg-green-600 text-white rounded">
                    Continue
                </a>
            </div>
        @endif

        @if($pipeline->status === 'failed')
            <div class="text-center pt-6">
                <p class="text-red-600 font-semibold">
                    Something went wrong. You can retry or contact support.
                </p>
            </div>
        @endif

    </div>

    <!-- AUTO REFRESH WHILE RUNNING -->
    @if(in_array($pipeline->status, ['pending', 'running']))
        <script>
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        </script>
    @endif

</x-app-layout>
