# 0004. Stripe account health monitoring: rationale

## Context

Payments on Stripe account #3 and #4 failed for weeks with "No valid payment method types". The real cause was that Stripe had switched off charging on both accounts (`charges_enabled` was false). PayHub only found out when clients hit a 500 error, and finding the cause meant running tinker commands and logging in to Stripe.

PayHub already stores each Stripe account's own secret key, so it can ask Stripe for the account's status directly. Stripe's standalone accounts (not Connect accounts) do not send `account.updated` webhooks, so PayHub has to ask, not wait to be told. Five accounts exist today, two of them inactive, so the number of Stripe calls is tiny.

The scheduler already runs on the server (the Clover sweep in `routes/console.php` depends on it), so no new infrastructure is needed.

This spec covers Stripe only. Revolut, Square, Viva and Clover give less health data and are left for later.

## Options considered

### Option 1: Scheduled check that saves the latest result

A command asks Stripe for each account every 15 minutes and stores one row per account. The page reads the saved rows.

**Pros**:
- The page is fast and never waits on Stripe.
- One shared checker serves the command, the buttons and the new account hook.
- Fits how the project already runs the Clover sweep.

**Cons**:
- Data can be up to 15 minutes old (Check now covers this).
- A small table and a scheduled job to maintain.

### Option 2: Ask Stripe live every time the page opens

The page calls Stripe for all accounts on each visit.

**Pros**:
- Always fresh, no table.

**Cons**:
- Slow page (several sequential Stripe calls) and it fails when Stripe is slow.
- No "restricted since" date, no list badge, no base for alerts later.

### Option 3: Stripe webhooks for account changes

Wait for `account.updated` events.

**Pros**:
- Instant updates.

**Cons**:
- Only sent to Connect platforms. These are standalone accounts, so nothing would arrive.

## Rationale

Option 1 is the only one that works for standalone accounts and also gives a fast page, a "status since" date and a base for alerts later (Context). The volume is about 480 Stripe calls a day across five accounts, so polling costs almost nothing, while Option 2 makes every page visit depend on Stripe being fast. Check now removes the main weakness of polling, the wait after a fix.

Recommended details, decided here: the checker is a service class shared by all three entry points so the status rules exist once. Check now runs inline (not queued) because a queue worker has already gone stale on this server once and the call takes about a second per account. Payment performance is worked out live from `payments` (not stored) so it can never go stale. Windows use `payments.created_at`, because `paid_at` is empty for failed and pending rows. Runner up for the last point: `paid_at` for completed rows, rejected because it would mix two different date bases in one rate.

