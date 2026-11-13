<?php

namespace App\Services\Nmi;

/**
 * Maps a v5 sale reply to one outcome (spec 0012, AC-8, `Reply mapping`). Pure: with NmiQueryReply
 * the only place NMI's field names are read. `approved` only means "the reply says so": the
 * controller still reads the transaction back through the resolver before any status changes.
 */
final class NmiSaleReply
{
    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    public const CHECK_DETAILS = 'check_details';

    public const UNCLEAR = 'unclear';

    public const ERROR = 'error';

    /** Decline codes that mean the typed card details were wrong (NMI response codes, to confirm in the sandbox). */
    private const CHECK_DETAIL_CODES = [220, 221, 222, 223, 224, 225];

    /** Processor codes that mean "we do not know if it was charged". */
    private const UNCLEAR_CODES = [420, 421, 430];

    /** Plain reasons for the codes PayHub sees most. Operators only. */
    private const REASONS = [
        200 => 'Card declined by the bank',
        201 => 'Bank refused (do not honor)',
        202 => 'Insufficient funds',
        203 => 'Over the card limit',
        204 => 'Transaction not allowed on this card',
        220 => 'Card details are not valid',
        221 => 'Card issuer not found',
        222 => 'Card number missing',
        223 => 'Card has expired',
        224 => 'Card expiry date is not valid',
        225 => 'Card security code did not match',
        240 => 'Bank asked to be called',
        250 => 'Card reported as pick up',
        251 => 'Card reported lost',
        252 => 'Card reported stolen',
        253 => 'Card reported as fraud',
        300 => 'Rejected by the gateway',
        400 => 'Processor returned an error',
        410 => 'Merchant configuration is not valid',
        411 => 'Merchant account is not active',
        420 => 'Communication error with the processor',
        421 => 'Communication error with the card issuer',
        430 => 'Duplicate transaction at the processor',
    ];

    /** The fixed words the pay page shows (never gateway text). */
    private const MESSAGES = [
        self::APPROVED => 'Payment received.',
        self::DECLINED => 'Your card was declined. Please try a different card.',
        self::CHECK_DETAILS => 'Please check your card details and try again.',
        self::UNCLEAR => 'We are confirming your payment. Please do not pay again.',
        self::ERROR => 'Your payment could not be processed. Please try again or use a different card.',
    ];

    /**
     * @param  array{response_code?: ?string, avs?: ?string, cvv?: ?string, error_code?: ?string, error_message?: ?string}  $detail  what the gateway said, for the attempt record
     */
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $transactionId = null,
        public readonly array $detail = [],
    ) {}

    /**
     * @param  array{status: int, body: array<string, mixed>}  $result
     */
    public static function fromResult(array $result): self
    {
        $body = $result['body'];

        if ($result['status'] >= 500 || $body === []) {
            return new self(self::UNCLEAR);
        }

        // The request itself was rejected (HTTP 400 and friends): type, error_code, message.
        if (isset($body['error_code'])) {
            $code = (string) $body['error_code'];
            $detail = [
                'error_code' => $code,
                'error_message' => is_string($body['message'] ?? null) ? $body['message'] : null,
            ];

            // A duplicate means NMI saw an identical sale a moment ago, so one may exist.
            return $code === 'E_DUPLICATE' ? new self(self::UNCLEAR, null, $detail) : new self(self::ERROR, null, $detail);
        }

        $code = isset($body['response_code']) ? (int) $body['response_code'] : null;
        $id = isset($body['id']) && (string) $body['id'] !== '' ? (string) $body['id'] : null;
        $detail = [
            'response_code' => $code !== null ? (string) $code : null,
            'avs' => self::letter($body['avs_response'] ?? null),
            'cvv' => self::letter($body['cvv_response'] ?? null),
        ];

        if ($code === 100) {
            // Approved needs a transaction id to read back; without one nothing can be confirmed.
            return $id !== null ? new self(self::APPROVED, $id, $detail) : new self(self::UNCLEAR, null, $detail);
        }

        if ($code === null) {
            return new self(self::UNCLEAR, $id, $detail);
        }

        if (in_array($code, self::UNCLEAR_CODES, true)) {
            return new self(self::UNCLEAR, $id, $detail);
        }

        if ($code >= 200 && $code < 300) {
            return new self(in_array($code, self::CHECK_DETAIL_CODES, true) ? self::CHECK_DETAILS : self::DECLINED, $id, $detail);
        }

        return new self(self::ERROR, $id, $detail);
    }

    public static function unclear(): self
    {
        return new self(self::UNCLEAR);
    }

    public function withOutcome(string $outcome): self
    {
        return new self($outcome, $this->transactionId, $this->detail);
    }

    public function asUnclear(): self
    {
        return $this->withOutcome(self::UNCLEAR);
    }

    /** A plain operator reason for the attempt row. Never shown to the client. */
    public function reason(): ?string
    {
        $code = isset($this->detail['response_code']) ? (int) $this->detail['response_code'] : null;

        if ($code !== null && isset(self::REASONS[$code])) {
            return self::REASONS[$code];
        }

        if (isset($this->detail['error_code'])) {
            return match ($this->detail['error_code']) {
                'E_DUPLICATE' => 'Duplicate sale blocked by NMI',
                'E_INVALID_SUBMISSION' => 'NMI rejected the request fields',
                'E_GENERIC_ERROR' => 'NMI rejected the request',
                // NMI's own message is for operators only (admin attempts panel); it holds no secrets.
                default => trim('NMI error '.$this->detail['error_code'].': '.($this->detail['error_message'] ?? ''), ': '),
            };
        }

        return $this->outcome === self::UNCLEAR ? 'No clear answer from NMI' : null;
    }

    public static function message(string $outcome): string
    {
        return self::MESSAGES[$outcome] ?? self::MESSAGES[self::ERROR];
    }

    private static function letter(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 8) : null;
    }
}
