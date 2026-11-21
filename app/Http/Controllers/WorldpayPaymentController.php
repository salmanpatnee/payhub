<?php

namespace App\Http\Controllers;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use App\Models\WorldpayLookupAttempt;
use App\Models\WorldpayPage;
use App\Services\Worldpay\WorldpayChargeResolver;
use App\Support\Countries;
use App\Services\Worldpay\WorldpayClient;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Worldpay Hosted Payment Pages pay thread (spec 0014, AC-6, AC-7, AC-10).
 *
 * The server creates a hosted page per payment from the amount on the Payment record. The browser
 * never sends an amount, currency or account, and nothing here marks a payment paid: only
 * WorldpayChargeResolver does, from Worldpay's own Payment Queries answer.
 */
class WorldpayPaymentController extends Controller
{
    public const RETURN_WORDS = ['success', 'pending', 'failure', 'error', 'cancel', 'expiry'];

    public function checkout(Payment $payment): JsonResponse
    {
        $payment->loadMissing('worldpayAccount', 'brand');

        if (! $this->payable($payment)) {
            return response()->json(['message' => 'unavailable'], 422);
        }

        // The hosted route never serves a card fields account (spec 0015 AC-2). No Worldpay call is made.
        if ($payment->worldpayAccount->isCardFields()) {
            return response()->json(['message' => 'This account takes card payments on the PayHub page.'], 422);
        }

        // Consent is enforced on the server, not only in the page (AC-6). Same code and message as NMI.
        if (! $payment->consents()->exists()) {
            return response()->json(['message' => 'Please accept the payment terms first.'], 422);
        }

        $lock = Cache::lock('worldpay:checkout:'.$payment->id, (int) config('services.worldpay.checkout_lock_seconds', 120));

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            return $this->preparing();
        }

