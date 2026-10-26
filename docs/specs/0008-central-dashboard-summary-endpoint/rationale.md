# 0008. Rationale: Central dashboard summary endpoint

## Context

PayHub runs as separate deployments: payhub1.com (MPGS) and payhub2.com (Authorize.net), each with its own database, users and merchants, both on Hostinger and both on the same PayHub code. More deployments are expected. Internal staff want one view of how every deployment is doing, without logging in to each one.

The architecture report (https://claude.ai/artifact/BDhcvs8CCBSvxrQGV39SKE) already chose a separate dashboard app that pulls a small summary on a schedule. This spec covers only the PayHub side of that choice.

Forces that shaped it:
- PayHub has no API routes and no token login today. Everything is a web page with a session.
- PayHub already works out similar numbers in `DashboardMetrics`, but for logged in users and in the app timezone.
- Production uses shared style hosting. Shared IP addresses can change, so an IP allow list is not dependable.
- The payments table is the live record of money. Anything new must not slow it down or change it.
- Both deployments set `APP_TIMEZONE=Asia/Karachi` in `.env.example`, but the config default is UTC, so a date rule must not depend on the app timezone.

## Options considered

### Option 1: Read only summary endpoint in PayHub, pulled by the dashboard

One versioned `GET` returns precomputed counts and cents for four windows. Token in a header, hashes in `.env`, cache for 60 seconds, rate limits, audit log.

**Pros**:
- No schema change and no change to payment code.
- Tiny, stable contract that both apps can test against one sample file.
- The deployment decides exactly what it shares.

**Cons**:
- Needs a new API route file, a middleware and a limiter.
- Numbers are a few minutes old.

### Option 2: Dashboard asks for filtered data (from and to chosen by the caller)

The same endpoint but the dashboard sends dates and maybe filters.

**Pros**:
- Flexible, any new report needs no PayHub change.

**Cons**:
- Every new view runs new queries on the live payments database.
- Harder to cache and harder to test.
- Contract grows with every request.

### Option 3: Tokens and audit in database tables, managed in the admin screens

Store hashed tokens and call logs in new tables, with an admin page.

**Pros**:
- Staff can rotate tokens without editing `.env`.

**Cons**:
- Two new migrations on both live deployments, against the "no database changes" decision.
- An admin screen is outside milestone 1.

### Option 4: Laravel Sanctum

Use the standard API token package.

**Pros**:
- Well known tokens with abilities.

**Cons**:
- Adds a package and a tokens table for a single read only call.
- Tokens would belong to users, which does not fit a machine to machine caller.

## Decision rationale

Option 1 fits the forces. It keeps the live payment database untouched, gives the dashboard one small stable shape, and keeps the first milestone small. Option 2 trades PayHub safety for flexibility the first milestone does not need. Options 3 and 4 add tables or packages to protect one read only door. Hashed tokens in `.env` match how PayHub already treats secrets (kept out of the database where possible, never shown), and two hash slots give rotation without downtime.

Fixed windows (all time, today, this month, last 30 days) were chosen over caller supplied dates so the dashboard makes one call per deployment and the cache works. Links are placed by created date and money by paid date, the same rule the existing PayHub dashboard uses for its revenue trend, so a number on the central dashboard can be checked against PayHub's own screen.

The 60 second cache is a safety measure for retries, not a performance claim. It is cheap, and it means a stuck scheduler cannot turn into a stream of queries on the payments table.

The engineer's preference for env stored hashes and a log file over tables was followed. It carries one tradeoff to accept: token changes need an `.env` edit and a cache reset on each deployment.
