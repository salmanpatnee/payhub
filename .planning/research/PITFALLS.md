# Domain Pitfalls

**Domain:** Multi-brand payment hub — Stripe Elements + Laravel 13 + multi-Stripe-account architecture
**Researched:** 2026-05-02
**Confidence:** HIGH (all critical pitfalls verified against official Stripe documentation and Laravel docs)

---

## Critical Pitfalls

Mistakes in this category cause silent financial errors, double-charges, security breaches, or full rewrites.

---

### CRITICAL-1: Cross-Account Key Contamination

**What goes wrong:**
When each brand has its own Stripe account, your system holds multiple (publishable_key, secret_key, webhook_secret) triplets. A lookup bug or missing brand scoping causes the server to use Brand A's secret key while the client-side Stripe.js was initialized with Brand B's publishable key. The PaymentIntent created by the wrong secret key belongs to the wrong Stripe account. On the client, confirmation silently fails or the payment is charged to the wrong merchant entirely.

**Why it happens:**
Developers fetch the publishable key for display from one place (e.g., `brand->publishable_key`) and the secret key from another code path (e.g., a cached config or default env variable). Under concurrency or misconfiguration the two code paths diverge.

**Consequences:**
- Payment charged to wrong brand's Stripe account, causing revenue misattribution
- `No such payment_intent: pi_xxx` error — the PaymentIntent was created on Account A but the server queries Account B
- Webhook signature verification failures because the wrong `webhook_secret` is used to verify events from the correct account
- Potential for money to land in the wrong merchant account with no automated reconciliation

**Prevention:**
- Model a `BrandStripeConfig` value object that holds all three fields together: `{publishable_key, secret_key, webhook_secret}`. Never pass them separately.
- Instantiate a new `\Stripe\StripeClient($brand->decrypted_secret_key)` per request rather than mutating global config. The Stripe PHP SDK supports per-client instantiation specifically for this use case.
- Add a database constraint: the `publishable_key` and `secret_key` stored for each brand must be from the same Stripe account (validate on save by calling `stripe.accounts.retrieve()` and comparing account ID).
- Log the Stripe account ID (`acct_xxx`) on every PaymentIntent creation and assert it matches the expected brand account before proceeding.

**Detection (warning signs):**
- `resource_missing` errors on PaymentIntent retrieval
- Revenue dashboards showing imbalanced per-brand totals
- Webhook events with an unexpected `account` field in the event payload

**Phase:** Core infrastructure / Brand management setup (Phase 1)

---

### CRITICAL-2: Trusting Client-Side Payment Confirmation Instead of Webhooks

**What goes wrong:**
After `stripe.confirmCardPayment()` or `stripe.handleNextAction()` resolves, the developer uses the returned PaymentIntent status from the JavaScript callback to mark the order as paid in the database. A user closes the browser after payment succeeds but before the callback runs — order is never fulfilled. A malicious user intercepts and replaces the Stripe.js response with a forged `{status: "succeeded"}` payload.

**Why it happens:**
The client-side API call resolves with a PaymentIntent object that contains a `status` field. It is tempting to use that status directly instead of setting up a separate webhook listener.

**Consequences:**
- Payments succeed on Stripe but orders are never fulfilled (lost revenue, angry customers)
- Fraudulent "free" access if a malicious client forges the response
- Duplicate fulfillment when the browser AND the webhook both trigger fulfillment

**Prevention:**
- All fulfillment (sending access emails, marking orders paid, generating receipts) must happen only in response to the `payment_intent.succeeded` webhook event.
- On the client, use the return from `confirmCardPayment()` only to show a success or error message to the user, never to write to the database.
- Verify payment status server-side by calling `$stripe->paymentIntents->retrieve($id)` if you need an immediate check — never trust a client-supplied status string.
- Official Stripe guidance: "Don't attempt to handle order fulfillment on the client side because customers can leave the page after payment is complete but before the fulfillment process initiates."

**Detection:**
- Orders stuck in `pending_payment` state after payment dashboard shows succeeded
- Missing fulfillment events in your logs after known test payments

