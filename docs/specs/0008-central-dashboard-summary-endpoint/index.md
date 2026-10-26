# 0008. Central dashboard summary endpoint

**Date**: 2026-10-26
**Status**: Proposed

## Summary

Each PayHub deployment gets one new, read only web address that a separate central dashboard calls every few minutes. It replies with a small summary: how many payment links exist per status and how much money was collected or is waiting, per currency, for four time windows. The call is protected by a secret token, rate limited, logged, and briefly cached so it cannot disturb live payments. No database tables change and no payment code changes. The exact reply is written once in `docs/integrations/central-dashboard-api.md`, which both apps follow.

## Requirements

**User stories**:
- As internal staff, I want one dashboard that shows every PayHub deployment so that I do not log in to each one.
- As the central dashboard, I want a stable, versioned summary from each deployment so that I can save it and show combined totals.
- As a PayHub operator, I want the summary door to be read only, locked by a secret token and limited in rate so that it cannot harm payments or leak data.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):
- **AC-1**: `GET /api/central/v1/summary` with a valid token returns 200 and a JSON body that follows contract v1 (`docs/integrations/central-dashboard-api.md`): `contract_version`, `deployment`, `generated_at`, `timezone` and the four windows `all_time`, `today`, `this_month`, `last_30_days`.
- **AC-2**: The token is sent as `Authorization: Bearer <token>`. A missing or wrong token returns 401 with `{"error":"unauthenticated"}` and no data. Either configured token (current or next) works. Tokens are compared by SHA 256 hash with a constant time comparison. When no token hash is configured the endpoint answers 404, so a deployment that has not opted in shows nothing.
- **AC-3**: When `CENTRAL_API_REQUIRE_HTTPS` is true and the request is not HTTPS, the answer is 403 `{"error":"https_required"}` before any token check. The default is true in production and false elsewhere.
- **AC-4**: Each token may call at most 6 times per minute (429 with `Retry-After` after that). A second limit of 30 calls per minute per IP address runs before the token check, so token guessing is slowed.
- **AC-5**: Link counts per window count payments by `created_at` inside the window, split into `total`, `pending`, `completed`, `failed`, `cancelled`, plus `expired` (pending links whose `expires_at` has passed, a part of `pending` and not extra). All providers are included. Soft deleted payments are never counted.
- **AC-6**: Money per window is whole cents per currency (`usd`, `gbp`, `pkr`, always present, 0 when empty), never added across currencies. `money.completed` sums completed payments by `paid_at` inside the window, with `paid_count` giving how many were paid. `money.pending` sums pending links by `created_at` inside the window.
- **AC-7**: Window edges are cut in `Asia/Karachi` (`CENTRAL_TIMEZONE`) whatever the app timezone is. A payment paid at 23:30 Karachi time on 25 October is outside `today` on 26 October.
- **AC-8**: The reply holds only counts, cents and deployment identity. It never contains client names, emails, reference codes, provider keys, account names or brand names. The request performs no database writes.
- **AC-9**: The summary is cached for `CENTRAL_API_CACHE_SECONDS` (default 60). A second call inside that time returns the same `generated_at` and runs no payment queries.
- **AC-10**: Every call that reaches the token check writes one line to the `central_audit` log channel with time, result (`ok`, `unauthenticated`), which token slot matched (`current` or `next`), IP address and duration in milliseconds. The token itself is never logged.
- **AC-11**: If the summary cannot be built (database error), the answer is 503 `{"error":"summary_unavailable"}` with no detail, and the error is reported through the normal Sentry and log path. It is never an HTML error page.
- **AC-12**: `docs/integrations/central-dashboard-sample.json` has exactly the same key structure as a real reply, and a test fails if the two drift apart.
- **AC-13**: `php artisan central:make-token` prints a new random token once and the matching `CENTRAL_API_TOKEN_HASH_*` line to paste into `.env`. It stores nothing.

## Decision

**Chosen option**: Option 1: Read only summary endpoint in PayHub, pulled by the dashboard

PayHub exposes one versioned, token protected `GET` that builds a small summary from the payments table with read only queries, caches it for 60 seconds, and the dashboard pulls it on a schedule.

**Implementation skills**: `laravel-best-practices` (`laravel/boost`, `.claude/skills/laravel-best-practices/`) · `pest-testing` (`laravel/boost`, `.claude/skills/pest-testing/`)

