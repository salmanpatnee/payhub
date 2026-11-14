# Memory — NMI provider (spec 0012), tenth payment provider

Last updated: 2026-11-13

## What was built

Branch `feature/nmi-provider` (from staging, not pushed). Commits: `6816619` spec and research, `8cb8a7e` AGENTS.md skills, `4e812f4` steps 1 to 8, `d58c1d6` steps 9 to 13.

- **Spec and research:** `docs/specs/0012-nmi-provider/` (`index.md` status In Progress, `rationale.md`, `verify.md`), `docs/research/nmi.md`, `docs/research/nmi-sandbox-check.md`, `docs/research/beyond-bancard.md`.
- **Backend:** `app/Services/Nmi/` (`NmiClient`, `NmiSaleReply`, `NmiQueryReply`, `NmiChargeResolver`, `NmiAmount`, `NmiStalePaymentSweep`); `NmiPaymentController` (charge, status), `NmiWebhookController`, `Admin/NmiAccountController`; jobs `ResolveNmiCharge`, `HandleNmiWebhookJob`; models `NmiAccount`, `NmiChargeAttempt`, `ProcessedNmiEvent`; 4 migrations dated 2026_11_13; limiters `nmi-charge` and `nmi-3ds-report`; route `pay/{payment}/nmi/charge|status`, `webhook/nmi/{nmiAccount}`, `admin/nmi-accounts`.
- **Frontend:** `ClientPayment/PayNmi.vue` + `NmiPaymentForm.vue` (package `@nmipayments/nmi-pay` 1.1.1), admin pages `admin/nmi-accounts/*`, plus NMI added to badge, filters, sidebar, payments and user forms, Show attempts panel.
- **Parity:** request rules, CurrencySupportResolver, ProviderAccountTable, CardLockGuard, DashboardMetrics, PaymentController, export, central summary all cover NMI.
- **Tests:** 65 NMI tests pass (`NmiChargeTest`, `NmiPayPageTest`, `NmiWebhookAndSweepTest`, `NmiAdminAndParityTest`). `public/build` rebuilt and committed.

## Decisions made

- Order id goes in v5 sale field `order_details.id` (not `order_id`); names and email in `billing_address`. `query.php` returns it as `order_id`.
- NMI has no idempotency key; `E_DUPLICATE` (HTTP 400) is treated as unclear. The pause key `nmi:pause:{id}` is set before the sale call.
- Sandbox host is `https://sandbox.nmi.com`; production `https://secure.nmi.com`. CSP adds both on the NMI pay page only.
- Sandbox accounts send no billing email (NMI sandbox only accepts the merchant's own email). Production sends `client_email`. This differs slightly from spec text; the spec was not updated.
- Google Pay is the component's express checkout (`onExpressCheckout`), card is `onPay`. Google Pay never runs 3DS.
- Resolver outcome `not_found` (no sale at NMI) drives the sweep give up after 30 minutes.

## Problems solved

- Sandbox rejected the sale with `E_ACCESS_DENIED: Sandbox accounts can only send emails to their own email address`; fixed by skipping the email on sandbox accounts. NMI's error message is now kept in the attempt `reason` (admin only) and the log.
- GBP is refused by this sandbox processor, so only USD can be tested.
- Sandbox test cards: Visa 4111111111111111, exp 10/29; 3DS frictionless 4100000000000100, challenge 4100000000005000 (code 12345 passes); amounts under 1.00 decline.
- Tool quirk: inline Python edits mangle backslashes (PHP namespaces); use the Edit tool for those. Auto mode denied `php artisan migrate` on the dev DB, so the user runs migrations.

## Current state

- Steps 0 (server side), 1 to 13 done and committed. Card payment flow works against the sandbox after the email fix (user was about to retry).
- Browser untested: 3DS, Google Pay, webhook delivery, CSP completeness.
- Step 0 open questions still unanswered: Google Pay hosts, 3DS `onComplete` shape and challenge hosts (`services.nmi.three_ds_hosts` is empty), webhook `event_body.order_id`, ZIP/AVS need, card detail decline codes (220 to 225 are guesses).
- Full suite: 1 known order dependent error (`NotificationTest` calls `stripePost()` from another file); passes alone.
- Uncommitted and unrelated: `memory.md`, `docs/integrations/central-dashboard-brief.md`, `docs/research/mpgs-failed-payments-*.md`, `docs/research/zen-payments.md`.
- Keys live only in local `.env` (`NMI_SANDBOX_PRIVATE_KEY`, `NMI_SANDBOX_PUBLIC_KEY`) and encrypted on the dev `nmi_accounts` row. Never paste or commit them.

## Next session starts with

Retry a sandbox card payment, then work the browser run (verify.md list): 3DS pass and fail, Google Pay, webhook registered in the NMI portal for the staging URL, and fill the three_ds and google_pay CSP hosts from what the browser blocks. Record answers in the spec Follow-up (via /architect). Then step 14 (staging run), `/sync` for AGENTS.md (tenth provider, NMI section, hookdeck declined line), `/check verify nmi-provider`.

## Open questions

- Does GBP get enabled on Beyond Bancard's processor, and what is the production host (secure.nmi.com or white label)?
- Confirm the PCI SAQ level with the acquirer before production.
- Should the spec text be updated for the sandbox no email rule and the CSP always including secure.nmi.com?
