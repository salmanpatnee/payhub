6a30e9f1-063c-a23e-8661-8a4d246d8a99The command is live. Here's what I built and how to use it.

  Why there's no UI

  Revolut Merchant webhooks are API-only — registration is POST /webhooks, and it returns a signing_secret once, which must then be stored encrypted on the account. The
  merchant dashboard has no create-webhook screen (especially in sandbox). So a command is the right tool.

  What I built
  php artisan revolut:register-webhook — registers the webhook via Revolut's Merchant API and stores the signing secret on the account automatically (so signature verification
  works). It also clears any existing webhook for the same URL first, so re-running is safe.

  - RevolutClient got createWebhook(), listWebhooks(), deleteWebhook() (hitting /webhooks on the 2024-09-01 API).
  - New command app/Console/Commands/RegisterRevolutWebhook.php.
  - 3 Pest tests (register + store secret, replace stale duplicate, reject non-HTTPS) — all green, Pint clean.

  How to run it on your live server

  # subscribes to ORDER_COMPLETED + ORDER_PAYMENT_FAILED, stores the signing secret
  php artisan revolut:register-webhook 1
  - It uses your APP_URL to build https://<your-domain>/webhook/revolut/1. If the public URL differs (e.g. a tunnel), override it:
  php artisan revolut:register-webhook 1 --url=https://your-public-domain/webhook/revolut/1
  - The URL must be HTTPS and publicly reachable (the command rejects http://localhost).
  - Make sure REVOLUT_ENVIRONMENT=sandbox and the account holds your sandbox secret key before running, so it registers against the sandbox API.

  After it runs, do a test payment → Revolut delivers ORDER_COMPLETED → PayHub verifies and flips the payment to completed. (Remember the queue note: QUEUE_CONNECTION=sync on
  the live server, or run a worker.)


  Good move — a separate staging env is exactly the right way to test Revolut sandbox + webhooks safely. Let me look at your actual env variables and deploy setup so the guide
  matches this app precisely (not generic boilerplate).

● Bash(cd "C:/Users/salmanabdul.ghani/Herd/payhub" && echo "=== .env.example ===" && cat .env.example 2>/dev/null && echo "=== composer scripts ===" && grep -A30 '"scr…)
  ⎿  === .env.example ===
     APP_NAME=Laravel
     APP_ENV=local
     … +106 lines (ctrl+o to expand)

● Here's the full picture first, then step-by-step. Two things about this app shape the whole plan, so read these before the steps:

  1. Provider secret keys are NOT in .env. Stripe/Revolut secret + webhook secrets live encrypted in the database (stripe_accounts / revolut_accounts), encrypted with APP_KEY.
  So staging needs its own APP_KEY and its own account rows with sandbox/test keys — you can not copy production's encrypted rows into a DB with a different key (they won't
  decrypt).
  2. REVOLUT_ENVIRONMENT is global. Setting it to sandbox on staging is exactly why a separate env solves your problem cleanly.

  ---  Staging environment — complete guide

  Strategy: a parallel deployment — its own subdomain, its own database, its own .env with sandbox/test credentials, and its own webhooks. Same codebase (deploy the branch
  you're testing).

  Step 1 — Subdomain + document root

  In your host (hPanel for Hostinger):
  - Create a subdomain, e.g. staging.yourdomain.com, pointing to a separate folder (e.g. ~/staging), not your production folder.
  - Set its document root to the /public subfolder (~/staging/public).
  - Issue an SSL cert for it (Let's Encrypt). HTTPS is mandatory — Revolut/Stripe only deliver webhooks over HTTPS.

  Step 2 — Get the code there

  cd ~/staging
  git clone <your-repo-url> .
  git checkout feat/revolut-integration     # the branch you want to test

  Step 3 — Separate database

  Create a new MySQL database + user (e.g. payhub_staging). Never point staging at the production DB.

  Step 4 — .env for staging

  Copy .env.example to .env and set these (tailored to this app):

  APP_NAME="PayHub (Staging)"
  APP_ENV=staging
  APP_KEY=                      # generated in Step 5 — leave blank for now
  APP_DEBUG=false              # set true only while actively debugging
  APP_URL=https://staging.yourdomain.com
  APP_TIMEZONE=Asia/Karachi

  # Separate database
  DB_CONNECTION=mysql
  DB_HOST=127.0.0.1
  DB_DATABASE=payhub_staging
  DB_USERNAME=payhub_staging
  DB_PASSWORD=********

  # Drivers (same as prod-style)
  SESSION_DRIVER=database
  CACHE_STORE=database
  QUEUE_CONNECTION=sync         # shared hosting: runs the webhook job inline, no worker

  # HTTPS hardening
  SESSION_SECURE_COOKIE=true

  # Revolut in SANDBOX (the whole point)
  REVOLUT_ENVIRONMENT=sandbox
  REVOLUT_API_VERSION=2024-09-01

  # Safety: never email real clients from staging
  MAIL_MAILER=log               # or Mailtrap creds if you want to see the emails

  # Telescope captures payloads incl. secrets — keep off (or behind auth)
  TELESCOPE_ENABLED=false

  ▎ Note: there are no STRIPE_* / REVOLUT_* secret-key env vars — those are entered as account rows via the admin UI (Step 9).

  Step 5 — Fresh app key

  php artisan key:generate
  This must be unique to staging. Because it encrypts the provider key columns, staging will hold its own sandbox/test keys — fully isolated from production.

  Step 6 — Install deps + build assets

  composer install --no-dev --optimize-autoloader
  npm ci && npm run build
  (If the host has no Node, run npm run build locally and upload the public/build folder.)

  Step 7 — Migrate + seed

  php artisan migrate --force --seed
  php artisan storage:link
  Seeding creates the admin/agent roles and seeded users (see docs/agent.md for the seeded logins — change those passwords on staging).

  Step 8 — Permissions + clear caches

  chmod -R 775 storage bootstrap/cache
  php artisan config:clear && php artisan route:clear && php artisan view:clear
  On shared hosting, prefer not config:cache (so .env stays live); if you do cache, re-run it after every .env change.

  Step 9 — Payment providers (sandbox/test) + webhooks

  This is the part that actually exercises your feature:

  1. Log in as admin → Admin → Revolut Accounts → Create → enter your Revolut sandbox secret key (and public key).
  2. Register its webhook with the command we built:
  php artisan revolut:register-webhook 1
  2. It targets https://staging.yourdomain.com/webhook/revolut/1 from APP_URL and stores the signing secret automatically.
  3. For Stripe: create a Stripe test-mode account row (test secret key), then register a Stripe webhook in the Stripe test dashboard →
  https://staging.yourdomain.com/webhook/stripe/{accountId} → paste its signing secret into the account.

  Step 10 — Queue

  QUEUE_CONNECTION=sync runs the webhook job inline — nothing else needed on shared hosting. On a VPS instead, use database + a worker (php artisan queue:work, via supervisor).
  Step 11 — Guardrails (keep staging private & safe)

  - Block indexing: HTTP Basic Auth (hPanel "Password Protect Directory") or an IP allowlist on the subdomain.
  - No real emails: MAIL_MAILER=log or Mailtrap (already in Step 4).
  - APP_DEBUG=false unless actively debugging; Telescope off.

  Step 12 — Verify

  1. Open https://staging.yourdomain.com/pay/{uuid} → Revolut card field loads (CSP already allows sandbox domain).
  2. Pay with the sandbox test card → redirected to success.
  3. Revolut fires ORDER_COMPLETED → check processed_revolut_events has a row and the payment is now completed with paid_at.

  ---
  Keeping staging updated (each deploy)

  cd ~/staging && git pull
  composer install --no-dev --optimize-autoloader
  npm ci && npm run build
  php artisan migrate --force
  php artisan config:clear && php artisan route:clear && php artisan view:clear

  App-specific pitfalls to avoid

  - Don't reuse production APP_KEY (defeats isolation) and don't import prod's encrypted account rows into staging — re-enter sandbox/test keys instead.
  - REVOLUT_ENVIRONMENT=sandbox must be set or it'll hit the prod Revolut API with sandbox keys and fail.
  - After any .env change, clear config (or re-cache).
  - Currency stays USD/GBP only; roles are admin/agent — seeding handles both.

  ---
  If it helps, I can scaffold two files into the repo to make this repeatable:
  - .env.staging.example — the template above, ready to copy.
  - deploy-staging.sh — the Step-12 deploy steps as a script.

  Want me to add those? (They're the only code changes here — the rest is host configuration you run on the server.)