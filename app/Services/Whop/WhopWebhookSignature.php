<?php

namespace App\Services\Whop;

/**
 * Standard Webhooks signature check for Whop (spec 0009, AC-9).
 *
 * Signed content is "{webhook-id}.{webhook-timestamp}.{raw body}", HMAC-SHA256,
 * base64 encoded. The header may hold several space separated "v1,<base64>"
 * values and any one match passes.
 *
 * Open question 5 (is the `ws_` secret used as is, or base64 decoded after the
 * prefix?) is not answered yet, so both keys are tried. Both come from the same
 * secret, so this does not let anyone without the secret forge a signature.
 */
class WhopWebhookSignature
{
    public const TOLERANCE_SECONDS = 300;

    public static function isValid(string $rawBody, string $id, string $timestamp, string $header, ?string $secret, ?int $now = null): bool
    {
        if ($secret === null || $secret === '' || $id === '' || $timestamp === '' || $header === '') {
            return false;
        }

        if (! ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $signed = $id.'.'.$timestamp.'.'.$rawBody;

        foreach (self::candidateKeys($secret) as $key) {
            $expected = base64_encode(hash_hmac('sha256', $signed, $key, true));

            foreach (preg_split('/\s+/', trim($header)) ?: [] as $candidate) {
                if (str_starts_with($candidate, 'v1,') && hash_equals($expected, substr($candidate, 3))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function candidateKeys(string $secret): array
    {
        $keys = [$secret];

        $withoutPrefix = preg_replace('/^(?:ws_|whsec_)/', '', $secret) ?? $secret;
        $decoded = base64_decode($withoutPrefix, true);

        if ($decoded !== false && $decoded !== '') {
            $keys[] = $decoded;
        }

        return $keys;
    }
}
