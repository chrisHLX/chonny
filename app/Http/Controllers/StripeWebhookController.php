<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;
use App\Http\Services\CreditService;
use App\Models\ProcessedStripeEvent;
use Stripe\Stripe;
use Stripe\Checkout\Session;



class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        try {
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                config('services.stripe.webhook_secret')
            );
        } catch (\Throwable $e) {
            Log::warning('❌ Stripe webhook signature failed');
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        Log::info("📡 Stripe event: {$event->type}");

        if ($event->type !== 'checkout.session.completed') {
            return response()->json(['status' => 'ignored']);
        }

        $session = $event->data->object;

        // ✅ Idempotency guard
        if (ProcessedStripeEvent::where('stripe_id', $session->id)->exists()) {
            return response()->json(['status' => 'duplicate']);
        }

        $userId  = $session->metadata->user_id ?? null;
        $credits = (int) ($session->metadata->credits ?? 0);

        if ($userId && $credits > 0) {
            app(CreditService::class)->addAiCredits(
                $userId,
                $credits,
                'Stripe purchase'
            );

            ProcessedStripeEvent::create([
                'stripe_id' => $session->id,
                'type' => $event->type,
            ]);

            Log::info("✅ Added {$credits} credits to user {$userId}");
        }

        return response()->json(['status' => 'success']);
    }
}
