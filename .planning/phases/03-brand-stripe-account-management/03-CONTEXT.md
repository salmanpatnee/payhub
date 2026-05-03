# Phase 3: Brand + Stripe Account Management - Context

**Gathered:** 2026-05-03
**Status:** Ready for planning

<domain>
## Phase Boundary

Admin can create and configure brands with visual identity (name, logo, primary color, secondary color), attach encrypted Stripe account credentials validated against the live Stripe API, and the system blocks test keys from reaching production.

No payment creation. No client-facing pages. No webhook endpoints. Admin brand + Stripe credential management only.

</domain>

<decisions>
## Implementation Decisions

### Logo Upload
- **D-01:** File upload via Laravel Storage, local disk (`storage` disk), publicly accessible via `php artisan storage:link`. Logo stored at `brands/logo_path` as a Storage-relative path. Displayed via `Storage::url($brand->logo_path)`.
- **D-02:** Accept jpg/png/webp/svg image files. Max size ~2MB. No dimension enforcement. `logo_path` is nullable — brand can be created without a logo.
- **D-03:** On brand edit, if a new logo is uploaded the old file should be replaced (delete old, store new). If no new file is submitted, keep existing `logo_path` unchanged.

### Color Input UX
- **D-04:** Brand form uses **native `<input type="color">`** paired with a shadcn **Input** text field showing the hex value. Both controls sync reactively in Vue — user can pick via the color wheel or type an exact hex code. No third-party color picker library needed.
- **D-05:** Below the color pickers, a **live brand preview card** updates in real-time as colors are selected. Preview shows: brand name, logo thumbnail (if set), and a colored header strip using the chosen primary/secondary colors. Gives admin confidence in the color combination before saving.

### Claude's Discretion
- Stripe nav structure: `/admin/stripe-accounts` as a top-level admin resource section, or nested under `/admin/brands/{id}/stripe-accounts` as a sub-resource. Choose whichever better fits the admin UX flow — brand → accounts drill-down is natural given the relationship.
- Secret key edit UX: the edit form must never re-display the raw decrypted secret key. Show a masked placeholder (e.g. `sk_••••••••••••••••` or `[saved — paste new key to replace]`) that makes clear a key is stored. Do not pre-fill the field with the decrypted value.
- Test/live key blocking: check the `pk_test_`/`sk_test_` prefix against `APP_ENV === 'production'`. In local/staging environments, test keys must work normally.
- Webhook secret: the `webhook_secret` column exists on the model but is NOT captured in Phase 3. Phase 3 only handles `publishable_key` + `secret_key` (per STRIPE-01). `webhook_secret` is set up in Phase 6 when webhook endpoints are configured.
- Brand slug: auto-generated from name (kebab-case, unique) — not user-inputted.
- Color hex validation: validate server-side as 6-char hex string (`/^#[0-9a-fA-F]{6}$/`). Reject named colors.
- Stripe API key validation (STRIPE-03): call Stripe `\Stripe\Account::retrieve()` or a lightweight API method using `new StripeClient($secret_key)` to confirm the key is valid. Return a validation error if the API returns an authentication failure.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project Definition
- `.planning/PROJECT.md` — Core constraints, tech stack, Stripe multi-account pattern, out-of-scope list
- `.planning/REQUIREMENTS.md` — BRAND-01 through BRAND-04, STRIPE-01 through STRIPE-05 (all Phase 3 requirements)
- `.planning/ROADMAP.md` — Phase 3 goal, 5 success criteria, depends-on Phase 2

### Prior Phase Context
- `.planning/phases/01-foundation/01-CONTEXT.md` — D-06 through D-10: full schema decisions, logo_path as nullable Storage path (D-08), secret_key/webhook_secret as TEXT with encrypted cast (D-10)
- `.planning/phases/02-auth-user-management/02-CONTEXT.md` — D-03 through D-10: admin CRUD pattern, /admin/ prefix convention, AppSidebarLayout, sidebar nav with Brands link already wired

### Research
- `.planning/research/SUMMARY.md` — Stack pitfalls, StripeService pattern notes
- `.planning/research/ARCHITECTURE.md` — StripeService per-account pattern, two route surfaces

