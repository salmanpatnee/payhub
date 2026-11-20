# Worldpay testing guide (Try environment)

How to test a Worldpay payment in PayHub. Spec: `docs/specs/0014-worldpay-provider/`.
Source: Worldpay's testing pages, read on 2026-11-18:

- Card payments: https://docs.worldpay.com/access/products/card-payments/testing
- 3D Secure: https://docs.worldpay.com/access/products/3ds/testing

These cards and values work in the **Try** (sandbox) environment only, never in live.

## The one rule that matters

PayHub sends the client to Worldpay's hosted page, and that page runs 3D Secure.
**Use a 3D Secure test card.** A card that is not on Worldpay's 3DS list makes the
"Cardholder authentication" step crash with:

> Application error: a client-side exception has occurred

Worldpay's console errors on that crash are `The current origin is not supported ... targetOrigin
in the live preview configuration` and `Minified React error #423`. The crash is on Worldpay's
page, not in PayHub. It was reproduced in a clean browser with no extensions, using the
Payments API card `4444333322221111` with the name `AUTHORISED`.

Also, set the cardholder name to any normal value, for example `Bob Smith`. Worldpay says not to
mix the old Payments API test values (such as `AUTHORISED` or `REFUSED`) with the 3DS values.

Use any future expiry date. Worldpay lists no passwords for the Try challenge screen. The Try
challenge expires after 10 minutes.

## Test cards (3D Secure)

| What you want | Visa | Mastercard |
|---|---|---|
| Pays with no challenge (frictionless success) | `4000000000002701` | `5200000000002235` |
| Pays after a challenge (challenged success) | `4000000000002503` | `5200000000002151` |
| Fails after a challenge (challenged failure) | `4000000000002370` | `5200000000002490` |
| Fails with no challenge (frictionless failure) | `4000000000002925` | `5200000000002276` |
| Rejected | `4000000000002537` | `5200000000002185` |
| Bypass 3D Secure | `4000000000002560` | `5200000000002508` |

Worldpay's 3DS page also lists Amex, Diners, JCB, Cartes Bancaires and UnionPay cards for each
result, plus cards for unavailable, system error, error, timeout and verification cases. Read that
page for the full list.

The team confirmed these cards work on the Worldpay hosted page (2026-11-18). The notes do not
record which of the cards in the table were run, so check a case here before relying on it.

## What PayHub should do for each result

| Result on Worldpay's page | PayHub |
|---|---|
| Success (frictionless or challenged) | The pay page waits, then shows success. The payment becomes `completed` only after PayHub reads one settled sale for the exact amount in GBP (poll, 10 minute sweep or optional webhook). |
| Failure, rejected or cancel | The payment stays `pending`. The pay page offers Pay now again. The admin payment page shows a `client return` row with the return word and Worldpay's error reference (and the error text when Worldpay sends it). A failed return also shows up in Sentry with the tag `payment.provider:worldpay`. A failed 3DS code (wrong OTP) comes back with `errorRefNumber` and an empty `errors`: the admin row then says Worldpay gave no reason, and the reference is what to give Worldpay support (seen on Try 2026-11-20). Worldpay results `refused`, `authorizationFailed`, `cancelled` count as `declined` and never set `failed`. |
| Crash on Worldpay's page | The payment stays `pending`. Nothing is recorded as paid. |

## Suggested test run

1. Create a GBP payment on a Worldpay account (Admin, Payments). Client email is optional.
2. Open the pay link, tick the consent box, click Pay now. You land on Worldpay's hosted page.
3. Frictionless success `4000000000002701`. Expect a return to PayHub, then success.
4. Challenged success `4000000000002503`. Expect the same result after the challenge screen.
5. Challenged failure `4000000000002370`. Expect `pending` and Pay now again.
6. Check the payment page as an admin. The lookup and attempt rows should show the result and no secrets.

## Other Worldpay values from the card payments page

These belong to the Payments API. Worldpay's pages do not mention hosted payment pages, so they
are not confirmed to work on the hosted page.

- Success cards: Visa `4444333322221111`, Mastercard `5555555555554444`.
- Cardholder name triggers: `REFUSED`, `REFUSED4` (hold card), `REFUSED51` (limit exceeded),
  `REFUSED55` (invalid security code), `SOFT_DECLINED`, `ERROR`.
- CVC triggers: `555` approved, `444` failed, blank not supplied.

## Local setup notes

- The hosted page can come from `payments-test.worldpay.com`, `hpp-sandbox.worldpay.com` (Try) or
  `hpp.worldpay.com`, `payments.worldpay.com` (live). Any https subdomain of `worldpay.com` is
  allowed (`config/services.php`, `worldpay.hpp_domains`), because Worldpay says not to list hosts.
- The 10 minute sweep needs the host cron to run `php artisan schedule:run` every minute.