        try {
            // A caller that waited for the lock sees what the first caller saved.
            $payment->refresh()->loadMissing('worldpayAccount', 'brand');

            if (! $this->payable($payment)) {
                return response()->json(['message' => 'unavailable'], 422);
            }

            return $this->handOut($payment);
        } finally {
            $lock->release();
        }
    }

    /**
     * Polled by the waiting state. Reads Payment.status and, at most once every few seconds per
     * payment, asks the resolver to look at Worldpay. Returns the state only (AC-10).
     */
    public function status(Payment $payment): JsonResponse
    {
        abort_unless($payment->provider === PaymentProvider::Worldpay, 404);

        $payment->loadMissing('worldpayAccount');

        if ($payment->status === 'pending'
            && $payment->worldpayPages()->usable()->exists()
            && Cache::add('worldpay:poll:'.$payment->id, 1, (int) config('services.worldpay.poll_lookup_seconds', 10))) {
            app(WorldpayChargeResolver::class)->resolve($payment, WorldpayChargeResolver::SOURCE_POLL);
            $payment->refresh()->loadMissing('worldpayAccount');
        }

        return response()->json(['state' => $this->state($payment)]);
    }

    /**
     * The result URL words: only the six known words count, anything else is ignored. Display only,
     * never used to decide a status.
     */
    public static function returnWord(?string $value): ?string
    {
        return in_array($value, self::RETURN_WORDS, true) ? $value : null;
    }

    private function handOut(Payment $payment): JsonResponse
    {
        // A row with no url and no failure mark is a crashed create. Inside the lock nobody is
        // creating one, so it is closed off like a failed page and its reference is never reused.
        $payment->worldpayPages()->whereNull('url')->whereNull('failed_at')->update(['failed_at' => now()]);

        $pages = $payment->worldpayPages()->orderByDesc('sequence')->get();
        $usable = $pages->whereNull('failed_at');
        $newest = $usable->first();

        if ($newest === null) {
            return $this->createPage($payment, $pages);
        }

        if (! $newest->isDead() && $newest->expires_at->isFuture()) {
            return response()->json(['url' => $newest->url]);
        }

        if (! $newest->isDead()) {
            // Past its expiry but still inside the grace time: a payment may be finishing right now.
            return $this->preparing();
        }

        // Every older page is dead. Look once more before making a new page, so a late sale is not paid twice.
        app(WorldpayChargeResolver::class)->resolve($payment, WorldpayChargeResolver::SOURCE_CHECKOUT);
        $payment->refresh();

        if ($payment->status === 'completed' || $this->hasPendingSale($payment)) {
            return response()->json(['message' => 'We are confirming your payment. Please do not pay again.'], 409);
        }

        if ($payment->status !== 'pending') {
            return response()->json(['message' => 'unavailable'], 422);
        }

        if ($usable->count() >= max(1, (int) config('services.worldpay.max_pages', 1))) {
            return response()->json(['message' => 'This payment link can no longer be used.'], 422);
        }

        return $this->createPage($payment, $pages);
    }

    private function hasPendingSale(Payment $payment): bool
    {
        $pending = (array) config('services.worldpay.pending_events', []);

        return $payment->worldpayPages()->whereNull('failed_at')->whereIn('last_event', $pending)->exists();
    }

    /**
     * @param  Collection<int, WorldpayPage>  $pages  every page of the payment, failed ones included
     */
    private function createPage(Payment $payment, $pages): JsonResponse
    {
        $account = $payment->worldpayAccount;
        $sequence = ((int) $pages->max('sequence')) + 1;
        $expirySeconds = (int) config('services.worldpay.page_expiry_seconds', 259200);

        // The row goes in first, so a reference is never reused even if the call below fails or crashes.
        $page = WorldpayPage::create([
            'payment_id' => $payment->id,
            'sequence' => $sequence,
            'transaction_reference' => $payment->uuid.'-'.$sequence,
            'expires_at' => now()->addSeconds($expirySeconds),
        ]);

        $client = app()->make(WorldpayClient::class, [
            'username' => $account->api_username,
            'password' => $account->api_password,
            'environment' => $account->environment,
        ]);

        $payUrl = route('pay.show', $payment);
        $results = [];

        foreach (['successURL' => 'success', 'pendingURL' => 'pending', 'failureURL' => 'failure', 'errorURL' => 'error', 'cancelURL' => 'cancel', 'expiryURL' => 'expiry'] as $field => $word) {
            $results[$field] = $payUrl.'?worldpay_return='.$word;
        }

        try {
            $reply = $client->createPage(
                entity: $account->entity_ref,
                transactionReference: $page->transaction_reference,
                narrative: $this->narrative($payment),
                amountMinorUnits: (int) $payment->amount,
                currency: 'GBP',
                expirySeconds: $expirySeconds,
                resultUrls: $results,
                email: $payment->client_email,
                name: $payment->client_name,
                countryCode: array_key_exists((string) $payment->country, Countries::all()) ? (string) $payment->country : null,
            );

            if (! $reply->hasAllowedHost($account->environment)) {
                // The host alone (no path or query) is safe to log and tells operators what to allow.
                throw new \UnexpectedValueException('Worldpay page address is not on an allowed host: '.(string) parse_url($reply->url, PHP_URL_HOST));
            }
        } catch (Throwable $e) {
            $page->update(['failed_at' => now()]);

            // Operators only: the HTTP status and a short reason, never a request body, the password or the URL (AC-6).
            Log::error('Worldpay page creation failed', [
                'payment_id' => $payment->id,
                'worldpay_account_id' => $account->id,
                'exception' => $e::class,
                'http_status' => $e instanceof RequestException ? $e->response->status() : null,
                'reason' => $e instanceof RequestException ? (string) $e->response->json('errorName') : null,
                'detail' => $e instanceof \UnexpectedValueException ? $e->getMessage() : null,
            ]);

            return response()->json(['message' => 'We could not start your payment. Please try again.'], 502);
        }

        DB::transaction(function () use ($page, $payment, $reply): void {
            $page->update(['url' => $reply->url]);

            // From here amount, currency, account and brand are locked and deleting is blocked, for good.
            if ($payment->worldpay_charge_started_at === null) {
                $payment->forceFill(['worldpay_charge_started_at' => now()])->save();
            }
        });

        return response()->json(['url' => $reply->url]);
    }

    /**
     * The brand name Worldpay shows. Worldpay accepts 24 characters of letters, digits, space and - . ,
     * so the name is made plain ASCII, cleaned, cut, and falls back to the app name when nothing is left.
     */
    private function narrative(Payment $payment): string
    {
        $max = (int) config('services.worldpay.narrative_max', 24);
        $pattern = (string) config('services.worldpay.narrative_pattern', '/[^A-Za-z0-9\-., ]/');

        $clean = fn (?string $value): string => trim(mb_substr(trim((string) preg_replace($pattern, '', Str::ascii((string) $value))), 0, $max));

        $line = $clean($payment->brand?->name);

        return $line !== '' ? $line : $clean((string) config('app.name'));
    }

    private function state(Payment $payment): string
    {
        if ($payment->status === 'completed') {
            return 'completed';
        }

        if ($payment->status !== 'pending' || $payment->worldpayAccount?->is_active !== true) {
            return 'unavailable';
        }

        // A refusal or close seen in the last minute, on a page that is dead, sends the client back to Pay now.
        $page = $payment->worldpayPages()->usable()->orderByDesc('sequence')->first();

        if ($page !== null && $page->isDead()) {
            $latest = WorldpayLookupAttempt::where('page_id', $page->id)->orderByDesc('id')->first();

            if ($latest !== null
                && in_array($latest->outcome, [WorldpayChargeResolver::OUTCOME_DECLINED, WorldpayChargeResolver::OUTCOME_CLOSED], true)
                && $latest->last_checked_at !== null
                && $latest->last_checked_at->gte(now()->subSeconds(60))) {
                return 'retry';
            }
        }

        return 'waiting';
    }

    private function payable(Payment $payment): bool
    {
        return $payment->provider === PaymentProvider::Worldpay
            && $payment->status === 'pending'
            && $payment->worldpayAccount?->is_active === true;
    }

    private function preparing(): JsonResponse
    {
        return response()->json(['message' => 'Your payment is being prepared. Please try again in a moment.'], 409);
    }
}