### Security Rules (CLAUDE.md)
- Never call `Stripe::setApiKey()` globally — always `new StripeClient($account->secret_key)`
- `secret_key` and `webhook_secret` columns must use Laravel `encrypted` cast (TEXT columns, no SQL WHERE)

### Existing Code
- `app/Models/Brand.php` — fillable: name, slug, logo_path, primary_color, secondary_color
- `app/Models/StripeAccount.php` — fillable: brand_id, account_name, publishable_key, is_active; encrypted cast on secret_key and webhook_secret
- `app/Http/Controllers/Admin/UserController.php` — reference for admin CRUD controller pattern
- `routes/web.php` — `/admin/brands` placeholder route exists at line 14 (needs upgrading to resource route in admin middleware group)
- `resources/js/pages/admin/users/` — Index.vue (table + dialog delete), Create.vue (Card form), Edit.vue — follow these patterns for brand and Stripe account pages

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `resources/js/pages/admin/users/Create.vue` — Card-based create form with CardHeader/CardContent/CardFooter layout, InputError, Select. Brand/StripeAccount create pages should follow this pattern exactly.
- `resources/js/pages/admin/users/Index.vue` — Table-based index with Dialog confirm-delete. Brand list should reuse this pattern.
- `resources/js/components/ui/` — Card, Input, Label, Button, Badge, Select, Dialog all available. No color picker component — use native `<input type="color">`.
- `app/Http/Requests/Admin/StoreUserRequest.php` / `UpdateUserRequest.php` — Form Request validation pattern. Brand and StripeAccount need equivalent FormRequests.

### Established Patterns
- Vue 3 Composition API + `<script setup lang="ts">` — all new components must follow
- shadcn-vue New York style (Phase 1 D-04) — all new UI components
- `defineOptions({ layout: { breadcrumbs: [...] } })` — breadcrumb pattern from user pages
- `useForm()` from `@inertiajs/vue3` — used for all form submissions
- Inertia redirect after store/update/destroy with `->with('success', '...')` flash message

### Integration Points
- `routes/web.php` — `/admin/brands` placeholder route (line 14) is inside `auth + verified` group but NOT in `role:admin` group. This is a bug — it must move to the `auth + verified + role:admin` group and become a full resource route.
- `resources/js/layouts/app/AppSidebar*.vue` — Brands nav link already exists in sidebar (pointing to `/admin/brands`). Phase 3 makes that route real.
- `app/Models/Brand.php` → `stripeAccounts()` HasMany relationship already defined — use this for eager loading.

</code_context>

<specifics>
## Specific Ideas

- The live color preview card in the brand form is the first component unique to Phase 3 (no analog in Phase 2). The preview card should use inline `:style` bindings driven by reactive hex values — no Tailwind dynamic classes (Tailwind 4 purges dynamic class names at build time).
- The brand list (`/admin/brands/index`) should show a color swatch alongside the brand name — a small `<span>` with `background-color` inline style. This makes the list immediately useful even before logos are uploaded.
- For Stripe account key validation (STRIPE-03): call the Stripe API in the FormRequest `afterValidation()` hook or in the controller's `store`/`update` action. Wrap the call in a try/catch for `\Stripe\Exception\AuthenticationException` — this is the specific exception thrown for invalid credentials.
- Deactivated Stripe accounts (`is_active = false`) must be excluded from the dropdown in Phase 4's payment creation form. Phase 3 sets up deactivation; Phase 4 queries only `is_active = true` accounts.

</specifics>

<deferred>
## Deferred Ideas

- **Webhook secret input** — `webhook_secret` column exists on StripeAccount but is NOT captured in Phase 3. This field is set during Phase 6 webhook endpoint setup.
- **Logo CDN / S3 upload** — Local disk is sufficient for v1. Cloud storage deferred to v2.
- **Brand deletion** — Phase 3 implements deactivation for Stripe accounts (STRIPE-05) but brands themselves have no deactivation requirement in v1. Hard-delete on Brand is Claude's discretion if needed for cleanup; no UI requirement exists.
- **Per-brand SMTP config** — Identified in STATE.md as a Phase 7 prerequisite blocker. Out of scope for Phase 3.

</deferred>

---

*Phase: 03-brand-stripe-account-management*
*Context gathered: 2026-05-03*
