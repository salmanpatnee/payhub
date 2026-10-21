# Memory — Authorize.net Accept.js (spec 0007), sandbox checks and polish

Last updated: 2026-10-21

## What was built

Branch `feat/authorizenet-accept-hosted` (not pushed). Commits since the spec 0007 build (`90e3979`):

- `1c2e09b` `AuthorizenetCardForm.vue` restyled to match MPGS and Clover: no labels, placeholders (`Card number`, `MM/YY`, `CVV`, `ZIP`), rounded-xl wrapper boxes, brand focus ring, shimmer skeleton, red ring plus short error text.
- `7d0e34f` `AuthorizenetChargeReply`: gateway response 3 with error 6 (bad card) or 8 (expired) now maps to `check_details` (was `error`). Test added. Spec 0007 `## Follow-up` filled with sandbox answers.
- `63e3dc4` Held reload fix: `ClientPaymentController::showAuthorizenet` passes a `held` prop (transaction id present); `PayAuthorizenet.vue` starts in the waiting state and polls. Test added.
- `0e7b40a` A card field's error clears as soon as that field is edited.
- `80a9bc7` Rebuilt `public/build` (the repo tracks built assets, with `build:` commits).

## Decisions made

- `public/build` is committed by convention; after frontend changes run `npm run build` and commit it as `build: ...`.
- Held state is derived from "pending with an authorizenet transaction id". An unclear payment (no transaction id) still shows the card form on reload; the 120 second server pause blocks a second charge.
- Kept the short red error text under invalid fields (Clover and MPGS show only the ring).

## Problems solved

- Real sandbox payments work end to end (4 completed). Gateway read-back showed response code 1, status `capturedPendingSettlement`.
- Sandbox is NOT in Test Mode: the "amount equals error code" trick with card `4222 2222 2222 2` does not work (everything approves). Force errors another way: card code `901` gives error 65 (`check_details`); ZIP `46282` gives error 2 (`declined`); a Luhn-bad number gives 6; an old expiry gives 8.
- A second identical charge (same card, amount, invoice, 300 second window) returns response 3, error 11 and the first transaction id, mapped to `unclear`.
- Vite "Unable to locate file in Vite manifest" on the pay page just means the dev server was not running.
- Browser check: the Chrome extension works on `https://payhub.test` once the page has loaded; wait a few seconds for the entrance animation.

## Current state

- 82 Authorize.net Pest tests pass; eslint and `vue-tsc` clean for the touched files; `npm run build` succeeds.
- Verified in the browser: restyled form, empty-submit errors, card formatting, brand focus ring, error clears on typing.
- Known small issue: the form shifts a few pixels vertically when errors appear or clear (page content is centred). Optional fix is a reserved error line under each field.
- Spec 0007 status is still `In Progress`; no `verify.md`; no JS test runner for `card.ts`.
- Payment 31 in the local DB is `pending` with no transaction id (probably from the missing-column error before the local DB fix). Not confirmed.
- Uncommitted: `memory.md`, `docs/research/`.

## Next session starts with

1. Webhook test on the staging server (open questions 9 and 10): deploy the branch, run its migrations (branch migrations were edited in place), set the account keys, change the webhook URL in Authorize.net to `https://<staging-host>/webhook/authorizenet/<account id>`, make a payment, then check `processed_authorizenet_events` and the status update, the `X-ANET-Signature` header format and the delay.
2. Held transaction test (Q7): needs a holding fraud filter or Test Mode in the sandbox. Also still untested: declines in the unsettled list (Q6) and retry after a decline with the same invoice.
3. `/check verify`, `/sync` (update `AGENTS.md` and `docs/agent.md` for the seventh provider and PCI note, refresh the provider-parity agent and skill text from four to seven providers), mark the spec Accepted.

## Open questions

- Reserve a fixed error line under each field to stop the layout shift, or keep the tight spacing?
- PCI: ask the bank or assessor to confirm SAQ A-EP.
- Production account needs its own API login, transaction key, signature key and public client key.
- The rate limits and pause timings (5 charges per payment, 20 per IP per hour, 120 second unclear pause) are unreviewed defaults.
- One full-suite test, `NotificationTest` (`stripePost()` undefined), fails with or without this work.
