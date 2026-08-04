# Memory — PKR Currency Support (Bank Account module only)

Last updated: 2026-08-04

## What was built

Added PKR as a supported currency scoped entirely to the Bank Account module, without touching Payments, Dashboard, or admin Users (which stay USD/GBP only).

**Backend:**
- `app/Enums/BankAccountCurrency.php` (new) — USD/GBP/PKR enum, decoupled from `App\Enums\SupportedCurrency` (which remains untouched, used only by Payments).
- `app/Models/BankAccount.php` — `currency` cast switched from `SupportedCurrency::class` to `BankAccountCurrency::class`.
- `app/Http/Requests/StoreBankAccountRequest.php` / `UpdateBankAccountRequest.php` — validation now uses `BankAccountCurrency::values()`.
- `app/Http/Controllers/BankAccountController.php` — currency filter uses `BankAccountCurrency::values()`.
- `app/Services/ActivityLogger.php` — `normalizePair()` generalized from `instanceof SupportedCurrency` to `instanceof \BackedEnum && method_exists($x, 'label')`, so it still resolves "USD → PKR" style labels for any backed enum (safe, non-breaking generalization).
- `database/factories/BankAccountFactory.php` — factory now randomizes across `usd`/`gbp`/`pkr`.

**Frontend:**
- `resources/js/lib/bank-account-currencies.ts` (new) — `BANK_ACCOUNT_CURRENCIES` array + `BANK_ACCOUNT_CURRENCY_LABELS` map, shared by Create/Edit/Index.
- `resources/js/pages/bank-accounts/Create.vue`, `Edit.vue`, `Index.vue` — currency `<Select>`s now `v-for` over the shared constant instead of hardcoded USD/GBP `<SelectItem>`s.
- `resources/js/lib/utils.ts` — `formatMoney()` routes `pkr` through `en-PK` locale; updated stale "USD and GBP only" comment.
- `BankDetailCard.vue` — confirmed already currency-agnostic, no change needed.

**Docs:**
- `CLAUDE.md` — currency rule now: "Payments support USD and GBP only. Bank Accounts additionally support PKR."

**Tests:**
- `tests/Feature/BankAccountManagementTest.php` — added PKR case to currency filter test, new dataset-style create/update tests across usd/gbp/pkr, unsupported-currency (`eur`) rejection test, index + "My Bank Accounts" PKR rendering test.
- `tests/Feature/BankAccountActivityLogTest.php` — added test asserting "USD → PKR" activity log label via generalized `ActivityLogger::normalizePair`.

## Decisions made

- Gave `BankAccount` its own `BankAccountCurrency` enum rather than adding PKR to the shared `SupportedCurrency` enum — keeps PKR from leaking into Payments validation, Dashboard filters, and admin User payment-account selectors, all of which share `SupportedCurrency`.
- Generalized `ActivityLogger::normalizePair`'s currency-label check to `BackedEnum` + `method_exists('label')` instead of hardcoding both enum types — works for both `SupportedCurrency` and `BankAccountCurrency` without duplicating logic.
- No DB migration needed — `bank_accounts.currency` is a plain `string` column with no check constraint.

## Problems solved

N/A — straightforward implementation, no unexpected blockers.

## Current state

- Feature fully implemented and committed on `fix/multi-issue-fixes` (commit `c8aa0e3`), **not yet merged to `staging`/`master`, not yet pushed**.
- `.claude/settings.local.json` (pre-existing unrelated change) and this `memory.md` were deliberately left out of the commit.
- `php artisan test`: 406/407 relevant tests pass. The 1 failure (`NotificationTest::...queues_PaymentSucceeded_mail_to_all_admins_only`) is a pre-existing, unrelated flaky/broken test — confirmed present on the clean base branch via `git stash` before this work started.
- `npx vue-tsc --noEmit`: only the 3 known pre-existing errors (`ssr.ts` overload mismatch, `viva-accounts/Edit.vue` ref-typing x2) — no new regressions.
- No manual dev-server/browser verification was performed this session (per project preference, Playwright MCP is flaky here — relied on Pest/vue-tsc instead).

## Next session starts with

- Nothing queued. If the user wants this shipped, next step is merging `fix/multi-issue-fixes` → `staging` → `master` and pushing (see prior session's memory for the worktree gotcha: `master` may be checked out in a separate worktree at `C:/Users/salmanabdul.ghani/Herd/payhub-fix-stale-pi`, not the main `payhub` directory).
- Deploying to Hostinger prod after merge still requires a manual `git pull` + opcache reset (no new migration needed for this feature).

## Open questions

- Whether/when to merge this branch and deploy to production is up to the user.
