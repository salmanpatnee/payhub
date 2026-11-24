# 0016. One shared payment attempt log for Stripe, Revolut, Square, Viva, Whop and future providers

**Date**: 2026-11-23
**Status**: In Progress

## Summary

PayHub has five attempt history tables and five separate screens (MPGS, Authorize.net, NMI, Kurv, Worldpay). This spec adds one shared table, `payment_attempts`, one small backend service that writes to it, and one Vue component that shows it. It starts with Stripe, Revolut, Square, Viva and Whop, which have no history today. For these providers an attempt is a verified outcome from the provider (a webhook, or Viva's API re check), so each row is a success, a failure or a cancel with whatever reason the provider sent. The old five providers are not touched. A new provider later needs one small extractor class and one config line, with no new table and no new screen.

## Requirements

**User stories**:
- As an admin, I want to open a Stripe, Revolut, Square, Viva or Whop payment and see every attempt with its time, amount, status, reference, error code and reason, so I can tell the team why a client's payment failed.
- As an admin, I want to expand a row and see the provider's response details, so I can check a decline without opening the provider dashboard.
- As a developer, I want to add a new provider's history by writing one extractor class, so I do not need a new table or a new screen.
- As the business, I want card numbers, security codes, secrets and tokens never to reach this table, even when a provider changes its payload.

**Acceptance criteria** (the contract, each one IDed and checkable on its own):
- **AC-1**: A migration creates `payment_attempts` exactly as in the data model below, with a unique index on `(payment_id, sequence)` and a unique index on `(provider, event_key)`. `provider` is a plain string column, not a database enum, so a new provider needs no migration. The `PaymentAttempt` model, its factory and `Payment::paymentAttempts()` (has many) exist.
- **AC-2**: `PaymentAttemptLogger::record()` never throws. Any exception (missing table, database down, bad payload, extractor bug) is caught, logged with `Log::warning` and sent to Sentry (tag `payment.provider`, fingerprint `['payment-attempt-log', provider, event]`, no payment id or reference in the message), and the caller carries on. A webhook still returns 200 and a payment still changes status when logging fails.
- **AC-3**: Each row gets the next `sequence` for its payment (1, 2, 3 and so on, in order of arrival). The same event never creates a second row: a redelivery, a queue retry or two parallel requests with the same `(provider, event_key)` end with one row. A different event for the same payment (a retry after a decline) creates the next row. A unique key clash is handled outside the transaction: a `(provider, event_key)` clash returns the existing row, and a `(payment_id, sequence)` clash (a different event taking the same number) retries once with a fresh number, so a real event is never lost.
- **AC-4**: Stripe: `payment_intent.payment_failed` writes a `failed` row and `payment_intent.succeeded` writes a `succeeded` row. `reference` is the PaymentIntent id, `error_code` is `last_payment_error.decline_code` else `last_payment_error.code`, `reason` is `last_payment_error.message`, amount and currency come from the PaymentIntent, `event_key` is the Stripe event id.
- **AC-5**: Revolut: `ORDER_COMPLETED` writes `succeeded` and `ORDER_PAYMENT_FAILED` writes `failed`. `reference` is the order id and `event_key` is `{order_id}:{event}`. The payload carries no reason or amount, so `error_code` and `reason` stay empty and the amount is copied from the payment with `amount_reported` false.
- **AC-6**: Square (declines are also logged from the charge call, see Consequences): only `COMPLETED` (writes `succeeded`), `FAILED` (writes `failed`) and `CANCELED` (writes `cancelled`) are logged. `APPROVED`, `PENDING` and other statuses are not. A status equal to the last logged status for the same Square payment id is skipped, so later `payment.updated` events (for example a refund) do not add duplicate rows. `reference` is the Square payment id, `event_key` is Square's `event_id`, amount and currency come from `amount_money`, `error_code` and `reason` come from the first entry of `card_details.errors` when present. The payment is found by `square_payment_id` and `square_account_id`. When that finds nothing (a declined charge throws before the id is saved, or the webhook beats the save), the fallback is the payment whose reference code equals the Square payment's `reference_id`, scoped to the same Square account.
- **AC-7**: Viva: the log row is written only after `HandleVivaWebhookJob` has re fetched the transaction from Viva (the webhook itself is unsigned, so it can never create a row alone). A null transaction id writes no row. The log call runs after the order code cross check and before the `statusId` return, so the job's existing order of checks changes: the `statusId !== 'F'` return moves below the log call. Status `F` writes `succeeded`, `E` writes `failed`, `X` writes `cancelled`, any other status writes `pending`. The failed event id `1798` is added to the handled list for logging only: it never changes the payment status. `reference` is the transaction id. The controller passes its already computed `$eventKey` (Viva's `MessageId`, else `{orderCode}:{eventTypeId}`) and `$eventTypeId` to the job.
- **AC-8**: Whop: `payment.succeeded` writes `succeeded` and `payment.failed` writes `failed`. The log call runs after the dedupe transaction commits, with no extra condition (the unique index on `(provider, event_key)` blocks duplicates). The payment is found by the `metadata.payment_id` uuid, `provider = whop` and the job's `whop_account_id`. A missing uuid, an unknown uuid or another account's payment writes no row (`Log::info`, no Sentry). A success that fails the amount or currency check is still logged, so the mismatch shows with `amount_reported` true. `payment.failed` still does not change the payment status. Other Whop events (refunds, disputes) are not logged. `event_key` is the Whop webhook id and `reference` is the Whop payment id.
- **AC-9**: Late events are logged for every provider. For Stripe, Revolut and Square, the completed payment guard in the controller runs after the log call. For Viva, the controller's completed guard moves after the dispatch (the job is already status safe, and logs before it decides anything). For Whop, the job already runs for completed payments. A failed event that arrives after a success still gets a row, and no status logic changes anywhere. Payment lookups for logging include the provider account foreign key, and events for soft deleted payments are not logged.
- **AC-10**: No sensitive value is stored. Each extractor names the fields it keeps (an allowlist). A shared `AttemptPayloadSanitizer` then runs on `request` and `response` as a second guard: it drops any key matching the secret key list in the Feature design section, cuts strings to 500 characters, limits depth to 4 levels, and replaces a body over 8 KB with `{"truncated": true}`. The text columns `reason`, `error_code` and `reference` also pass a check that masks any run of 13 to 19 digits (with or without spaces or dashes). Card data is limited to `brand`, `last4`, `funding` and `country`. A test feeds a payload full of card numbers, CVV, `client_secret`, access tokens and API keys and asserts none appear in any column.
- **AC-11**: `PaymentController::show` sends a new prop `attemptLog` only when the user is an admin and the payment's provider value is a key in `config('payment_attempts.extractors')`. Everyone else, and any provider with no extractor (the legacy five), gets `null`. The existing `attempts` prop and the existing inline card are unchanged. Rows are newest first, at most 100.
- **AC-12**: `PaymentAttemptLog.vue` shows for each row: attempt number, date and time, amount with currency, status badge, transaction reference, error code and reason, and the provider once in the card header. Missing values show a dash. It renders as a table on wide screens and as labelled cards on a phone. An empty log shows "No attempts recorded for this payment. History starts from the day this feature went live."
- **AC-13**: A row expands in place (chevron button, keyboard accessible) to show `request` and `response` as a tidy key and value list with a copy button. A row with no details shows no chevron. Values are rendered as escaped text, never as HTML.
- **AC-14**: MPGS, Authorize.net, NMI, Kurv and Worldpay behave exactly as before. Their tables, models, controllers and the inline card are not edited, and their existing tests pass unchanged. Clover keeps its own `clover_charge_attempts` table and flow too; it only gains a log call (see AC-17).
- **AC-17**: Clover has no webhook, so `CloverChargeResolver::resolve()` (the one place every Clover status is written, from the charge call and from `ResolveCloverChargeAttempt`) calls the logger with the Clover charge body. `approved` writes `succeeded`, `declined` and `hard_error` write `failed` (`error_code` is `declineCode`, else `code`; `reason` is `error.message`), `unknown` writes nothing and is logged when it is later resolved. `event_key` is `clover:{attempt_id}`, so one attempt gives one row. `source` is `call`. No status logic changes.
- **AC-15**: Adding a provider needs only: one extractor class implementing `AttemptExtractor`, one line in `config/payment_attempts.php`, and one `record()` call at the provider's trusted point. A test registers a fake provider this way and gets rows and a rendered history with no migration and no change to the Vue component.
- **AC-16**: Pest tests cover every supported provider for a success, a failure, a retry (two failures then a success, sequence 1, 2, 3), a duplicate event, a late event, and an event with missing details (no reason, no amount, unknown payment). Admin, agent and account role visibility is tested. `npm run types:check` passes for the touched files.

