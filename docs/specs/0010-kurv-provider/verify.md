# Verify: Kurv provider · spec 0010 · updated 2026-11-05
_Steps derived from spec 0010 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones. This first version covers build steps 1 to 5 only. Steps for the checkout, pay page, sweep and attempts panel are added when those steps are built._

## UI / manual
- [ ] Sign in as admin, open Kurv Accounts in the sidebar, add an account with a `kp_test_` key and Sandbox → saved, key not shown again → AC-1
- [ ] Add an account with a `kp_live_` key and Sandbox selected → error that the key does not match the environment → AC-1
- [ ] Edit an account, leave the key blank, change only the name → saved, Test connection still works with the stored key → AC-1
- [ ] Edit an account, leave the key blank, switch Environment to Production → error (the stored key is a test key) → AC-1
- [ ] Press Test connection with a good sandbox key → "Connected successfully" → AC-1
- [ ] Press Test connection with a wrong `kp_test_` key → "Wrong key or missing permission" → AC-1
- [ ] Try to delete an account that has a payment → blocked with a message; delete an unused account → removed → AC-1
- [ ] Deactivate then activate an account → status changes, and an inactive account is missing from the new payment account list → AC-1
- [ ] Sign in as agent and as account role, open `/admin/kurv-accounts` → 403 → AC-1
- [ ] Create a payment as admin with a Kurv account, USD, with a client email → saved, reference code uses the account prefix → AC-2, AC-3
- [ ] Create a Kurv payment with GBP, then with PKR → both show "This Kurv account only accepts USD payments." → AC-2
- [ ] Create a Kurv payment with no client email → "Client email is required for Kurv payments." → AC-2
- [ ] Give an agent a USD slot that points to a Kurv account (Users, edit) → saved; that agent can create a Kurv payment without choosing an account → AC-3
- [ ] Give an agent a GBP slot on a Kurv account → rejected → AC-2

## Pay page and checkout (build steps 6 and 7)
- [ ] Open a Kurv payment link as a client → brand layout, amount and "Pay now"; the consent box is ticked and the three policies open in a read only dialog → AC-4
- [ ] Press Pay now with the consent box cleared → "You must agree to the Terms..." and no request to Kurv → AC-4
- [ ] Press Pay now with consent → browser goes to a `sandbox-pay.kurv.app` page; the payment now shows a Kurv request id and the amount, currency, account and brand are locked in the edit form → AC-5, AC-6
- [ ] Go back and press Pay now again → same Kurv page opens, and the Kurv sandbox shows only one payment request for the reference → AC-5
- [ ] Pay with a sandbox card on Kurv and let it return → page says "We are confirming your payment. Please do not pay again.", the address bar loses the query values, and within about 10 seconds you land on the Success page → AC-9
- [ ] Reload the page while it says confirming → it keeps waiting (does not show Pay now) → AC-9
- [ ] Press cancel on Kurv's page (back to PayHub without `kurv_return`) → "Pay now" is shown again → AC-9
- [ ] Pay with a declining sandbox card, return → within about a minute the page shows "Your payment was not completed. You can try again." with Pay now, and the same Kurv link is used → AC-9, AC-11
- [ ] Wait 10 minutes on the confirming state without paying → "Still confirming, you will get a receipt by email." → AC-9
- [ ] Open the link of a completed payment → goes to Success; of a cancelled one → "unavailable" page; with the account deactivated → "unavailable" page → AC-4
- [ ] Call the checkout endpoint 11 times in an hour → the 11th gives a JSON 429 "Too many attempts. Please try again later." and makes no Kurv call → AC-6
- [ ] View page source and the Inertia props of the pay page → no API key, no `kurv_payment_url`, no Kurv request id; the Content Security Policy header is the same as a Stripe page → AC-4, AC-14

