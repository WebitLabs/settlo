# Settlo — Build Progress

Living document. Updated after every phase. Specs live in `settlo-specs/`.

## Status: September 2026 feedback + full gap round implemented — 981 tests passing (4199 assertions), not yet committed. Payments are SIMULATED (no Stripe).

## Done

| Phase | Commit | What |
|---|---|---|
| 0–1 Foundation | `18eaaf6` | Three Filament panels (app/firm/admin), roles (owner/accountant/superadmin), full data model (UUID PKs), factories, seeders, threat-model defenses (default-deny policies, guarded mass-assignment) |
| 2 Billing | `9667709` | Plans + feature matrix, dummy payment gateway (Stripe-swappable), subscription lifecycle, plan gating (`hasFeature`), monthly quotas with atomic `lockForUpdate` transitions |
| 5 core Tax engine | `efe3330` | Canton-aware calculator (federal/cantonal/communal/church + AHV/IV/EO), VAT threshold ladder, immutable `TaxEstimation` snapshots, recalc job |
| Infra | `50abc94` | Horizon on Redis (supervisors: default/files/ai), Reverb broadcasting, Gemini extraction service (`ReceiptExtractor` → `GeminiExtractor`/`FakeExtractor`, key server-side only), Filament DB notifications |
| Tenancy | `54cefe3` | App panel tenant = BusinessEntity, firm panel tenant = AccountingFirm, `canAccessTenant` cross-tenant guard, explicit tenant scoping on all resources |
| 3 Invoicing | `0c51045` | Clients CRUD, invoices with line items + live totals, invoice numbering (locked sequence), Swiss QR-bill (QRR/SCOR) PDF via dompdf (hardened), send/markPaid/cancel/overdue lifecycle, issued invoices immutable |
| 4 Expenses | `1ba67b4` | Receipt upload (private disk) → Horizon `files` queue → Gemini OCR → review/confirm flow, `processing_status` with real-time broadcast (`business.{id}` private channel) + table polling loaders, category matching, authz'd receipt download |
| 5 UI | `d8518f7` | Dashboard widgets (BusinessOverview stats, RecentInvoices), `/tax` page with breakdown + canton comparison, plan-gated |
| 6 Ask Settlo | `71e7f0a` + `f233c42` | 3-pane Inertia chat (SSE streaming + fallback), per-call context assembly (canton/revenue/VAT/profile → `context_snapshot`), escalations with atomic quota consumption + simulated accountant answer on `ai` queue + broadcast, dashboard preview widget, demo seeds. Engine: **Gemini** (`GeminiChatResponder`, same `GEMINI_API_KEY` as OCR; fake responder when key empty) |
| 7 Firm panel | `20be417` | Read-only client books behind active-assignment policies, escalation queue (claim/answer/KB capture/SLA), hashed-token client invitations + accept flow, member management, firm dashboard widgets, settings |
| 8 Superadmin | `f7d201c` | AuditLogger + append-only viewer, impersonation with cross-panel banner + audit trail, user/entity/firm/plan/subscription/payment resources, effective-dated tax-config editing, MRR/growth/plan-mix/ops metrics, KB approval + escalation oversight |
| 9 Onboarding + polish | `4172ba5` | Registration + 5-step tenant onboarding wizard (IBAN mod-97 validation, trial start), business settings, bank accounts, tax-breakdown/VAT-progress/to-do widgets, proactive VAT alerts, per-rate VAT breakdown (form+PDF), DE/FR/IT/EN invoice PDF, invoice view page, expense VAT summary |
| Review hardening | `d570748` | Final adversarial review (4 auditors + refuter verification): chat rate limiting, transactional escalation credit spend, firm-membership answer authorization, atomic claim, resolve guard, horizon snapshot schedule, VAT zero-threshold guard |

