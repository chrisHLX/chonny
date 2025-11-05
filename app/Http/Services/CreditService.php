<?php
namespace App\Http\Services;

use App\Models\UserCredit;
use App\Models\CreditTransaction;
use Illuminate\Support\Facades\DB;

class CreditService
{
    public function getUserCredits($user)
    {
        return DB::transaction(function () use ($user) {
            return UserCredit::firstOrCreate(['user_id' => $user]);
        });
    }

    public function getBalances($user)
    {
        return DB::transaction(function () use ($user) {
            $credit = $this->getUserCredits($user);
            return [
                'ai_credits' => $credit->ai_credits,
                'learned_credits' => $credit->learned_credits,
                'total_credits' => $credit->ai_credits + $credit->learned_credits,
            ];
        });
        
    }

    // 🟣 Add AI Credits (e.g. after Stripe payment)
    public function addAiCredits($user, int $amount, string $description = null)
    {
        return DB::transaction(function () use ($user, $amount, $description) {
            $credit = $this->getUserCredits($user);
            $credit->increment('ai_credits', $amount);

            CreditTransaction::create([
                'user_id' => $user,
                'amount' => $amount,
                'credit_type' => 'ai',
                'action' => 'purchase',
                'description' => $description,
            ]);
        });
        
    }

    // 🟣 Deduct AI Credits (for AI generations)
    public function spendAiCredits($user, int $amount, string $description = null)
    {
        return DB::transaction(function () use ($user, $amount, $description) {
            $credit = $this->getUserCredits($user);

            if ($credit->ai_credits < $amount) {
                throw new \Exception('Insufficient AI Credits.');
            }

            $credit->decrement('ai_credits', $amount);

            CreditTransaction::create([
                'user_id' => $user,
                'amount' => -$amount,
                'credit_type' => 'ai',
                'action' => 'generation',
                'description' => $description,
            ]);
        });
    }

    // 🟢 Reward Learned Credits (for contributing/using AI)
    public function rewardLearnedCredits($user, int $amount, string $description = null)
    {
        return DB::transaction(function () use ($user, $amount, $description) {
            $credit = $this->getUserCredits($user);
            $credit->increment('learned_credits', $amount);

            CreditTransaction::create([
                'user_id' => $user,
                'amount' => $amount,
                'credit_type' => 'learned',
                'action' => 'reward',
                'description' => $description,
            ]);
        });
    }

    // 🟢 Spend Learned Credits (hints, retries, unlocks)
    public function spendLearnedCredits($user, int $amount, string $description = null)
    {
        $credit = $this->getUserCredits($user);

        if ($credit->learned_credits < $amount) {
            throw new \Exception('Not enough Learned Credits.');
        }

        $credit->decrement('learned_credits', $amount);

        CreditTransaction::create([
            'user_id' => $user,
            'amount' => -$amount,
            'credit_type' => 'learned',
            'action' => 'spend',
            'description' => $description,
        ]);
    }
}
