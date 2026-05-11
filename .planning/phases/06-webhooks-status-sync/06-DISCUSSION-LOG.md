# Phase 6: Webhooks + Status Sync - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-11
**Phase:** 06-webhooks-status-sync
**Areas discussed:** webhook_secret setup

---

## webhook_secret setup

### Q1: Where should admin enter the webhook_secret?

| Option | Description | Selected |
|--------|-------------|----------|
| Edit form | Add webhook_secret field to existing StripeAccount edit page — same form as keys | ✓ |
| Dedicated webhook tab/section | Separate section showing endpoint URL + secret input | |

**User's choice:** Edit form

---

### Q2: Show webhook endpoint URL on edit form?

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, show the URL | Read-only field showing /webhook/stripe/{accountId} for easy copy | ✓ |
| No, just the secret field | Admin constructs URL manually | |

**User's choice:** Yes, show the URL

---

### Q3: webhook_secret field behavior

| Option | Description | Selected |
|--------|-------------|----------|
| Masked input, blank if not set | Password-type input, empty if null, ●●●● if set | ✓ |
| Show Set/Not set badge | Separate "Change secret" button reveals input | |

**User's choice:** Masked input, blank if not set

---

### Q4: Blank field on submit behavior

| Option | Description | Selected |
|--------|-------------|----------|
| Preserve existing secret | Blank = no change, existing secret kept | ✓ |
| Clear it (set to null) | Blank = clear the webhook_secret | |

**User's choice:** Preserve existing secret

---

## Claude's Discretion

- **Handler architecture** — User did not select this area. Claude recommends custom webhook controller over spatie/laravel-stripe-webhooks infrastructure. Rationale: spatie is designed for single signing secret from config; per-account secrets require overrides. Custom controller is simpler for v1's 2 event types.
- **Duplicate event policy** — User did not select this area. Claude recommends double-status-check pattern: controller checks status before queuing, job re-checks on execution. Sufficient for v1.
- **Job structure** — Single `HandleStripeWebhookJob` with event_type + event_data params.
- **`paid_at` field** — Set to `now()` on `payment_intent.succeeded`.

## Deferred Ideas

- Webhook event audit log (WebhookCall model from spatie) — v2
- Dead-letter queue / retry visibility UI — v2
- Strict event-ID deduplication — v2
