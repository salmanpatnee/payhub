# Context

Phase 6 built per-account Stripe webhook endpoints with signature verification and queued fulfillment. User needs complete end-to-end test steps — from Stripe CLI setup through admin UI configuration to live webhook delivery and database verification.

Three layers: automated suite (already passing), admin UI manual checks, and live webhook firing via Stripe CLI.

## Action

Save this guide as `.planning/phases/06-webhooks-status-sync/06-TESTING-GUIDE.md` in the project.

---

# Prerequisites

## 1. Start dev servers
```bash
# Terminal 1
php artisan serve

# Terminal 2
npm run dev
```

## 2. Seed a StripeAccount with real test keys
If no real StripeAccount exists, create one via admin UI at `/admin/stripe-accounts/create` using test keys from Stripe dashboard (`pk_test_...` / `sk_test_...`). Leave `webhook_secret` blank for now.

Create a Payment linked to that StripeAccount (status: `pending`) so there's a record to transition.

---

# Step 1: Automated Tests (5 min)

```bash
php artisan test --compact --filter=StripeWebhookTest
```

Expected: **14 tests, 14 passed**.

```bash
php artisan test --compact --filter="StripeAccountManagement"
```

Expected: all green, no regressions.

---

# Step 2: Stripe CLI Setup

## Install
Download from https://stripe.com/docs/stripe-cli or:
```bash
# Windows (scoop)
scoop install stripe

# macOS
brew install stripe/stripe-cli/stripe
```

## Login
```bash
stripe login
# Opens browser — authenticate with your Stripe test account
```

## Start listener (forward to your local server)
```bash
stripe listen --forward-to http://payhub.test/webhook/stripe/{stripeAccountId}
# Replace {stripeAccountId} with the integer ID from your StripeAccount (e.g. 1, 2)
```

CLI prints:
```
> Ready! Your webhook signing secret is whsec_xxxxxxxxxxxxxxxxxxxxxxxx (^C to quit)
```

**Copy that `whsec_...` value.**

---

# Step 3: Configure webhook_secret in Admin UI

1. Go to `/admin/stripe-accounts/{id}/edit`
2. Find **Webhook Endpoint URL** field — should show `http://payhub.test/webhook/stripe/{id}`
3. Copy button works (copies URL to clipboard)
4. Find **Webhook signing secret** field — masked bullets `••••••••••••` if secret stored
5. Paste the `whsec_...` value from CLI into the password input
6. Click **Update Account**
7. Re-open the edit form — bullets must appear (secret stored), raw value must NOT be visible in page source

**Verify in DB:**
```bash
php artisan tinker --execute 'echo App\Models\StripeAccount::find({id})->webhook_secret;'
# Should output the whsec_ value (decrypted by Eloquent encrypted cast)
```

---

# Step 4: Manual Security Checks (curl)

```bash
# 4a. GET → 405 (wrong method)
curl -i http://payhub.test/webhook/stripe/1
# Expect: HTTP/1.1 405

# 4b. No CSRF token → 200, not 419
curl -i -X POST http://payhub.test/webhook/stripe/1 \
  -H "Content-Type: application/json" \
  -d '{}'
# Expect: HTTP/1.1 400 (bad sig — not 419 CSRF error)

# 4c. Unknown account → 404
curl -i -X POST http://payhub.test/webhook/stripe/99999 \
  -H "Content-Type: application/json" \
  -d '{}'
# Expect: HTTP/1.1 404

# 4d. Bad signature → 400
curl -i -X POST http://payhub.test/webhook/stripe/1 \
  -H "Stripe-Signature: t=1,v1=badhash" \
  -H "Content-Type: application/json" \
  -d '{"type":"payment_intent.succeeded"}'
# Expect: HTTP/1.1 400

# 4e. Missing Stripe-Signature → 400
curl -i -X POST http://payhub.test/webhook/stripe/1 \
  -H "Content-Type: application/json" \
  -d '{"type":"payment_intent.succeeded"}'
# Expect: HTTP/1.1 400
```

---

# Step 5: Live Webhook — payment_intent.succeeded

Make sure you have a Payment record in DB with:
- `stripe_account_id = {id}` (matching your StripeAccount)
- `stripe_payment_intent_id = NULL` (or leave blank for now — CLI trigger creates a real PI)
- `status = 'pending'`

## Option A: Link an existing PaymentIntent
If you have a real `pi_xxx` ID from a previous test payment:
```bash
# Update the payment record to link it
php artisan tinker --execute '
    App\Models\Payment::find(1)->update([
        "stripe_payment_intent_id" => "pi_xxx_yourId",
        "stripe_account_id" => {stripeAccountId}
    ]);
'
```

