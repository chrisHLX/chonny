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

        // ✅ Create Stripe Customer if user doesn't have one yet
        if (!$user->stripe_customer_id) {
            $customer = Customer::create([
                'email' => $user->email,
                'name'  => $user->name, // Optional: use nickname for privacy
            ]);

            $user->stripe_customer_id = $customer->id;
            $user->save();
        } else {
            $customer = Customer::retrieve($user->stripe_customer_id);
        }

        $session = Session::create([
            'mode' => 'payment',
            'customer' => $customer->id, // ensures personal Stripe account name is not shown
            'line_items' => [[
                'price_data' => [
                    'currency' => 'aud',
                    'product_data' => [
                        'name' => 'AI Credits – 200 Pack',
                    ],
                    'unit_amount' => 100, // $1.00
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