## Commands
- [ ] `php artisan test --parallel --filter=Kurv` → all green (52 tests at the time of writing) → AC-1, AC-2, AC-6, AC-7, AC-8, AC-11, AC-12, AC-14
- [ ] `php artisan migrate:status` → the three `2026_11_05_00000[2-4]` Kurv migrations show `Ran` → AC-1, AC-11
- [ ] `php artisan tinker --execute='echo \App\Models\KurvAccount::factory()->create()->getRawOriginal("api_key");'` → prints an encrypted value, not `kp_test_placeholder_for_dev_only` → AC-1

## Value sourcing (one step per row of the spec table, for the parts built so far)
- [ ] Callback: open `POST /webhook/kurv/{id}` with a wrong token, an unknown payment UUID, a payment of another account, and an unknown account id → always 200, nothing queued, status unchanged → AC-8
- [ ] Callback with a good token and a body that claims `status: success` → 200, a lookup is queued, the payment stays `pending` until Kurv's own API says paid → AC-7, AC-8
- [ ] Resolver, which Kurv request: change the callback body's `transaction_id` to another id → ignored, the saved `kurv_request_id` is what is looked up → AC-7
- [ ] Resolver, paid check: Kurv returns success with a different amount, a different currency, a pre-authorization type, or another transaction id → outcome `mismatch`, status unchanged, an error is logged → AC-7
- [ ] Resolver, paid time: Kurv returns `payment_date` 09:30 UTC → `paid_at` shows 14:30 in the app timezone (Pakistan time) in the admin payment page → AC-7
- [ ] Resolver, history row: Kurv reports a paid request with no `payment_id` and the history lists two rows for the reference with different transaction ids → only the row with the saved transaction id is used → AC-7
- [ ] Resolver, a decline: a failed history row appears for the transaction → one `declined` attempt, status stays `pending`; the same old row on the next lookup is not a second decline → AC-11
- [ ] Resolver, unknown values: Kurv returns a status PayHub does not know, or a 404, 422, 429 or 500 → outcome `unknown` or `error`, status unchanged, no crash → AC-7, AC-11
- [ ] Resolver, completed payment: a second callback arrives and the history shows a second successful payment id → outcome `duplicate`, status and `kurv_payment_id` unchanged, an error is logged → AC-12
- [ ] Locks: edit amount, currency, account or brand of a Kurv payment whose link exists and is not closed → rejected; after `kurv_link_closed_at` is set an amount change is accepted and the saved link columns are cleared → AC-6
- [ ] Delete lock: delete a Kurv payment with an open link → blocked; after the link is closed → deleted → AC-6
- [ ] Callback token in logs: send a callback with a bad token and read `storage/logs` → only the first 6 characters of the token appear, never the whole value → AC-14
- [ ] Sandbox smoke check (needs the key in `.env` as `KURV_KEY`, never printed): create a payment request through `KurvClient`, then `getPaymentRequest` on its id → status `pending`, amount and currency read back as the same integer cents and `usd` → AC-5 groundwork, AC-13

## Acceptance-criteria coverage
- AC-1 covered by the account management steps and the migration and encryption commands
- AC-2 covered by the GBP, PKR, email and agent slot steps
- AC-3 partly covered (account lists and agent assignment); the dashboard, export, badge and client pages come with build step 10
- AC-6 covered by the lock and delete steps (the checkout limiter and lock come with build step 6)
- AC-7 covered by the resolver steps
- AC-8 covered by the callback steps
- AC-11 partly covered (attempt rows and outcomes); the admin attempts panel and fixed client messages come with build steps 7 and 9
- AC-12 covered by the completed payment step
- AC-14 partly covered (token masking); the CSP and key logging checks are finished with the pay page
- AC-4, AC-5, AC-9 covered by the pay page and checkout steps
- AC-10 (the sweep) is not built yet and has no steps here
- AC-13 covered in part by the sandbox smoke check; the browser items (return URL values, cancel URL, callback retries, double payment) stay open in the spec's Follow-up