Then trigger:
```bash
stripe trigger payment_intent.succeeded --override payment_intent:id=pi_xxx_yourId
```

## Option B: Use Stripe CLI auto-generated event
```bash
stripe trigger payment_intent.succeeded
```

CLI creates a real PaymentIntent on your Stripe test account and fires the event. Note the `pi_xxx` ID from the CLI output, then manually link it:
```bash
php artisan tinker --execute '
    App\Models\Payment::where("status","pending")->first()->update([
        "stripe_payment_intent_id" => "pi_xxx_fromOutput",
        "stripe_account_id" => {stripeAccountId}
    ]);
'
```

**Then trigger again:**
```bash
stripe trigger payment_intent.succeeded --override payment_intent:id=pi_xxx_fromOutput
```

**Stripe CLI terminal should show:** `200 POST /webhook/stripe/{id}`

**Verify in DB:**
```bash
php artisan tinker --execute '
    $p = App\Models\Payment::where("stripe_payment_intent_id","pi_xxx_fromOutput")->first();
    echo $p->status;     // should be: completed
    echo $p->paid_at;    // should be: non-null timestamp
'
```

---

# Step 6: Live Webhook — payment_intent.payment_failed

Create another pending payment and link a different PI:
```bash
stripe trigger payment_intent.payment_failed --override payment_intent:id=pi_xxx_failed
```

**Verify:**
```bash
php artisan tinker --execute '
    $p = App\Models\Payment::where("stripe_payment_intent_id","pi_xxx_failed")->first();
    echo $p->status;   // should be: failed
    echo $p->paid_at;  // should be: NULL (not set on failure)
'
```

---

# Step 7: Idempotency Test

Fire the **same event** twice:
```bash
stripe trigger payment_intent.succeeded --override payment_intent:id=pi_xxx_fromOutput
stripe trigger payment_intent.succeeded --override payment_intent:id=pi_xxx_fromOutput
```

Both deliveries → `200 OK` in CLI.

**Verify:** `paid_at` has the **same** timestamp as Step 5 (not updated on second delivery). Status still `completed`.

---

# Step 8: Deactivated Account Test

```bash
php artisan tinker --execute 'App\Models\StripeAccount::find({id})->update(["is_active" => false]);'
```

```bash
stripe trigger payment_intent.succeeded
```

**Stripe CLI:** `200 POST /webhook/stripe/{id}` (acknowledged, not processed).
**Verify:** No new DB changes. Payment status unchanged.

Reactivate:
```bash
php artisan tinker --execute 'App\Models\StripeAccount::find({id})->update(["is_active" => true]);'
```

---

# Step 9: Admin UI — Validation Checks

1. Go to `/admin/stripe-accounts/{id}/edit`
2. Enter `sk_live_xxx` in webhook_secret field → save → expect validation error: "must begin with whsec_"
3. Enter `bad_value` → expect same validation error
4. Leave blank → save → secret unchanged (bullets still shown)
5. Enter valid `whsec_test_xxx` → save → secret updated (bullets still shown, new value in DB)

---

# Step 10: Replay Event (Stripe Dashboard)

In Stripe test dashboard → Developers → Webhooks → select your endpoint → find an event → click **Resend**. Verify 200 response and idempotent DB state.

---

# Critical Files

- `app/Http/Controllers/StripeWebhookController.php` — main handler
- `app/Jobs/HandleStripeWebhookJob.php` — DB write logic
- `app/Http/Controllers/Admin/StripeAccountController.php` — edit/update for webhook_secret
- `resources/js/pages/admin/stripe-accounts/Edit.vue` — admin UI
- `tests/Feature/StripeWebhookTest.php` — automated suite (14 tests)

---

# Verification Checklist

- [ ] `php artisan test --compact --filter=StripeWebhookTest` → 14/14 green
- [ ] Stripe CLI listener running, `whsec_` saved in admin UI
- [ ] curl GET → 405, no-sig POST → 400, bad-sig POST → 400
- [ ] `stripe trigger payment_intent.succeeded` → 200, DB status=completed, paid_at set
- [ ] `stripe trigger payment_intent.payment_failed` → 200, DB status=failed, paid_at null
- [ ] Same event twice → 200 both times, DB unchanged on second delivery
- [ ] Deactivated account → 200, no DB change
- [ ] Admin UI validation: blank preserves secret, invalid prefix rejected, valid whsec_ accepted
