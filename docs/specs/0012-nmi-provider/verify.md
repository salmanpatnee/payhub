# Verify: NMI provider · spec 0012 · updated 2026-11-13
_Steps derived from spec 0012 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones._

## UI / manual (sandbox, staging)
- [ ] Admin adds an NMI account with a bad public key (`nope`) → error, nothing saved → AC-1
- [ ] Admin pastes the private key into the public key field → "This looks like a private key. Paste the public tokenization key." → AC-1
- [ ] Admin saves a valid account, reopens Edit → keys are blank, "Saved" placeholders show, page source has no key text → AC-1
- [ ] Account saved without a webhook signing key → list shows "Webhooks are off for this account; payments settle by lookup only." → AC-1
- [ ] Test connection with a good key → "Connected"; with a wrong key → "Specified API key not found"; no transaction appears in the NMI portal → AC-2
- [ ] Agent opens `/admin/nmi-accounts` → 403 → AC-1
- [ ] Create a GBP payment on a USD account → "This NMI account only accepts USD payments." → AC-3
- [ ] Payment link for an NMI payment: card fields and the "Name on card" field show, name is prefilled from the client name → AC-5
- [ ] Pay with sandbox card 4111 1111 1111 1111 (no 3DS) → success page, payment `completed`, one notification email → AC-7, AC-8, AC-9
- [ ] Pay with a sandbox decline amount (0.01 or 0.99) → "Your card was declined. Please try a different card.", form stays, payment still `pending`, attempt row shows `declined` → AC-9, AC-11, AC-12
- [ ] Decline three times → "Payment paused" notice (card lock); admin can release it → AC-12
- [ ] Turn 3DS on, pay with a Test 3DS card, pass the challenge → success; fail the challenge → "We could not verify your card…", no sale in NMI, attempt row `3ds_failed` → AC-6
- [ ] Turn Google Pay on, pay with Google Pay → success without a 3DS step; record the Google Pay and 3DS hosts the browser blocks, then fix config → AC-5, AC-16
- [ ] Close the tab right after paying, reopen the link → redirected to the success page → AC-10
- [ ] Open the browser devtools Network tab during a charge → private key never appears in any request, response or page prop → AC-5
- [ ] Payment page (admin) shows the attempts panel with sequence, status, reason, transaction id → AC-11
- [ ] CSV export, dashboard "Accounts Today", filters, provider badge and sidebar all show NMI → AC-4
- [ ] After one charge attempt, edit amount/currency/account → rejected; delete → refused → AC-13
- [ ] Register the staging webhook in the NMI portal, pay → webhook arrives, Payment completes once; replay the same event → no change → AC-14
- [ ] Break the lookup (wrong key on the account) after a charge → sweep stops after 30 minutes with a warning and the attempt row says "No transaction found at NMI" → AC-15

## Value sourcing (one step per row of the spec's table)
- [ ] Tamper the charge request body with a different amount and currency → NMI receives the amount and currency from the payment record → AC-7
- [ ] Charge twice on one payment → the same `order_details.id` (the payment's `nmi_order_id`) is sent each time → AC-7, AC-9
- [ ] Payment with a one word client name → first and last name both use that word → AC-7
- [ ] Sandbox: payment `client_email` that is not the merchant's own address → NMI rejects the email (sandbox rule); set it to the merchant's own address for sandbox tests → AC-7
- [ ] Reference code prefix, account name and `provider_reference` (transaction id, else order id) match the NMI account and payment → AC-4

## Commands
- [ ] `php artisan test --parallel --filter=Nmi` → all pass → AC-1 to AC-16
- [ ] `php artisan test --parallel` → one known order dependent failure (`NotificationTest` calls `stripePost()` defined in another file); passes alone → AC-4
- [ ] `npm run build` then `git status public/build` → new hashed files present → AC-5

## Acceptance-criteria coverage
- AC-1 · AC-2 admin steps above and `NmiAdminAndParityTest` · AC-3 currency steps · AC-4 export, dashboard, list, badge, sidebar · AC-5 pay page steps · AC-6 3DS steps · AC-7 to AC-9 charge and value steps (`NmiChargeTest`) · AC-10 close tab step · AC-11 attempts panel · AC-12 messages and card lock · AC-13 lock and limiter (`NmiChargeTest`) · AC-14 webhook steps (`NmiWebhookAndSweepTest`) · AC-15 sweep step · AC-16 CSP (`NmiPayPageTest`, plus the browser host check) · AC-17 Open questions 5, 6, 7, 10, 11 and the card detail decline codes, still to answer in a browser run
