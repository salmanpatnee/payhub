# 0016. Rationale: one shared payment attempt log

## Context

> ⚠️ Premise note: "Payment attempt" does not mean the same thing for every provider. MPGS, Authorize.net and NMI log a charge call that PayHub makes itself, so they can write a row before the call and a timeout still leaves a trace. Stripe, Revolut, Square, Viva and Whop take their status from webhooks only, so PayHub makes no charge call to log. For them an attempt can only be a verified outcome event. The design below treats it that way, and a future provider that does make its own charge calls fits through the `source`, `request` and `response` columns.

PayHub has five attempt history tables (`mpgs_charge_attempts`, `authorizenet_charge_attempts`, `nmi_charge_attempts`, `kurv_lookup_attempts`, `worldpay_charge_attempts` and `worldpay_lookup_attempts`) plus an unused Clover one. Each has its own model and its own branch of the payment detail page. Each new provider has so far added a table, a model and more provider checks in `Payments/Show.vue`.

Stripe, Revolut, Square, Viva and Whop have no history. Their webhook jobs get thin data: Stripe's job sees the whole PaymentIntent, Revolut's sees an event name and an order id, Square's sees a payment id and a status, Viva's job re fetches the transaction itself, and Whop's job reads a raw body but ignores `payment.failed`. Viva's controller only handles the success event, so a failed Viva payment leaves no trace. Admins asking "why did this client's payment fail" have no answer inside PayHub for these five.

The log must also respect hard rules already in `AGENTS.md`: status comes from webhooks only for these providers, nothing from the browser is trusted, secrets and card data never reach logs, and a logging problem must not slow or break a webhook.

## Options considered

### Option 1: Another table per provider

Follow the existing pattern: one table, model and Vue branch for each of the five.

**Pros**:
- Matches existing code exactly, no new concepts.
- Each table can fit its provider's fields.

**Cons**:
- Five more tables and five more branches in `Show.vue`, and the same cost again for every future provider.
- The page component already has provider checks scattered through one template.

### Option 2: One shared table with per provider extractors

One `payment_attempts` table with common columns plus JSON for provider details, one logger service, one Vue component. A small extractor per provider turns its event into the shared shape.

**Pros**:
- A new provider needs one class and one config line.
- Dedupe, sequence, sanitizing and failure isolation are written once.
- The page component never learns provider names.

**Cons**:
- A second attempt system lives beside the five legacy ones until they migrate.
- JSON columns are not queryable in the way real columns are (acceptable, as the common fields are real columns).

### Option 3: Shared table and move the legacy providers now

Build Option 2 and migrate MPGS, Authorize.net, NMI, Kurv and Worldpay onto it in the same change.

**Pros**:
- One system, no duplication.

**Cons**:
- Those flows hold the double charge guards and resolver logic. Touching them risks live payments for no visible gain.
- The engineer said to keep them working without breaking or replacing them, and chose "migrate them later".

### Option 4: Store the raw webhook payload in a generic events table

Keep every webhook body as is and read what is needed at display time.

**Pros**:
- Least extraction code, nothing lost.

**Cons**:
- Webhook bodies can carry a Stripe `client_secret` and other secrets (the Stripe job comment already warns about this). Storing raw bodies breaks the "never store secrets" rule.
- The page would need provider knowledge to read each body.

## Rationale

Option 2 fits the forces. The cost that keeps growing is the per provider screen and table, and a shared shape removes it. The sensitive part is the data, not the table: Stripe objects carry `client_secret`, so an allowlist per provider, backed by a sanitizer, is safer than storing raw bodies (Option 4) and safer than a denylist alone. Keeping the legacy five untouched (against Option 3) respects the double charge guards and the engineer's explicit choice, and makes the change purely additive: a new table, a new prop and a new card. The engineer asked for the strangler instinct, and that is what a later migration spec will do.

Putting the log call in the controller for the three signed providers (Stripe, Revolut, Square) is a deliberate choice. The controllers hold the verified payload and the event id, and they run before the "already completed" guard, which is the only way a late failed event can be recorded without touching status logic. Viva and Whop log from their jobs because Viva's webhook is unsigned (only the API re fetch is trustworthy) and Whop's dedupe and parsing already live in the job.

Status uses a small shared set (`succeeded`, `failed`, `cancelled`, `pending`) with the provider's own word kept in `event`, so one badge mapping serves every provider. The dedupe key is the provider's own event id where one exists, enforced by a database unique index, because a code check alone loses races between two queue retries. Sequence is taken inside a transaction that locks the payment row, so two events at once cannot share a number. Logging failures are swallowed and reported to Sentry because the log is diagnostic and must never decide whether a payment completes.

The amount rule (what the provider reported, else the payment's, with `amount_reported` saying which) keeps the column always filled for display while letting a mismatch stay visible.

## Evidence: how each provider reaches the log today

| Provider | Trusted point | Data in hand | Failure event handled today | Gap |
|---|---|---|---|---|
| Stripe | `StripeWebhookController` (signature checked, `ProcessedStripeEvent`) | full PaymentIntent array, event id, `created` | `payment_intent.payment_failed` sets `failed` | no history, no reason shown |
| Revolut | `RevolutWebhookController` (signature checked, `ProcessedRevolutEvent`, key `order:event`) | event name, order id | `ORDER_PAYMENT_FAILED` sets `failed` | no reason, no amount, no event id |
| Square | `SquareWebhookController` (signature checked, `ProcessedSquareEvent`) | whole `payment` object, `event_id` | `FAILED`, `CANCELED` | only id and status are passed to the job |
| Viva | `HandleVivaWebhookJob` after `retrieveTransaction()` | transaction from Viva's API | none (only `1796` is handled) | failures leave no trace |
| Whop | `HandleWhopWebhookJob` (signed, `ProcessedWhopEvent`) | raw body, `WhopWebhookPayload` | `payment.failed` is logged to the Laravel log and ignored | failure reason not kept |

Existing code read for this spec: `HandleStripeWebhookJob`, `HandleRevolutWebhookJob`, `HandleSquareWebhookJob`, `HandleVivaWebhookJob`, `HandleWhopWebhookJob`, the five webhook controllers, `WhopWebhookPayload`, `PaymentController::attemptHistory` and `Payments/Show.vue` (the inline attempts card), `NmiChargeAttempt`, and `AGENTS.md`.