Tests (POC): 216 passing (748 assertions); see the September 2026 section for the current count. Demo logins (all password `password`): `anna@test.ch` (owner, /app), `maria@test.ch` (accountant, /firm), `admin@settlo.ch` (superadmin, /admin). Queue: Horizon (Redis, port 6380). OCR: Gemini (`GEMINI_API_KEY` in `.env`, empty → FakeExtractor). Real-time: Reverb + Filament DB notifications + table polling.

## September 2026 feedback round

Plan: [latest_requirements/IMPLEMENTATION_PLAN.md](latest_requirements/IMPLEMENTATION_PLAN.md). Gap analysis and decisions: [latest_requirements/GAP_ANALYSIS.md](latest_requirements/GAP_ANALYSIS.md).

| Phase | What |
|---|---|
| A | Form foundations (no native validation, acronyms in messages, re-validate on blur), Ask Settlo 500 + truncation, per-tab business settings, IBAN hardening, invoice/expense UX, AHV consistency, no VAT when not registered, Swiss postal code / UID / VAT rules, Tariff C + residence status |
| F3 | All 2,110 BFS communes (`settlo:import-communes`), `multiplier_is_estimated` |
| B | Two owner panels: personal area `/app` + workspace `/app/w/{uuid}`; tax profile belongs to the user; consolidated personal tax estimate with per-workspace shares |
| E | Per-workspace Stripe subscriptions (Cashier, tables `stripe_subscriptions*`), discount tiers 0/20/30 %, yearly = 10 × monthly, trial for the first workspace only, webhooks |
| C | Registration with terms consent, phone with country code, email verification, SMS code behind a flag (off), "Set up a business" stepper |
| D | Personal dashboard, profile, tax profile, personal tax, My businesses, workspace dashboard widgets |
| F1/F2 | GeoAdmin address search, postal-code autofill (`settlo:import-postal-codes`), UID register lookup |
| Simulated payments | Pay button activates the workspace in-process, clearly labelled; Stripe kept behind config |
| Full gap round | Deploy command + cron schedule, AI key required in production, queue drained by cron, security headers; superadmin and accountant access fixes, canned accountant answer only when no firm is assigned, audit trail on human answers; frozen creditor on issued PDFs, minimal Form 300 + year-end export, preview PDF; VAT threshold per person, both tax figures shown, escalating VAT alerts; POC toggles (no paywalls, demo seeding, optional phone, English-only UI); browser tests via Playwright |
| Gap fixes | Billing hardening (no dummy gateway outside local/testing, no double checkout, unsigned webhooks refused, coupon defaults), read-only expired workspaces, sibling tax shares, Pillar 3a 20 % cap, IBAN letters, MWST suffix, OTP reset on phone change |

