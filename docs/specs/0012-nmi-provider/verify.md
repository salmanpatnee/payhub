# Verify: NMI provider · spec 0012 · updated 2026-11-14
_Steps derived from spec 0012 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones._

## UI / manual (sandbox, staging)
- [x] Admin adds an NMI account with a bad public key (`nope`) → error, nothing saved → AC-1
- [x] Admin pastes a private key (or any wrong value) into the public key field → rejected, nothing saved (decided 2026-11-14: the generic "This is not a public tokenization key" message is accepted) → AC-1
- [x] Admin saves a valid account, reopens Edit → private key and webhook key blank with "Saved" placeholders, page source has no private key text; the public key is shown filled in (decided 2026-11-14: fine, it is public) → AC-1
- [x] Account saved without a webhook signing key → list shows "Webhooks are off for this account; payments settle by lookup only." → AC-1
- [x] Test connection with a good key → "Connected"; with a wrong key → "Specified API key not found"; no transaction appears in the NMI portal → AC-2
- [x] Agent opens `/admin/nmi-accounts` → 403 → AC-1
- [x] Create a payment on a USD NMI account → the form locks currency to the account's USD ("Locked to this account's currency"); a forced GBP request is refused by the server with "This NMI account only accepts USD payments." (`NmiAdminAndParityTest`, same rule for GBP accounts) → AC-3
- [x] Payment link for an NMI payment: card fields and the "Name on card" field show, name is prefilled from the client name → AC-5
- [x] Pay with sandbox card 4111 1111 1111 1111 (no 3DS) → success page, payment `completed` (email points skipped, decided 2026-11-14) → AC-7, AC-8, AC-9
- [x] Pay with a sandbox decline amount (0.01 or 0.99) → "Your card was declined. Please try a different card.", form stays, payment still `pending`, attempt row shows `declined` → AC-9, AC-11, AC-12
- [x] Decline three times → "Payment paused" notice (card lock); admin can release it → AC-12
- [x] Turn 3DS on, pay with a Test 3DS card, pass the challenge → success; fail the challenge → "We could not verify your card…", no sale in NMI, attempt row `3ds_failed` → AC-6
- [ ] Turn Google Pay on, pay with Google Pay → success without a 3DS step; record the Google Pay and 3DS hosts the browser blocks, then fix config → AC-5, AC-16
- [x] Close the tab right after paying, reopen the link → redirected to the success page → AC-10
- [x] During a real charge (browser fetch/XHR capture, request and response bodies, plus the page HTML and props) → the private key never appears; the card goes only to NMI frames and the token to our charge call (checked by hash, with a positive control; request headers are not visible to page scripts) → AC-5
- [x] Payment page (admin) shows the attempts panel with sequence, status, reason, transaction id → AC-11
- [x] CSV export, dashboard "Accounts Today", filters, provider badge and sidebar all show NMI → AC-4
- [x] After one charge attempt, edit amount/currency/account → rejected; delete → refused → AC-13
- [x] Register the staging webhook in the NMI portal, pay → webhook arrives, Payment completes once (staging run 2026-11-14: `processed_nmi_events` has 6 rows matching the 3 declines and 3 approvals, signing key accepted, no 401) → AC-14
- [ ] Replay the same webhook event from the NMI portal → no change (not run yet) → AC-14
- [x] Break the lookup (wrong key on the account) after a charge → sweep stops after 30 minutes with a warning and the attempt row says "No transaction found at NMI" → AC-15

## Value sourcing (one step per row of the spec's table)
- [x] Tamper the charge request body with a different amount and currency → NMI receives the amount and currency from the payment record → AC-7
- [x] Charge twice on one payment → the same `order_details.id` (the payment's `nmi_order_id`) is sent each time → AC-7, AC-9
- [x] Payment with a one word client name → first and last name both use that word → AC-7
- [x] Sandbox: no billing email is sent (NMI sandbox rejects any email but the merchant's own); confirmed in NMI query: email empty (other email points skipped, decided 2026-11-14) → AC-7
- [x] Reference code prefix, account name and `provider_reference` (transaction id, else order id) match the NMI account and payment → AC-4

## Commands
- [x] `php artisan test --parallel --filter=Nmi` → all pass → AC-1 to AC-16
- [x] `php artisan test --parallel` → all pass (1191 passed, 15 skipped on 2026-11-14; the `stripePost()` helper now lives in `tests/Pest.php`, so `NotificationTest` no longer depends on file order) → AC-4
- [x] `npm run build` then `git status public/build` → new hashed files present → AC-5

## Acceptance-criteria coverage
- Staging run 2026-11-14: approve, decline, card lock, 3DS fail/cancel and webhook delivery confirmed on payments NMI-001112 to NMI-001116. Open: the 3DS challenge frame is a fixed 600px tall, so on a short window the code input is below the fold; Google Pay and event replay not run.
- AC-1 · AC-2 admin steps above and `NmiAdminAndParityTest` · AC-3 currency steps · AC-4 export, dashboard, list, badge, sidebar · AC-5 pay page steps · AC-6 3DS steps · AC-7 to AC-9 charge and value steps (`NmiChargeTest`) · AC-10 close tab step · AC-11 attempts panel · AC-12 messages and card lock · AC-13 lock and limiter (`NmiChargeTest`) · AC-14 webhook steps (`NmiWebhookAndSweepTest`) · AC-15 sweep step · AC-16 CSP (`NmiPayPageTest`, plus the browser host check) · AC-17 Open questions 5, 6, 7, 10, 11 and the card detail decline codes, still to answer in a browser run
