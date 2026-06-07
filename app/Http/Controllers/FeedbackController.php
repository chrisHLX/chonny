<?php

namespace App\Http\Controllers;

use App\Http\Requests\FeedbackRequest;
use App\Models\Feedback;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FeedbackController extends Controller
{
    public function create()
    {
        return view('feedback');
    }

    public function store(FeedbackRequest $request)
    {
        $feedback = Feedback::create([
            'user_id'        => auth()->id(),
            'category'       => $request->category,
            'message'        => $request->message,
            'email'          => $request->email,
            'discord'        => $request->discord,
            'screenshot_url' => $request->screenshot_url,
        ]);

        $this->notifyDiscord($feedback, $request);

        return redirect()->route('feedback.create')
            ->with('success', 'Thanks for your feedback! We read every submission.');
    }

    private function notifyDiscord(Feedback $feedback, FeedbackRequest $request): void
    {
        $webhookUrl = config('services.discord.feedback_webhook_url');

        if (! $webhookUrl) {
            return;
        }

        try {
            $user = auth()->user();

            $categoryEmoji = match ($feedback->category) {
                'Bug'              => '🐛',
                'Confusing'        => '😵',
                'Feature Idea'     => '💡',
                'General Feedback' => '💬',
                default            => '📝',
            };

            // Truncate message to stay within Discord's 1024-char field limit
            $message = mb_strlen($feedback->message) > 900
                ? mb_substr($feedback->message, 0, 900) . '…'
                : $feedback->message;

            $fields = [
                ['name' => 'Category', 'value' => "{$categoryEmoji} {$feedback->category}", 'inline' => true],
                ['name' => 'Message', 'value' => $message, 'inline' => false],
            ];

            if ($user) {
                $fields[] = ['name' => 'Account', 'value' => "{$user->name} ({$user->email})", 'inline' => true];
            }

            if ($feedback->email && $feedback->email !== $user?->email) {
                $fields[] = ['name' => 'Contact Email', 'value' => $feedback->email, 'inline' => true];
            }

            if ($feedback->discord) {
                $fields[] = ['name' => 'Discord', 'value' => $feedback->discord, 'inline' => true];
            }

            if ($feedback->screenshot_url) {
                $fields[] = ['name' => 'Screenshot', 'value' => $feedback->screenshot_url, 'inline' => false];
            }

            $fields[] = ['name' => 'App URL', 'value' => config('app.url'), 'inline' => true];

            Http::timeout(5)->post($webhookUrl, [
                'embeds' => [[
                    'title'     => "{$categoryEmoji} New Feedback — {$feedback->category}",
                    'color'     => $this->embedColor($feedback->category),
                    'fields'    => $fields,
                    'footer'    => ['text' => 'MindCollector Feedback'],
                    'timestamp' => now()->toIso8601String(),
                ]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Discord feedback webhook failed', ['error' => $e->getMessage()]);
        }
    }

    private function embedColor(string $category): int
    {
        return match ($category) {
            'Bug'              => 0xED4245, // red
            'Confusing'        => 0xFEE75C, // yellow
            'Feature Idea'     => 0x57F287, // green
            'General Feedback' => 0x5865F2, // blurple
            default            => 0x99AAB5,
        };
    }
}
