# Phase 6: Webhooks + Status Sync - Context

**Gathered:** 2026-05-11
**Status:** Ready for planning

<domain>
## Phase Boundary

Per-account Stripe webhook endpoints at `/webhook/stripe/{accountId}` that verify signatures using each account's own `webhook_secret`, immediately queue fulfillment on a valid event, and write payment status to the database from the queue — the only authoritative path by which a payment is ever marked `completed` or `failed`.

This phase also adds the `webhook_secret` field to the StripeAccount edit form with the webhook endpoint URL displayed for easy copy into the Stripe dashboard.

Out of scope: admin email notifications on payment success (Phase 7). No changes to the client payment page or payment creation flow.

</domain>

<decisions>
## Implementation Decisions

### webhook_secret Provisioning UI
- **D-01:** `webhook_secret` is added to the **existing StripeAccount edit form** (`/admin/stripe-accounts/{id}/edit`). No new routes or separate UI needed.
- **D-02:** The edit form shows a **read-only webhook endpoint URL field** displaying `/webhook/stripe/{accountId}`. Admin copies this URL into the Stripe dashboard when setting up the webhook. Field is labelled clearly (e.g., "Webhook Endpoint URL") and copyable.
- **D-03:** The `webhook_secret` input is a **masked/password-type field**. Shows blank when `webhook_secret` is null; shows `●●●●●●●●` placeholder when a secret is already stored. Admin re-enters the full value to change it.
- **D-04:** **Blank field on submit = no change.** If admin submits the edit form with `webhook_secret` left blank, the existing secret is preserved. The backend only updates `webhook_secret` when a non-empty value is provided. This prevents accidental secret clearance.