**Phase:** Payment confirmation flow (Phase 2)

---

### CRITICAL-3: Webhook Raw Body Mutation Breaks Signature Verification

**What goes wrong:**
Laravel's JSON middleware (`Content-Type: application/json`) parses the request body before your webhook controller sees it. Stripe's signature verification requires the byte-for-byte raw body that was actually sent. After JSON parsing, whitespace may be normalised, key order may change, or encoding transforms occur — causing `\Stripe\Webhook::constructEvent()` to always throw `SignatureVerificationException`.

**Why it happens:**
Laravel applies the `App\Http\Middleware\TrimStrings` and body parsing middleware globally. Webhook routes are rarely excluded explicitly.

**Consequences:**
- Webhook signature verification fails in production silently
- Developers disable signature verification as a "quick fix", removing the only guarantee that the event is genuinely from Stripe
- An attacker can POST fabricated `payment_intent.succeeded` events to mark orders as paid without paying

**Prevention:**
- Exclude the webhook route from CSRF middleware in `bootstrap/app.php` (Laravel 11+):
  ```php
  ->withMiddleware(function (Middleware $middleware) {
      $middleware->validateCsrfTokens(except: ['webhook/stripe/*']);
  })
  ```
- Read the raw body with `$request->getContent()`, not `$request->all()` or `$request->json()`.
- In the webhook controller, call `\Stripe\Webhook::constructEvent($payload, $sigHeader, $secret)` before any other logic.
- Use `spatie/laravel-stripe-webhooks` which handles raw-body capture and signature verification correctly, and supports per-brand webhook secrets via its config.

**Detection:**
- `Stripe\Exception\SignatureVerificationException` in logs
- All webhook deliveries returning `400` in Stripe dashboard

**Phase:** Webhook infrastructure (Phase 2)

---

### CRITICAL-4: Per-Account Webhook Secret Not Used — All Events Verified Against One Secret

**What goes wrong:**
Each brand has its own Stripe account and therefore its own webhook endpoint with its own `whsec_xxx` signing secret. If the application stores only one webhook secret and uses it for all incoming webhook events, verification will fail for every account except the one that matches. Worse, if verification is soft (catching the exception and logging rather than rejecting), events from all accounts appear verified when they are not.

**Why it happens:**
In a single-account system, one webhook secret suffices. In multi-account systems, developers copy the single-account pattern without adapting it.

**Consequences:**
- Silent verification failure: events from 9 of 10 brands are never cryptographically verified
- An attacker can send fabricated payment events for any brand whose secret is not properly applied
- Legitimate events are rejected, causing missed fulfillments

**Prevention:**
- Route incoming webhooks to a shared endpoint that first reads the `Stripe-Account` header (or a brand slug in the URL path like `/webhook/stripe/{brand_slug}`) to identify the brand.
- Look up the brand's `webhook_secret` from the database (decrypted), then call `constructEvent()` with that specific secret.
- Prefer per-brand webhook URLs (`/webhook/brand/{uuid}`) so the secret lookup is unambiguous even if the Stripe header is missing.
- Register a separate webhook endpoint in each brand's Stripe dashboard pointing to that brand's URL.

**Detection:**
- `SignatureVerificationException` for specific brands but not others
- Webhook events in Stripe dashboard showing delivery attempts to the wrong endpoint

**Phase:** Webhook infrastructure (Phase 2)

---

### CRITICAL-5: Payment Amount Can Be Tampered by a Malicious Client

**What goes wrong:**
The payment link page loads and passes the order amount from a URL parameter or hidden form field to a server endpoint that creates the PaymentIntent. A malicious user modifies the amount (e.g., changes `amount=10000` to `amount=1`) before the server creates the PaymentIntent, charging $0.01 for a product priced at $100.

A documented CVE (CVE-2026-2890, Formidable Forms) scored 7.5 CVSS demonstrated exactly this: checking only whether a PaymentIntent `succeeded` without verifying the amount matched the expected order total allowed payment bypass.