## Decision

**Chosen option**: Option 2: One shared `payment_attempts` table with per provider extractors.

Add one table, one logger service and one Vue component. Each provider gets a small extractor that turns its own event into a shared shape. The five new providers log verified outcome events, and the five legacy providers stay as they are until a later spec moves them.

**Implementation skills**: `laravel-best-practices` (`laravel/boost`, `.claude/skills/laravel-best-practices/`) · `inertia-vue-development` (`laravel/boost`, `.claude/skills/inertia-vue-development/`) · `pest-testing` (`laravel/boost`, `.claude/skills/pest-testing/`) · `provider-parity-check` (project, `.claude/skills/provider-parity-check/`)

## Rationale

Reasoning and options: see [rationale.md](rationale.md).

## Feature design

**Data model**:

`payment_attempts`, one row per verified provider outcome. A `Payment` has many `PaymentAttempt` (1:N), and rows are deleted with their payment.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `payment_id` | FK to `payments.id`, cascade on delete | required |
| `provider` | string(32) | the `PaymentProvider` value |
| `sequence` | unsigned int | per payment, unique with `payment_id` |
| `event_key` | string(191), nullable | unique with `provider`; null only for a future provider with no event id (no dedupe then) |
| `event` | string(100) | the provider's own word, such as `payment_intent.payment_failed` or `FAILED` |
| `status` | string(20) | `succeeded`, `failed`, `cancelled`, `pending` |
| `amount` | unsigned bigint, nullable | minor units |
| `currency` | string(3), nullable | lowercase |
| `amount_reported` | boolean, default false | true when the provider's event gave the amount |
| `reference` | string(191), nullable | transaction reference |
| `error_code` | string(100), nullable | |
| `reason` | string(500), nullable | |
| `source` | string(20), default `webhook` | room for `lookup` and `call` later |
| `request` | json, nullable | allowlisted, sanitized |
| `response` | json, nullable | allowlisted, sanitized, 8 KB cap |
| `occurred_at` | timestamp | provider event time, else time received |
| `created_at`, `updated_at` | timestamps | |

