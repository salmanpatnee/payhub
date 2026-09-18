<?php

namespace App\Services\Clover;

/**
 * Classifies a response from Clover's /v1/charges (or a later read of the same
 * charge) into exactly one of the four buckets spec 0002 defines — by response
 * *content*, never by HTTP status alone, so an ordinary decline delivered as a
 * 4xx is never mistaken for a hard error (the bug the spec's 2026-09-17 cross
 * check found and fixed).
 *
 * Clover's real error responses (captured live against the sandbox, 2026-09-18)
 * are NOT perfectly uniform: an invalid request looks like
 * `{"error": {"type": "invalid_request_error", "code": ..., "message": ...}}`,
 * while a genuine card decline looks like
 * `{"error": {"code": "card_declined", "declineCode": "issuer_declined",
 * "charge": "...", "message": "..."}}` — no `type` field, no top-level `paid`,
 * and the decline code is camelCase (`declineCode`), not the snake_case
 * `decline_code` Clover's own field-reference docs use. The classifier checks
 * both casings and doesn't require `type` to be present, since a real decline
 * doesn't always carry one.
 */
class CloverChargeClassifier
{
    private const HARD_ERROR_TYPES = ['invalid_request_error', 'authentication_error', 'api_error'];

    /**
     * @param  array<string, mixed>  $body
     */
    public static function classify(int $httpStatus, array $body, int $expectedAmount, string $expectedCurrency): string
    {
        $paid = $body['paid'] ?? null;
        $captured = $body['captured'] ?? null;

        if ($paid === true && $captured === true) {
            $amountMatches = isset($body['amount']) && (int) $body['amount'] === $expectedAmount;
            $currencyMatches = isset($body['currency']) && strtolower((string) $body['currency']) === strtolower($expectedCurrency);

            // paid true but captured false, or an amount/currency mismatch, is
            // deliberately NOT approved — see spec 0002, AC-7.
            return $amountMatches && $currencyMatches ? 'approved' : 'unknown';
        }

        // Checked before the hard-error type/status fallback below so a real
        // decline (which may carry no "type" field at all) is never misread as
        // a hard error just because it also lacks one.
        if (self::hasDeclineSignal($body)) {
            return 'declined';
        }

        $errorType = $body['error']['type'] ?? null;

        // 2026-09-18 fix: PayHub previously treated ANY body carrying an "error"
        // key as a decline. A too-long external_reference_id (Clover caps it at
        // 12 characters) came back as
        // {"error":{"type":"invalid_request_error",...}} — a request-shape
        // problem PayHub's own server caused, not a card decline — and got shown
        // to the client as "Your card was declined."
        if (in_array($errorType, self::HARD_ERROR_TYPES, true) || in_array($httpStatus, [400, 401, 403, 404], true)) {
            return 'hard_error';
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function hasDeclineSignal(array $body): bool
    {
        if (($body['paid'] ?? null) === false) {
            return true;
        }

        $error = $body['error'] ?? null;

        if (! is_array($error)) {
            return isset($body['decline_code']) || isset($body['declineCode']);
        }

        return isset($error['decline_code'])
            || isset($error['declineCode'])
            || ($error['type'] ?? null) === 'card_error';
    }
}