**Why it happens:**
Developers treat the payment link page as trusted because it appears server-rendered, and pass the amount through the URL or Inertia page props without re-verifying it server-side on PaymentIntent creation.

**Consequences:**
- Customers pay arbitrary (attacker-chosen) amounts for any product
- Difficult to detect unless you cross-check Stripe receipts with internal order records

**Prevention:**
- Never create a PaymentIntent from a client-supplied amount. Derive the amount exclusively from the server-side `PaymentLink` record in your database, keyed by the link's UUID.
- The only input from the client should be the link's UUID (or slug). The server resolves the canonical amount from there.
- After the PaymentIntent is created, store the `payment_intent_id` and expected `amount` together in your `payments` table. On `payment_intent.succeeded`, assert `event.amount == stored_amount` before fulfilling.
- Official Stripe guidance: "Always decide how much to charge on the server-side."

**Detection:**
- Stripe charges in dashboard that don't match your `payment_links.amount` records
- Unusually low charges (cents) for high-value links

**Phase:** Payment link creation and checkout flow (Phase 2)

---

## Moderate Pitfalls

Mistakes in this category cause failures in subsets of payments, compliance gaps, or significant debugging time.

---

### MOD-1: 3DS/SCA `requires_action` Not Handled — Silent Payment Failures for European Cards

**What goes wrong:**
After calling `stripe.confirmCardPayment()`, the returned PaymentIntent has `status: "requires_action"`. If the code only checks for `error` and assumes anything non-error is success, the 3DS authentication modal is never triggered. The payment sits in `requires_action` and eventually expires, but the user sees no error.

**Why it happens:**
Developers test only with US test cards that do not trigger 3DS. European cards (especially UK/EU post-PSD2) frequently require 3DS. The code path for `requires_action` is never exercised in development.

**Consequences:**
- European customers cannot complete payment
- Silent failures logged as abandoned payments rather than as a code bug
- SCA non-compliance exposure for EU transactions

**Prevention:**
- After `confirmCardPayment()`, explicitly check for `paymentIntent.status === 'requires_action'` and call `stripe.handleNextAction(clientSecret)`.
- Always supply a `return_url` when creating the PaymentIntent server-side, so redirect-based 3DS flows (some card issuers do not support popup/iframe) can return to your page.
- Test with Stripe's 3DS test card numbers (`4000002760003184` for required 3DS, `4000008400001629` for optional 3DS).
- Ensure your Content Security Policy includes `frame-src https://js.stripe.com https://hooks.stripe.com` — missing this blocks the 3DS iframe silently.
- The `return_url` page must call `stripe.retrievePaymentIntent(clientSecret)` to check final status after redirect.

**Detection:**
- High abandonment rate on payment pages from EU IP addresses
- PaymentIntents stuck in `requires_action` state in Stripe dashboard

**Phase:** Payment confirmation flow (Phase 2)

---

### MOD-2: Duplicate Webhook Event Processing — Double Fulfillment

**What goes wrong:**
Stripe retries webhook delivery for up to 3 days if your endpoint returns a non-2xx response. A slow database write or queue job timeout causes a 500 response, triggering a retry. The retry is processed a second time, resulting in two fulfillment emails, two access grants, or two "payment received" records.

**Why it happens:**
The check-before-insert idempotency pattern is non-atomic: two concurrent workers can both pass the `WHERE stripe_event_id = ?` check before either inserts the record.

**Consequences:**
- Double emails to customers
- Double-counted revenue in reports
- If fulfillment involves provisioning (creating accounts, API keys), duplicate resources are created

**Prevention:**
- Add a `stripe_events` table with a unique constraint on `event_id`. Insert the event ID as the first operation in a database transaction. If the insert fails with a unique violation, return 200 immediately without processing.
- Pattern: `DB::transaction(function() use ($event) { StripeEvent::create(['event_id' => $event->id]); /* fulfillment */ });`
- Use `WithoutOverlapping` middleware on queued webhook jobs keyed by `stripe_event_id`.
- Return HTTP 200 to Stripe as quickly as possible, and dispatch a queued job for actual processing — don't do heavy work synchronously in the webhook handler.

