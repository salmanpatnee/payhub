<?php

namespace App\Services\Whop;

use Carbon\CarbonImmutable;

/**
 * Reads a decoded Whop webhook body. Together with WhopClient this is the only
 * place that knows Whop field names (spec 0009, AC-13).
 *
 * Money shape and field names confirmed against the sandbox payments API on 2026-11-03;
 * the webhook body itself is still unseen (Open question 4). A field
 * that is missing returns null, so the job fails closed and never completes a payment.
 */
class WhopWebhookPayload
{
    /** Field in `data` that equals the price PayHub set, before any tax or fee (Open question 11). */
    public const PRICE_FIELD = 'subtotal';

    public const PAID_AT_FIELD = 'paid_at';

    /** @param array<string, mixed> $body */
    public function __construct(private readonly array $body) {}

    public static function fromRawBody(string $raw): ?self
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? new self($decoded) : null;
    }

    public function eventType(): ?string
    {
        $type = $this->body['type'] ?? $this->body['event'] ?? null;

        return is_string($type) ? $type : null;
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $data = $this->body['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    public function whopPaymentId(): ?string
    {
        $id = $this->data()['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** The PayHub payment UUID that WhopClient put in `metadata.payment_id`. */
    public function paymentUuid(): ?string
    {
        $metadata = $this->data()['metadata'] ?? null;
        $uuid = is_array($metadata) ? ($metadata['payment_id'] ?? null) : null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /**
     * Whop sends money as an object: {"currency":"usd","amount":"111.00","decimals":2,...}
     * (confirmed against the sandbox payments API). A plain number or string is accepted too.
     */
    public function priceCents(): ?int
    {
        $value = $this->data()[self::PRICE_FIELD] ?? null;

        if (is_array($value)) {
            $value = $value['amount'] ?? null;
        }

        return is_string($value) || is_int($value) || is_float($value)
            ? WhopAmount::decimalToCents($value)
            : null;
    }

    public function currency(): ?string
    {
        $currency = $this->data()['currency'] ?? null;

        // Fall back to the currency inside the money object.
        if (! is_string($currency) || $currency === '') {
            $price = $this->data()[self::PRICE_FIELD] ?? null;
            $currency = is_array($price) ? ($price['currency'] ?? null) : null;
        }

        return is_string($currency) && $currency !== '' ? strtolower($currency) : null;
    }

    public function paidAt(): ?CarbonImmutable
    {
        $value = $this->data()[self::PAID_AT_FIELD] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        try {
            return is_int($value) ? CarbonImmutable::createFromTimestamp($value) : CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