## Feature design

**Data model sketch**:
No new tables, columns or migrations. The endpoint reads `payments` only: `status`, `currency`, `amount` (integer cents), `created_at`, `paid_at`, `expires_at`, `deleted_at`. Tokens live in `.env` as SHA 256 hashes, never in the database.

**State transitions**: none. The endpoint never changes a payment.

**API surface**:
| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `/api/central/v1/summary` | GET | `Authorization: Bearer <token>` header. No query params in v1. | `contract_version`, `deployment`, `generated_at`, `timezone`, `windows` | Bearer token, hash checked | 401 unauthenticated, 403 https_required, 404 not enabled, 429 too many calls, 503 summary_unavailable |

**Value sourcing** (every value the reply produces and where it comes from):
| Action | Value produced / displayed | Source |
|---|---|---|
| summary | `contract_version` | constant `v1` in the controller, matches the path |
| summary | `deployment.key` | `config('central.deployment_key')` from `CENTRAL_DEPLOYMENT_KEY` (set per deployment, for example `payhub1`) |
| summary | `deployment.name` | `CENTRAL_DEPLOYMENT_NAME`, falls back to `config('app.name')` |
| summary | `deployment.app_release` | `config('sentry.release')` (short git hash), null if unknown |
| summary | `generated_at`, `timezone` | the time the summary was built, written in `CENTRAL_TIMEZONE` (default `Asia/Karachi`) |
| summary | window `from` and `to` | derived: day, month and 30 day edges cut in `CENTRAL_TIMEZONE`, then converted to the app timezone before querying; `all_time` has null edges |
| summary | `links.*` | `payments` rows grouped by `status`, filtered on `created_at` in the window, soft deleted excluded by the model |
| summary | `links.expired` | pending rows in the window with `expires_at` before the time of building |
| summary | `money.completed.<currency>`, `paid_count` | `sum(amount)` and `count(*)` of completed rows filtered on `paid_at` in the window, grouped by `currency` |
| summary | `money.pending.<currency>` | `sum(amount)` of pending rows filtered on `created_at` in the window, grouped by `currency` |
| summary | currency keys | fixed list `usd`, `gbp`, `pkr` from `config('central.currencies')`, plus any other currency found in the data |
| token check | the stored hashes | `CENTRAL_API_TOKEN_HASH_CURRENT` and `CENTRAL_API_TOKEN_HASH_NEXT` |
| audit line | caller IP and token slot | the request IP, and which configured hash matched |

**Key invariants**:
- The endpoint never writes to the payments tables or any payment related table.
- Money is integer cents. Currencies are never summed together or converted.
- A payment appears in `links` by when it was created and in `money.completed` by when it was paid. The two views can differ, and the contract says so.
- `expired` is part of `pending`, never added to it.
- Key structure of the reply never changes inside v1. Adding a key is allowed; removing, renaming or changing a meaning needs v2.

**Security model**:
- One secret token per dashboard, 64 hex characters from `random_bytes(32)`. PayHub keeps only the SHA 256 hash. Two hash slots allow rotation without downtime: add the new hash as `NEXT`, switch the dashboard, then move it to `CURRENT` and clear `NEXT`.
- HTTPS only in production. No IP allow list (Hostinger shared IP addresses can change).
- Counts and cents only. No personal data, so the payment compliance scope (PCI DSS) is not widened. No card, provider key or webhook data is read.
- Audit lines go to `storage/logs/central-audit.log` (30 days), never containing the token.

**Configuration required**:
- `CENTRAL_DEPLOYMENT_KEY`: short id for this deployment, lowercase letters, digits, dash or underscore.
- `CENTRAL_DEPLOYMENT_NAME`: friendly name shown on the dashboard (optional).
- `CENTRAL_API_TOKEN_HASH_CURRENT`: SHA 256 hash of the active token. Empty means the endpoint is off (404).
- `CENTRAL_API_TOKEN_HASH_NEXT`: hash of the incoming token during rotation (optional).
- `CENTRAL_TIMEZONE`: default `Asia/Karachi`.
- `CENTRAL_API_REQUIRE_HTTPS`: default true in production.
- `CENTRAL_API_CACHE_SECONDS`: default 60.

