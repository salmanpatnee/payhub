# Verify: central dashboard summary endpoint · spec 0008 · updated 2026-10-26
_Steps come from the spec 0008 acceptance criteria and its Value sourcing table. `/check verify` runs these; `/test` locks the durable ones._

## UI / manual
- [ ] Call the endpoint on a deployment with `curl` and compare `today` and `this_month` with the PayHub dashboard for the same dates → the link counts and money match (remember links use created date, money uses paid date) → AC-1, AC-5, AC-6
- [x] Pay a test link just before and just after midnight Karachi time, then call the endpoint → the payment shows in the right day of `money.completed` → AC-7
- [x] Soft delete a payment in PayHub, wait 60 seconds, call again → the counts drop by one → AC-5
- [x] Read the whole reply by eye → no client names, emails, reference codes, account names or brand names → AC-8
- [x] Open `storage/logs/central-audit-<date>.log` after a good call and a bad call → one line each, with result, slot, IP and duration, and no token → AC-10

## Commands
- [x] `curl -i https://<host>/api/central/v1/summary` (no token) → 401 `{"error":"unauthenticated"}` → AC-2
- [x] `curl -i -H "Authorization: Bearer wrong" https://<host>/api/central/v1/summary` → 401, no data → AC-2
- [x] `curl -i -H "Authorization: Bearer <token>" https://<host>/api/central/v1/summary` → 200 with `contract_version`, `deployment`, `generated_at`, `timezone` and the four windows → AC-1
- [x] Same call with the token placed in `CENTRAL_API_TOKEN_HASH_NEXT` only → 200 → AC-2
- [x] Empty both `CENTRAL_API_TOKEN_HASH_*` values, clear config cache, call → 404 → AC-2
- [x] With `CENTRAL_API_REQUIRE_HTTPS=true`, call over `http://` → 403 `{"error":"https_required"}` even with a wrong token → AC-3
- [x] Call 7 times in one minute with the same token → the 7th is 429 with a `Retry-After` header → AC-4
- [x] Call 31 times in one minute with a wrong token → the 31st is 429 → AC-4
- [x] Call twice within 60 seconds → same `generated_at` both times → AC-9
- [x] Stop the database (or point to a bad one on a test box) and call with an empty cache → 503 `{"error":"summary_unavailable"}`, JSON not HTML, and the error appears in Sentry and `laravel.log` → AC-11
- [x] `php artisan central:make-token` → prints a 64 character token and a `CENTRAL_API_TOKEN_HASH_CURRENT=` line, and writes nothing to `.env` → AC-13
- [x] `echo -n <token> | sha256sum` → equals the printed hash → AC-13
- [x] `php artisan test --parallel --filter=CentralSummary` → all pass, including the sample JSON drift test → AC-12

## Value sourcing checks (one per row of the spec table)
- [x] `contract_version` is `v1` and the path says `v1` → constant in the controller path
- [ ] Set `CENTRAL_DEPLOYMENT_KEY=payhub-x`, clear config cache → `deployment.key` is `payhub-x`
- [ ] Unset `CENTRAL_DEPLOYMENT_NAME` → `deployment.name` falls back to the app name; set it → shows the new name
- [ ] `deployment.app_release` equals the short git hash shown by Sentry (or null when unknown)
- [ ] Set `APP_TIMEZONE=UTC` and `CENTRAL_TIMEZONE=Asia/Karachi` → `generated_at` and every `from` and `to` carry `+05:00`
- [x] `today.from` is 00:00 Karachi today and `today.to` is 00:00 tomorrow; `all_time` has null edges
- [ ] `last_30_days.from` is 29 days before today's start; `this_month.to` is the 1st of next month
- [ ] A link created yesterday and paid today → counts in yesterday's `links` and in today's `money.completed`
- [ ] A pending link past its `expires_at` → counted in `links.expired` and still inside `links.pending`
- [x] A completed PKR payment on an MPGS account → appears under `pkr`; a currency with no payments shows 0 (`usd`, `gbp`, `pkr` always present)
- [ ] Change the stored hash while a cached copy exists → the old token still works until the cache expires; after that it is rejected (token hash comes from env only)

## Acceptance-criteria coverage
- AC-1 … covered by the 200 call, the dashboard comparison · AC-2 … the five token and 404 calls · AC-3 … the http call · AC-4 … the 7 call and 31 call checks · AC-5 … dashboard comparison, soft delete · AC-6 … dashboard comparison, PKR check · AC-7 … midnight Karachi payment · AC-8 … read the reply by eye · AC-9 … two calls inside 60 seconds · AC-10 … audit log check · AC-11 … database stopped check · AC-12 … the Pest command · AC-13 … make-token commands
