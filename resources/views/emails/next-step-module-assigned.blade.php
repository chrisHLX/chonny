<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; color: #333; line-height: 1.5; }
        .container { max-width: 600px; margin: 40px auto; padding: 0 20px; }
        .why { background: #f6f6f8; border-radius: 8px; padding: 16px 20px; margin: 16px 0; }
        .btn { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #6366f1; color: #fff; text-decoration: none; border-radius: 6px; }
    </style>
</head>
<body>
<div class="container">
    <h2>Your next module is ready</h2>
    <p><strong>{{ $step->title }}</strong></p>

    <div class="why">
        {{ $step->instructions }}
    </div>

    @if($step->module)
        <a href="{{ route('modules.show', $step->module) }}" class="btn">View Module</a>
    @endif

    <p style="margin-top:32px;color:#999;font-size:13px;">{{ config('app.name') }}</p>
</div>
</body>
</html>
