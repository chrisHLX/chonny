<?php

namespace App\Http\Controllers;

use Stripe\Stripe;
use Stripe\Checkout\Session;
use Illuminate\Http\Request;

class StripeController extends Controller
{
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
