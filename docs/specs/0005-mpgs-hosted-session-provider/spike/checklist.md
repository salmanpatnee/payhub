# Spike checklist: spec 0005 (AC-13)

Throwaway. Run it once you have a **test** merchant from the acquiring bank with Hosted Session and 3DS enabled. Write each answer into the spec's `## Follow-up`. If an answer changes a field name, endpoint or sequence, run `/architect` before steps 4 to 7 are built.

## Before you start

- [ ] Test merchant id, integration password, and the gateway host (ask the bank which host; Open question 7).
- [ ] Which currencies are enabled on the test merchant (USD, GBP).
- [ ] Bank test card numbers: one frictionless 3DS, one challenge 3DS, one decline, one insufficient funds.
- [ ] Set the env vars (PowerShell: `$env:MPGS_SPIKE_HOST = '...'`). The password is read from the environment and never printed. Do not paste it into chat.

## Runs

| # | Run | Answers |
|---|---|---|
| 1 | `php spike.php session` | Q1 (session id, API version works), Q6 (is create+discard fine for test connection), Q7 (same host test and live) |
| 2 | `php spike.php session-update <sessionId> <orderId> 10.50 USD` | Q3 (amount/currency on the session; any conflict with sending them on PAY) |
| 3 | Open the hosted fields page from the gateway docs for your API version, with the session id. Note the exact `session.js` URL | Q1 (URL pattern, 3DS library URL and version) |
| 4 | In the browser: enter a test card, run 3DS initiate then authenticate (frictionless, then challenge). Record callbacks and where the challenge renders | Q4 (sequence, inline rendering, whether name + email alone suffice, no billing address) |
| 5 | `php spike.php pay <sessionId> <orderId> <authTxnId> 10.50 USD` | Q2 (success result, gateway code, transaction type/result, order status, captured amount, receipt), Q11 (length limits on reference), Q12 (codes) |
| 6 | `php spike.php order <orderId>` | Q8 (how to find the PAY transaction by id in the list) |
| 7 | Repeat step 5 with a decline card, an insufficient funds card, and a currency the merchant has NOT enabled | Q9 (error shape for a disabled currency: must be hard error, not decline), Q12 (decline codes) |
| 8 | `php spike.php webhook-url`, then run a PAY with a public notify URL | Q5 (notificationUrl honoured, secret header, JSON body, two notifications on 3DS) |
| 9 | Note the session expiry (wait, then try to PAY on the old session) | Q12 (real session lifetime) |
| 10 | From the browser network tab and console, list every host loaded and framed | Q10 (CSP hosts; whether `fullScreenRedirect` is needed for any bank) |

## What to hand back

Paste the **response bodies** (not the password) for runs 1, 2, 5, 6, 7 and the webhook delivery. Redact card numbers. Then I update `MpgsClient`, `MpgsChargeClassifier` and the CSP in one pass; their field names live in a single place each.
