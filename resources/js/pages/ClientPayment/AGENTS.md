# Client pay pages

## Overview

The public pages a client sees when they open a payment link (`/pay/{payment}`). `ClientPaymentController::show` picks one page per provider with a `match` on `$payment->provider`, and the result pages (`Success`, `Failed`, `Unavailable`) are shared by every provider.

## Key files

| File | Owns |
|---|---|
| `Pay.vue` | Stripe and Square (rendered with a `provider` prop; Square form in `SquarePaymentForm.vue`) |
| `PayRevolut.vue`, `PayViva.vue` | Revolut and Viva pay pages |
| `PayClover.vue` + `CloverPaymentForm.vue` | Clover pay page and its card form |
| `PayMpgs.vue` + `MpgsPaymentForm.vue` | MPGS hosted session fields and the 3DS step |
| `PayAuthorizenet.vue` + `AuthorizenetCardForm.vue` | Authorize.net, PayHub's own card inputs plus Accept.js (`@/lib/acceptjs`, `@/lib/card`) |
| `PayWhop.vue` | Whop: embed (`@whop/elements` checkout mounted on load) or redirect (Pay now), waiting state, polling |
| `CardLockedNotice.vue` | "Payment paused, contact your representative" notice (MPGS and Authorize.net) |
| `Success.vue`, `Failed.vue`, `Unavailable.vue` | Shared result pages |

## Conventions

- Every pay page records the client's consent (`storeConsent`) before any card data is sent; the Terms, Refund and Privacy policies open in a read only dialog. `Pay.vue` owns the consent box for both Stripe and Square and passes `recordConsent` into `SquarePaymentForm`.
- Wallet buttons (Square: Apple Pay, Google Pay, Cash App Pay; Revolut: Google Pay, Revolut Pay) are faded while the consent box is unticked. Apple Pay and Google Pay must open their sheet straight from the click, so Square tokenizes first and records consent before the charge; Revolut records it in `createOrder`. A wallet that cannot start stays hidden and the card form still works. Wallet failures show fixed text only.
- Amount, currency and account always come from page props built on the server; nothing a page sends decides them.
- Show the client only fixed messages. Gateway error text never reaches the page.
- Pages whose status comes from a charge reply (Clover, MPGS, Authorize.net) poll their status endpoint every 3 seconds after a held or unclear result instead of showing the form again. Authorize.net stops showing the waiting message after 10 minutes of visible time.
- `PayWhop.vue` never trusts Whop's `onComplete` or the return query: it only shows "We are confirming your payment" (kept across reloads in `sessionStorage`) and polls `/pay/{uuid}/whop/status` every 3 seconds until the webhook completes the payment, giving up after 10 visible minutes. Whop text never reaches the page.
- A JSON endpoint answering HTTP 423 means the card attempt lock is on: reload so the page shows `CardLockedNotice` (the `lockedUntil` prop).
- Card values on the Authorize.net form live only in local refs, are never inside a `<form>`, and the wrapper carries `sentry-block`. Never put card values in Inertia form state, a URL, storage, a log or the console.
- A new provider or pay page feature must land on every pay page (see the parity rule in root `AGENTS.md`).

## Gotchas

- `Pay.vue` serves two providers; check the `provider` prop before changing it.
- After any change here, run `npm run build`: compiled assets in `public/build` are committed and production serves them.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
