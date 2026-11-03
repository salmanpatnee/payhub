# Verify: Whop payment provider · spec 0009 · updated 2026-11-02
_Steps derived from spec 0009 acceptance criteria. Covers build plan steps 1 to 4 only (accounts, webhook, form rules). Steps for the charge endpoint and pay page (AC-4 to AC-8, AC-14) are added when those steps are built. `/check verify` runs these; `/test` locks the durable ones._

## UI / manual
- [ ] Sign in as admin, open Whop Accounts in the sidebar, add an account with a sandbox company ID and API key, then click Test connection → "Connected successfully" with a good key; "Wrong key" (401), "Missing permission" (403), "Wrong company ID" (404) with bad values → AC-1
- [ ] Save the account, open Edit → the API key and webhook secret fields are empty, only a masked bar shows when a value is stored; view page source and confirm neither secret appears → AC-1
- [ ] Add a second active account with the same company ID → blocked with "Another active Whop account already uses this company ID." Deactivate the first, then it saves → AC-1
- [ ] Create a payment as admin, pick a Whop account, USD then GBP → both save; choose PKR → rejected → AC-2
- [ ] Create a Whop payment with no client email → "Client email is required for Whop payments." → AC-4
- [ ] Create a user with the agent role, give them a Whop account for GBP, sign in as that agent and create a payment with no email → email error; with an email → payment is created on the Whop account → AC-2, AC-4
- [ ] Sign in as an agent and as an account user, open /admin/whop-accounts → 403 → AC-1
- [ ] Open a Whop payment in the payments list → the provider badge reads Whop and the provider filter has a Whop option → AC-3

## Commands
- [ ] `php artisan test --parallel --filter=Whop` → all pass → AC-1, AC-2, AC-6, AC-9, AC-10, AC-11, AC-12
- [x] `php artisan whop:register-webhook 1 --url=http://example.test` → refuses, non https URL, no Whop call → AC-12
- [ ] `php artisan whop:register-webhook 1 --url=https://<public host>/webhook/whop/1` against the sandbox → prints the table and stores an encrypted secret; running it again asks before replacing → AC-12, AC-13
- [ ] POST to `/webhook/whop/{id}` with a bad signature, a missing header, a timestamp 10 minutes old, or an account with no stored secret → 401 (403 for no secret), no job queued → AC-9
- [x] Send a signed `payment.succeeded` with the right metadata and price → payment becomes `completed`, one notification queued; send the same `webhook-id` again → no change → AC-10
- [ ] Send a signed `payment.succeeded` with a wrong amount, wrong currency, another account's payment, or a cancelled payment → status unchanged, an error line in the log → AC-10
- [ ] Send signed `payment.failed`, a refund and a dispute event → 200, payment status unchanged, info line in the log → AC-11
- [ ] Try to change amount, currency or account on a payment whose `whop_charge_started_at` is set → rejected; changing the name still works → AC-6

## Value sourcing
- [ ] Webhook account and secret come from the route's account row: send an event signed with account A's secret to account B's URL → 401 → AC-9
- [ ] Webhook payment comes from `metadata.payment_id` and is checked against the route's account → AC-10
- [ ] Amount compared is the sandbox confirmed price field (`WhopWebhookPayload::PRICE_FIELD`, currently `subtotal`); vary the price with a tax or fee in the sandbox and confirm it still matches → AC-10, AC-13
- [ ] Amounts convert exactly for odd cents (12.34, 0.10) and GBP → AC-10

## Acceptance-criteria coverage
- AC-1 covered by steps 1 to 3, 7 · AC-2 by steps 4, 6 · AC-3 by step 8 (partial, parity sweep is build plan step 7) · AC-4 by steps 5, 6 (email rule only) · AC-6 by the last command step (edit lock only) · AC-9 by the signature steps · AC-10 by the completion steps · AC-11 by the other events step · AC-12 by the two register steps · AC-13 by the register step and the price field step
- Not covered yet: AC-5, AC-7, AC-8, AC-14 and the rest of AC-4, AC-6

