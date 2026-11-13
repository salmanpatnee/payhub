<?php

namespace App\Services\Nmi;

use App\Models\NmiAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The only class that talks to NMI (spec 0012): the v5 sale call and the Classic `query.php`
 * lookup. One instance per account, built with that account's private key and host. The private
 * key lives only here and is never logged. Tests fake the HTTP layer with Http::fake.
 *
 * A transport failure (timeout, DNS) throws ConnectionException; the caller treats that as unclear.
 */
class NmiClient
{
    public function __construct(
        private readonly string $privateKey,
        private readonly string $host,
    ) {}

    /** A client for one account: its private key and its environment's host (config, never typed in). */
    public static function forAccount(NmiAccount $account): self
    {
        return new self(
            (string) $account->private_key,
            (string) config("services.nmi.hosts.{$account->environment->value}"),
        );
    }

    /**
     * Charges a one time token. `$sale` already holds NMI's field names (the controller builds
     * it from the Payment record). The token and the body are never stored or logged.
     *
     * @param  array<string, mixed>  $sale
     * @return array{status: int, body: array<string, mixed>}
     */
    public function sale(array $sale): array
    {
        $response = $this->request((int) config('services.nmi.timeout', 30))
            ->withHeaders(['Authorization' => $this->privateKey])
            ->asJson()
            ->acceptJson()
            ->post('/api/v5/payments/sale', $sale);

        $body = $response->json();

        return ['status' => $response->status(), 'body' => is_array($body) ? $body : []];
    }

    public function queryByOrderId(string $orderId): NmiQueryReply
    {
        return $this->query(['order_id' => $orderId]);
    }

    public function queryByTransactionId(string $transactionId): NmiQueryReply
    {
        return $this->query(['transaction_id' => $transactionId]);
    }

    /**
     * Test connection (AC-2): one read only lookup of an order id that cannot exist. A good key
     * gives an empty reply, a bad key gives NMI's own error.
     *
     * @return array{ok: bool, message: string}
     */
    public function ping(): array
    {
        $reply = $this->queryByOrderId('payhub-connection-test-'.bin2hex(random_bytes(6)));

        return $reply->ok
            ? ['ok' => true, 'message' => 'Connected']
            : ['ok' => false, 'message' => $reply->error ?: 'NMI did not accept these details'];
    }

    /**
     * @param  array<string, string>  $criteria
     */
    private function query(array $criteria): NmiQueryReply
    {
        $response = $this->request((int) config('services.nmi.lookup_timeout', 10))
            ->asForm()
            ->post('/api/query.php', ['security_key' => $this->privateKey] + $criteria);

        if ($response->serverError()) {
            return NmiQueryReply::failed('NMI is not answering');
        }

        return NmiQueryReply::fromXml($response->body());
    }

    private function request(int $timeout): PendingRequest
    {
        return Http::baseUrl(rtrim($this->host, '/'))
            ->connectTimeout((int) config('services.nmi.connect_timeout', 10))
            ->timeout($timeout);
    }
}
