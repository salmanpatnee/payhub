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
 * Field names were confirmed in the 2026-10-21 sandbox spike (spec 0006,
 * `## Follow-up`); keep every gateway field name in this class so a
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
     * Create a one time hosted payment page token. The token is returned in the
     * body (`token`) and must never be stored or logged by the caller.
     *
     * @param  array<string, mixed>  $order  {invoice, amount (decimal string), email, first_name, last_name, button_text, communicator_url}
     * @return array{status: int, body: array<string, mixed>}
     */
    public function getHostedPaymentPage(array $order): array
    {
        $settings = [
            'hostedPaymentReturnOptions' => ['showReceipt' => false],
            'hostedPaymentButtonOptions' => ['text' => $order['button_text']],
            'hostedPaymentPaymentOptions' => ['cardCodeRequired' => true, 'showCreditCard' => true, 'showBankAccount' => false],
            'hostedPaymentBillingAddressOptions' => ['show' => false, 'required' => false],
            'hostedPaymentShippingAddressOptions' => ['show' => false, 'required' => false],
            'hostedPaymentCustomerOptions' => ['showEmail' => false, 'requiredEmail' => false, 'addPaymentProfile' => false],
            'hostedPaymentOrderOptions' => ['show' => false],
            'hostedPaymentStyleOptions' => ['bgColor' => 'ffffff'],
            'hostedPaymentIFrameCommunicatorUrl' => ['url' => $order['communicator_url']],
        ];

        return $this->send(['getHostedPaymentPageRequest' => [
            'merchantAuthentication' => $this->authentication(),
            'refId' => $order['invoice'],
            'transactionRequest' => [
                'transactionType' => 'authCaptureTransaction',
                'amount' => $order['amount'],
                'order' => ['invoiceNumber' => $order['invoice']],
                'customer' => ['email' => $order['email']],
                'billTo' => ['firstName' => $order['first_name'], 'lastName' => $order['last_name']],
            ],
            'hostedPaymentSettings' => ['setting' => array_map(
                fn (string $name, array $value) => ['settingName' => $name, 'settingValue' => json_encode($value)],
                array_keys($settings),
                array_values($settings),
            )],
        ]]);
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
