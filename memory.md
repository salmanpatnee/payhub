# Memory — Zelle Accounts: verify, icon, card cleanup, build

Last updated: 2026-09-21

## What was built

- `/check verify zelle accounts` run against the real app (`https://payhub.test`, DB `payhub_live`) as admin, agent and account role. Verdict PASS. 12 of 14 steps in `docs/specs/0003-zelle-accounts-module/verify.md` ticked; spec status set to `Accepted` (commit `e6e749f`).
- `resources/js/components/icons/ZelleIcon.vue` (Simple Icons Zelle mark, same pattern as `CloverIcon.vue`). Used in `AppSidebar.vue` and `pages/zelle-accounts/ZelleDetailCard.vue` (commit `6759652`).
- Agent card: removed the "Currency" row (badge in header stays). "Copy all" now copies name, email, mobile only. Spec `index.md` (AC-9, copy all sourcing table) and `verify.md` step text updated to match (commit `c29c719`).
- `npm run build` run and `public/build` committed (commit `3d65ecd`). `public/build` is tracked in git.

## Decisions made

- Zelle icon is monochrome (`currentColor`), no brand colour.
- Currency is shown only as the badge on agent cards, never in the copied text.
- No user without a role can exist, so the "no role sees empty state" part of AC-11 is not applicable.

## Problems solved

- Browser session cookie lives on `payhub.test` (Herd), not `127.0.0.1:8000`. Use `payhub.test` for browser checks.
- `public/build` had no Zelle pages, so verifying needed `npm run dev` (Vite) running, which creates `public/hot`. Remove `public/hot` afterwards.
- `pkill` is not installed in this shell. Stop Vite and `artisan serve` with PowerShell (`Get-CimInstance Win32_Process` then `Stop-Process`).
- Inertia returns 409 after the asset version changes (build to Vite dev). A fresh page load fixes it.
- Claude cannot type passwords in the browser. The user signs in as each role.

## Current state

- Branch `feat/zelle-accounts`, all Zelle work committed. Only `memory.md` is uncommitted.
- 27 of 27 Pest tests passed in `tests/Feature/ZelleAccountManagementTest.php` (run before the card change; the card change is frontend only).
- `zelle_accounts` table is empty in `payhub_live` (test rows were removed). `activity_logs` is 0.
- `vue-tsc` has 3 old errors (`admin/viva-accounts/Edit.vue` twice, `ssr.ts`), none in Zelle files.
- Dev servers are stopped.

## Next session starts with

- Decide whether to open a PR or merge `feat/zelle-accounts` into `master` (CLAUDE.md: merge only after tests pass). Re-run the Zelle Pest file first.
- Production runs `master` and needs a manual pull, opcache reset and migrate on Hostinger. Push does not deploy.

## Open questions

- Two `verify.md` steps stay unticked: agent reactivation was done through the database, not watched in the browser; individual copy icons were not clicked and "Copied" was only seen through a script.
- Agent card header icon and dark mode / collapsed sidebar for the new Zelle icon were not checked visually.
