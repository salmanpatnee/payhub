<?php

use App\Support\CurrencySupportResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * For every user still carrying a legacy provider FK, resolve the single
     * (provider, accountId) they were assigned — stripe > revolut > square > viva
     * priority, same order as the old agentAccountData() — and insert one
     * user_payment_accounts row per currency that provider/account supports.
     * A Stripe-assigned agent gets both USD and GBP rows pointing at the same
     * account; a Viva-assigned agent gets only GBP, leaving USD unconfigured.
     */
    public function up(): void
    {
        $users = DB::table('users')
            ->select('id', 'stripe_account_id', 'revolut_account_id', 'square_account_id', 'viva_account_id')
            ->where(function ($query) {
                $query->whereNotNull('stripe_account_id')
                    ->orWhereNotNull('revolut_account_id')
                    ->orWhereNotNull('square_account_id')
                    ->orWhereNotNull('viva_account_id');
            })
            ->get();

        $now = now();
        $rows = [];

        foreach ($users as $user) {
            [$provider, $accountId] = match (true) {
                $user->stripe_account_id !== null => ['stripe', $user->stripe_account_id],
                $user->revolut_account_id !== null => ['revolut', $user->revolut_account_id],
                $user->square_account_id !== null => ['square', $user->square_account_id],
                default => ['viva', $user->viva_account_id],
            };

            $accountCurrency = $provider === 'square'
                ? DB::table('square_accounts')->where('id', $accountId)->value('currency')
                : null;

            foreach (CurrencySupportResolver::currenciesFor($provider, $accountCurrency) as $currency) {
                $rows[] = [
                    'user_id' => $user->id,
                    'currency' => $currency,
                    'provider' => $provider,
                    'account_id' => $accountId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('user_payment_accounts')->insert($rows);
        }
    }

    public function down(): void
    {
        DB::table('user_payment_accounts')->truncate();
    }
};
