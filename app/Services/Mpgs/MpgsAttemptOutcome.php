<?php

namespace App\Services\Mpgs;

/**
 * Turns what the gateway holds about an order into the plain-language reason an
 * attempt ended the way it did. Pure: no I/O. Operators only, never shown to payers.
 *
 * Field names were confirmed against real Bank Alfalah orders (retrieve order body:
 * `authenticationStatus`, `risk.response`, and a `transaction[]` list holding an
 * AUTHENTICATION entry and, once a charge was sent, a PAYMENT entry). Keep every
 * field name in the constants below so a correction lands in one place.
 *
 * Never reads or returns rule `data` entries: they carry IP addresses and card BINs.
 */
class MpgsAttemptOutcome
{
    private const AUTH_TYPE = 'AUTHENTICATION';

    /** Transaction types that mean a charge was sent. */
    private const CHARGE_TYPES = ['PAYMENT', 'PAY', 'AUTHORIZATION', 'CAPTURE'];

    private const AUTH_FAILED = ['AUTHENTICATION_FAILED', 'AUTHENTICATION_REJECTED', 'AUTHENTICATION_UNSUCCESSFUL'];

    private const AUTH_PENDING = ['AUTHENTICATION_PENDING', 'AUTHENTICATION_INITIATED'];

    /** 3DS2 transaction status the issuer's ACS gives when it rejects the payer. */
    private const THREE_DS_REJECTED = 'R';

    /** 3DS2 status for a challenge that was started and not finished. */
    private const THREE_DS_CHALLENGE = 'C';

    /** A risk rule scoring at least this much is named in the reason. */
    private const RULE_SCORE_FLOOR = 50;

    /**
     * @param  array<string, mixed>  $order  a retrieve order body
     * @return array{auth_status: ?string, gateway_code: ?string, reason: ?string}
     */
    public static function fromOrder(array $order, string $transactionId): array
    {
        $transactions = is_array($order['transaction'] ?? null) ? $order['transaction'] : [];
        $authStatus = self::string($order['authenticationStatus'] ?? null);
        $auth = self::firstOfType($transactions, [self::AUTH_TYPE]);
        $charge = MpgsChargeClassifier::findTransaction($order, $transactionId)
            ?? self::firstOfType($transactions, self::CHARGE_TYPES);

        $gatewayCode = self::string(($charge ?? $auth)['response']['gatewayCode'] ?? null);

        return [
            'auth_status' => $authStatus,
            'gateway_code' => $gatewayCode,
            'reason' => $charge !== null
                ? self::chargeReason($charge, $order, $authStatus)
                : self::authReason($auth, $authStatus),
        ];
    }

    /**
     * The reason for a charge the gateway answered with a failure. Used on its own
     * for a PAY response body or a transaction entry from an order lookup.
     *
     * @param  array<string, mixed>  $charge
     * @param  array<string, mixed>  $order  where the risk block lives when it is not on the charge
     */
    public static function chargeReason(array $charge, array $order = [], ?string $authStatus = null): ?string
    {
        if (self::string($charge['result'] ?? null) !== 'FAILURE') {
            return null;
        }

        $code = self::string($charge['response']['gatewayCode'] ?? null);
        $risk = $charge['risk']['response'] ?? $order['risk']['response'] ?? [];
        $blocked = $code === 'BLOCKED' || self::string($risk['gatewayCode'] ?? null) === 'REJECTED';

        if ($blocked) {
            return self::blockedReason($risk, in_array($authStatus, self::AUTH_FAILED, true));
        }

        $acquirerCode = self::string($charge['response']['acquirerCode'] ?? null);
        $acquirerMessage = self::string($charge['response']['acquirerMessage'] ?? null);

        if ($acquirerCode !== null || $acquirerMessage !== null) {
            $detail = implode(': ', array_filter([$acquirerCode !== null ? "code {$acquirerCode}" : null, $acquirerMessage]));

            return self::limit("Card's bank declined ({$detail})");
        }

        return $code !== null ? self::limit("Declined by the gateway ({$code})") : null;
    }

    /**
     * @param  array<string, mixed>  $risk
     */
    private static function blockedReason(array $risk, bool $authFailed): string
    {
        $rules = collect($risk['rule'] ?? [])
            ->filter(fn ($rule) => is_array($rule) && ($rule['score'] ?? 0) >= self::RULE_SCORE_FLOOR && is_string($rule['id'] ?? null))
            ->pluck('id')
            ->unique()
            ->values();

        $blocked = $rules->isNotEmpty()
            ? 'blocked by gateway fraud rules ('.$rules->implode(', ').')'
            : 'blocked by gateway country/IP rules';

        return self::limit($authFailed ? 'Bank refused the 3-D Secure check, then '.$blocked : ucfirst($blocked));
    }

    /**
     * No charge was sent, so the reason lies in the 3-D Secure step.
     *
     * @param  array<string, mixed>|null  $auth
     */
    private static function authReason(?array $auth, ?string $authStatus): ?string
    {
        $threeDs = self::string($auth['authentication']['3ds2']['transactionStatus'] ?? null);

        if (in_array($authStatus, self::AUTH_FAILED, true)) {
            return $threeDs === self::THREE_DS_REJECTED
                ? 'Bank rejected the 3-D Secure check'
                : 'Bank refused the 3-D Secure check';
        }

        if (in_array($authStatus, self::AUTH_PENDING, true) || $threeDs === self::THREE_DS_CHALLENGE) {
            return 'Customer did not finish the 3-D Secure check';
        }

        if ($authStatus === 'AUTHENTICATION_SUCCESSFUL') {
            return 'Card check passed but the charge was never completed';
        }

        return null;
    }

    /**
     * @param  list<mixed>  $transactions
     * @param  list<string>  $types
     * @return array<string, mixed>|null
     */
    private static function firstOfType(array $transactions, array $types): ?array
    {
        foreach ($transactions as $entry) {
            if (is_array($entry) && in_array($entry['transaction']['type'] ?? null, $types, true)) {
                return $entry;
            }
        }

        return null;
    }

    private static function limit(string $text): string
    {
        return mb_substr($text, 0, 255);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
