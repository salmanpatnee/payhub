<?php

namespace App\Services\PaymentAttempts;

/**
 * Second guard behind each extractor's allowlist (spec 0016, AC-10).
 */
class AttemptPayloadSanitizer
{
    /** Dropped when the key contains any of these. */
    private const SECRET_PARTS = [
        'secret', 'token', 'password', 'api_key', 'apikey', 'access_token', 'authorization',
        'signature', 'cvv', 'cvc', 'cvn', 'security_code', 'card_number', 'client_secret',
        'private_key', 'public_key',
    ];

    /** Dropped only when the whole key equals one of these, so `reference_number` and `company` survive. */
    private const SECRET_WORDS = ['pan', 'number'];

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    public function sanitize(?array $data): ?array
    {
        if ($data === null || $data === []) {
            return null;
        }

        $clean = $this->walk($data, 1);

        if ($clean === []) {
            return null;
        }

        $json = json_encode($clean);

        if ($json === false || strlen($json) > (int) config('payment_attempts.limits.max_body_bytes', 8192)) {
            return ['truncated' => true];
        }

        return $clean;
    }

    /** Masks any run of 13 to 19 digits (spaces or dashes allowed) in a short text column. */
    public function text(?string $value, int $max = 500): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($this->maskDigits($value), 0, $max);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function walk(array $data, int $depth): array
    {
        $maxDepth = (int) config('payment_attempts.limits.max_depth', 4);
        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSecretKey($key)) {
                continue;
            }

            if (is_array($value)) {
                if ($depth >= $maxDepth) {
                    continue;
                }

                $out[$key] = $this->walk($value, $depth + 1);
            } elseif (is_string($value)) {
                $out[$key] = mb_substr(
                    $this->maskDigits($value),
                    0,
                    (int) config('payment_attempts.limits.string_length', 500),
                );
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function isSecretKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::SECRET_PARTS as $part) {
            if (str_contains($lower, $part)) {
                return true;
            }
        }

        return in_array($lower, self::SECRET_WORDS, true);
    }

    private function maskDigits(string $value): string
    {
        return preg_replace('/(?<!\d)\d(?:[ -]?\d){12,18}(?!\d)/', '[masked]', $value) ?? $value;
    }
}
