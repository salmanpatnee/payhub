<?php

namespace App\Services\Whop;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Whop API for a single company (spec 0009).
 *
 * Build one per WhopAccount with that account's decrypted key, never a global
 * credential: app()->make(WhopClient::class, ['apiKey' => ..., 'companyId' => ..., 'environment' => ...]).
 *
 * UNVERIFIED until the step 0 sandbox check (Open questions 2, 9 and 10): paths and
 * field names below follow Whop's public docs. This class and WhopWebhookPayload are
 * the only places that know them. The charge call is added in build plan step 5.
 */
class WhopClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $companyId,
        private readonly string $environment = 'sandbox',
    ) {}

    /**
     * One read only call used by Test connection. Returns the HTTP status so the
     * caller can map 401, 403 and 404 to plain words.
     */
    public function companyStatus(): int
    {
        return $this->request()->get('/companies/'.rawurlencode($this->companyId))->status();
    }

    /**
     * Create a webhook for this company. The reply holds the `ws_` signing secret
     * (Open question 10: whether it is shown only once).
     *
     * @param  list<string>  $events
     * @return array<string, mixed>
     */
    public function createWebhook(string $url, array $events): array
    {
        return $this->request()->post('/webhooks', [
            'url' => $url,
            'events' => $events,
        ])->throw()->json();
    }

    /**
     * Create the one checkout configuration for a payment (spec 0009, AC-5).
     * Whop's current API takes an inline `variant` (a priced, hidden plan) and the company as
     * `account_id`; the older inline `plan` shape is refused ("variant_id is required for payment mode").
     * Amount is Whop's decimal built from integer cents; currency is lowercase.
     * Throws RequestException on 4xx/5xx and ConnectionException on timeout.
     *
     * @param  array<string, string>  $metadata
     * @return array{id: string, purchase_path: string}
     */
    public function createCheckoutConfiguration(
        int $amountCents,
        string $currency,
        string $planTitle,
        array $metadata,
        string $redirectUrl,
    ): array {
        $reply = $this->request()->post('/checkout_configurations', [
            'mode' => 'payment',
            'account_id' => $this->companyId,
            'variant' => [
                'title' => $planTitle,
                'billing_type' => 'one_time',
                'release_method' => 'buy_now',
                // One off price for one payment: keep it out of the company's store.
                'visibility' => 'hidden',
                'currency' => strtolower($currency),
                // Whop wants a JSON number in dollars; the string comes from integer math.
                'initial_price' => (float) WhopAmount::centsToDecimal($amountCents),
            ],
            'metadata' => $metadata,
            'redirect_url' => $redirectUrl,
        ])->throw()->json();

        $id = is_array($reply) ? ($reply['id'] ?? null) : null;
        $path = is_array($reply) ? ($reply['purchase_url'] ?? null) : null;

        if (! is_string($id) || ! str_starts_with($id, 'ch_') || ! is_string($path) || $path === '') {
            throw new \UnexpectedValueException('Unreadable Whop checkout configuration reply.');
        }

        return ['id' => $id, 'purchase_path' => $path];
    }

    /** Full hosted checkout URL: the environment's checkout host plus the path Whop returned. */
    public function purchaseUrl(string $purchasePath): string
    {
        if (str_starts_with($purchasePath, 'https://')) {
            return $purchasePath;
        }

        $key = $this->environment === 'production' ? 'production' : 'sandbox';

        return rtrim((string) config("services.whop.hosts.{$key}.checkout"), '/').'/'.ltrim($purchasePath, '/');
    }

    /**
     * Pull the signing secret and webhook id out of a createWebhook reply.
     * UNVERIFIED field names (Open question 10).
     *
     * @param  array<string, mixed>  $reply
     * @return array{id: ?string, secret: ?string}
     */
    public static function readWebhookReply(array $reply): array
    {
        $secret = $reply['webhook_secret'] ?? $reply['secret'] ?? null;
        $id = $reply['id'] ?? null;

        return [
            'id' => is_string($id) ? $id : null,
            'secret' => is_string($secret) && $secret !== '' ? $secret : null,
        ];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('services.whop.connect_timeout', 10))
            ->timeout((int) config('services.whop.timeout', 30));
    }

    private function baseUrl(): string
    {
        $key = $this->environment === 'production' ? 'production' : 'sandbox';

        return rtrim((string) config("services.whop.hosts.{$key}.api"), '/');
    }
}
