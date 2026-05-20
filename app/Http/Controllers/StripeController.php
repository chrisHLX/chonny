<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\Checkout\Session;

class StripeController extends Controller
{
    public function create(Request $request)
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        $user = $request->user();

        $session = Session::create([
            'mode' => 'payment',

            'line_items' => [[
                'price_data' => [
                    'currency' => 'aud',
                    'product_data' => [
                        'name' => 'AI Credits – 100 Pack',
                    ],
                    'unit_amount' => 500, // $5.00
                ],
                'quantity' => 1,
            ]],

            'success_url' => route('checkout.success'),
            'cancel_url' => route('checkout.cancel'),

            // ✅ Attach metadata HERE
            'metadata' => [
                'user_id' => $user->id,
                'credits' => 100,
            ],
        ]);

        return response()->json([
            'id' => $session->id,
        ]);
    }
}