### Claude's Discretion
- **Handler architecture:** Use a **custom webhook controller** rather than spatie/laravel-stripe-webhooks infrastructure. The spatie package is built for a single signing secret from config — per-account secrets require non-trivial overrides. A custom `StripeWebhookController` that resolves the `StripeAccount` by `{accountId}`, calls `\Stripe\Webhook::constructEvent($rawBody, $signature, $account->webhook_secret)`, and dispatches a job is simpler, more testable, and directly maps to the 2-event requirement. The spatie package remains installed but unused.
- **Duplicate/idempotency handling:** Before queuing, check payment status. If the payment is already `completed` or `failed`, return HTTP 200 immediately without dispatching. Idempotency at the job level: job re-checks status on execution, applies update only if still `pending`. This is double-gated.
- **Job structure:** Single `HandleStripeWebhookJob` dispatched with `stripe_account_id`, `event_type` (string), and `event_data` (array). Job resolves the Payment by `stripe_payment_intent_id` from the event data, re-checks status, then applies: `completed` + `paid_at = now()` for `payment_intent.succeeded`; `failed` for `payment_intent.payment_failed`.
- **`paid_at` field:** Set to `now()` when `payment_intent.succeeded` marks the payment as `completed`. This is when the payment was authoritatively confirmed by Stripe.
- **Raw body preservation:** The webhook route must receive the raw request body for `constructEvent()`. Use `\Illuminate\Http\Request::getContent()` (not the parsed JSON). The route must be excluded from CSRF middleware. Add to the `VerifyCsrfToken` `$except` array.
- **Unknown events:** Any event type other than `payment_intent.succeeded` and `payment_intent.payment_failed` should return HTTP 200 immediately (Stripe requires 200 for all received events, even those you don't handle).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project Definition
- `.planning/PROJECT.md` — Core constraints, tech stack, Stripe multi-account pattern, `new StripeClient($account->secret_key)` rule
- `.planning/REQUIREMENTS.md` — WEBHOOK-01 through WEBHOOK-06, SEC-03 (all Phase 6 requirements)
- `.planning/ROADMAP.md` — Phase 6 goal and 5 success criteria

### Security Rules (CLAUDE.md — MUST enforce)
- `NEVER` call `Stripe::setApiKey()` globally — always `new StripeClient($account->secret_key)` (same pattern applies to webhook signing: use per-account `webhook_secret`)
- `NEVER` trust client-side `confirmPayment()` for DB writes — all payment status writes come exclusively from webhook handler
- Webhook routes MUST be excluded from CSRF middleware; raw body MUST be preserved for `constructEvent()`
- `PaymentIntent client_secret` never logged — not applicable to this phase but maintain in all logs

### Prior Phase Context
- `.planning/phases/05-client-payment-page/05-CONTEXT.md` — D-02: `stripe_payment_intent_id` stored on Payment at page load; this is the lookup key for webhook event → Payment record matching
- `.planning/phases/03-brand-stripe-account-management/03-CONTEXT.md` — `webhook_secret` made nullable (not required at account creation); encrypted cast on `StripeAccount`

### Key Existing Files
- `app/Models/StripeAccount.php` — `webhook_secret` encrypted cast, NOT mass-assignable
- `app/Models/Payment.php` — `stripe_payment_intent_id` (fillable), `status` (fillable), `paid_at` (datetime cast)
- `app/Http/Controllers/Admin/StripeAccountController.php` — existing edit/update logic to extend with webhook_secret handling
- `resources/js/pages/admin/stripe-accounts/Edit.vue` — existing edit form to extend with webhook_secret field + endpoint URL display
- `routes/web.php` — webhook route must be added OUTSIDE all auth middleware groups; also add to `VerifyCsrfToken::$except`
- `app/Http/Middleware/VerifyCsrfToken.php` — add `/webhook/stripe/*` to `$except`

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Http/Controllers/Admin/StripeAccountController.php` — `update()` method to extend. Follow the Phase 3 pattern for `secret_key` (only write when non-empty) — apply the same logic to `webhook_secret`.
- `resources/js/pages/admin/stripe-accounts/Edit.vue` — extend with two new fields: read-only endpoint URL (text input + copy button) and masked webhook_secret input. Follow the existing masked input pattern used for `secret_key`.
- `resources/js/pages/payments/Show.vue` — copy-to-clipboard pattern already exists for the payment link. Reuse same pattern for the webhook endpoint URL copy button.

### Established Patterns
- All new PHP classes via `php artisan make:` commands
- `new StripeClient($account->secret_key)` — per-account Stripe client (established in Phase 5)
- Queue driver: `database` — `php artisan queue:work` for local testing
- Vue 3 Composition API + `<script setup lang="ts">` — all new components
- `php artisan make:job` for the fulfillment job

### Integration Points
- `routes/web.php` — new webhook route: `Route::post('/webhook/stripe/{stripeAccount}', [StripeWebhookController::class, 'handle'])` added OUTSIDE auth/admin middleware
- `app/Http/Middleware/VerifyCsrfToken.php` — add `/webhook/stripe/*` to `$except` array (SEC-03)
- `Payment::where('stripe_payment_intent_id', $piId)->first()` — lookup pattern in fulfillment job
- `HandleStripeWebhookJob` dispatched on queue from controller; updates `Payment::status` and `paid_at`

</code_context>

<specifics>
## Specific Ideas

- Webhook route resolves `{stripeAccount}` via implicit model binding (ID-based, not UUID — `StripeAccount` uses auto-increment). Route: `Route::post('/webhook/stripe/{stripeAccount}', ...)`.
- Controller flow: (1) get raw body via `$request->getContent()`, (2) get `Stripe-Signature` header, (3) call `\Stripe\Webhook::constructEvent($payload, $sig, $account->webhook_secret)` — throws `\Stripe\Exception\SignatureVerificationException` on tamper/missing sig → return 400. (4) check event type — if not handled, return 200. (5) dispatch `HandleStripeWebhookJob`. (6) return 200.
- `webhook_secret` edit form: blank = preserve existing (controller: `if ($request->filled('webhook_secret')) { $account->webhook_secret = $request->webhook_secret; }`). Uses `$account->save()` directly since `webhook_secret` is not mass-assignable.
- Webhook endpoint URL displayed in edit form: `{{ appUrl }}/webhook/stripe/{{ stripeAccount.id }}` — pass `appUrl` from controller as Inertia prop via `config('app.url')`.
- Stripe CLI local testing: `stripe listen --forward-to localhost:8000/webhook/stripe/{accountId}` — matches CLAUDE.md dev command.

</specifics>

<deferred>
## Deferred Ideas

- **Admin email notification on payment success** — Phase 7 (NOTIFY-01/NOTIFY-02). The `HandleStripeWebhookJob` should emit an event or be structured to allow Phase 7 to hook in, but the notification itself is out of scope here.
- **Webhook event audit log / WebhookCall table** — spatie package provides this via `WebhookCall` model. Deferred to v2 — not required for v1 success criteria.
- **Dead-letter queue / retry visibility UI** — v2 (in REQUIREMENTS.md v2 deferred list).
- **Duplicate event deduplication via event ID** — Stripe includes `event.id` in every event. Strict dedup (store event IDs in a table) is a v2 enhancement. v1 uses double-status-check pattern (controller + job) which is sufficient.

</deferred>

---

*Phase: 06-webhooks-status-sync*
*Context gathered: 2026-05-11*
