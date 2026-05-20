<!-- resources/views/checkout/success.blade.php -->
<x-app-layout>
    <div class="p-6">
        <h1 class="text-2xl font-bold">Payment Successful!</h1>
        <p>Your AI credits have been added.</p>
        <a href="{{ route('dashboard') }}" class="text-blue-600 underline">Return to Dashboard</a>
    </div>
</x-app-layout>
