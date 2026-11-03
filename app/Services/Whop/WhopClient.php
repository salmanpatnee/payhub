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
