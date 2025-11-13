<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Services\CreditService;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $event = json_decode($payload);

        if (!$event) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        Log::info("📡 Stripe Webhook received: {$event->type}");

        switch ($event->type) {
            case 'payment_intent.succeeded':
                $intent = $event->data->object;

                // Assuming you add metadata when creating the payment
                $userId = $intent->metadata->user_id ?? null;

                if ($userId) {
                    app(CreditService::class)->addAiCredits(
                        (object)['id' => $userId],
                        100,
                        'Purchased 100 AI Credits via Stripe'
                    );
                    Log::info("✅ Added credits to user #{$userId}");
                }
                break;

            default:
                Log::info("ℹ️ Unhandled event: {$event->type}");
                break;
        }

        return response()->json(['status' => 'success']);
    }

    public function createCheckoutSession(Request $request)
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        $user = $request->user();

        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'aud',
                    'product_data' => [
                        'name' => 'AI Credits Package',
                    ],
                    'unit_amount' => 500, // $5.00 (amount in cents)
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => route('checkout.success'),
            'cancel_url' => route('checkout.cancel'),
            // Important! Attach user info so webhook knows who paid
            'metadata' => [
                'user_id' => $user->id,
                'credits' => 100, // Example: give 100 credits
            ],
        ]);

        return response()->json(['id' => $session->id]);
    }
}
