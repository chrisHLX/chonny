<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; color: #333; line-height: 1.5; }
        .container { max-width: 600px; margin: 40px auto; padding: 0 20px; }
        table { border-collapse: collapse; width: 100%; margin: 16px 0; }
        td { padding: 8px 12px; border: 1px solid #ddd; }
        td:first-child { font-weight: bold; width: 120px; }
        .btn { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #6366f1; color: #fff; text-decoration: none; border-radius: 6px; }
    </style>
</head>
<body>
<div class="container">
    <h2>New User Registered</h2>
    <p>A new user has signed up on {{ config('app.name') }}.</p>
    <table>
        <tr><td>Name</td><td>{{ $user->name }}</td></tr>
        <tr><td>Email</td><td>{{ $user->email }}</td></tr>
        <tr><td>Registered</td><td>{{ $user->created_at->format('d M Y, H:i') }} UTC</td></tr>
    </table>
    <a href="{{ config('app.url') }}/admin" class="btn">View Admin Panel</a>
    <p style="margin-top:32px;color:#999;font-size:13px;">{{ config('app.name') }}</p>
</div>
</body>
</html>
