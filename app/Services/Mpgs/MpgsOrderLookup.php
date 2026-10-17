<?php

namespace App\Services\Mpgs;

use App\Models\MpgsChargeAttempt;

/**
 * The read only gateway lookup shared by the resolver job and the webhook job
 * (spec 0005, AC-9 and AC-10): retrieve the attempt's order, pick the PAY
 * transaction by the id PayHub issued (never "the last one"), and classify it
 * against the amount and currency on the Payment record.
 *
 * Never sends anything but a GET. Applying the result is the caller's job, via
 * MpgsChargeResolver.
 */
class MpgsOrderLookup
{
    /**
     * @return array{classification: string, entry: array<string, mixed>}|null null when the
     *                                                                         gateway has no PAY transaction for this attempt yet (404, empty body, id not in the list)
     */
    public function check(MpgsChargeAttempt $attempt): ?array
    {
        $payment = $attempt->payment;
        $account = $payment?->mpgsAccount;

        if (! $payment || ! $account) {
            return null;
        }

        $mpgs = app()->make(MpgsClient::class, [
            'merchantId' => $account->merchant_id,
            'integrationPassword' => $account->integration_password,
            'apiVersion' => $account->api_version,
        ]);

        $result = $mpgs->retrieveOrder($attempt->order_id);

        if ($result['body'] === []) {
            return null;
        }

        $entry = MpgsChargeClassifier::findTransaction($result['body'], $attempt->transaction_id);

        if ($entry === null) {
            return null;
        }

        return [
            'classification' => MpgsChargeClassifier::fromOrder($result['body'], $attempt->transaction_id, $payment->amount, $payment->currency),
            'entry' => $entry,
        ];
    }
}