## Checkout and pay page (build plan steps 5 to 10, added 2026-11-03)

### UI / manual
- [ ] Edit a Whop account, switch checkout mode between Embed and Redirect, save → value persists; invalid value is rejected → AC-1
- [ ] Open a Whop pay link in `redirect` mode, tick consent, press Pay now → browser goes to Whop's hosted checkout; pay with `4242 4242 4242 4242` → returns to the pay page, shows "We are confirming your payment. Please do not pay again.", then Success once the webhook lands → AC-4, AC-7, AC-8
- [x] Return with a failed card (`4000 0000 0000 0002`) → page shows Pay now again, status stays `pending` (embed: the checkout stays open for a retry; staging 2026-11-03) → AC-7, AC-11
- [x] Reload during the confirming state → still shows confirming, not Pay now; address bar has no `payment` or `status` → AC-7, AC-8
- [ ] Leave the tab confirming for 10 visible minutes → "Still confirming, you will get a receipt by email" → AC-8
- [x] In `embed` mode (works in the sandbox, staging 2026-11-03): the card fields mount on load with no Pay now step, cards only, Whop's header and email field hidden, email and billing name prefilled → AC-4, AC-14
- [ ] Deactivate the account → pay link shows Unavailable; a webhook for a mid flight payment still completes it → AC-4, AC-9
- [ ] After Pay now, try to change amount, currency, account, brand, or delete the payment in admin → all refused → AC-6

### Commands
- [x] `php artisan test --filter=Whop` → all pass (77 tests, 300 assertions on 2026-11-03) → AC-1 to AC-12, AC-14
- [ ] `curl -X POST https://<host>/pay/<uuid>/whop/checkout` 11 times in an hour → the 11th returns 429 with no Whop call → AC-6
- [ ] Open the pay page of a Whop `redirect` account and a Stripe payment, read the `Content-Security-Policy` header → no `whop.com` host in either; embed account adds `https://cdn.whop.com` and the API origin → AC-14
- [ ] Export CSV, filter the dashboard by Whop, check "Accounts Today" → Whop payment appears with the Whop payment id → AC-3

### Value sourcing
- [ ] Create a GBP payment with 12.34 → Whop checkout shows 12.34 GBP; 0.10 shows 0.10 → AC-5
- [ ] Flip the account mode between page load and Pay now → the endpoint reply's mode wins → AC-4
- [ ] Pay with a Whop tax or fee in play → webhook `subtotal` still equals the payment amount, otherwise fix `WhopWebhookPayload::PRICE_FIELD` → AC-10, AC-13
- [x] Trigger a Whop retry of a webhook and check its `webhook-timestamp` is fresh (Open question 6, answered 2026-11-03: replaying an 11 minute old delivery from Whop's dashboard returned 200 OK) → AC-9

## Staging run 2026-11-03 (embed mode, `QUEUE_CONNECTION=sync`)
- [x] Success `4242 4242 4242 4242`: the page turns from confirming to the success page with the reference code; delivery 200 OK; `subtotal`, `total` and `usd_total` are `100.00` `usd`, `metadata.payment_id` is the payment uuid → AC-7, AC-8, AC-10, AC-13
- [x] Decline `4000 0000 0000 0002`, then pay again on the same link with `4242`: `payment.failed` 200 with no status change, then `payment.succeeded` completes → AC-7, AC-11
- [x] 3D Secure `5385 3083 6013 5181` (password `Checkout1!`) completes → AC-4
- [x] Reload while confirming keeps the waiting message → AC-8
- [x] GBP payment completes → AC-5, AC-10
- [x] Replay from Whop's dashboard of an 11 minute old event → 200 OK → AC-9
- [ ] Not run yet: `redirect` mode, rate limit (11 posts), 10 visible minutes confirming, deactivated account, wrong amount or currency, a signature from another account, admin edit locks on a live payment, a payment with Whop tax or fees, CSP header on a `redirect` account page, refund and dispute events