**Detection:**
- Duplicate records in `orders` or `payments` tables sharing the same `payment_intent_id`
- Customer complaints about duplicate emails

**Phase:** Webhook infrastructure (Phase 2)

---

### MOD-3: Zero-Decimal Currency Overcarge (100x)

**What goes wrong:**
The application stores amounts as integers (correct) but the conversion logic multiplies by 100 before passing to Stripe. For zero-decimal currencies like JPY, KRW, and THB, Stripe expects the raw unit (e.g., `1000` for ¥1000), not multiplied by 100. Multiplying results in a ¥100,000 charge for a ¥1,000 item.

**Why it happens:**
The `* 100` conversion is correct for USD/EUR/GBP and becomes a habit. Developers don't audit zero-decimal currency handling separately.

**Consequences:**
- Customers overcharged by 100x for zero-decimal currency transactions
- High dispute rates, potential account termination by Stripe for excessive chargebacks

**Prevention:**
- Create a `CurrencyFormatter` service that knows which currencies are zero-decimal. Never do raw `* 100` arithmetic outside this service.
- Zero-decimal currencies include: BIF, CLP, DJF, GNF, JPY, KMF, KRW, MGA, PYG, RWF, UGX, VND, VUV, XAF, XOF, XPF.
- Store all amounts in your database as integers in the **smallest currency unit** already (i.e., cents for USD, yen for JPY). This eliminates conversion in both directions.
- Write unit tests asserting that ¥1000 produces Stripe `amount: 1000` and $10.00 produces Stripe `amount: 1000`.

**Detection:**
- Stripe amount in dashboard doesn't match `payment_links.amount` after division by 100
- Customer complaints about incorrect charges in JPY/KRW

**Phase:** Payment link creation (Phase 1), multi-currency support (Phase 3)

---

### MOD-4: Laravel APP_KEY Rotation Breaks All Encrypted Stripe Secret Keys

**What goes wrong:**
The Stripe secret keys for each brand are stored encrypted with `Crypt::encryptString()` using Laravel's `APP_KEY`. If the `APP_KEY` is rotated (leaked key, security audit, server migration) without following the `APP_PREVIOUS_KEYS` grace period pattern, all stored Stripe keys become unreadable. Every payment attempt fails with `DecryptException`.

**Why it happens:**
Developers rotate `APP_KEY` by simply replacing the old value in `.env` without reading the Laravel key rotation documentation. The old key is not preserved.

**Consequences:**
- All brands' Stripe keys become undecryptable simultaneously
- Complete payment outage until keys are re-entered manually
- If backups of the old key are unavailable, the data is permanently lost

**Prevention:**
- Use `APP_PREVIOUS_KEYS` (available since Laravel 11) to provide a fallback for decryption during rotation:
  ```
  APP_KEY="base64:NEW_KEY"
  APP_PREVIOUS_KEYS="base64:OLD_KEY"
  ```
- Write a migration script that re-encrypts all brand Stripe keys with the new `APP_KEY` before removing `APP_PREVIOUS_KEYS`.
- Store a `key_version` column alongside each encrypted secret so you can identify which records need re-encryption during rotation.
- Schedule a post-rotation audit: query all brands, attempt decryption, alert if any fail.
- Never use a custom AES implementation for this purpose. Use `Crypt::encryptString()` because it includes MAC verification and IV randomisation out of the box. Rolling your own AES-256-CBC without per-value IV randomisation means identical keys encrypt to identical ciphertexts, enabling ciphertext comparison attacks.

**Detection:**
- `Illuminate\Contracts\Encryption\DecryptException` in payment logs after a key rotation
- Brand payment pages returning 500 errors

**Phase:** Brand credential management (Phase 1)

---

### MOD-5: CSRF Token Mismatch on Guest Payment Pages

