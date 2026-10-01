<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\StripeAccount;
use App\Services\Stripe\StripeAccountHealthChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class StripeHealthController extends Controller
{
    public function index(): Response
    {
        $performance = $this->performance();

        $accounts = StripeAccount::active()
            ->with('health')
            ->orderBy('account_name')
            ->get()
            ->map(function (StripeAccount $account) use ($performance) {
                $health = $account->health;
                $stats = $performance[$account->id] ?? null;

                // Explicit field list — never send the model (keys stay server side).
                return [
                    'id' => $account->id,
                    'account_name' => $account->account_name,
                    'prefix' => $account->prefix,
                    'is_active' => $account->is_active,
                    'health' => $health === null ? null : [
                        'status' => $health->status->value,
                        'charges_enabled' => $health->charges_enabled,
                        'payouts_enabled' => $health->payouts_enabled,
                        'details_submitted' => $health->details_submitted,
                        'disabled_reason' => $health->disabled_reason,
                        'requirements' => $health->requirements,
                        'country' => $health->country,
                        'default_currency' => $health->default_currency,
                        'last_error' => $health->last_error,
                        'status_changed_at' => $health->status_changed_at?->toIso8601String(),
                        'last_checked_at' => $health->last_checked_at?->toIso8601String(),
                    ],
                    'performance' => [
                        'completed_7d' => (int) ($stats->completed_7d ?? 0),
                        'failed_7d' => (int) ($stats->failed_7d ?? 0),
                        'completed_30d' => (int) ($stats->completed_30d ?? 0),
                        'failed_30d' => (int) ($stats->failed_30d ?? 0),
                        'stuck_pending' => (int) ($stats->stuck_pending ?? 0),
                    ],
                ];
            });

        return Inertia::render('StripeHealth/Index', ['accounts' => $accounts]);
    }

    public function checkAll(StripeAccountHealthChecker $checker): RedirectResponse
    {
        $accounts = StripeAccount::active()->orderBy('id')->get();

        foreach ($accounts as $account) {
            $checker->check($account);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Checked {$accounts->count()} Stripe accounts."]);

        return back();
    }

    public function check(StripeAccount $stripeAccount, StripeAccountHealthChecker $checker): RedirectResponse
    {
        $health = $checker->check($stripeAccount);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$stripeAccount->account_name}: {$health->status->value}."]);

        return back();
    }

    /**
     * One grouped query for all accounts. Windows use created_at because
     * paid_at is empty for failed and pending rows. Soft deleted rows are
     * excluded by the model's global scope.
     *
     * @return Collection<int, object>
     */
    private function performance(): Collection
    {
        $d7 = now()->subDays(7);
        $d30 = now()->subDays(30);
        $stuck = now()->subHours(24);

        return Payment::query()
            ->whereNotNull('stripe_account_id')
            ->where(fn ($q) => $q
                ->where('created_at', '>=', $d30)
                ->orWhere(fn ($q) => $q->where('status', 'pending')->where('created_at', '<', $stuck)))
            ->selectRaw(
                'stripe_account_id,
                SUM(CASE WHEN status = ? AND created_at >= ? THEN 1 ELSE 0 END) AS completed_7d,
                SUM(CASE WHEN status = ? AND created_at >= ? THEN 1 ELSE 0 END) AS failed_7d,
                SUM(CASE WHEN status = ? AND created_at >= ? THEN 1 ELSE 0 END) AS completed_30d,
                SUM(CASE WHEN status = ? AND created_at >= ? THEN 1 ELSE 0 END) AS failed_30d,
                SUM(CASE WHEN status = ? AND created_at < ? THEN 1 ELSE 0 END) AS stuck_pending',
                ['completed', $d7, 'failed', $d7, 'completed', $d30, 'failed', $d30, 'pending', $stuck]
            )
            ->groupBy('stripe_account_id')
            ->get()
            ->keyBy('stripe_account_id');
    }
}
