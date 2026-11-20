<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where Worldpay sent the client back after a hosted page ended without a payment (spec 0014), with the
 * error reference and text Worldpay adds to the address. Display only for admins: the values come from
 * the browser address, so they never decide a payment status.
 */
class WorldpayPageReturn extends Model
{
    public const UPDATED_AT = null;

    /** The return words worth saving. Success and pending are covered by the lookup. */
    public const WORDS = ['failure', 'error', 'cancel', 'expiry'];

    protected $fillable = ['payment_id', 'page_id', 'word', 'error_ref', 'error_text'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(WorldpayPage::class, 'page_id');
    }

    /** Worldpay's error reference: letters, digits and - _ . only, at most 64 characters. */
    public static function cleanRef(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = mb_substr((string) preg_replace('/[^A-Za-z0-9\-_.]/', '', $value), 0, 64);

        return $clean === '' ? null : $clean;
    }

    /** Worldpay's error text: a string or a list, tags and control characters removed, at most 255 characters. */
    public static function cleanText(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode('; ', array_filter($value, 'is_string'));
        }

        if (! is_string($value)) {
            return null;
        }

        $clean = strip_tags($value);
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $clean);
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
        $clean = mb_substr($clean, 0, 255);

        return $clean === '' ? null : $clean;
    }
}