**What goes wrong:**
The public payment page is accessed by unauthenticated visitors. Laravel's CSRF cookie (`XSRF-TOKEN`) is set on first page load. If the page is cached (CDN, Cloudflare, reverse proxy) the CSRF cookie from the cached response is stale. When the user submits the payment form, their fresh `X-XSRF-TOKEN` header doesn't match the cached token, resulting in a 419 error.

A second variant: Stripe's 3DS redirect flow sends the user away and back. During the redirect, Chrome treats `XSRF-TOKEN` as `SameSite=Lax` and may not send it on the return POST, causing a 419 after 3DS completes.

**Why it happens:**
CDN caching of HTML pages is a common performance optimisation. SameSite cookie behaviour during cross-origin redirects (e.g., from `acs.bank.com` back to your domain) is a browser security feature that developers rarely test.

**Consequences:**
- Payment confirmation POST fails with 419 after 3DS authentication completes
- Customers who have already authenticated with their bank see a cryptic error and cannot pay

**Prevention:**
- Exclude all payment page routes from CDN/Cloudflare caching (`Cache-Control: no-store`).
- Set the session `XSRF-TOKEN` cookie to `SameSite=None; Secure` for payment pages that involve external redirects.
- The Stripe 3DS `return_url` page should use a GET request with the `payment_intent_client_secret` query parameter to check status, not a POST — this avoids the CSRF problem entirely.
- Add the webhook route(s) to the CSRF exception list (unrelated to the user flow but a common omission that causes webhook 419s).

**Detection:**
- 419 errors in logs after 3DS redirect returns
- CSRF failures only on mobile Chrome or Safari (SameSite behaviour differs by browser)

**Phase:** Payment UI / 3DS flow (Phase 2)

---

### MOD-6: PaymentIntent Created Per Page Load — Orphaned Intents and Confirmation-Limit Cancellation

**What goes wrong:**
The payment page controller creates a new `PaymentIntent` every time the page is loaded (including back-navigation and refreshes). Stripe will automatically cancel a PaymentIntent after it has been confirmed too many times. More practically, thousands of orphaned PaymentIntents accumulate in Stripe, polluting dashboards, triggering billing implications, and making it impossible to audit real payment activity.

**Why it happens:**
Creating a new intent per request is the simplest implementation path.

**Consequences:**
- PaymentIntents cancelled by Stripe's automatic protection after repeated confirmations from page refreshes
- Stripe dashboard pollution making reconciliation impossible
- `canceled` PaymentIntents appearing in reports as failed payments

**Prevention:**
- Store the `payment_intent_id` in your `payments` table when created, associated with the payment link UUID.
- On page load, check if a `requires_payment_method` or `requires_confirmation` intent already exists for this link. Return the existing intent's `client_secret` instead of creating a new one.
- Because payment links never expire in this system, a given link UUID should have at most one active (non-succeeded, non-canceled) PaymentIntent at any time.
- If the existing intent is in `requires_payment_method` state, reuse it. If it is `canceled` or `succeeded`, create a new one and update the record accordingly.

**Detection:**
- Multiple `payment_intent` records in your database for the same `payment_link_id`
- Stripe API errors about payment intent cancellation due to too many confirmations

**Phase:** Payment link checkout flow (Phase 2)

---

### MOD-7: Stripe Publishable Key Exposed Belongs to Wrong Environment (Test vs Live)

**What goes wrong:**
The publishable key rendered in the Inertia page props comes from the database. During initial setup, a developer configures a brand with the Stripe **test** publishable key and **test** secret key while the application is in production. Payments appear to succeed in Stripe's test mode but no real money moves. The error is invisible because test payments go through without error.

A related variant: the Stripe secret key in the database is live-mode but the publishable key is test-mode. The client initializes Stripe.js in test mode, creates test card tokens, but the server attempts to confirm with a live-mode API, causing an authentication error.

**Why it happens:**
Brands are onboarded manually and there is no validation that test vs live mode is consistent between the two keys.

**Consequences:**
- Customers see payment success but no money is collected
- Revenue appears in Stripe test dashboard, not live dashboard
- Discovered only during reconciliation, potentially weeks later

