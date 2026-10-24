<?php

namespace App\Services\Mpgs;

use App\Models\MpgsChargeAttempt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read only: retrieves an attempt's order from the gateway and works out why it
 * ended the way it did (see MpgsAttemptOutcome). Shared by the queued job and the
 * backfill command. Returns null when the gateway has nothing to say (no order, or
 * the lookup failed); a failed lookup is logged and never thrown, because this is
 * diagnostic only.
 */
class MpgsAttemptOutcomeLookup
{
    /**
     * @return array{auth_status: ?string, gateway_code: ?string, reason: ?string}|null
     */
    public function check(MpgsChargeAttempt $attempt): ?array
    {
        $account = $attempt->payment?->mpgsAccount;

        if (! $account) {
            return null;
        }

        try {
            $result = app()->make(MpgsClient::class, [
                'merchantId' => $account->merchant_id,
                'integrationPassword' => $account->integration_password,
                'apiVersion' => $account->api_version,
                'gatewayHost' => $account->gatewayHost(),
            ])->retrieveOrder($attempt->order_id);
        } catch (Throwable $e) {
            Log::warning('MPGS attempt outcome lookup failed', ['attempt_id' => $attempt->id, 'error' => $e->getMessage()]);

            return null;
        }

        return $result['body'] === [] ? null : MpgsAttemptOutcome::fromOrder($result['body'], $attempt->transaction_id);
    }
}