Indexes: unique `(payment_id, sequence)`, unique `(provider, event_key)`, plain `(payment_id, occurred_at)`.

**Backend pieces** (all under `app/Services/PaymentAttempts/` unless noted):
- `PaymentAttemptLogger::record(PaymentProvider $provider, ?Payment $payment, array $payload, array $context = []): ?PaymentAttempt`. Picks the extractor from `config/payment_attempts.php`, skips quietly when `$payment` is null, wraps everything in try and catch (AC-2), and inserts inside one transaction: lock the payment row, return the existing row if `(provider, event_key)` exists, take `max(sequence) + 1`, insert, and treat a unique key violation as "already logged".
- `AttemptExtractor` (interface): `extract(array $payload, array $context): AttemptData`. One class per provider: `StripeAttemptExtractor`, `RevolutAttemptExtractor`, `SquareAttemptExtractor`, `VivaAttemptExtractor`, `WhopAttemptExtractor`. Each is the only class that knows that provider's field names for logging.
- `AttemptData` (readonly value object): event, status, eventKey, reference, errorCode, reason, amount, currency, amountReported, request, response, occurredAt.
- `AttemptPayloadSanitizer`: the second guard from AC-10.
- `app/Models/PaymentAttempt.php`, `database/factories/PaymentAttemptFactory.php`, `config/payment_attempts.php` (provider value to extractor class map).

