<?php

namespace App\Services\Kurv;

/**
 * Reply to POST /payment-requests (spec 0010, AC-5). One of the only classes that know
 * Kurv's field names. Step 0 sandbox check: `result` is "success", `transaction_id` is the
 * payment request id, `long_url` is the hosted page and `short_url` a krpy.me redirect.
 */
final class KurvPaymentRequestReply
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $url,
    ) {}

    /**
     * @param  array<string, mixed>|null  $body
     *
     * @throws \UnexpectedValueException when the reply cannot be used
     */
    public static function fromArray(?array $body): self
    {
        $id = $body['transaction_id'] ?? null;
        $url = $body['long_url'] ?? $body['short_url'] ?? null;

        if (($body['result'] ?? null) !== 'success' || ! is_string($id) || $id === ''
            || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new \UnexpectedValueException('Unreadable Kurv payment request reply.');
        }

        return new self($id, $url);
    }
}
