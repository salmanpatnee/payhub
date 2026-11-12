---
name: provider-parity-check
description: "Check that a payment-related change implements all nine providers (Stripe, Revolut, Square, Viva, Clover, MPGS, Authorize.net, Whop, Kurv) in parallel rather than just one or two. Use after adding or modifying anything that touches Payment records, provider accounts, webhooks, pay pages, CSV exports, admin account CRUD, dashboard/filter surfaces, or client-facing receipt pages. Also use when explicitly asked to verify provider parity, check for missing provider support, or audit multi-provider consistency."
license: MIT
---

# Provider Parity Check

PayHub runs nine payment providers behind the `PaymentProvider` enum: **Stripe, Revolut, Square, Viva, Clover, MPGS, Authorize.net, Whop, Kurv** (values `stripe`, `revolut`, `square`, `viva`, `clover`, `mpgs`, `authorizenet`, `whop`, `kurv`). History shows this is where features quietly break: Square landed after Stripe/Revolut and several read-side surfaces (dashboard filters, "Accounts Today" account resolution) were missed on first pass. Every later provider is a candidate for the same mistake unless checked explicitly. Always read the current list from `app/Enums/PaymentProvider.php`; if it has grown past nine, check the new ones too.

This skill is a **verification pass**, not an implementation guide. Run it after a payment-touching change is written, before calling it done.

## The 6 surfaces that must stay in parallel

For any feature that touches payments, every provider should be represented on:

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

   For each hit, count how many of the nine providers are actually covered. Flag any branch that lists fewer than all of them — a `default` fallback that silently does nothing for the missing provider is the exact shape of the historical Square bug.

3. **Check structural symmetry.** For each provider, confirm the equivalent file/method exists wherever one provider has it:
   - Webhook controller and job (`App\Http\Controllers\*WebhookController`, `App\Jobs\Handle*WebhookJob`), or the provider's resolver where status comes from a lookup (`MpgsChargeResolver`, `AuthorizenetChargeResolver`, `KurvChargeResolver`, Clover's charge reply)
   - Provider-specific account model + admin CRUD views/routes
   - Provider-specific columns on `Payment` (`*_account_id` plus the provider's id column, e.g. `stripe_payment_intent_id`, `revolut_order_id`, `square_payment_id`, `whop_checkout_configuration_id`, `kurv_request_id`)

   If the diff added or changed one of these for one provider, check the same file exists (or was equivalently updated) for the others.

4. **Confirm the new/changed surface is reachable for every provider** — e.g. if a dashboard filter dropdown gained a new option, check it lists all nine provider account types, not just the ones that existed when the filter was first built.

5. **Don't flag intentional asymmetry.** Some differences are correct, not bugs (root `AGENTS.md` lists them):
   - Square accounts are single-currency (`square_accounts.currency`); Kurv takes USD only; Whop takes USD and GBP; PKR is MPGS only.
   - Status sources differ: most providers use webhooks only, while Clover, MPGS, Authorize.net and Kurv read status from the gateway's own reply or a lookup through one resolver. Check that the guarantee exists for each, not that it is implemented identically.
   - Revolut webhooks carry no event id (idempotency via `order_id:event_type`); others carry event ids or use their own scheme.
   - One tap wallets exist only where the provider supports them on our integration (see root `AGENTS.md`).

## Output

Report as a short checklist, one line per provider per surface touched by the change:

```
Surface: <name>
  Stripe:        ✅ / ❌ (missing: <what>)
  Revolut:       ✅ / ❌
  Square:        ✅ / ❌
  Viva:          ✅ / ❌
  Clover:        ✅ / ❌
  MPGS:          ✅ / ❌
  Authorize.net: ✅ / ❌
  Whop:          ✅ / ❌
  Kurv:          ✅ / ❌
```

Only call the change complete when every touched surface is ✅ across all providers, or the asymmetry is explicitly justified (e.g. currency lock, provider-specific status source).
