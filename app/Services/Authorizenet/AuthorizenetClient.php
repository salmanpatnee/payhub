<?php

namespace App\Services\Authorizenet;

use App\Enums\AuthorizenetEnvironment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Authorize.net JSON API for one merchant.
 *
 * Built per AuthorizenetAccount with that account's decrypted credentials,
 * never a global client. Resolve via
 * app()->make(AuthorizenetClient::class, ['apiLoginId' => ..., 'transactionKey' => ..., 'environment' => ...])
 * so tests can fake the HTTP layer (Http::fake).
 *
 * PROVISIONAL (spec 0006, AC-12): the request and response field names here
 * come from documentation, not a verified sandbox. The sandbox spike must
 * confirm them before later build steps rely on them; keep every field name
 * in this class so a correction lands in one place.
 *
 * Lookups deliberately do NOT ->throw() on a non-2xx so the caller can
 * classify the result instead of every failure collapsing into "transport
 * failed". A transport failure (timeout, DNS) still throws.
 */
class AuthorizenetClient
{
    public function __construct(
        private readonly string $apiLoginId,
        private readonly string $transactionKey,
        private readonly AuthorizenetEnvironment $environment = AuthorizenetEnvironment::Sandbox,
    ) {}

    /**
     * One authenticated read call, used by "Test connection". Never touches a payment.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function getMerchantDetails(): array
    {
        return $this->send(['getMerchantDetailsRequest' => [
            'merchantAuthentication' => $this->authentication(),
        ]]);
    }

    /**
     * True when the response is an authenticated success.
     *
     * @param  array{status: int, body: array<string, mixed>}  $result
     */
    public static function isOk(array $result): bool
    {
        return $result['status'] === 200
            && ($result['body']['messages']['resultCode'] ?? null) === 'Ok';
    }

    /**
     * @return array<string, string>
     */
    private function authentication(): array
    {
        return ['name' => $this->apiLoginId, 'transactionKey' => $this->transactionKey];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function send(array $payload): array
    {
        $response = $this->request()->post('/xml/v1/request.api', $payload);

        // The gateway prefixes its JSON with a UTF-8 BOM, which breaks json_decode.
        $body = json_decode(ltrim($response->body(), "\xEF\xBB\xBF"), true);

        return [
            'status' => $response->status(),
            'body' => is_array($body) ? $body : [],
        ];
    }

    private function request(): PendingRequest
    {
        $host = config("services.authorizenet.hosts.{$this->environment->value}.api");

        return Http::baseUrl($host)->acceptJson()->asJson()->timeout(15);
    }
}
