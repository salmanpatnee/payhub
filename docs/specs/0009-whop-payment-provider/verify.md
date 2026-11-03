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
- [ ] `php artisan whop:register-webhook 1 --url=http://example.test` → refuses, non https URL, no Whop call → AC-12
- [ ] `php artisan whop:register-webhook 1 --url=https://<public host>/webhook/whop/1` against the sandbox → prints the table and stores an encrypted secret; running it again asks before replacing → AC-12, AC-13
- [ ] POST to `/webhook/whop/{id}` with a bad signature, a missing header, a timestamp 10 minutes old, or an account with no stored secret → 401 (403 for no secret), no job queued → AC-9
- [ ] Send a signed `payment.succeeded` with the right metadata and price → payment becomes `completed`, one notification queued; send the same `webhook-id` again → no change → AC-10
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
