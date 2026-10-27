# Central dashboard API, contract v1

**Owner**: PayHub (this repo). The central dashboard follows this file.
**Version**: v1, written 2026-10-26.
**Spec**: `docs/specs/0008-central-dashboard-summary-endpoint/index.md`
**Sample reply**: `docs/integrations/central-dashboard-sample.json` (a test checks that real replies have the same keys)

This file is the one agreement between a PayHub deployment and the central dashboard. If the reply shape must change in a way that breaks the dashboard, the version becomes v2 with a new path, and v1 keeps working until the dashboard has moved.

## What it is

One read only call that returns a small summary of one PayHub deployment: how many payment links exist per status, and how much money was collected or is waiting, per currency. The dashboard calls it about every 5 minutes for each deployment and saves the answer.

It returns counts and amounts only. It never returns client names, emails, reference codes, provider keys, account names or brand names.

## Request

```
GET https://<deployment host>/api/central/v1/summary
Authorization: Bearer <token>
Accept: application/json
```

- HTTPS only.
- No query parameters in v1.
- The token is a 64 character random string. PayHub stores only its SHA 256 hash. Each deployment has its own token.
- A deployment accepts two tokens at once (`current` and `next`) so a token can be replaced without downtime.

## Reply, 200

See the sample file for a full example.

| Field | Meaning |
|---|---|
| `contract_version` | Always `"v1"` on this path. |
| `deployment.key` | Short id of the deployment, for example `payhub1`. Set by the deployment. |
| `deployment.name` | Friendly name. |
| `deployment.app_release` | Short code release id of the running PayHub, or null. Useful to spot an older deployment. |
| `generated_at` | When the summary was built (ISO 8601, with offset). The reply can be cached for up to 60 seconds, so this may be up to 60 seconds old. |
| `timezone` | Time zone used to cut days and months. `Asia/Karachi` for now. |
| `windows` | Four windows: `all_time`, `today`, `this_month`, `last_30_days`. |

### A window

| Field | Meaning |
|---|---|
| `from`, `to` | Window edges, start included, end excluded, in `timezone`. Both null for `all_time`. `today` ends tomorrow 00:00. `this_month` ends on the 1st of next month. `last_30_days` is today and the 29 days before it. |
| `links.total` | Payment links created in the window. |
| `links.pending`, `completed`, `failed`, `cancelled` | The same links split by their current status. They add up to `total`. `failed` means status `failed` only. A declined card attempt does not make a link `failed`. |
| `links.expired` | Pending links whose expiry time has passed. This is a part of `pending`, not extra. |
| `money.completed.<currency>` | Money actually paid in the window, in whole cents (1500 means 15.00). Includes links created earlier. |
| `money.paid_count` | How many payments were paid in the window. |
| `money.pending.<currency>` | Money on pending links created in the window, in whole cents. |

Currency keys are lowercase: `usd`, `gbp`, `pkr`. All three are always present, with 0 when empty. A new currency key may appear in future; the dashboard must accept unknown currency keys.

## How to read the numbers (important)

- **Links and money use different dates.** Links are placed by when they were created. Money is placed by when it was paid. A link made yesterday and paid today counts in yesterday's `links` and in today's `money.completed`. So `links.completed` and `money.paid_count` for the same window can differ.
- **Never add currencies together.** Sum each currency on its own across deployments.
- **A link is not an attempt or a payment.** Each link is one row. A client may try several times on the same link. Attempts are not in v1.
- **Deleted payments are not counted.**
- **Adding up deployments:** add `links.*` and each currency's cents across the latest good reply of each deployment. Each reply is a full recount, so nothing is added over time.

## Errors

| Status | Body | Meaning |
|---|---|---|
| 401 | `{"error":"unauthenticated"}` | Missing or wrong token. |
| 403 | `{"error":"https_required"}` | The request was not HTTPS. |
| 404 | empty or standard not found | The endpoint is not turned on for this deployment. |
| 429 | standard, with `Retry-After` | Too many calls. Limit is 6 per minute per token. |
| 503 | `{"error":"summary_unavailable"}` | PayHub could not build the summary. Try again later; keep the last good reply. |

The dashboard must treat any non 200 reply as "unavailable", keep the last good reply, and mark it stale.

## Limits and logging

- 6 calls per minute per token, and 30 per minute per IP address before the token check.
- Every call is written to PayHub's audit log (time, result, which token slot, IP, duration). The token is never logged.

## Change rules

- Adding a field is allowed in v1.
- Removing a field, renaming it, or changing what it means needs v2.
- The sample JSON and this file change together, in the same commit as the code.