**Prevention:**
- On brand credential save, call Stripe API with both keys to verify: use the secret key to retrieve the account, and verify `livemode` attribute is consistent with the publishable key prefix (`pk_live_` vs `pk_test_`).
- Enforce a validation rule: if `APP_ENV=production`, reject `pk_test_` keys for active brands.
- Display the key mode (Live/Test) prominently in the admin brand management UI.

**Detection:**
- Stripe `livemode: false` on PaymentIntents in a production database
- Publishable key starting with `pk_test_` in production Inertia props

**Phase:** Brand management / credential validation (Phase 1)

---

## Minor Pitfalls

---

### MIN-1: Float Arithmetic in Amount Calculations

**What goes wrong:**
Amounts are calculated using PHP floats (e.g., `$amount * 1.1` for a tax) before converting to integer cents. Floating point representation errors produce amounts like `110.00000000001` which when multiplied by 100 and cast to int becomes `11000` — but occasionally produces `10999` due to rounding direction.

**Prevention:**
- Use PHP's `bcmath` or `intl` Money library for all monetary arithmetic.
- Store amounts in the database as integer smallest-unit (cents/pence/yen) from the moment they enter the system.
- Never perform arithmetic on decimal representations. Convert to smallest-unit first, do arithmetic, then display.

**Phase:** Payment link data model (Phase 1)

---

### MIN-2: Content Security Policy Missing Stripe Domains

**What goes wrong:**
A strict CSP blocks Stripe.js from loading, the 3DS iframe from rendering, or the analytics beacon (`q.stripe.com`) from connecting. The payment form renders but card input fields are blank (Stripe iframes blocked) or 3DS modal never appears.

**Prevention:**
Add to your CSP headers:
```
connect-src: https://api.stripe.com https://q.stripe.com
frame-src: https://js.stripe.com https://hooks.stripe.com
script-src: https://js.stripe.com
```
Test with the browser console open — CSP violations are logged there.

**Phase:** Payment page frontend (Phase 2)

---

### MIN-3: Stripe.js Initialized Once with the Wrong Brand's Publishable Key

**What goes wrong:**
Stripe.js is initialized at application boot with a default publishable key from `.env`. When a user visits a payment link for Brand B, the Stripe instance is still using Brand A's key. Card tokenisation works but the PaymentIntent confirmation fails because the token is created under the wrong account.

**Prevention:**
- Initialize Stripe.js on the payment page with the publishable key passed from server-side Inertia props for that specific brand.
- Do not use a global `window.Stripe` instance shared across brands. Instantiate per page load: `const stripe = Stripe(pageProps.publishableKey)`.

**Phase:** Payment page frontend (Phase 2)

---

### MIN-4: Idempotency Key Scope Collision Across Brands

**What goes wrong:**
Idempotency keys are generated as `order_{order_id}` without including a brand identifier. If Brand A and Brand B both have an order with `id=1`, the Stripe API call for Brand B uses the same idempotency key as Brand A. Stripe's idempotency is scoped per API key (per Stripe account), so this is safe across different accounts — but if the bug means both brands use the same secret key, the idempotency key causes the second order to silently return the first order's PaymentIntent.

**Prevention:**
- Generate idempotency keys as `{brand_id}_{payment_link_uuid}` to make them globally unique.
- Because the PaymentIntent is created per brand's Stripe account (different API keys), Stripe itself provides isolation — but the defensive scoping prevents bugs if the key lookup ever regresses.

**Phase:** Payment intent creation (Phase 2)

---

## Phase-Specific Warnings

