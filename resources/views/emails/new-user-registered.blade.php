<x-mail::message>
# New User Registered

A new user has signed up on {{ config('app.name') }}.

| | |
|---|---|
| **Name** | {{ $user->name }} |
| **Email** | {{ $user->email }} |
| **Registered** | {{ $user->created_at->format('d M Y, H:i') }} UTC |

<x-mail::button :url="config('app.url') . '/admin'">
View Admin Panel
</x-mail::button>

Thanks,
{{ config('app.name') }}
</x-mail::message>
