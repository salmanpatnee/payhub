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

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $transactionId = null,
        public readonly ?string $gatewayCode = null,
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

        return match ($responseCode) {
            '1' => new self($transactionId ? self::APPROVED : self::UNCLEAR, $transactionId, $errorCode),
            '4' => new self($transactionId ? self::HELD : self::UNCLEAR, $transactionId, $errorCode),
            '2' => new self(in_array($errorCode, self::CARD_DETAIL_CODES, true) ? self::CHECK_DETAILS : self::DECLINED, $transactionId, $errorCode),
            '3' => new self($errorCode === self::DUPLICATE_CODE ? self::UNCLEAR : self::ERROR, $transactionId, $errorCode),
            default => new self(self::UNCLEAR, $transactionId, $errorCode),
        };
    }

    /** A timeout, connection error or unreadable reply: PayHub cannot tell if the card was charged. */
    public static function unclear(): self
    {
        return new self(self::UNCLEAR);
    }

    public function withOutcome(string $outcome): self
    {
        return new self($outcome, $this->transactionId, $this->gatewayCode);
    }

    /** The reply said approved or held but the read back did not confirm it. */
    public function asUnclear(): self
    {
        return new self(self::UNCLEAR, $this->transactionId, $this->gatewayCode);
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