**Critical test scenarios** (each maps to an acceptance criterion in ## Requirements):
- Happy path: seeded payments of all four statuses and three currencies come back with the exact counts and cents, verifies **AC-1**, **AC-5**, **AC-6**
- Time zone edge: a payment paid just before and just after midnight Karachi time lands in the right window with the app timezone set to UTC, verifies **AC-7**
- Soft deleted and privacy: a deleted payment is not counted, and no client email or reference appears anywhere in the body, verifies **AC-5**, **AC-8**
- Auth: no token, wrong token, `NEXT` token, no hashes configured, verifies **AC-2**
- HTTPS and limits: plain HTTP in production gets 403, the seventh call in a minute gets 429, verifies **AC-3**, **AC-4**
- Cache: second call returns the same `generated_at` with no payment queries, verifies **AC-9**
- Audit and failure: audit line written without the token, a thrown database error gives 503 JSON, verifies **AC-10**, **AC-11**
- Contract drift: key structure equals the sample JSON, verifies **AC-12**
- Token command: output contains a 64 character token and a hash that matches it, verifies **AC-13**

## Rationale

Reasoning and options: see `rationale.md`.

## Build plan

Build approach: the project has none recorded, so this plan uses end to end thin slices (Tracer Bullet): get one authenticated call returning real numbers first, then add each protection and window.

1. Add `config/central.php` and the new env keys to `.env.example`. Register API routing in `bootstrap/app.php` and create `routes/api.php` with the versioned route group, satisfies **AC-1**
2. Thin thread: the `central.token` middleware (hash check, two slots, 404 when off), an invokable `CentralSummaryController`, and the `all_time` window with link counts only, satisfies **AC-1**, **AC-2**, **AC-5**
3. Add the `CentralSummary` service with all four windows, the money blocks and `expired`, with edges cut in `CENTRAL_TIMEZONE`, satisfies **AC-5**, **AC-6**, **AC-7**, **AC-8**
4. Add the two rate limiters (per IP before auth, per token after) and the HTTPS rule, satisfies **AC-3**, **AC-4**
5. Add the 60 second cache, the 503 error answer, and the `central_audit` log channel and writes, satisfies **AC-9**, **AC-10**, **AC-11**
6. Add `php artisan central:make-token`, satisfies **AC-13**
7. Add `docs/integrations/central-dashboard-api.md` and the sample JSON (written with this spec), and the Pest tests including the drift test, satisfies **AC-12** and every scenario above
8. Roll out: set the env keys on payhub1.com, pull and reset opcache by hand, call the endpoint with `curl` and compare against the PayHub dashboard for the same dates, then repeat on payhub2.com, satisfies **AC-1**

## Consequences

**Positive**:
- No migrations and no change to payment code, so risk to live payments is low.
- One contract serves both deployments and any future one, because they run the same code.
- A leaked token exposes only counts and totals, and can be rotated without downtime.

**Negative / tradeoffs**:
- The dashboard sees numbers up to about 6 minutes old (60 second cache plus the 5 minute schedule).
- `links` and `money` use different date rules, so a day's counts and money can disagree. The contract explains it.
- Reusing `DashboardMetrics` was not possible as is: it filters with `whereDate` in the app timezone and per filter, so the summary has its own small service that reuses the same status words and sums idea.
- The `payments` table is queried about 16 times per uncached call. Fine at today's size, to be checked with real volume.

**Neutral**:
- A new `routes/api.php` file and `api` routing now exist in PayHub. Nothing else uses them yet.
- Failed means status `failed` only. Declined card attempts (MPGS, Authorize.net) are not in v1.

## Follow-up

- [ ] Check the 16 queries with `EXPLAIN` on production sized data. If any is slow, a database index becomes a separate decision (it would be the first schema change for this feature).
- [ ] Confirm on Hostinger that PayHub sees HTTPS correctly behind its proxy (`$request->isSecure()`) before turning `CENTRAL_API_REQUIRE_HTTPS` on, or the endpoint will answer 403 by mistake.
- [ ] Write the dashboard side spec in the dashboard repository, and save the architecture report there as `docs/architecture.md`.
- [ ] Later, if needed: per brand and daily series blocks (contract v1 additions), attempt statistics, request signing.
- [ ] Add a one line pointer to `docs/integrations/central-dashboard-api.md` in root `AGENTS.md` once this ships (`/sync` owns that edit).
