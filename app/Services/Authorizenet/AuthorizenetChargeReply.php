<?php

namespace App\Services\Authorizenet;

/**
 * Maps a createTransaction reply to one outcome (spec 0007, AC-7, `Reply mapping`).
 * Pure: the only place the charge reply's field names are read, so a spike
 * correction lands here. `approved` and `held` only mean "the reply says so";
 * the controller still reads the transaction back before trusting either.
 */
final class AuthorizenetChargeReply
{
    public const APPROVED = 'approved';

    public const HELD = 'held';

    public const DECLINED = 'declined';

    public const CHECK_DETAILS = 'check_details';

    public const UNCLEAR = 'unclear';

    public const ERROR = 'error';

    /** Decline error codes that mean the typed card details were wrong. */
    private const CARD_DETAIL_CODES = ['6', '7', '8', '27', '44', '45', '65'];

    private const DUPLICATE_CODE = '11';

    /** Plain-language reasons for the error codes PayHub sees most. Operators only. */
    private const REASONS = [
        '2' => 'Card declined by the bank',
        '6' => 'Card number is not valid',
        '7' => 'Card expiry date is not valid',
        '8' => 'Card has expired',
        '11' => 'Duplicate charge blocked by the gateway',
        '27' => 'Address (ZIP) did not match',
        '44' => 'Card security code did not match',
        '45' => 'Card security code and address did not match',
        '65' => 'Card security code declined',
    ];

    /**
     * @param  array{response_code?: ?string, error_text?: ?string, avs?: ?string, cvv?: ?string, cavv?: ?string}  $detail  what the gateway said, for the attempt record
     */
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $transactionId = null,
        public readonly ?string $gatewayCode = null,
        public readonly array $detail = [],
    ) {}

    /**
     * @param  array{status: int, body: array<string, mixed>}  $result
     */
    public static function fromResult(array $result): self
    {
        $body = $result['body'];
        $response = $body['transactionResponse'] ?? null;

        if ($result['status'] >= 500 || $body === []) {
            return new self(self::UNCLEAR);
        }

        if (! is_array($response)) {
            // The request itself was rejected (bad credentials, bad field, ...).
            $code = $body['messages']['message'][0]['code'] ?? null;

            return ($body['messages']['resultCode'] ?? null) === 'Error'
                ? new self(self::ERROR, null, is_string($code) ? $code : null)
                : new self(self::UNCLEAR);
        }

        $responseCode = (string) ($response['responseCode'] ?? '');
        $transactionId = (string) ($response['transId'] ?? '');
        $transactionId = $transactionId !== '' && $transactionId !== '0' ? $transactionId : null;
        $errorCode = isset($response['errors'][0]['errorCode']) ? (string) $response['errors'][0]['errorCode'] : null;
        $detail = [
            'response_code' => $responseCode !== '' ? $responseCode : null,
            'error_text' => is_string($response['errors'][0]['errorText'] ?? null) ? $response['errors'][0]['errorText'] : null,
            'avs' => self::letter($response['avsResultCode'] ?? null),
            'cvv' => self::letter($response['cvvResultCode'] ?? null),
            'cavv' => self::letter($response['cavvResultCode'] ?? null),
        ];

        return match ($responseCode) {
            '1' => new self($transactionId ? self::APPROVED : self::UNCLEAR, $transactionId, $errorCode, $detail),
            '4' => new self($transactionId ? self::HELD : self::UNCLEAR, $transactionId, $errorCode, $detail),
            '2' => new self(in_array($errorCode, self::CARD_DETAIL_CODES, true) ? self::CHECK_DETAILS : self::DECLINED, $transactionId, $errorCode, $detail),
            // Sandbox spike: a bad card number (6) or expired card (8) comes back as 3, not 2.
            '3' => new self(match (true) {
                $errorCode === self::DUPLICATE_CODE => self::UNCLEAR,
                in_array($errorCode, self::CARD_DETAIL_CODES, true) => self::CHECK_DETAILS,
                default => self::ERROR,
            }, $transactionId, $errorCode, $detail),
            default => new self(self::UNCLEAR, $transactionId, $errorCode, $detail),
        };
    }

    /**
     * A short plain-language reason for the attempt record. Operators only, never shown
     * to the client (the client sees the fixed messages below).
     */
    public function reason(): ?string
    {
        $code = $this->gatewayCode;
        $text = $this->detail['error_text'] ?? null;

        return match (true) {
            $this->outcome === self::HELD => 'Held for fraud review',
            $this->outcome === self::UNCLEAR => 'No clear answer from the gateway; PayHub checks again',
            $code === '2' && ($this->detail['cvv'] ?? null) === 'N' => 'Card security code did not match',
            $code === '2' && ($this->detail['avs'] ?? null) === 'N' => 'Address did not match',
            $code !== null && isset(self::REASONS[$code]) => self::REASONS[$code],
            is_string($text) && $text !== '' => mb_substr($text, 0, 255),
            $code !== null => "Gateway error {$code}",
            default => null,
        };
    }

    private static function letter(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 8) : null;
    }

    /** A timeout, connection error or unreadable reply: PayHub cannot tell if the card was charged. */
    public static function unclear(): self
    {
        return new self(self::UNCLEAR);
    }

    public function withOutcome(string $outcome): self
    {
        return new self($outcome, $this->transactionId, $this->gatewayCode, $this->detail);
    }

    /** The reply said approved or held but the read back did not confirm it. */
    public function asUnclear(): self
    {
        return new self(self::UNCLEAR, $this->transactionId, $this->gatewayCode, $this->detail);
    }

    /** Fixed client strings (AC-10); the gateway's own text is never shown. */
    public static function message(string $outcome): string
    {
        return match ($outcome) {
            self::APPROVED => 'Payment received.',
            self::HELD => 'Your payment is being processed and needs a quick review. You will get a receipt by email once it is confirmed.',
            self::DECLINED => 'Your card was declined. Please try a different card.',
            self::CHECK_DETAILS => 'Please check your card details and try again.',
            self::UNCLEAR => 'We are confirming your payment. Please do not pay again.',
            default => 'Your payment could not be processed. Please try again or use a different card.',
        };
    }
}
