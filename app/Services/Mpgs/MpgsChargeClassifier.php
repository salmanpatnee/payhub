<?php

namespace App\Services\Mpgs;

/**
 * Classifies a gateway result into exactly one of the four buckets spec 0005
 * defines: approved, declined, hard_error, unknown. Pure: no I/O.
 *
 * Applied to the PAY response, to a retrieve order lookup (via fromOrder()),
 * and to the webhook triggered lookup. Approved is deliberately narrow: the
 * gateway result is success AND the PAY transaction is approved AND the order
 * is captured AND the captured amount and currency equal what PayHub expects.
 * Anything short of that is unknown, never approved.
 *
 * PROVISIONAL (spec 0005, AC-13): field names and the gateway code sets come
 * from the integration handoff, not a verified sandbox (Open questions 2, 8, 9
 * and 12). The sandbox spike confirms or corrects them; keep them in the
 * constants and the two body readers below so a correction lands in one place.
 */
class MpgsChargeClassifier
{
    /** Gateway codes that mean the issuer or 3DS refused this card. */
    private const DECLINE_CODES = [
        'DECLINED',
        'DECLINED_DO_NOT_CONTACT',
        'DECLINED_PAYMENT_PLAN',
        'INSUFFICIENT_FUNDS',
        'EXPIRED_CARD',
        'REFERRED',
        'BLOCKED',
        'LIMIT_EXCEEDED',
        'AUTHENTICATION_FAILED',
        'NOT_ENROLLED_3D_SECURE',
    ];

    /** Error causes that mean PayHub's request itself was wrong or unauthorized. */
    private const HARD_ERROR_CAUSES = [
        'INVALID_REQUEST',
        'REQUEST_REJECTED',
        'UNAUTHORIZED',
    ];

    /**
     * @param  array<string, mixed>  $body  a PAY response body (or one shaped like it)
     */
    public static function classify(int $httpStatus, array $body, int $expectedAmount, string $expectedCurrency): string
    {
        $result = self::string($body['result'] ?? null);
        $gatewayCode = self::string($body['response']['gatewayCode'] ?? null);

        if ($result === 'SUCCESS' && $gatewayCode === 'APPROVED') {
            $order = $body['order'] ?? [];
            $captured = self::string($order['status'] ?? null) === 'CAPTURED';
            $amountMatches = MpgsAmount::toMinorUnits($order['totalCapturedAmount'] ?? null) === $expectedAmount;
            $currencyMatches = self::string($order['currency'] ?? null) === strtoupper($expectedCurrency);

            // Success without a captured order or an exact amount and currency
            // match is deliberately NOT approved (spec 0005, AC-7).
            return $captured && $amountMatches && $currencyMatches ? 'approved' : 'unknown';
        }

        if ($result === 'FAILURE' && in_array($gatewayCode, self::DECLINE_CODES, true)) {
            return 'declined';
        }

        if ($result === 'ERROR') {
            $cause = self::string($body['error']['cause'] ?? null);

            // SERVER_BUSY / SERVER_FAILED and anything else fall through to unknown.
            return in_array($cause, self::HARD_ERROR_CAUSES, true) ? 'hard_error' : 'unknown';
        }

        if (in_array($httpStatus, [401, 403], true)) {
            return 'hard_error';
        }

        return 'unknown';
    }

    /**
     * Classify a retrieve order body by locating the PAY transaction by its id
     * (never "the last one"). No matching transaction means the PAY outcome is
     * not known yet, so unknown.
     *
     * @param  array<string, mixed>  $orderBody
     */
    public static function fromOrder(array $orderBody, string $transactionId, int $expectedAmount, string $expectedCurrency): string
    {
        $entry = self::findTransaction($orderBody, $transactionId);

        if ($entry === null) {
            return 'unknown';
        }

        return self::classify(200, [
            'result' => $entry['result'] ?? null,
            'response' => $entry['response'] ?? [],
            'transaction' => $entry['transaction'] ?? [],
            'order' => [
                'status' => $orderBody['status'] ?? null,
                'totalCapturedAmount' => $orderBody['totalCapturedAmount'] ?? null,
                'currency' => $orderBody['currency'] ?? null,
            ],
        ], $expectedAmount, $expectedCurrency);
    }

    /**
     * The entry of a retrieve order body's transaction list whose transaction
     * id is the one PayHub issued, or null.
     *
     * @param  array<string, mixed>  $orderBody
     * @return array<string, mixed>|null
     */
    public static function findTransaction(array $orderBody, string $transactionId): ?array
    {
        $transactions = $orderBody['transaction'] ?? [];

        if (! is_array($transactions)) {
            return null;
        }

        foreach ($transactions as $entry) {
            if (is_array($entry) && self::string($entry['transaction']['id'] ?? null) === $transactionId) {
                return $entry;
            }
        }

        return null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
