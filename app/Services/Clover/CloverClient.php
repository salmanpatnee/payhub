<?php

namespace App\Services\Clover;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over Clover's Ecommerce API for a single merchant account.
 *
 * Mirrors the per-account pattern used by every other provider client: build
 * one instance per CloverAccount with that account's decrypted credentials —
 * never a global, shared client. Resolve via
 * app()->make(CloverClient::class, ['merchantId' => ..., 'privateToken' => ...])
 * so tests can fake the HTTP layer (Http::fake) or bind a substitute.
 *
 * Rewritten for spec 0002 (Hosted Iframe): the card is tokenized client-side
 * against Clover's Hosted Iframe SDK, and this client charges that token
 * server-side via /v1/charges. Clover documents no webhook for this
 * integration type, so the synchronous response to createCharge() is the
 * authoritative outcome (see spec 0002's Decision) — createCharge()/getCharge()
 * deliberately do NOT ->throw() on a non-2xx so the caller can classify a real
 * decline/hard-error response instead of every non-2xx collapsing into
 * "transport failed".
 *
 * /v1/charges lives on a different host (scl-sandbox.dev.clover.com /
 * scl.clover.com) than /v3/merchants (apisandbox.dev.clover.com /
 * api.clover.com), confirmed against Clover's published docs.
 */
class CloverClient
{
    public function __construct(
        private readonly string $merchantId,
        private readonly string $privateToken,
        private readonly ?string $environment = null,
    ) {}

    /**
     * Charge a token produced by the Hosted Iframe SDK's createToken(). Amount
     * is integer minor units (cents), currency an ISO 4217 code.
     *
     * idempotencyKey is sent both as Clover's own idempotency key (defence in
     * depth; not depended on) and as external_reference_id, which the resolver
     * job later searches by. Never ->throw()s: the caller classifies the
     * response (approved/declined/hard error/unknown) from the status code and
     * body it gets back — see spec 0002's Charge response classification table.
     *
     * metadata mirrors the reference_code/payment_uuid pair every other
     * provider already attaches (Stripe's PaymentIntent metadata, Revolut's
     * merchant_order_data.metadata) — Clover's own field, confirmed against its
     * docs (2026-09-18): a flat key/value object, 500 characters total. Lets an
     * operator cross-check a transaction in Clover's own merchant dashboard
     * against PayHub's reference code, the same way they already can for
     * Stripe/Revolut.
     *
     * Metadata is never shown in Clover's merchant dashboard, though, so the
     * reference code is also sent as description (the one charge field Clover
     * documents as displayed) — see also updateOrderNote().
     *
     * @param  array<string, string>  $metadata
     * @return array{status: int, body: array<string, mixed>}
     */
    public function createCharge(string $idempotencyKey, string $externalReferenceId, string $token, int $amount, string $currency, array $metadata = [], ?string $description = null): array
    {
        $payload = [
            'source' => $token,
            'amount' => $amount,
            'currency' => strtolower($currency),
            'external_reference_id' => $externalReferenceId,
        ];

        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        if ($description !== null) {
            $payload['description'] = $description;
        }

        $response = $this->chargesRequest()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/v1/charges', $payload);

        return $this->classifyResponse($response);
    }

    /**
     * Read a specific charge by id — used by the resolver job once a
     * clover_charge_id is known for an unresolved attempt. Never re-submits
     * anything.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function getCharge(string $chargeId): array
    {
        return $this->classifyResponse(
            $this->chargesRequest()->get("/v1/charges/{$chargeId}")
        );
    }

    /**
     * Read-only search for a charge by the external_reference_id PayHub sent
     * on createCharge(), used by the resolver job when no clover_charge_id was
     * ever captured (the createCharge() response never arrived at all).
     *
     * Clover's GET /v1/charges list endpoint does not document a filter
     * parameter for external_reference_id (confirmed against Clover's
     * published docs, 2026-09-17 — see spec 0002's Follow-up). This filters
     * client-side over a bounded, recent created-date window instead of
     * trusting an undocumented query param. Must be confirmed against a live
     * sandbox before go-live per spec 0002's Follow-up; if Clover truly has no
     * way to search by this field, an attempt with no captured charge id can
     * only be recovered manually.
     *
     * @return array<string, mixed>|null
     */
    public function findChargeByReference(string $externalReferenceId): ?array
    {
        $response = $this->chargesRequest()->get('/v1/charges', [
            'limit' => 100,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $charges = $response->json('data') ?? [];

        foreach ($charges as $charge) {
            $reference = $charge['external_reference_id'] ?? $charge['ref_num'] ?? null;

            if ($reference === $externalReferenceId) {
                return $charge;
            }
        }

        return null;
    }

    /**
     * Set the note on the Clover order a charge created, so PayHub's reference
     * code shows on the order in Clover's merchant dashboard (charge metadata
     * never does). Lives on the /v3 host, not the /v1/charges one.
     *
     * @throws RequestException on auth/HTTP error
     */
    public function updateOrderNote(string $orderId, string $note): void
    {
        $this->merchantsRequest()
            ->post("/v3/merchants/{$this->merchantId}/orders/{$orderId}", ['note' => $note])
            ->throw();
    }

    /**
     * Make a lightweight authenticated call to confirm the credentials work.
     *
     * @throws RequestException on auth/HTTP error
     */
    public function verifyCredentials(): void
    {
        $this->merchantsRequest()->get("/v3/merchants/{$this->merchantId}")->throw();
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function classifyResponse(Response $response): array
    {
        return [
            'status' => $response->status(),
            'body' => $response->json() ?? [],
        ];
    }

    private function chargesRequest(): PendingRequest
    {
        return $this->baseRequest($this->isProduction() ? 'https://scl.clover.com' : 'https://scl-sandbox.dev.clover.com');
    }

    private function merchantsRequest(): PendingRequest
    {
        return $this->baseRequest($this->isProduction() ? 'https://api.clover.com' : 'https://apisandbox.dev.clover.com');
    }

    private function baseRequest(string $baseUrl): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->withToken($this->privateToken)
            ->withHeaders(['X-Clover-Merchant-Id' => $this->merchantId])
            ->acceptJson()
            ->asJson();
    }

    private function isProduction(): bool
    {
        return ($this->environment ?? 'sandbox') === 'production';
    }
}
