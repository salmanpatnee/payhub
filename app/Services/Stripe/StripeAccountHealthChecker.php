<?php

namespace App\Services\Stripe;

use App\Enums\StripeHealthStatus;
use App\Models\StripeAccount;
use App\Models\StripeAccountHealth;
use Stripe\StripeClient;
use Throwable;

class StripeAccountHealthChecker
{
    /**
     * Ask Stripe about one account and save the result. Never throws for a
     * Stripe or network failure: it records `unreachable` instead.
     */
    public function check(StripeAccount $account): StripeAccountHealth
    {
        $health = StripeAccountHealth::firstOrNew(['stripe_account_id' => $account->id]);
        $previous = $health->exists ? $health->status : null;
        $now = now();

        try {
            // Per-account client — NEVER Stripe::setApiKey() globally.
            // app()->make() lets tests bind a fake StripeClient.
            $stripe = app()->make(StripeClient::class, ['config' => $account->secret_key]);
            $remote = $stripe->accounts->retrieve();

            $requirements = $remote->requirements ?? null;
            $lists = [
                'currently_due' => $this->list($requirements, 'currently_due'),
                'past_due' => $this->list($requirements, 'past_due'),
                'eventually_due' => $this->list($requirements, 'eventually_due'),
                'pending_verification' => $this->list($requirements, 'pending_verification'),
                'current_deadline' => $requirements->current_deadline ?? null,
            ];

            $charges = (bool) ($remote->charges_enabled ?? false);
            $payouts = (bool) ($remote->payouts_enabled ?? false);

            $status = match (true) {
                ! $charges => StripeHealthStatus::Restricted,
                ! $payouts || $lists['past_due'] !== [] || $lists['currently_due'] !== [] => StripeHealthStatus::NeedsAttention,
                default => StripeHealthStatus::Healthy,
            };

            $health->fill([
                'stripe_remote_id' => $remote->id ?? null,
                'charges_enabled' => $charges,
                'payouts_enabled' => $payouts,
                'details_submitted' => (bool) ($remote->details_submitted ?? false),
                'disabled_reason' => $requirements->disabled_reason ?? null,
                'requirements' => $lists,
                'country' => $remote->country ?? null,
                'default_currency' => $remote->default_currency ?? null,
                'last_error' => null,
            ]);
        } catch (Throwable $e) {
            // Keep the last good data; only status, error and check time change.
            $status = StripeHealthStatus::Unreachable;
            $health->last_error = $this->cleanError($e->getMessage());
        }

        $health->status = $status;
        $health->last_checked_at = $now;

        if ($previous !== $status) {
            $health->status_changed_at = $now;
        }

        $health->save();

        return $health;
    }

    /**
     * @return array<int, string>
     */
    private function list(mixed $requirements, string $key): array
    {
        $value = $requirements->{$key} ?? [];

        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }

        return array_values(array_map('strval', (array) $value));
    }

    private function cleanError(string $message): string
    {
        $message = preg_replace('/\b(?:sk|rk|pk)_(?:live|test)?_?[A-Za-z0-9_*]+/', '[redacted]', $message) ?? '';

        return mb_substr($message, 0, 500);
    }
}
