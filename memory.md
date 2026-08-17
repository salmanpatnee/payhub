# Memory — Sentry Triage + Remove Delete Account Feature

Last updated: 2026-08-17

## What was built

**Sentry issue triage** (org `cis-2r`, project `payhub-backend`):
- **PAYHUB-BACKEND-1N** (`Cannot read properties of undefined (reading 'M_ID')` on `/dashboard`) — resolved. Traced to a browser extension's injected script (`chrome-extension://eppiocemhmnlbhjplcgkofciiegomcon`), not app code.
- **PAYHUB-BACKEND-1M** (`ReferenceError: zaloJSV2 is not defined` on `/pay/{uuid}`) — resolved. Traced to the Zalo in-app browser's own WebView bridge, not app code.
- **PAYHUB-BACKEND-K** (`Failed to fetch dynamically imported module` on `/payments`) — set to **ignored (untilEscalating)**, not fixed. Root cause identified: classic Vite stale-chunk-after-deploy — `resources/js/app.ts` has no explicit `resolve` key; `@inertiajs/vite` (in `vite.config.ts`) injects one at build time via AST transform using `import.meta.glob('./pages/**/*.vue', {eager:false})`, and that dynamic `import()` 404s when a user's stale tab requests a chunk hash that a newer deploy removed. Idiomatic fix identified but **not implemented** (user chose to leave as-is, then ignore in Sentry instead): a global `window.addEventListener('vite:preloadError', () => window.location.reload())` in `app.ts`, with a sessionStorage guard to avoid a reload loop if a deploy is genuinely broken. Revisit if this issue escalates.

**Removed "Delete account" feature from `/settings/profile`** (admin-only self-destroy-account flow):
- Deleted `resources/js/components/DeleteUser.vue`, `app/Http/Requests/Settings/ProfileDeleteRequest.php`.
- `resources/js/pages/settings/Profile.vue` — removed `<DeleteUser />` usage and import.
- `app/Http/Controllers/Settings/ProfileController.php` — removed `destroy()` action and unused imports.
- `routes/settings.php` — removed `DELETE settings/profile` route (`profile.destroy`).
- `tests/Feature/Settings/ProfileUpdateTest.php` — removed the two tests covering delete-account.
- Regenerated Wayfinder actions with `php artisan wayfinder:generate --with-form` (must use `--with-form` to match `vite.config.ts`'s `wayfinder({ formVariants: true })`, otherwise `.form()` call sites across the app break type-checking).
- Kept `PasswordValidationRules` trait — still shared by `CreateNewUser`, `ResetUserPassword`, `PasswordUpdateRequest`.

## Decisions made

- For PAYHUB-BACKEND-K: chose not to implement the `vite:preloadError` fix — impact is negligible (0 users, 2 events in 6 weeks) — and instead marked the Sentry issue ignored-until-escalating so it stops resurfacing on isolated recurrences but will reopen if frequency spikes.
- Delete-account removal was full-stack (frontend + backend + tests), not just UI-hiding, per explicit user instruction.

## Problems solved

- Wayfinder regeneration silently drops `.form()` variants unless `--with-form` is passed to the CLI (the vite plugin's `formVariants: true` option only applies during `vite build`/dev, not `php artisan wayfinder:generate` run standalone). Caused a broad but false-looking wave of `vue-tsc` errors across unrelated files (Login.vue, Security.vue, TwoFactorChallenge.vue, etc.) until re-run with the flag.

## Current state

- All 3 Sentry issues triaged (2 resolved, 1 ignored).
- Delete-account removal fully implemented, tested (`ProfileUpdateTest` 3/3 pass, full Pest suite passed, `vue-tsc --noEmit` shows only the 3 known pre-existing errors — `ssr.ts` overload mismatch, `viva-accounts/Edit.vue` ref-typing x2), committed, and merged all the way up:
  - Commit `90331ce` (feature removal) + `f32ca42` (rebuilt assets) on `fix/multi-issue-fixes`, pushed.
  - Merged into `staging` (`27ae1e9..6e68b05`), pushed.
  - Merged `staging` into `master` (`4f641e6..81bd926`) via the separate worktree at `C:/Users/salmanabdul.ghani/Herd/payhub-fix-stale-pi` (master is checked out there, not in the main `payhub` dir — must `cd` there for any master-branch git operations), pushed.
- **Prod (Hostinger) has NOT been updated** — pushing to `master` does not auto-deploy; still needs a manual `git pull` + opcache reset on the box.
- `.claude/settings.local.json` has an unrelated pre-existing local modification that was deliberately left out of every commit this session (consistent with prior sessions).

## Next session starts with

- Nothing code-related queued. If the user wants this live, next step is deploying `master` to Hostinger prod (manual `git pull` + opcache reset).
- If PAYHUB-BACKEND-K (stale-chunk-on-deploy) recurs/escalates, implement the `vite:preloadError` listener fix in `resources/js/app.ts` (design already discussed and ready to execute — see "What was built" above).

## Open questions

- Whether/when to deploy `master` to Hostinger prod is up to the user.
