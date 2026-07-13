---
name: provider-parity-check
description: "Check that a payment-related change implements Stripe, Revolut, Square, and Viva in parallel rather than just one or two providers. Use after adding or modifying anything that touches Payment records, provider accounts, webhooks, pay pages, CSV exports, admin account CRUD, dashboard/filter surfaces, or client-facing receipt pages. Also use when explicitly asked to verify provider parity, check for missing Square/Viva/Revolut support, or audit multi-provider consistency."
license: MIT
---

# Provider Parity Check

PayHub runs four payment providers behind the `PaymentProvider` enum: **Stripe, Revolut, Square, Viva**. History shows this is where features quietly break: Square landed after Stripe/Revolut and several read-side surfaces (dashboard filters, "Accounts Today" account resolution) were missed on first pass. Viva landed later still. Every payment feature is a candidate for the same mistake unless checked explicitly.

This skill is a **verification pass**, not an implementation guide. Run it after a payment-touching change is written, before calling it done.

## The 6 surfaces that must stay in parallel

For any feature that touches payments, all four providers should be represented on:

1. **Pay page** — client-facing payment collection UI/flow
2. **CSV export** — admin export of payment/transaction data
3. **Webhook handling** — event ingestion, signature verification, status sync
4. **Admin account CRUD** — creating/editing/listing provider accounts
5. **Dashboard / filter surfaces** — dashboard widgets, filter dropdowns, "Accounts Today" style aggregations
6. **Client-facing receipt pages** — payment confirmation/receipt views

## How to run the check

1. **Identify what changed.** Get the diff (`git diff` / `git status`) or the set of files just edited.

2. **Grep for provider-branching patterns** across the codebase (not just the diff) — these are the places parity breaks:
   - `PaymentProvider::` enum usage (`match`, `switch`, `===` comparisons)
   - `in_array($provider, [...])` or `in_array($account->provider, [...])`
   - Two/three-way ternaries keyed on provider (`$provider === 'stripe' ? ... : ...`)
   - Blade/Vue `v-if`/`v-else-if` chains keyed on `payment.provider`

   For each hit, count how many of the four providers (`stripe`, `revolut`, `square`, `viva`) are actually covered. Flag any branch that lists fewer than 4 — a `default` fallback that silently does nothing for the missing provider is the exact shape of the historical Square bug.

3. **Check structural symmetry.** For each of the 4 providers, confirm the equivalent file/method exists wherever one provider has it:
   - `App\Http\Controllers\{Stripe,Revolut,Square,Viva}WebhookController`
   - `App\Jobs\Handle{Stripe,Revolut,Square,Viva}WebhookJob`
   - Provider-specific account model + admin CRUD views/routes
   - Provider-specific client fields on `Payment` (e.g. `stripe_payment_intent_id` / `revolut_order_id` / `square_payment_id` / equivalent for Viva)

   If the diff added or changed one of these for one provider, check the same file exists (or was equivalently updated) for the other three.

4. **Confirm the new/changed surface is reachable for all 4 providers** — e.g. if a dashboard filter dropdown gained a new option, check it lists all 4 provider account types, not just the ones that existed when the filter was first built.

5. **Don't flag intentional asymmetry.** Some differences are correct, not bugs:
   - Square accounts are single-currency (`square_accounts.currency`) — `StorePaymentRequest`/`UpdatePaymentRequest` reject currency mismatches for Square only. This is documented, expected behavior, not a parity gap.
   - Revolut webhooks carry no event id (idempotency via `order_id:event_type`); Square/Stripe do carry event ids. Different verification mechanics per provider are fine — the check is whether verification exists for all 4, not whether it's implemented identically.

## Output

Report as a short checklist, one line per provider per surface touched by the change:

```
Surface: <name>
  Stripe:  ✅ / ❌ (missing: <what>)
  Revolut: ✅ / ❌
  Square:  ✅ / ❌
  Viva:    ✅ / ❌
```

Only call the change complete when every touched surface is ✅ across all four providers, or the asymmetry is explicitly justified (e.g. currency lock, provider-specific verification mechanics).
