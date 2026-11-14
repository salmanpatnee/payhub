<?php

namespace App\Services\Nmi;

/**
 * Reads a `query.php` XML reply (spec 0012, AC-9). Pure: with NmiSaleReply the only place NMI's
 * field names are read. An empty `<nm_response>` is a valid "no match"; an `<error_response>` is a
 * failed lookup (bad key, sandbox on the wrong host); anything unreadable is also an error.
 */
final class NmiQueryReply
{
    /**
     * @param  array<int, array{transaction_id: string, condition: string, order_id: string, currency: string, amount_cents: ?int, sale: bool, sale_success: bool, avs: ?string, cvv: ?string}>  $transactions
     */
    private function __construct(
        public readonly bool $ok,
        public readonly array $transactions = [],
        public readonly ?string $error = null,
    ) {}

    public static function fromXml(string $xml): self
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $doc = simplexml_load_string($xml, options: LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($doc === false || $doc->getName() !== 'nm_response') {
            return new self(false, [], 'Unreadable reply');
        }

        if (isset($doc->error_response)) {
            return new self(false, [], trim(preg_replace('/\s*REFID:\d+$/', '', (string) $doc->error_response) ?? ''));
        }

        $transactions = [];

        foreach ($doc->transaction ?? [] as $t) {
            $sale = false;
            $saleSuccess = false;

            foreach ($t->action ?? [] as $action) {
                if ((string) $action->action_type === 'sale') {
                    $sale = true;
                    $saleSuccess = $saleSuccess || (string) $action->success === '1';
                }
            }

            $transactions[] = [
                'transaction_id' => trim((string) $t->transaction_id),
                'condition' => strtolower(trim((string) $t->condition)),
                'order_id' => trim((string) $t->order_id),
                'currency' => strtoupper(trim((string) $t->currency)),
                'amount_cents' => NmiAmount::decimalToCents(self::firstAmount($t)),
                'sale' => $sale,
                'sale_success' => $saleSuccess,
                'avs' => self::letter((string) $t->avs_response),
                'cvv' => self::letter((string) $t->csc_response),
            ];
        }

        return new self(true, $transactions);
    }

    public static function failed(string $error): self
    {
        return new self(false, [], $error);
    }

    /** The amount of the sale action (the transaction level node can be a running total). */
    private static function firstAmount(\SimpleXMLElement $t): ?string
    {
        foreach ($t->action ?? [] as $action) {
            if ((string) $action->action_type === 'sale') {
                return (string) $action->amount;
            }
        }

        return null;
    }

    private static function letter(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? substr($value, 0, 8) : null;
    }
}
