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
 * Field names were partly confirmed in the 2026-10-21 sandbox spike (spec 0006,
 * `## Follow-up`); the createTransaction ones come from documentation (spec 0007
 * Open questions). Keep every gateway field name in this class so a
 * correction lands in one place.
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
     * Charge a one time Accept.js code (`opaqueData`) with authCapture. The data
     * value, and this request body, must never be stored or logged by the caller.
     * Does not ->throw(): the caller maps the reply; a transport failure still throws.
     *
     * @param  array<string, mixed>  $order  {invoice, description, amount (decimal string), data_descriptor, data_value, email, first_name, last_name, zip, ip, duplicate_window}
     * @return array{status: int, body: array<string, mixed>}
     */
    public function createTransaction(array $order): array
    {
        // Element order matters to the gateway (its schema is a sequence).
        $transaction = [
            'transactionType' => 'authCaptureTransaction',
            'amount' => $order['amount'],
            'payment' => ['opaqueData' => [
                'dataDescriptor' => $order['data_descriptor'],
                'dataValue' => $order['data_value'],
            ]],
            'order' => ['invoiceNumber' => $order['invoice'], 'description' => $order['description']],
            'customer' => ['email' => $order['email']],
            'billTo' => ['firstName' => $order['first_name'], 'lastName' => $order['last_name'], 'zip' => $order['zip']],
            'customerIP' => $order['ip'],
            'transactionSettings' => ['setting' => [
                ['settingName' => 'duplicateWindow', 'settingValue' => (string) $order['duplicate_window']],
            ]],
        ];

        return $this->send(['createTransactionRequest' => [
            'merchantAuthentication' => $this->authentication(),
            'refId' => $order['invoice'],
            'transactionRequest' => $transaction,
        ]], 30);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function getTransactionDetails(string $transactionId): array
    {
        return $this->send(['getTransactionDetailsRequest' => [
            'merchantAuthentication' => $this->authentication(),
            'transId' => $transactionId,
        ]]);
    }

    /**
     * One page of the unsettled transaction list, newest first. $offset is 1 based.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function getUnsettledTransactionList(int $limit = 100, int $offset = 1): array
    {
        return $this->send(['getUnsettledTransactionListRequest' => [
            'merchantAuthentication' => $this->authentication(),
            'sorting' => ['orderBy' => 'submitTimeUTC', 'orderDescending' => true],
            'paging' => ['limit' => $limit, 'offset' => $offset],
        ]]);
    }

    /**
     * The account's public client key from a getMerchantDetails result, or null
     * when the merchant has not generated one. A public identifier, not a secret.
     *
     * @param  array{status: int, body: array<string, mixed>}  $result
     */
    public static function publicClientKey(array $result): ?string
    {
        $key = $result['body']['publicClientKey'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
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
    private function send(array $payload, int $timeout = 15): array
    {
        $response = $this->request($timeout)->post('/xml/v1/request.api', $payload);

        // The gateway prefixes its JSON with a UTF-8 BOM, which breaks json_decode.
        $body = json_decode(ltrim($response->body(), "\xEF\xBB\xBF"), true);

        return [
            'status' => $response->status(),
            'body' => is_array($body) ? $body : [],
        ];
    }

    private function request(int $timeout): PendingRequest
    {
        $host = config("services.authorizenet.hosts.{$this->environment->value}.api");

        return Http::baseUrl($host)->acceptJson()->asJson()->timeout($timeout);
    }
}
