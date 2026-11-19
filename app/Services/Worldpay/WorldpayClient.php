<?php

namespace App\Services\Worldpay;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Thin wrapper over Access Worldpay for one account (spec 0014).
 *
 * Build one per WorldpayAccount with that account's decrypted login, never a global
 * credential: app()->make(WorldpayClient::class, ['username' => ..., 'password' => ..., 'environment' => ...]).
 *
 * This class and the two reply classes are the only places that know Worldpay request and
 * reply field names. Paths and fields were checked against the Worldpay Try environment on
 * 2026-11-17 (spec 0014, Step 0).
 */
class WorldpayClient
{
    private const PAGES_TYPE = 'application/vnd.worldpay.payment_pages-v1.hal+json';

    private const QUERIES_TYPE = 'application/vnd.worldpay.payment-queries-v1.hal+json';

    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $environment = 'try',
    ) {}

    /**
     * One read only call used by Test connection. Returns the HTTP status so the caller can map
     * 401 (wrong login) and 403 (Payment Queries not allowed) to plain words. Creates nothing.
     */
    public function ping(): int
    {
        return $this->queriesRequest()
            ->get('/paymentQueries/payments', ['transactionReference' => 'payhub-test-'.Str::lower(Str::random(12))])
            ->status();
    }

    /**
     * Create one hosted payment page (spec 0014, AC-6). A 429 is retried once after a short wait.
     * Throws RequestException on 4xx/5xx, ConnectionException on timeout and
     * UnexpectedValueException on an unreadable reply.
     *
     * @param  array<string, string>  $resultUrls  keys successURL, pendingURL, failureURL, errorURL, cancelURL, expiryURL
     */
    public function createPage(
        string $entity,
        string $transactionReference,
        string $narrative,
        int $amountMinorUnits,
        string $currency,
        int $expirySeconds,
        array $resultUrls,
    ): WorldpayPageReply {
        $body = [
            'transactionReference' => $transactionReference,
            'merchant' => ['entity' => $entity],
            'narrative' => ['line1' => $narrative],
            'value' => ['amount' => $amountMinorUnits, 'currency' => strtoupper($currency)],
            'expiry' => $expirySeconds,
            'resultURLs' => $resultUrls,
        ];

        // Hosted page options (HPP API, hostedProperties). The billing address mode is SHOW, HIDE or EDIT.
        // Left out when the config value is empty, so the account's own Worldpay setting applies.
        $billing = strtoupper((string) config('services.worldpay.show_billing_address', ''));

        if (in_array($billing, ['SHOW', 'HIDE', 'EDIT'], true)) {
            $body['hostedProperties'] = ['showBillingAddress' => $billing];
        }

        $response = $this->pagesRequest()->post('/payment_pages', $body);

        if ($response->status() === 429) {
            usleep(750_000);
            $response = $this->pagesRequest()->post('/payment_pages', $body);
        }

        return WorldpayPageReply::fromArray($response->throw()->json());
    }

    /**
     * Payment rows Worldpay holds for one transaction reference. An empty list means no payment
     * was started on that page yet. Throws RequestException on 4xx/5xx and ConnectionException.
     *
     * @return list<WorldpayLookupReply>
     */
    public function queryPayments(string $transactionReference): array
    {
        $response = $this->queriesRequest()
            ->get('/paymentQueries/payments', ['transactionReference' => $transactionReference])
            ->throw();

        return WorldpayLookupReply::rowsFromBody($response->json());
    }

    private function pagesRequest(): PendingRequest
    {
        return $this->base(self::PAGES_TYPE)
            ->asJson()
            ->contentType(self::PAGES_TYPE)
            ->timeout((int) config('services.worldpay.timeout', 30));
    }

    private function queriesRequest(): PendingRequest
    {
        return $this->base(self::QUERIES_TYPE)
            ->timeout((int) config('services.worldpay.lookup_timeout', 10));
    }

    private function base(string $accept): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withBasicAuth($this->username, $this->password)
            ->withHeaders(['Accept' => $accept])
            ->connectTimeout((int) config('services.worldpay.connect_timeout', 10));
    }

    private function baseUrl(): string
    {
        $key = $this->environment === 'live' ? 'live' : 'try';

        return rtrim((string) config("services.worldpay.hosts.{$key}"), '/');
    }
}
