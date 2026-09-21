# Verify: Zelle Accounts · spec 0003 · updated 2026-09-21
_Steps derived from spec 0003 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones._

## UI / manual
- [ ] Log in as admin, open Zelle Accounts, Add account with name, email `Pay@Acme.test`, mobile, USD → saved, listed, email shown lowercase → AC-1, AC-2
- [ ] Add a second account with the same email in different case → error on email → AC-2
- [ ] Delete the first account, then re-add the same email → allowed → AC-2
- [ ] Enter `555-CALL` as mobile → error → AC-3
- [ ] Edit, deactivate, activate, delete from the list as admin and as the account role user → all work → AC-4, AC-5
- [ ] Assign an agent on Create/Edit; only agents appear in the picker → AC-6
- [ ] Create 16 accounts; list shows 15, page 2 shows 1; set currency filter then go to page 2, filter stays → AC-7
- [ ] Search partial email, `555 123`, `(555)123`, and `%` → correct rows, `%` matches nothing → AC-8, Value sourcing (admin list)
- [ ] Log in as the assigned agent → only active, assigned accounts appear as cards; deactivate one as admin, it disappears; reactivate, it returns → AC-5, AC-9, Value sourcing (agent list)
- [ ] On an agent card click each copy icon and "Copy all" → "Copied" shows; pasted block has name, email, mobile (skipped when empty), currency → AC-10, Value sourcing (copy all)
- [ ] Sidebar shows "Zelle Accounts" for admin, agent, account; a user with no role sees the empty state → AC-11
- [ ] Type a lowercase-vs-uppercase email and edit an account without touching assignments → assignments unchanged → AC-6, Value sourcing (assigned users)

## Commands
- [ ] `php artisan test tests/Feature/ZelleAccountManagementTest.php` → all pass → AC-1 to AC-12
- [ ] `SELECT COUNT(*) FROM activity_logs` before and after the manual steps → unchanged → AC-12

## Acceptance-criteria coverage
- AC-1 … steps 1, 4 · AC-2 … 1 to 3 · AC-3 … 4 · AC-4 … 5 · AC-5 … 5, 9 · AC-6 … 6, 12 · AC-7 … 7 · AC-8 … 8 · AC-9 … 9 · AC-10 … 10 · AC-11 … 11 · AC-12 … Commands
