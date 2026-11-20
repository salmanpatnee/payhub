# Central Dashboard: product brief for /scope

Give this file to the `/scope` skill in the new dashboard repository. Also copy these two files into that repo (they are the agreement with PayHub):

- `central-dashboard-api.md` (the contract, v1)
- `central-dashboard-sample.json` (a sample reply to build against)

## The idea in one paragraph

We run more than one PayHub deployment (payhub1.com, payhub2.com, maybe more later). Staff should not log in to each one to see how payments are going. The Central Dashboard is a separate app that asks every PayHub deployment for a small summary every 5 minutes, saves the answers, and shows one combined view plus a card per deployment. It is read only. It never changes anything in PayHub and never sees client names, emails or reference codes.

## Who uses it

- **Internal staff** (owners, managers): look at combined and per deployment numbers.
- **Admin**: also adds, edits, pauses and removes deployments, and manages who can log in.

Invite only login, like PayHub. No public sign up.

## What PayHub gives us (do not change, follow it)

One call per deployment: `GET https://<host>/api/central/v1/summary` with `Authorization: Bearer <token>`. It returns, for four windows (all time, today, this month, last 30 days):

- Link counts: total, pending, completed, failed, cancelled, and expired (expired is part of pending).
- Money in whole cents, per currency (`usd`, `gbp`, `pkr`): completed money, number paid, and pending money.
- Deployment identity: key, name, app release.

Full detail is in `central-dashboard-api.md`.

## Rules the dashboard must follow

1. Never add different currencies together. Show each currency on its own.
2. Links are counted by created date. Money is counted by paid date. They can disagree for the same day. Show them as two separate views and explain it in the interface.
3. Each reply is a full recount. To combine deployments, add the latest good reply of each one. Never add replies over time.
4. Accept unknown extra keys and unknown currency keys. Treat any `contract_version` other than `v1` as an error.
5. Any reply that is not 200 means "unavailable". Keep the last good reply and mark it stale. Do not crash and do not show zero.
6. On 429, wait for `Retry-After` before trying again. PayHub allows 6 calls per minute per token.
7. Money is whole cents everywhere. No floats.
8. Store each deployment's token encrypted. PayHub keeps only a hash, but the dashboard needs the real token to call it. Never show the token again after it is saved, never log it, never send it to the browser.

## What the dashboard should do (first version)

- Admin adds a deployment: key, name, base URL, token, on or off.
- A scheduled job pulls every active deployment about every 5 minutes and saves each good reply with the time it was pulled. Keep failed pulls too, with the reason, so problems can be seen.
- Home page: combined totals per currency for each window, then a card per deployment (today, this month, status, last updated).
- Deployment page: all four windows, link counts, money per currency, release version, and recent pull history.
- Freshness: show "last updated" on everything. Show a clear stale or failing badge when a deployment has not given a good reply for a while (for example 15 minutes).
- Alerts (later slice): tell staff when a deployment has been failing for a set time.

## Out of scope for the first version

- Per brand numbers, daily charts, attempt statistics (PayHub contract v1 does not have them yet).
- Anything that writes to PayHub.
- Currency conversion.
- Public access or client access.

## Suggested tech stack

Match PayHub so the same habits, components and tools work in both:

- **Backend:** Laravel 13, PHP 8.3
- **Frontend:** Vue 3 (Composition API, `<script setup>`), Inertia.js v3
- **Styling:** Tailwind CSS 4, shadcn-vue (Reka UI)
- **Auth:** Laravel Fortify, invite only
- **Roles:** spatie/laravel-permission (admin and viewer)
- **Database:** MySQL (it stores deployments and saved summaries)
- **Scheduling and queues:** Laravel scheduler plus queued jobs (database driver at first), one job per deployment so one slow deployment does not block the others
- **HTTP calls:** Laravel's HTTP client with a short timeout and a retry limit
- **Secrets:** Laravel `encrypted` cast for stored tokens
- **Errors:** Sentry
- **Tests:** Pest, using the sample JSON and fake HTTP replies
- **Hosting:** same type as PayHub (Hostinger), so the scheduler needs a cron entry that runs `schedule:run` every minute

## Decisions for /architect to settle

- Keep every saved reply, or only the latest few per deployment plus a daily summary, to control table size.
- How long before a deployment counts as stale, and how long before it triggers an alert.
- Whether staff need different roles (see all deployments versus only some).
- How to rotate a deployment's token from the dashboard side without a gap (PayHub already supports a current and a next token).

## Order to build in

1. One deployment, pulled by hand, using the sample JSON.
2. Save pulls and add the scheduled job.
3. The deployment page.
4. Several deployments and combined totals.
5. Stale badges, failure handling and alerts.

## Open items on the PayHub side

The dashboard can be built against the sample now. Real data needs PayHub's rollout first: staging, then payhub1.com, then payhub2.com, each with its own token. See spec 0008 in the PayHub repository.
