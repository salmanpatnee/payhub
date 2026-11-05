<?php

namespace App\Services\Kurv;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Kurv Merchant API for one account (spec 0010).
 *
 * Build one per KurvAccount with that account's decrypted key, never a global
 * credential: app()->make(KurvClient::class, ['apiKey' => ..., 'environment' => ...]).
 *
 * This class and the two reply classes are the only places that know Kurv's request
 * and reply field names. Paths and fields were checked against the Kurv sandbox
 * on 2026-11-05 (spec 0010 Follow-up, Step 0).
 */
class KurvClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $environment = 'sandbox',
    ) {}

    /**
     * One read only call used by Test connection. Returns the HTTP status so the caller
     * can map 403 (wrong key or missing permission) and 422 (plan) to plain words.
     */
    public function ping(): int
    {
        return $this->request(lookup: true)->get('/payments', ['limit' => 1])->status();
    }

    /**
     * Create the one hosted payment request for a payment (spec 0010, AC-5). A 429 is
     * retried once after a short wait. Throws RequestException on 4xx/5xx,
     * ConnectionException on timeout and UnexpectedValueException on an unreadable reply.
     */
    public function createPaymentRequest(
        int $amountCents,
        string $currency,
        string $customerName,
        string $email,
        string $referenceNumber,
        string $responseUrl,
        string $redirectUrl,
        string $cancelUrl,
    ): KurvPaymentRequestReply {
        $body = [
            'request_methods' => ['WEB'],
            // JSON number in whole units with decimals, built from integer cents.
            'amount' => (float) KurvAmount::centsToDecimal($amountCents),
            // Kurv rejects a lower case currency.
            'currency' => strtoupper($currency),
            'fixed_amount' => true,
            'customer_first_name' => $customerName,
            'email' => $email,
            'reference_number' => $referenceNumber,
            'response_url' => $responseUrl,
            'redirect_url' => $redirectUrl,
            'cancel_url' => $cancelUrl,
        ];

        $response = $this->request()->post('/payment-requests', $body);

        if ($response->status() === 429) {
            usleep(750_000);
            $response = $this->request()->post('/payment-requests', $body);
        }

        return KurvPaymentRequestReply::fromArray($response->throw()->json());
    }

    /**
     * GET /payment-requests/{id}. Null when the reply has no readable transaction.
     * Throws RequestException on 4xx/5xx (an unknown id is a 422) and ConnectionException.
     */
    public function getPaymentRequest(string $requestId): ?KurvLookupReply
    {
        $response = $this->request(lookup: true)->get('/payment-requests/'.rawurlencode($requestId))->throw();

        return KurvLookupReply::fromRequestBody($response->json());
    }

    /**
     * GET /payments?keywords=... Rows of the payment history. Rows for an older payment
     * with the same reference come back too, so the caller must match on the transaction id.
     *
     * @return list<KurvLookupReply>
     */
    public function searchPayments(string $keywords): array
    {
        $json = $this->request(lookup: true)
            ->get('/payments', ['keywords' => $keywords, 'limit' => 50])
            ->throw()
            ->json();

        $rows = is_array($json['payments'] ?? null) ? $json['payments'] : [];

        return array_values(array_map(
            fn (array $row) => KurvLookupReply::fromPaymentRow($row),
            array_filter($rows, 'is_array'),
        ));
    }

    private function request(bool $lookup = false): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('services.kurv.connect_timeout', 10))
            ->timeout((int) config($lookup ? 'services.kurv.lookup_timeout' : 'services.kurv.timeout', $lookup ? 10 : 30));
    }

    private function baseUrl(): string
    {
        $key = $this->environment === 'production' ? 'production' : 'sandbox';

        return rtrim((string) config("services.kurv.hosts.{$key}"), '/');
    }
}