**Call sites** (each wrapped by the logger's own safety, so no call site needs a try block):
| Provider | Where | Why there |
|---|---|---|
| Stripe | `StripeWebhookController`, after signature check and `ProcessedStripeEvent` insert, before the completed guard | has the full PaymentIntent and `$event->id`, `$event->created` |
| Revolut | `RevolutWebhookController`, after signature check and `ProcessedRevolutEvent` insert, before the completed guard | signed, has `event` and `order_id` |
| Square | `SquareWebhookController`, after signature check and `ProcessedSquareEvent` insert, before the completed guard | signed, has the full `payment` object and `event_id` |
| Viva | `HandleVivaWebhookJob`, right after `retrieveTransaction()` and the order code cross check, before the `statusId` return (the two blocks swap order) | webhook is unsigned, so only the re fetched transaction is trusted |
| Whop | `HandleWhopWebhookJob`, after the dedupe transaction commits, for `payment.succeeded` and `payment.failed` | job parses the body and owns dedupe |

`$context` keys passed to `record()` per provider: Stripe `event_id`, `created` (epoch seconds), `type`, with the controller passing `$event->data->object->toArray()` as the payload; Revolut `event`, `timestamp_ms` from the signed `Revolut-Request-Timestamp` header; Square `event_id`, `created_at` (the event's top level field); Viva `event_key`, `event_type_id`; Whop `webhook_id`. The payment is always resolved by the call site and passed in.

`VivaWebhookController::HANDLED_EVENT_TYPE_IDS` becomes `[1796, 1798]`, and the controller's completed guard moves after the dispatch. The job receives `eventKey` and `eventTypeId` as nullable properties that are not promoted constructor properties, read as `$this->eventTypeId ?? 1796`. That keeps jobs already on the queue working, because PHP skips the constructor when it unserializes a job. For `1798` the job logs and returns, and never reaches the status update. The `1798` id is unconfirmed against a live sandbox, and a code comment says so. Viva amounts arrive in major units, so the extractor converts to minor units with string math; currency codes other than 826 (gbp) and 840 (usd) use the payment's currency with `amount_reported` false.

`request` is null for every webhook row in this spec. Stripe success rows carry no card detail (the PaymentIntent holds only a payment method id), and card detail appears only on failed rows from `last_payment_error.payment_method.card`.

**Status and field mapping** (what each extractor keeps; anything not named is dropped):
| Provider | status | reference | error_code | reason | amount and currency | `response` allowlist | occurred_at |
|---|---|---|---|---|---|---|---|
| Stripe | `succeeded`, `failed` | PaymentIntent id | `decline_code` else `code` | `message` | PaymentIntent `amount`, `currency` | `status`, `latest_charge`, `last_payment_error.{type,code,decline_code,message,payment_method.type}`, card `brand`, `last4`, `funding`, `country` | event `created` |
| Revolut | `succeeded`, `failed` | order id | none | none | payment's own | `event`, `order_id` | time received |
| Square | `succeeded`, `failed`, `cancelled` | payment id | `card_details.errors[0].code` | `errors[0].detail` | `amount_money` | `status`, `card_details.status`, `errors[].{code,category,detail}`, card `card_brand`, `last_4`, `card_type`, `entry_method` | event `created_at` |
| Viva | `F` succeeded, `E` failed, `X` cancelled, other pending | transaction id | none | none | transaction `amount`, mapped `currencyCode` (826 gbp, 840 usd), else payment's own | `statusId`, `orderCode`, `eventTypeId`, `transactionTypeId` | transaction time, else received |
| Whop | `succeeded`, `failed` | Whop payment id | `failure_code` if present | `failure_message` if present | `subtotal` money object | `id`, `status`, `failure_code`, `failure_message`, `substatus`, `payment_method_type`, card `brand`, `last4` | `created_at` |

Whop failure field names and Viva status letters other than `F` are unconfirmed against live payloads. The extractor reads them defensively (missing means null, never an error), and the Follow-up list holds the check.

**Sanitizer secret key list** (case insensitive). Matched as a part of the key: `secret`, `token`, `password`, `api_key`, `apikey`, `access_token`, `authorization`, `signature`, `cvv`, `cvc`, `cvn`, `security_code`, `card_number`, `client_secret`, `private_key`, `public_key`. Matched only as a whole key or whole word segment (split on `_`), so `company`, `expand` and `reference_number` survive: `pan`, `number`.

**Controller and page**:
- `PaymentController::show` adds `'attemptLog' => $this->attemptLog($payment, $user)`. The method returns `null` unless `$user->hasRole('admin')` and the provider is one of Stripe, Revolut, Square, Viva, Whop, or any provider listed in `config('payment_attempts.extractors')`. It returns `{provider, rows}` with each row `{id, sequence, event, status, amount, currency, amount_reported, reference, error_code, reason, source, request, response, occurred_at}`, newest first, limit 100.
- `resources/js/components/payments/PaymentAttemptLog.vue` takes `{provider, rows}`, reuses `PaymentStatusBadge` (tones: `succeeded` to completed, `failed` to failed, `cancelled` to cancelled, `pending` to pending), `PaymentProviderBadge`, and the page's existing date and money helpers. The expanded panel is a small `AttemptDetails` list inside the same file or a sibling file. `Payments/Show.vue` renders it with `v-if="attemptLog"` beside the existing card.
- Types for the row live next to the component. Adding a provider changes neither.

**Value sourcing**:
| Action | Value produced or displayed | Source |
|---|---|---|
| `record()` | `payment_id` | the `Payment` the call site already resolved, always scoped by the provider account FK and excluding soft deleted payments (Stripe `stripe_payment_intent_id`, Revolut `revolut_order_id`, Square `square_payment_id` else `reference_id` fallback, Viva `viva_order_code`, Whop `metadata.payment_id` uuid); null means skip |
| `record()` | `provider` | the `PaymentProvider` argument |
| `record()` | `sequence` | `max(sequence) + 1` for the payment inside the locked transaction |
| `record()` | `event_key` | the extractor, per the mapping table |
| `record()` | `amount`, `currency`, `amount_reported` | event when it carries them, else the `Payment` row with `amount_reported` false |
| `record()` | `occurred_at` | event time per the mapping table (Stripe `created`, Revolut signed timestamp header, Square `created_at`, Viva transaction time, Whop `created_at`), else `now()` |
| Page | provider label | `payment.provider` already on the page |
| Page | amount display | row `amount` and `currency` through the page's existing money formatter |

**Key invariants**:
- A `payment_attempts` write can never change a payment's status, and never blocks or fails the request that caused it.
- `(provider, event_key)` and `(payment_id, sequence)` are unique at the database level.
- No raw payload is ever stored; only extractor allowlisted fields that also pass the sanitizer.
- The log is diagnostic only. The status logic in the existing jobs and resolvers stays the only writer of payment status.

**Security model**: Admins only, matching the other attempt cards (`attemptLog` is null for agents and the account role, and never sent to the page). Scope is PCI aware: no card number, expiry, CVV or BIN is stored, and `last4` with brand, funding and country is the most card detail kept. Nothing stored is a secret, so no `encrypted` cast is needed. Viva rows exist only after an API re fetch, so a forged webhook cannot write history.

**Configuration required**: none. No new env vars. `config/payment_attempts.php` holds the extractor map and the size limits.

**Critical test scenarios** (each maps to an acceptance criterion):
- Happy path per provider: a signed success event writes one `succeeded` row with the right reference, amount and sequence 1, verifies **AC-4** to **AC-8**
- Retry: two failed events then a success on one payment give sequence 1, 2, 3 in order, verifies **AC-3**
- Duplicate: the same event delivered twice, and a job run twice, give one row, verifies **AC-3**
- Late event: `failed` after `completed` writes a row and leaves the status `completed`, verifies **AC-9**
- Logging failure: drop the table or force an exception in the extractor, the webhook still returns 200 and the payment still becomes `completed`, verifies **AC-2**
- Secrets: a payload with card number, CVV, `client_secret` and tokens leaves none in any column, verifies **AC-10**
- Missing details: no `last_payment_error`, no amount, unknown payment id, malformed JSON body: no crash, dashes in the UI, no row for an unknown payment, verifies **AC-2**, **AC-12**
- Viva: unsigned `1796` or `1798` with a transaction that Viva does not confirm writes nothing (API returns an error); a confirmed `1798` writes `failed` and leaves the status alone, verifies **AC-7**
- Whop: `payment.failed` writes a row and leaves the status alone; a refund event writes nothing, verifies **AC-8**
- Auth: admin sees `attemptLog`, agent and account get `null`; a legacy provider payment gets `null`, verifies **AC-11**, **AC-14**
- Extensibility: a fake provider with a test extractor and one config line produces rows and renders, verifies **AC-15**

## Build plan

Build approach: none recorded in `AGENTS.md` (the GSD phases are history), so this uses the default, thin end to end slices (Tracer Bullet): prove one provider through every layer first, then thicken.

1. Migration, `PaymentAttempt` model, factory, `Payment::paymentAttempts()`, `config/payment_attempts.php`, satisfies **AC-1**
2. `AttemptData`, `AttemptExtractor`, `AttemptPayloadSanitizer`, `PaymentAttemptLogger` with transaction, sequence, dedupe and failure isolation, satisfies **AC-2**, **AC-3**, **AC-10**
3. Stripe thread: `StripeAttemptExtractor`, call in `StripeWebhookController`, `attemptLog` prop in `PaymentController::show`, `PaymentAttemptLog.vue` with expand panel, `Payments/Show.vue` wiring, satisfies **AC-4**, **AC-9**, **AC-11**, **AC-12**, **AC-13**
4. Revolut and Square extractors and controller calls, satisfies **AC-5**, **AC-6**, **AC-9**
5. Viva: extractor, `HandleVivaWebhookJob` log call and new event type argument, add `1798` for logging only, satisfies **AC-7**
6. Whop: extractor, call after the dedupe transaction for succeeded and failed, satisfies **AC-8**
7. Tests: `PaymentAttemptLoggerTest` (including inserting the same event twice, because SQLite ignores the row lock, and a sequence clash retry), one logging test file per provider (success, failure, retry, duplicate, late, missing details), `PaymentAttemptSanitizerTest` (including `company` and `reference_number` surviving and card like digits masked in text columns), `PaymentAttemptVisibilityTest` (including a provider with no extractor getting null), fake provider extensibility test, satisfies **AC-2** to **AC-16**
8. Confirm legacy untouched: run the full `php artisan test --parallel` suite, plus `npm run types:check` on touched files, and run the `provider-parity-check` skill, satisfies **AC-14**, **AC-16**
9. Add the Follow up documentation lines (below) after `/check verify`. This is a documentation task and satisfies no acceptance criterion.

## Consequences

**Positive**:
- Stripe, Revolut, Square, Viva and Whop finally have a visible decline trail, including the Viva and Whop failures that are dropped today.
- A new provider costs one extractor, one config line and one call, with no table and no screen.
- One database level dedupe and one sequence rule replace per provider logic.

**Negative / tradeoffs**:
- There are now two attempt systems (five legacy tables plus the shared one) until a migration spec moves the old ones.
- Revolut and Square events carry almost no failure reason, so those rows often show dashes. This is a limit of the data, not a bug.
- Revolut has no event id, so two real failures on one order collapse into one row (the existing `ProcessedRevolutEvent` key already drops the second).
- Logging runs inside the webhook request for the three signed providers, adding one small database write to each.
- Old payments have no history. The page says so.
- Square declines come from the charge call, not the webhook: Square refuses the payment outright and sends no `payment.updated`, so `ClientPaymentController::chargeSquare` logs a `failed` row with `source = call` for a 4xx answer (5xx and network errors are not logged, the outcome is unclear). If Square ever also sends a FAILED webhook for the same attempt, there will be two rows, because the call row has no payment id. Rows for soft deleted payments are not written, and existing rows survive a soft delete (the cascade fires only on a forced delete).
- The controllers' `exists()` then `create()` dedupe is not atomic, so a parallel duplicate can still 500 and be retried by the provider. The unique index keeps the log correct either way. This is existing behavior and out of scope.

**Neutral**:
- The Viva status letters `E` and `X`, the `1798` event id and the Whop failure field names are unconfirmed and read defensively.
- The migration is additive and safe to deploy first. If code ships before the migration, logging fails quietly (AC-2) and payments are unaffected. Rollback is to drop the table.
- `AGENTS.md` and `docs/agent.md` need a short pointer (see Follow up). `/sync` owns that.

## Follow-up

- [ ] Confirm Viva's failed event id (`1798`) and its transaction status letters in a live sandbox, then confirm the `E` and `X` mapping
- [ ] Capture one real Whop `payment.failed` body and confirm `failure_code` and `failure_message`
- [ ] Write a later spec to move MPGS, Authorize.net, NMI, Kurv and Worldpay onto the shared log (Clover is done, AC-17) (the engineer chose "migrate them later")
- [ ] After build, `/sync` should add a one line pointer to this log in `AGENTS.md` (Payment providers section) and a short "Payment attempt log" note in `docs/agent.md`
- [ ] Consider a read only lookup for Revolut and Square decline reasons if admins find the dashes unhelpful (the engineer chose webhook data only for now)
- [ ] Decide later whether agents should see a trimmed version (no `request` or `response`) of the log
