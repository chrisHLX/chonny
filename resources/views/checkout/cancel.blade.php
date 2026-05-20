<!-- resources/views/checkout/cancel.blade.php -->
<x-app-layout>
    <div class="p-6">
        <h1 class="text-2xl font-bold">Payment Cancelled</h1>
        <p>No charges were made.</p>
        <a href="{{ route('dashboard') }}" class="text-blue-600 underline">Return to Dashboard</a>
    </div>
</x-app-layout>