| Phase Topic | Likely Pitfall | Mitigation |
|---|---|---|
| Brand onboarding / credential storage | APP_KEY rotation destroys all secrets (MOD-4) | Implement key rotation procedure before storing first real key |
| Brand onboarding / credential storage | Test/live key mismatch silently passes (MOD-7) | Validate key mode on save via Stripe API |
| Payment link data model | Float arithmetic errors in amount fields (MIN-1) | Use bcmath/integer-only from day 1 |
| Multi-currency support | Zero-decimal currency 100x overcharge (MOD-3) | Currency service with zero-decimal list before first multi-currency deployment |
| Stripe.js initialization on payment page | Wrong brand key used (MIN-3) | Inject publishable key from server into page props |
| PaymentIntent creation endpoint | Amount tampered by client (CRITICAL-5) | Amount derived only from DB record, never from request body |
| PaymentIntent creation endpoint | Intent created every page load (MOD-6) | Check for existing active intent before creating |
| Payment confirmation / 3DS | requires_action not handled, European cards fail (MOD-1) | Explicit status check + handleNextAction + return_url |
| Payment confirmation / 3DS | CSRF 419 after 3DS redirect (MOD-5) | GET-only return_url handler, SameSite=None on XSRF cookie |
| Webhook endpoint registration | Single secret for all brands (CRITICAL-4) | Per-brand URL + per-brand secret lookup |
| Webhook controller | Raw body parsed, signature fails (CRITICAL-3) | Exclude from body middleware, use getContent() |
| Webhook processing logic | Duplicate event double-fulfillment (MOD-2) | Unique constraint on stripe_event_id |
| Order fulfillment logic | Client-side status trusted instead of webhook (CRITICAL-2) | All DB writes driven by webhook events only |
| Cross-brand routing | Wrong account's keys used (CRITICAL-1) | BrandStripeConfig value object, per-request StripeClient |

---

## Sources

- [Stripe: Finalize payments on the server](https://docs.stripe.com/payments/finalize-payments-on-the-server) — HIGH confidence
- [Stripe: PaymentIntent lifecycle](https://docs.stripe.com/payments/paymentintents/lifecycle) — HIGH confidence
- [Stripe: Verifying payment status](https://docs.stripe.com/payments/payment-intents/verifying-status) — HIGH confidence
- [Stripe: Webhook signature verification](https://docs.stripe.com/webhooks/signature) — HIGH confidence
- [Stripe: Webhook delivery and retries](https://docs.stripe.com/webhooks) — HIGH confidence
- [Stripe: 3DS authentication flow](https://docs.stripe.com/payments/3d-secure/authentication-flow) — HIGH confidence
- [Stripe: Integration security guide](https://docs.stripe.com/security/guide) — HIGH confidence
- [Stripe: API key best practices](https://docs.stripe.com/keys-best-practices) — HIGH confidence
- [Stripe: Currencies (zero-decimal list)](https://docs.stripe.com/currencies) — HIGH confidence
- [Laravel 13: Encryption and key rotation](https://laravel.com/docs/13.x/encryption) — HIGH confidence
- [Laravel 13: CSRF protection](https://laravel.com/docs/13.x/csrf) — HIGH confidence
- [Inertia.js CSRF protection](https://inertiajs.com/docs/v2/security/csrf-protection) — HIGH confidence
- [DEV: Debugging Stripe webhook signature verification in production](https://dev.to/nerdincode/debugging-stripe-webhook-signature-verification-errors-in-production-1h7c) — MEDIUM confidence
- [Hookdeck: Implementing webhook idempotency](https://hookdeck.com/webhooks/guides/implement-webhook-idempotency) — MEDIUM confidence
- [CVE-2026-2890 Formidable Forms payment bypass](https://www.himpfen.com/formidable-forms-stripe-payment-bypass-cve-2026-2890/) — MEDIUM confidence (real-world illustration of CRITICAL-5)
- [spatie/laravel-stripe-webhooks](https://github.com/spatie/laravel-stripe-webhooks) — MEDIUM confidence (recommended library for CRITICAL-3/4)
- [Floats don't work for storing cents — Modern Treasury](https://www.moderntreasury.com/journal/floats-dont-work-for-storing-cents) — MEDIUM confidence
- [Zero-downtime secrets rotation for Laravel](https://brainlet.medium.com/zero-downtime-secrets-rotation-for-laravel-f727db307361) — MEDIUM confidence