New environment variables (see `.env.example`): `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `CASHIER_CURRENCY`, `CASHIER_CURRENCY_LOCALE`, `SETTLO_PAYMENT_GATEWAY`, `STRIPE_COUPON_WORKSPACE_20/30`, `SETTLO_TERMS_URL`, `SETTLO_PRIVACY_URL`, `SETTLO_TERMS_VERSION`, `SETTLO_PHONE_VERIFICATION`, `SETTLO_PHONE_VERIFICATION_DRIVER`, `GEMINI_CHAT_MAX_OUTPUT_TOKENS`, `GEMINI_CHAT_THINKING_LEVEL`.

### Simulated payments (2026-09-21 decision)

`SETTLO_PAYMENT_GATEWAY` accepts `simulated` (default), `stripe` or `dummy`. While it is `simulated`:

- Pressing pay activates the workspace in-process — no Stripe redirect, no hosted checkout, no external call, no webhook. `SubscriptionService::payNow()` locks the row, is idempotent (a repeat press adds no second payment row and does not move the billing period) and runs the same after-payment logic as a paid webhook: status Active, billing period, quota reset, pending plan / cancellation cleared, one `paid` ledger row.
- The UI says so: the button reads "Pay now (simulated)" and the modal and notifications state that no card is charged.
- `SimulatedGateway` is bound only on that explicit config value, never as a fallback; `settlo:renew-subscriptions` renews `dummy` and `simulated` rows.
- Stripe is untouched behind config: set `SETTLO_PAYMENT_GATEWAY=stripe` plus the keys to restore real Checkout, the billing portal and webhooks.

**Deploy command:** `php artisan settlo:deploy` (or `GET /cron/deploy?token=$CRON_SECRET`) runs migrations, seeds reference data and verifies it landed. `vercel.json` schedules the two daily commands the Vercel Hobby plan allows (trial expiry, overdue invoices); the queue drain, quota resets and renewals need an external pinger against the same `/cron/{command}` endpoints, or a Pro plan.

**Browser tests:** `php artisan test --testsuite=Browser` needs Node on `PATH` and Playwright installed (`npm install playwright && npx playwright install chromium`).

**Before deploying:** set `SETTLO_PAYMENT_GATEWAY=simulated` on the target environment (no Stripe keys or webhook secret needed while simulating). With `stripe` the app refuses to run billing without `STRIPE_SECRET` and refuses webhooks without `STRIPE_WEBHOOK_SECRET`. Take a database backup, run migrations, then `php artisan settlo:import-communes` and `php artisan settlo:import-postal-codes` (plus `php artisan settlo:stripe-sync-plans` once Stripe is real).

### Stripe test-mode runbook (for when Stripe is switched back on)

1. `.env`: `SETTLO_PAYMENT_GATEWAY=stripe`, `STRIPE_KEY=pk_test_…`, `STRIPE_SECRET=sk_test_…`, `CASHIER_CURRENCY=chf`; leave the coupon variables empty.
2. `php artisan settlo:stripe-sync-plans` — prints product and monthly/yearly price ids per plan and the coupons `settlo-workspace-20` / `-30`.
3. `stripe listen --forward-to http://localhost:8001/stripe/webhook`; put the printed `whsec_…` in `STRIPE_WEBHOOK_SECRET`, then `php artisan config:clear`.
4. First business: "Start 14-day free trial", no checkout, status Trialing. Billing → Choose plan → Checkout with trial end, card `4242 4242 4242 4242`. Back on Billing (`?checkout=success`) Choose plan disappears once `customer.subscription.created` arrives.
5. Second business: "Continue to payment" → Checkout shows 20 % off → Billing shows "Payment received, activating…" → after the webhook the status is Active with one payment row.
6. Third business: "Skip for now" creates it as Incomplete without checkout; pay later from Billing (30 % off).
7. Failures: card `4000 0000 0000 0341` via the billing portal plus `stripe trigger invoice.payment_failed` → Past due and an owner notification; card `4000 0025 0000 3155` for 3-D Secure.
8. Change plan, cancel and resume from Billing; webhooks update status and dates.
9. Hardening: with `APP_ENV=production` and no webhook secret, `POST /stripe/webhook` returns 403; with no `STRIPE_SECRET`, billing pages fail instead of using the dummy gateway.

## Remaining

Nothing in POC scope. Deferred (Later bucket, see GAP_ANALYSIS.md L1–L7): multi-currency/exchange rates, RAG knowledge-base retrieval into AI context, invoice dunning, production firm↔owner escalation routing beyond the demo, demo receipt shortcuts, Form 300 document export.

## Gap analysis

Done 2026-07-17 via 8-agent workflow (7 spec-vs-code scanners + synthesis). Full report: [GAP_ANALYSIS.md](GAP_ANALYSIS.md) — 53 gaps (G1–G53) mapped to Phases 6–9 plus a Later bucket (L1–L7: real Stripe, multi-currency, RAG, dunning, mobile).

## Postgres test run (opt-in)

The default suite runs on SQLite in-memory, which silently accepts some SQL Postgres rejects (BUG-48: `select "name" from "users"`). Before shipping anything that touches raw column names, also run the suite on Postgres:

```bash
docker exec settlo-pgsql createdb -U settlo settlo_test   # one-time; never use the dev `settlo` database
vendor/bin/pest -c phpunit.pgsql.xml --compact
```
