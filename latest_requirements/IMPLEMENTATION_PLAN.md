# Settlo — Implementation Plan for the September 2026 Feedback

> **Audience:** the implementing agent/developer. This document is self-contained: every task
> names the exact files, the current behaviour (with `file:line` references taken from the code
> on `master` @ `7f56f93`), the root cause, the exact change, and the tests to write.
> **Sources:** `latest_requirements/email_feedback.txt` (Bogdan, architecture + UX review) and
> `latest_requirements/Settlo_Platform_TestingFeedback - Settlo_Feedback_Onboarding.xls.csv`
> (BUG-01 … BUG-59, incl. unnumbered follow-up rows).
> **Written:** 2026-09-17, against the code as it exists today (Laravel 13.2, Filament 5.4.3,
> Livewire 4.2.3, Inertia 3 / React 19, Pest 4, Postgres 17 in dev/prod, SQLite in tests).

---

## Table of contents

0. [How to work with this plan](#0-how-to-work-with-this-plan)
1. [Decisions & open questions](#1-decisions--open-questions)
2. [Traceability matrix (every email topic + every CSV row)](#2-traceability-matrix)
3. [Phase overview & ordering](#3-phase-overview--ordering)
4. [Phase A — Cross-cutting form foundations & critical bug fixes](#4-phase-a--cross-cutting-form-foundations--critical-bug-fixes)
5. [Phase B — User-centric architecture (Person → Business Workspaces)](#5-phase-b--user-centric-architecture)
6. [Phase C — Registration & onboarding redesign](#6-phase-c--registration--onboarding-redesign)
7. [Phase D — Personal area & dashboards](#7-phase-d--personal-area--dashboards)
8. [Phase E — Per-workspace billing with Stripe (Cashier, test mode)](#8-phase-e--per-workspace-billing-with-stripe)
9. [Phase F — Swiss data lookups (address autocomplete, UID register, communes)](#9-phase-f--swiss-data-lookups)
10. [Test plan summary & regression checklist](#10-test-plan-summary--regression-checklist)
11. [Appendix — reference snippets, API payloads, data sources](#11-appendix)

---

## 0. How to work with this plan

### 0.1 Local environment (already set up on this machine)

| Thing | Value |
|---|---|
| PHP 8.4 + Composer | `~/.config/herd-lite/bin` (on `PATH` in new shells via `~/.zshrc`) |
| Node 22 | nvm (`~/.nvm`), `nvm use 22` |
| Postgres / Redis | `docker compose up -d` → `settlo-pgsql` on **5433**, `settlo-redis` on **6380** |
| App URL | `http://localhost:8001` (port 8000 is taken by another project) — `APP_URL` is set accordingly |
| Dev stack | `npx concurrently "php artisan serve --port=8001" "php artisan horizon" "php artisan reverb:start" "php artisan pail --timeout=0" "npm run dev"` (the `composer run dev` script hard-codes port 8000) |
| Demo logins (password `password`) | `anna@test.ch` (owner), `maria@test.ch` (accountant, `/firm`), `admin@settlo.ch` (superadmin, `/admin`) |
| Gemini | `GEMINI_API_KEY` is set in `.env` → real `GeminiExtractor` / `GeminiChatResponder` are bound |
| Filament Blueprint license | `auth.json` (git-ignored) |

- The **Laravel Boost MCP** server failed to start in the planning session because PHP was not on
  `PATH` yet. Restart Claude Code so `search-docs`, `database-schema`, etc. work. Until then, the
  Filament docs are available offline in `vendor/filament/*/docs/*.md`.

### 0.2 Commands

```bash
php artisan test --compact                                  # full suite (242 tests pass today)
php artisan test --compact --filter=BusinessSettings        # targeted
vendor/bin/pint --dirty --format agent                      # after every PHP change
npm run build                                               # after JS/CSS/Blade class changes
php artisan migrate                                         # dev DB is Postgres
php artisan migrate:fresh --seed                            # reset demo data
```

### 0.3 Codebase conventions you MUST keep

1. **Guarded columns are written with `forceFill()`** (owner_id, business_entity_id, iban, status,
   money totals, quotas). Never add them to `$fillable`. See `app/Models/BusinessEntity.php:18-29`.
2. **Tenant isolation is explicit**: every workspace resource overrides `getEloquentQuery()` with a
   `where('business_entity_id', $tenant->getKey())` (e.g. `ClientResource.php:71-78`). Keep doing this.
3. **Money = BCMath strings**, rounded only at the end (`InvoiceService::round()`, `TaxCalculator`).
4. **Policies are default-deny** and check ownership + `canWrite()`.
5. PHP: curly braces always, constructor promotion, explicit return types, PHPDoc blocks (no inline
   comments unless the logic is complex), TitleCase enum keys, array-shape PHPDoc.
6. Use `php artisan make:* --no-interaction` to scaffold (`make:filament-page`, `make:filament-resource`,
   `make:migration`, `make:class`, `make:test --pest`, `make:enum`, `make:middleware`, `make:job`,
   `make:listener`).
7. **Tests use SQLite in-memory** (`phpunit.xml`). SQLite silently treats an unknown double-quoted
   identifier as a string literal — that is exactly how BUG-48 (a `select "name" from users` on a
   table without a `name` column) passed the test-suite but 500s on Postgres. Task A4.3 adds an
   opt-in Postgres test run; use it before shipping anything that touches raw column names.
8. After every task: run the affected tests, then `vendor/bin/pint --dirty --format agent`.
9. Do **not** add Composer/NPM dependencies other than the ones approved in §1.1.

### 0.4 Filament v5 namespace cheat-sheet (use these exact imports)

| Kind | Namespace |
|---|---|
| Form fields (`TextInput`, `Select`, `Checkbox`, `Toggle`, `Radio`, `DatePicker`, `FileUpload`, `Repeater`, `Textarea`, `Hidden`) | `Filament\Forms\Components\…` |
| Form field base class | `Filament\Forms\Components\Field` |
| Read-only display inside forms (replaces deprecated `Placeholder`) | `Filament\Infolists\Components\TextEntry` with `->state(...)` |
| Layout (`Section`, `Grid`, `Tabs`, `Tabs\Tab`, `Wizard`, `Wizard\Step`, `Group`, `FusedGroup`, `Actions`, `Form`, `EmbeddedSchema`, `Callout`) | `Filament\Schemas\Components\…` |
| Utilities | `Filament\Schemas\Components\Utilities\Get`, `…\Set` |
| Actions (all) | `Filament\Actions\…` (`Action`, `ActionGroup`, `DeleteAction`, `DeleteBulkAction`, …) |
| Table columns / filters | `Filament\Tables\Columns\…`, `Filament\Tables\Filters\…` |
| Icons | `Filament\Support\Icons\Heroicon` |
| Widths | `Filament\Support\Enums\Width` |
| Notifications | `Filament\Notifications\Notification` |
| Navigation item | `Filament\Navigation\NavigationItem` |

- `Filament\Forms\Components\Placeholder` is **deprecated** in v5 (`vendor/filament/forms/src/Components/Placeholder.php:7`) — new code must use `TextEntry::make(...)->state(fn (...) => ...)`.
- Use `->live()`, never `->reactive()`.
- Docs (online): https://filamentphp.com/docs/5.x/ — offline: `vendor/filament/forms/docs/`, `vendor/filament/schemas/docs/`.

---

## 1. Decisions & open questions

### 1.1 Decisions taken (2026-09-17, by Marius)

| # | Decision | Consequence for the plan |
|---|---|---|
| D1 | **Integrate Stripe now, in test mode.** | Add `laravel/cashier:^16.8` (supports Laravel 13; pulls `stripe/stripe-php`, `moneyphp/money`). Phase E. Cashier tables are renamed so they don't collide with the existing domain `subscriptions` table. |
| D2 | **Placeholder discount tiers**: 1st workspace 0 %, 2nd 20 %, 3rd+ 30 %; yearly price = 10 × monthly. | Config-driven (`config/settlo.php → billing`), Stripe coupons per tier. |
| D3 | **Phone: country code + format validation now; SMS OTP later** (feature flag, off). | Add `propaganistas/laravel-phone:^6.0` (libphonenumber; supports Laravel 13) — the only other new dependency. OTP is built behind a `PhoneVerifier` contract + `settlo.phone_verification.enabled=false`. |
| D4 | **Free trial only for the first workspace** (14 days, no card). | Later workspaces must complete Stripe Checkout before the workspace unlocks. |

### 1.2 Decisions taken by the planner (change here if you disagree)

| # | Decision | Why |
|---|---|---|
| P1 | Two owner panels: **`app` = Personal area** at `/app` (no tenancy: login, registration, personal dashboard, profile, tax profile, businesses, billing) and **`workspace` = Business workspace** at `/app/w/{businessEntity}` (tenant = `BusinessEntity`). | Filament v5 registers every page of a tenant panel under the `{tenant}` route prefix (`vendor/filament/filament/routes/web.php:112-131`), so a tenant-less personal dashboard cannot live in the same panel. Keeping the id `app` for the personal panel keeps the existing login/register/password-reset routes and URLs (`/app/login`, `/app/register`). |
| P2 | **Tax profile belongs to the user.** `vat_status` and `estimated_annual_revenue` move to `business_entities` (they are per business). | Email §1 + BUG-50.5. |
| P3 | **Consolidated personal tax estimate** (all sole-proprietorship workspaces summed) is the source of truth; each workspace shows its proportional share. | Income tax and AHV are levied on the person, not on each business. |
| P4 | Ask Settlo stays **workspace-scoped** this round (conversation context = business + personal tax profile). | Keeps the React island, routes and escalations unchanged except for URLs. |
| P5 | Discount tier is **locked when a workspace subscription is created** (not recalculated when another workspace is cancelled). | Avoids surprise price changes; confirm with Bogdan (Q7). |
| P6 | Directory rename: `app/Filament/App` → `app/Filament/Workspace` (namespace `App\Filament\Workspace`), new `app/Filament/Personal`, shared schema builders in `app/Filament/Shared`, support classes in `app/Filament/Support`. | Clear naming once there are two owner panels. |

### 1.3 Open questions for Bogdan (a default is applied so work is not blocked)

| # | Question | Default used in this plan |
|---|---|---|
| Q1 | AHV deduction rule. The spec (`settlo-specs/Settlo_Tax_Engine_Algorithms.docx` §Step 2) says *only 50 % of AHV* is deductible; Swiss practice for self-employed is that personal AHV/IV/EO contributions are deductible in full. | Keep the spec (50 % of AHV) but fix the minimum-contribution inconsistency (BUG-43) and show the deduction line. |
| Q2 | Which *residence statuses* stop the engine (Quellensteuer)? | B, L and G (all nationalities) stop, like today's "B permit"; Swiss citizens and C permits are calculated. |
| Q3 | "Tariff C" is a withholding-tax tariff; federal income tax only has single/married schedules. | Tariff C uses the married (B) federal brackets; the label says "Married, dual income (Tariff C)". |
| Q4 | VAT registration for sole proprietorships is per **person** (all sole-prop activities share one VAT number). | Keep VAT status per workspace this round; show an info callout on the VAT summary when the user has more than one sole-prop workspace. |
| Q5 | Production mail provider for email verification (today `MAIL_MAILER=log`). | Local: `log`/Mailpit. Production: configure before enabling (Resend/Postmark/SES). |
| Q6 | SMS provider for OTP (later). | `LogPhoneVerifier` fake; flag off. |
| Q7 | Discount behaviour when a workspace is cancelled (P5), real tier percentages, yearly pricing, and the Stripe account's legal entity (waiting on Cristina). | P5 + D2 placeholders; Stripe **test** keys only. |
| Q8 | Terms of Service / Privacy Notice URLs. | `config('settlo.legal.terms_url')` = `https://settlo.ch/terms`, `privacy_url` = `https://settlo.ch/privacy` (env-overridable). |
| Q9 | BUG-37 could not be reproduced on the server (two consecutive Create calls with an invalid IBAN are both rejected). Exact steps / IBAN? | Add defence-in-depth (model-level guard + double-submit test). |
| Q10 | BUG-01/02 not reproducible (Bogdan couldn't either); the code has no default values → it was the tester browser's saved-credentials autofill. | Add `autocomplete` hints only. |
| Q11 | Commune tax multipliers (Steuerfuss): only 6 communes have real values today. | Import all 2,110 communes from BFS; multiplier = canton capital default (`canton_fiscal_configs.communal_multiplier_default`) until an ESTV Steuerfuss import is done (flagged `multiplier_is_estimated`). |
| Q12 | The CSV export lost Bogdan's blue colour coding (which bugs he verified). | All rows are planned; rows he explicitly rejected are marked "won't do". |

---

## 2. Traceability matrix

Legend — **Status**: ✅ root cause confirmed in code/test/API during planning · 🔎 probable cause from code reading (verify visually) · ❌ not reproducible · 🚫 won't do (Bogdan rejected).

### 2.1 Email topics

Email topics are labelled **M1–M6** (the email's section numbers) so they don't clash with Phase E task ids.

| Topic | Summary | Tasks |
|---|---|---|
| M1 Structure | User (person) at the centre with Personal Profile, Tax Profile, personal settings; N Business Workspaces under one account; future GmbH/AG without re-architecture. | B1–B7, D2–D5 |
| M2 Business model | One subscription per workspace; 2nd/3rd workspaces discounted. | E1–E5 |
| M3 Onboarding | One short flow: account → email verification → phone OTP (SMS) → straight into the app; everything else later. | C1–C7 |
| M4 Dashboard | Personal dashboard (all workspaces, tax profile, personal tax, consolidation) + one dashboard per workspace (invoices, expenses, clients, bank accounts, VAT, profit, cash). | D0–D7 |
| M5 Layout & UX | Onboarding looks like a stretched mobile layout; desktop needs wider, premium forms. Native mobile app later. | C5, C6, A8, A10, A21 |
| M6 Stripe | One Stripe customer per user; one Stripe subscription per workspace; price tier by number of active workspace subscriptions. | E1–E5 |

### 2.2 CSV rows

| ID | Module / page | Problem (short) | Status | Root cause (short) | Task | Phase |
|---|---|---|---|---|---|---|
| BUG-01 | Register | Password prefilled with "password" | ❌ | No default in code (`Register.php:47`); browser autofill | A22 | A |
| BUG-02 | Login/Register | Email prefilled `anna@test.ch` | ❌ | Same as BUG-01 | A22 | A |
| BUG-03 | Register | Native browser tooltip instead of styled error | ✅ | Filament renders `required`/`type=email`; browser blocks submit | A1 | A |
| BUG-04 | Register | Password length error doesn't clear live | ✅ | Errors only recomputed on submit | A3, C2 | A/C |
| BUG-05 | Register | "Too many registration attempts" too fast | ✅ | `Register::register()` calls `rateLimit(2)` **before** validation (`vendor/filament/filament/src/Auth/Pages/Register.php:70`) + per-email limit of 2 | A15 | A |
| BUG-06 | Register | Phone accepts "abcde" | ✅ | `TextInput::make('phone')->tel()` with no format rule (`Register.php:36-40`) | C3 | C |
| BUG-07 | Register | No Terms / Privacy consent | ✅ | Field missing | C2 | C |
| BUG-08 | Register | No Romansh | 🚫 | Bogdan: "NU este cazul" | — | — |
| BUG-09 | Onboarding | Stepper shows green ticks although step invalid | 🔎 | Superseded by BUG-57 redesign | C5 | C |
| BUG-10 | Onboarding | Errors stay after correction | ✅ | Same as BUG-04 | A3 | A |
| BUG-11 | Onboarding | Address optional; wants autocomplete (street+no → PLZ, city, canton) | ✅ | Fields optional (`RegisterBusinessEntity.php:112-124`) | C5, D2, F1 | C/D/F |
| BUG-12 | Onboarding / Tax profile | Commune "No options available" for AG | ✅ | `CommuneSeeder` seeds only 6 communes (ZH×3, ZG, GE, BS) | F3 | F (do early) |
| BUG-13 | Tax profile | Missing Tariff C | ✅ | `MaritalStatus` has 3 cases | A20 | A |
| BUG-14 | Tax profile | "Residence status" with 8 options | ✅ | `ResidencePermit` has 2 cases | A20 | A |
| BUG-15 | Clients create | Native tooltip for empty name | ✅ | As BUG-03 | A1 | A |
| BUG-16 | Invoice create | Triple-click doesn't clear numbers → "0200" | 🔎 | Default `0` values + no select-on-focus | A8 | A |
| BUG-17 | Invoice create | Summary total wrong / not live | 🔎 | Summary recomputed only after `live(onBlur)` round-trips; concatenated inputs; float math | A8 | A |
| BUG-18 | Invoice view | Line-item columns squeezed | 🔎 | `RepeatableEntry` with 12-col grid (`InvoiceInfolist.php:45-54`) | A8 | A |
| BUG-19 | Invoice view | No Send / Mark paid / Edit on view page | ✅ | `ViewInvoice::getHeaderActions()` only has PDF (hidden for drafts) (`ViewInvoice.php:16-25`) | A9 | A |
| BUG-20 | Expense create | Saves with amount 0 and no vendor/category | ✅ (probe) | `amount` `minValue(0)`, default 0 (`ExpenseForm.php:63-70`) | A10 | A |
| BUG-21 | Expense edit | −50 / 150 % → no visible error | ✅ (probe) | Browser blocks submit (native `min`); `vat_rate` has no max | A1, A10 | A |
| BUG-22 | Expense form | Category label wraps on 5 lines | 🔎 | 2-col section, long option labels | A10 | A |
| BUG-23 | Expenses list | Deductibility vs Status contradictory; confirm step hidden | ✅ | Both columns use the label "Review needed" (`DeductibilityStatus.php:21`, `ExpenseStatus.php:17`); confirm hidden in `ActionGroup` | A11 | A |
| BUG-24 | Expense form | VAT amount not auto-calculated from rate | ✅ | No reactive link between `vat_rate` and `vat_amount` | A10 | A |
| BUG-25 | Ask Settlo | Empty-state elements overlap conversation | 🔎 | Header/pills layout inside 3-pane island | A12 | A |
| BUG-26 | Dashboard → Ask Settlo | `?q=` doesn't start a conversation | ✅ | `Index.jsx` never reads `q` | A12 | A |
| BUG-27 | Dashboard to-do | "Send invoice" goes to Edit | ✅ | `ToDoWidget.php:88` links to `edit` | A9 | A |
| BUG-28 | Business settings | "uID" in error | ✅ | Filament `Str::lcfirst($label)` (`vendor/filament/forms/src/Components/Concerns/CanBeValidated.php:783`) | A2 | A |
| BUG-29 | Business settings | Postal code accepts "ABCDE" | ✅ | No format rule (`BusinessSettings.php:148`) | A16 | A |
| BUG-30 | Business settings | "iBAN" in error | ✅ | As BUG-28 | A2 | A |
| BUG-31 | Business settings | Errors stale until tab switch | ✅ | As BUG-04 | A3, A6 | A |
| BUG-32 | Business settings | IBAN not persisted after reload | ✅ (probe) | `mount()` never fills `iban` (`BusinessSettings.php:98-103`; `ENTITY_FIELDS` lacks it) | A6 | A |
| BUG-33 | Business settings | Tax profile not saved | ✅ (probe) | One `save()` validates all tabs; empty required IBAN blocks it (`BusinessSettings.php:276`) | A6 | A |
| BUG-34 | Business settings | No communes for AG | ✅ | As BUG-12 | F3 | F |
| BUG-35 | Business settings | Children accepts −5 | 🔎 | Probe showed server `minValue(0)` works; browser blocks submit (native) → no visible error. Add `integer` + live validation | A18 | A |
| BUG-36 | Business settings | Pillar 3a not capped | ✅ | Cap applied silently on save only (`BusinessSettings.php:292`); UI shows raw 999999; cap ignores Pillar 2 | A18 | A |
| BUG-37 | Bank accounts | Invalid IBAN saved on 2nd click | ❌ | Not reproduced server-side; add hardening | A7 | A |
| BUG-38 | Clients create | No error text, only green border | ✅ | As BUG-03 (green = focus ring) | A1 | A |
| BUG-39 | Clients create | VAT number accepts anything | ✅ | No rule (`ClientForm.php:31-33`) | A16 | A |
| BUG-40 | Clients create | Postal code "ZZ99" | ✅ | No rule (`ClientForm.php:44-46`) | A16 | A |
| BUG-41 | Clients list | Delete dialog doesn't warn about invoices | ✅ | Plain `DeleteBulkAction` / `DeleteAction`; `invoices.client_id` is `restrictOnDelete` → force-delete would 500 | A17 | A |
| BUG-42 | Clients list | Clearing search leaves "No clients" | 🔎 | Server side works (probe) → front-end | A17 | A |
| BUG-43 | Tax estimate | AHV shown ≠ AHV deducted | ✅ | Minimum contribution (CHF 514) replaces `totalSI` but deduction uses unfloored AHV: 966.41 × 10.6 % × 50 % = 51.22 (`TaxCalculator.php:120-132`) | A13 | A |
| BUG-44 | VAT summary | Unconfirmed expenses silently excluded | ✅ | Query filters `status = reviewed` (`VatSummary.php:60`) with no notice | A11 | A |
| BUG-45 | Business settings | Tax save fails silently when IBAN missing | ✅ (probe) | As BUG-33 | A6 | A |
| BUG-46 | Invoices / tax | VAT charged while "Not registered" | ✅ | Invoice form always offers 8.1 % (`InvoiceForm.php:99-105`); revenue uses gross total | A14 | A |
| BUG-47 | Ask Settlo | Answers truncated mid-sentence | ✅ (API test) | `maxOutputTokens: 1024` (`GeminiChatResponder.php:21`); Gemini 3.5 thinking consumed 982 of 1024 tokens → `finishReason: MAX_TOKENS` | A5 | A |
| BUG-48 | Ask Settlo | "Verify with accountant" → 500 | ✅ (Postgres) | `$escalation->accountant()->value('name')` (`AskSettloController.php:312`) → `column "name" does not exist` | A4 | A |
| BUG-49 | Ask Settlo | Context badges overlap header/bubbles | 🔎 | As BUG-25 | A12 | A |
| BUG-50 | Onboarding | "Only sole proprietorships…" text; want disabled GmbH/AG with "Coming soon" | ✅ | `RegisterBusinessEntity.php:99-106` | A21, C5 | A/C |
| BUG-50.5 (+ follow-up row) | Structure | Person first, businesses from dashboard; Tax profile is personal and aggregates business income | — | Architecture | B*, C*, D* | B–D |
| BUG-51 | Onboarding | Subtitle + "Skip for now" | — | Feature | C5 | C |
| BUG-52 | Onboarding | Phone with country code, format validation, OTP | — | Feature | C3, C4 | C |
| BUG-53 | Onboarding | Back/Start trial buttons confusing; Skip on every step; Next disabled until valid | ✅ | Wizard submit button rendered on last step only; no skip | C5 | C |
| BUG-54 | Onboarding | "Legal name" → "Trading name" | — | Copy | A21 | A |
| BUG-55 | Onboarding | Payment terms only 15/30/60 | ✅ | `Select` options (`RegisterBusinessEntity.php:147-152`, `BusinessSettings.php:178-182`) | A21 | A |
| BUG-56 | Onboarding | Explain UID | — | Copy | A21 | A |
| BUG-57 | Onboarding | No progress indicator; mobile layout on desktop | ✅ | `RegisterTenant` is a `SimplePage` (narrow card) | C6 | C |
| BUG-58 (1) | Onboarding | UID validated only on Next | ✅ | Regex rule only runs on step validation | A3, A21 | A |
| BUG-58 (2) | Onboarding | UID should autofill from public register | — | Feature (UID-WSE public service verified reachable) | F2 | F |
| BUG-58 (3) | Tax profile | Commune list empty | ✅ | As BUG-12 | F3 | F |
| BUG-58 (4) (unnumbered) | Tax profile | Canton not taken from business profile | ✅ | Tax step reads `canton_id` but the settings page uses a separate `tax_canton_id`; in the new model the tax canton defaults from the **personal** address | D3 | D |
| BUG-58 (5) (unnumbered) | All forms | Long helper texts → info "i" tooltip | — | Use `hintIcon(..., tooltip:)` | A21 | A |
| BUG-59 | Invoice create | Cramped sections; stack full width | 🔎 | 2-col sections + 12-col repeater | A8 | A |

---

## 3. Phase overview & ordering

**Execution order: A → F3 → B → E → C → D → F1/F2** (sections are numbered by topic, not by execution order).

```
1. Phase A  (ship first — production is live on Vercel)
     A1–A3   form foundations (novalidate, acronym labels, validate-on-blur helper)
     A4–A5   Ask Settlo 500 + truncation                     ← highest user impact
     A6–A7   business settings save + IBAN hardening
     A8–A11  invoices / expenses / VAT summary UX
     A12     Ask Settlo UI
     A13–A14 tax engine consistency, VAT when not registered
     A15–A23 registration throttling, field validation, enums, copy, fresh-setup config fix
2. Phase F3 commune dataset (data only; needed by every commune select)
3. Phase B  architecture: panels, migrations, data backfill, directory move, authorization
4. Phase E  per-workspace subscriptions + Stripe Checkout/Portal/webhooks (test mode)
5. Phase C  registration + onboarding (email verification, phone, terms, set-up-business stepper)
6. Phase D  personal dashboard, personal profile, tax profile page, workspace dashboard
7. Phase F1–F2 address autocomplete + postal-code autofill + UID lookup
```

**Dependencies:** B before C/D/E. E before C (the plan step and checkout redirect use per-workspace
subscriptions) and before D (workspace cards show subscription status). A6's per-tab forms are kept by
B (the Tax-profile tab is then removed). A20's enum changes are used by D3. F1's `SwissAddressFields`
is referenced by C5/D2/D3 — until F1 lands, use plain `street`/`street_number`/`postal_code`/`city`/`CantonSelect`
fields in those forms and swap in the builder later. Ship each phase separately (tests green, Pint clean).

**Estimated size:** A ≈ 2–3 days, B ≈ 3 days, C ≈ 2 days, D ≈ 2–3 days, E ≈ 3 days, F ≈ 2 days.

---

## 4. Phase A — Cross-cutting form foundations & critical bug fixes

> Everything in Phase A works on today's structure (`app/Filament/App/...`, panel id `app`).
> Phase B later moves these files to `app/Filament/Workspace/...`; the changes carry over unchanged.
> **Important layout finding:** resource forms default to **2 columns** and `Section`s are **not**
> full width. `InvoiceForm`, `ExpenseForm`, `ClientForm` and `BankAccountForm` never call
> `$schema->columns(1)`, so their sections sit side-by-side at 50 % width; nested 12-column /
> 2-column grids then shrink fields to ~8–25 % of the screen. This is the real cause of BUG-59,
> BUG-16 (can't see what you type), BUG-22 and BUG-18-style cramping.

### A1 — Turn off native browser validation everywhere (BUG-03, BUG-15, BUG-38, part of BUG-21/35)

- **Root cause (✅):** Filament renders HTML validation attributes (`required`, `type="email"`, `min`, `max`)
  on inputs (`vendor/filament/forms/resources/views/components/text-input.blade.php:61`). Inside a
  `<form>` the browser blocks the submit, shows its own tooltip and focuses the field (the "green
  border" in BUG-38 is the primary-colour focus ring). No Livewire request is sent, so Filament's
  styled server-side error never appears. Action modals don't render a `<form>` tag, so only page
  forms are affected (`vendor/filament/schemas/resources/views/components/form.blade.php:1` is the only form tag).
- **Change:** `app/Providers/AppServiceProvider.php` → `boot()`:
  ```php
  use Filament\Schemas\Components\Form;

  // Server-side (Livewire) validation is the single source of truth; the browser's native
  // tooltips would otherwise block the request and hide Filament's styled messages.
  Form::configureUsing(function (Form $form): void {
      $form->extraAttributes(['novalidate' => true], merge: true);
  });
  ```
  (`configureUsing` is applied to subclasses too — `vendor/filament/support/src/Components/ComponentManager.php:90-110`;
  `extraAttributes(array, bool $merge)` — `vendor/filament/support/src/Concerns/HasExtraAttributes.php:18`.)
- **Tests** — new `tests/Feature/FormValidationUxTest.php`:
  - `get('/app/register')` → `assertSee('novalidate', false)`.
  - As an owner with tenant: `Livewire::test(CreateClient::class)` → `assertSeeHtml('novalidate')`.
  - `Livewire::test(CreateClient::class)->fillForm(['name' => null])->call('create')->assertHasFormErrors(['name' => 'required'])->assertSee('The name field is required.')`.

### A2 — Keep acronyms in validation messages (BUG-28 "uID", BUG-30 "iBAN")

- **Root cause (✅):** `Field::getValidationAttribute()` returns `Str::lcfirst($label)`
  (`vendor/filament/forms/src/Components/Concerns/CanBeValidated.php:771-783`) → "IBAN" → "iBAN".
- **Change:** `AppServiceProvider::boot()`:
  ```php
  use Filament\Forms\Components\Field;
  use Illuminate\Contracts\Support\Htmlable;
  use Illuminate\Support\Str;

  Field::configureUsing(function (Field $field): void {
      $field->validationAttribute(function (Field $component): ?string {
          $label = $component->getLabel();

          if (blank($label) || $label instanceof Htmlable) {
              return null; // fall back to Filament's default
          }

          $label = (string) $label;

          // "IBAN", "UID", "VAT number", "AHV" keep their capitals.
          return preg_match('/^\p{Lu}{2,}/u', $label) === 1 ? $label : Str::lcfirst($label);
      });
  });
  ```
  Fields that set their own `->validationAttribute()` later (e.g. Filament's password field) still win.
- **Tests** (in `FormValidationUxTest`): Business settings with empty IBAN → error text is exactly
  `The IBAN field is required.`; invalid UID → message contains `UID` (not `uID`).

### A3 — "Validate on blur" helper (BUG-04, BUG-10, BUG-31, BUG-58 part 1)

- **Root cause (✅):** errors are only recomputed on submit/Next, so a corrected value keeps its stale
  error. Verified during planning that `validateOnly('data.<field>')` on a Filament page uses the
  schema's rules (`vendor/filament/schemas/src/Concerns/InteractsWithSchemas.php:368-418`).
- **New class** `app/Filament/Support/ValidatesOnBlur.php` (`php artisan make:class Filament/Support/ValidatesOnBlur --no-interaction`):
  ```php
  namespace App\Filament\Support;

  use Filament\Forms\Components\Field;
  use Filament\Forms\Components\Select;
  use Livewire\Component as LivewireComponent;

  /**
   * Re-validates one field as soon as the user leaves it (or pauses typing), so an error
   * appears — and disappears once fixed — without pressing Save/Next.
   *
   * Usage (must be the LAST call in the chain so other afterStateUpdated hooks run first):
   *   TextInput::make('iban')->required()->rule(new ValidIban)->tap(new ValidatesOnBlur)
   */
  final readonly class ValidatesOnBlur
  {
      public function __construct(private ?int $debounceMs = null) {}

      public function __invoke(Field $field): void
      {
          match (true) {
              $field instanceof Select => $field->live(),
              $this->debounceMs !== null => $field->live(debounce: $this->debounceMs),
              default => $field->live(onBlur: true),
          };

          $field->afterStateUpdated(function (LivewireComponent $livewire, Field $component): void {
              $livewire->validateOnly($component->getStatePath());
          });
      }
  }
  ```
  `Field` is `Tappable` (`vendor/filament/support/src/Components/Component.php:17`), so `->tap(new ValidatesOnBlur)` works.
  `live(bool $onBlur, int|string|null $debounce)` — `vendor/filament/schemas/src/Concerns/HasStateBindingModifiers.php:21`.
- **Apply `->tap(new ValidatesOnBlur)` to** (text inputs; selects get `live()` automatically):
  - `app/Filament/App/Auth/Register.php`: first_name, last_name, email, phone, password, passwordConfirmation (see C2 for the password pair).
  - `app/Filament/App/Tenancy/RegisterBusinessEntity.php`: name, legal_name, uid (`new ValidatesOnBlur(debounceMs: 500)` — BUG-58 asks for as-you-type), street, street_number, postal_code, city, iban, default_payment_term_days, invoice_number_prefix, number_of_children, pillar3a_amount, estimated_annual_revenue, other_income.
  - `app/Filament/App/Pages/BusinessSettings.php`: every `TextInput` (see A6 for the new per-tab forms).
  - `ClientForm`, `ExpenseForm`, `BankAccountForm`, `InvoiceForm` (header fields; repeater item fields too — `validateOnly('data.lineItems.<uuid>.quantity')` works).
- **Do not** apply it to `FileUpload`, `Repeater`, `Toggle`, `Checkbox`, `Radio`.
- **Gotcha:** Filament's register password field carries `->same('passwordConfirmation')`
  (`vendor/filament/filament/src/Auth/Pages/Register.php:216-228`); on-blur validation would show
  "must match" before the user reaches the confirmation. C2 moves the `same` rule to the
  confirmation field. The probe during planning showed exactly this double message.
- **Tests** (`FormValidationUxTest`):
  - Register: `->set('data.password', 'short')` → `assertHasErrors(['data.password'])`; then
    `->set('data.password', 'long-enough-Passw0rd')` → `assertHasNoErrors(['data.password'])`.
  - Business settings invoicing form: set IBAN to invalid → error; set valid → error cleared (no save call).

### A4 — Fix "Verify with accountant" 500 (BUG-48)

- **Root cause (✅, reproduced on Postgres):** `app/Http/Controllers/AskSettlo/AskSettloController.php:312`
  `'accountantName' => $escalation->accountant()->value('name') ?? 'Maria Schneider'` runs
  `select "name" from "users" …`. `users` has no `name` column (`first_name`/`last_name`), so Postgres
  throws `SQLSTATE[42703] column "name" does not exist`. The escalation row and the credit spend are
  already committed (`EscalationService::escalate()` transaction), then the response 500s → the UI
  shows "Server Error", quota/history don't refresh; reloading the chat would 500 too because
  `bootstrap()` → `presentMessage()` → `presentEscalation()` runs the same query. Tests passed only because
  SQLite treats `"name"` as a string literal.
- **Changes:**
  1. `AskSettloController::presentEscalation()` (line 303-315): replace the query with
     `'accountantName' => $escalation->accountant?->getFilamentName() ?? 'Maria Schneider',`.
  2. Eager-load to respect `Model::preventLazyLoading()` (enabled locally, `AppServiceProvider.php:78`):
     - `conversationsFor()` (line 205-213): `->with(['messages.escalation.accountant', 'escalations'])`.
     - `presentConversation()` (line 255): `$conversation->loadMissing(['messages.escalation.accountant']);`
     - `escalate()` (line 172): `'escalation' => $this->presentEscalation($escalation->loadMissing('accountant')),`
     - `resolve()` (line 196): same `loadMissing('accountant')`.
  3. Same bug class, non-fatal (renders blank): `app/Filament/Firm/Resources/Escalations/Schemas/EscalationInfolist.php:35`
     `TextEntry::make('accountant.name')` → `TextEntry::make('accountant_display_name')->label('Accountant')->state(fn (AiEscalation $record): ?string => $record->accountant?->getFilamentName())->placeholder('—')`;
     `app/Filament/Admin/Resources/AccountingFirms/RelationManagers/MembersRelationManager.php:29`
     `TextColumn::make('user.name')` → `TextColumn::make('member_name')->label('Name')->state(fn ($record): ?string => $record->user?->getFilamentName())` (eager-load `user` in the relation manager's `modifyQueryUsing`).
  4. Friendlier client error — `resources/js/Pages/AskSettlo/Index.jsx:202-204`:
     ```js
     } catch (error) {
         setToast(error.status >= 500
             ? 'We could not send this to your accountant. Please try again in a moment.'
             : (error.message || 'Could not send to accountant.'));
     }
     ```
  5. `EscalationService::notifyOwner()` (line 183) and `FirmInvitationController.php:68` build `url('/app/'.$id)` by hand —
     leave for now; B7 replaces them with panel URLs.
- **A4.3 (recommended) — opt-in Postgres test run** so this bug class is caught:
  - `docker exec settlo-pgsql createdb -U settlo settlo_test`
  - Copy `phpunit.xml` → `phpunit.pgsql.xml`, change the DB envs to
    `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5433`, `DB_DATABASE=settlo_test`,
    `DB_USERNAME=settlo`, `DB_PASSWORD=secret`, and remove `DB_URL`.
  - Run: `vendor/bin/pest -c phpunit.pgsql.xml --compact`. Mention it in `PROGRESS.md`.
- **Tests** (`tests/Feature/AskSettloHttpTest.php`):
  - existing escalate tests keep passing; add `->assertJsonPath('escalation.accountantName', 'Maria Schneider')`.
  - new: escalation answered by a real accountant (`User::factory()->accountant()` if the state exists, else create with `forceFill(['role' => UserRole::Accountant])`) → bootstrap payload contains that accountant's full name.
  - new: `DB::listen` guard — escalate + bootstrap must not run any SQL matching `/select "name" from "users"/`.

### A5 — Stop truncated AI answers (BUG-47)

- **Root cause (✅, reproduced against the live API on 2026-09-17):** `app/Services/Ai/GeminiChatResponder.php:21`
  `MAX_OUTPUT_TOKENS = 1024`. Gemini 3.5 Flash counts *thinking* tokens against `maxOutputTokens`:
  the probe returned `finishReason: MAX_TOKENS`, `thoughtsTokenCount: 982`, `candidatesTokenCount: 38`
  (a 190-character answer cut mid-sentence). With `maxOutputTokens: 8192` (+ `thinkingLevel: "low"`)
  the same prompt returned `finishReason: STOP` and a complete ~2,000-character answer.
- **Changes:**
  1. `config/services.php` → `gemini`:
     ```php
     'chat_max_output_tokens' => (int) env('GEMINI_CHAT_MAX_OUTPUT_TOKENS', 8192),
     'chat_thinking_level' => env('GEMINI_CHAT_THINKING_LEVEL', 'low'), // minimal|low|medium|high, null = model default
     ```
     Add both to `.env.example` next to `GEMINI_CHAT_TIMEOUT`.
  2. `GeminiChatResponder`: delete the `MAX_OUTPUT_TOKENS` constant; build
     ```php
     'generationConfig' => array_filter([
         'maxOutputTokens' => (int) config('services.gemini.chat_max_output_tokens', 8192),
         'thinkingConfig' => filled($level = config('services.gemini.chat_thinking_level'))
             ? ['thinkingLevel' => $level]
             : null,
     ]),
     ```
  3. `parse()`: read `$finishReason = data_get($body, 'candidates.0.finishReason')`.
     - If `MAX_TOKENS`: `Log::warning('Ask Settlo reply hit the output token limit.', ['thoughts' => data_get($body, 'usageMetadata.thoughtsTokenCount'), 'visible' => data_get($body, 'usageMetadata.candidatesTokenCount')])`
       and append `"\n\n_(This answer was shortened. Ask me to continue for the rest.)_"` to the content (still return it).
     - If content is empty and `finishReason` is `SAFETY`, `RECITATION`, `PROHIBITED_CONTENT` or `BLOCKLIST` → throw `AiException('The assistant could not answer this question.')`.
     - Never log bodies (keep the existing security note).
  4. `app/Services/Ai/ChatContextAssembler.php:80-83` prompt: add
     `'Keep answers under about 350 words; prefer short paragraphs and bullet lists. '`.
  5. Vercel: `vercel.json` `maxDuration` is 60 s and `GEMINI_CHAT_TIMEOUT` is 60 — leave as is (the probe completed in a few seconds).
- **Tests** (`tests/Feature/AskSettloServiceTest.php`, existing Gemini tests at lines 84-140 use `Http::fake`):
  - request payload contains `generationConfig.maxOutputTokens = 8192` and `generationConfig.thinkingConfig.thinkingLevel = 'low'` (`Http::assertSent`).
  - `finishReason: MAX_TOKENS` fake → returned content ends with the "shortened" notice; `Log::shouldReceive('warning')` once.
  - `finishReason: SAFETY` with no text → `AiException`.
  - config `chat_thinking_level = null` → no `thinkingConfig` key.

### A6 — Business settings: IBAN reload + independent saving per tab (BUG-32, BUG-33, BUG-45, BUG-31)

- **Root causes (✅, reproduced with a Livewire probe):**
  1. `BusinessSettings::mount()` (`app/Filament/App/Pages/BusinessSettings.php:91-104`) fills the form from
     `ENTITY_FIELDS` (line 63-67), which does **not** contain `iban`. The IBAN is saved (line 279-282) but the
     field is empty after every reload → BUG-32.
  2. One `save()` (line 271-299) calls `$this->form->getState()`, validating all three tabs. Because the
     reloaded IBAN is empty and `required` (line 172-177), **every** save fails with
     "The iBAN field is required." — shown only on the Invoicing tab → Tax-profile edits are "silently" lost (BUG-33, BUG-45).
- **Change — split into one form per tab** (Filament discovers each `xxxForm(Schema $schema)` method as its own schema,
  `vendor/filament/schemas/src/Concerns/InteractsWithSchemas.php:195-300`):
  - Properties: replace `public ?array $data` with
    `public ?array $profileData = [];`, `public ?array $invoicingData = [];`, `public ?array $taxData = [];`
    (`$taxData` and `taxForm` are deleted in B3 when the tax profile moves to the personal area).
  - Constants: `PROFILE_FIELDS = ['name','legal_name','type','uid','street','street_number','city','postal_code','canton_id','logo_url']`,
    `INVOICING_FIELDS = ['default_payment_term_days','default_language','invoice_number_prefix','default_invoice_notes']`,
    `TAX_FIELDS` unchanged.
  - Methods:
    ```php
    public function profileForm(Schema $schema): Schema   { return $schema->statePath('profileData')->columns(2)->components($this->businessProfileFields()); }
    public function invoicingForm(Schema $schema): Schema { return $schema->statePath('invoicingData')->columns(2)->components($this->invoicingFields()); }
    public function taxForm(Schema $schema): Schema       { return $schema->statePath('taxData')->columns(2)->components($this->taxProfileFields()); }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Settings')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Business profile')->icon(Heroicon::OutlinedBuildingOffice)->schema([$this->formBlock('profileForm', 'saveProfile')]),
                    Tab::make('Invoicing')->icon(Heroicon::OutlinedDocumentText)->schema([$this->formBlock('invoicingForm', 'saveInvoicing')]),
                    Tab::make('Tax profile')->icon(Heroicon::OutlinedCalculator)->schema([$this->formBlock('taxForm', 'saveTax')]),
                ]),
        ]);
    }

    private function formBlock(string $schemaName, string $handler): Form
    {
        return Form::make([EmbeddedSchema::make($schemaName)])
            ->id($schemaName)
            ->livewireSubmitHandler($handler)
            ->footer([
                Actions::make([
                    Action::make($handler)->label('Save changes')->submit($handler),
                ])->alignment('end')->key("{$schemaName}-actions"),
            ]);
    }
    ```
    Move the existing field arrays out of `businessProfileTab()` / `invoicingTab()` / `taxProfileTab()` into
    `businessProfileFields()` / `invoicingFields()` / `taxProfileFields()` returning `array<Component>`.
    Delete `form()`, `save()`, `getFormContentComponent()`, `getFormActions()`.
  - `mount()`:
    ```php
    $this->profileForm->fill($entity->only(self::PROFILE_FIELDS));
    $this->invoicingForm->fill([
        ...$entity->only(self::INVOICING_FIELDS),
        'iban' => ValidIban::format((string) $entity->iban),
        'default_currency' => $entity->default_currency,
    ]);
    $this->taxForm->fill([...(existing tax fill), 'tax_canton_id' => $taxProfile?->canton_id ?? $entity->canton_id]);
    ```
  - Save handlers (each validates **only its own form**):
    - `saveProfile()`: `$data = $this->profileForm->getState();` → `fill(Arr::only($data, array_diff(PROFILE_FIELDS, ['canton_id'])))`, `forceFill(['canton_id' => $data['canton_id']])`, save,
      `RecalculateTaxEstimation::dispatch($entity->getKey())`, success notification **"Business profile saved"**.
    - `saveInvoicing()`: `getState()` → fill INVOICING_FIELDS, `forceFill(['iban' => ValidIban::normalize($data['iban'])])`, save; also update the default `BankAccount`
      of this entity if it still has the old IBAN (`$entity->bankAccounts()->where('is_default', true)->where('iban', $oldIban)->update(['iban' => $newIban])`);
      notification **"Invoicing settings saved"**.
    - `saveTax()`: the existing tax half of `save()`; notification **"Tax profile saved"**.
  - `ValidIban::format(string $iban): string` (new static, `app/Rules/ValidIban.php`): normalized IBAN grouped in blocks of 4 separated by spaces (`CH93 0076 2011 6238 5295 7`); empty string stays empty.
  - All text inputs get `->tap(new ValidatesOnBlur)` (A3); IBAN helper text becomes a hint tooltip (A21).
- **Tests** — rewrite `tests/Feature/BusinessSettingsTest.php`:
  - `mount` shows the stored IBAN: `->assertSchemaStateSet(['iban' => 'CH93 0076 2011 6238 5295 7'], 'invoicingForm')`.
  - saving the tax form without touching invoicing succeeds (the exact BUG-45 scenario): `->fillForm([...], 'taxForm')->call('saveTax')->assertHasNoFormErrors([], 'taxForm')`; DB updated; notification sent.
  - saving profile with empty name → errors only on `profileForm`; tax/invoicing data untouched.
  - saving invoicing with empty IBAN → `assertHasFormErrors(['iban' => 'required'], 'invoicingForm')` and message `The IBAN field is required.`.
  - saving invoicing updates the matching default bank account's IBAN.
  - owner-only access tests unchanged.

### A7 — Bank accounts: IBAN defence in depth (BUG-37, ❌ not reproduced)

- **Probe result:** two consecutive `call('create')` with `CH12 3456 7890 1234 5678 9` → both rejected, 0 rows. Ask Bogdan for exact steps (Q9).
- **Hardening:**
  1. `app/Models/BankAccount.php` → `booted()`:
     ```php
     static::saving(function (BankAccount $account): void {
         $account->iban = ValidIban::normalize((string) $account->iban);

         if (! ValidIban::isValid($account->iban)) {
             throw new InvalidArgumentException('A bank account needs a valid Swiss (CH) or Liechtenstein (LI) IBAN.');
         }
     });
     ```
  2. `CreateBankAccount::handleRecordCreation()` / `EditBankAccount::handleRecordUpdate()`: before saving, re-check
     `ValidIban::isValid($data['iban'] ?? '')` and otherwise `throw ValidationException::withMessages(['data.iban' => 'Enter a valid Swiss (CH) or Liechtenstein (LI) IBAN.'])`.
  3. `BankAccountForm`: `$schema->columns(1)`; section `->columns(['default' => 1, 'md' => 2])`; IBAN `->tap(new ValidatesOnBlur)`,
     `->mask('aa99 9999 9999 9999 9999 9')`, `->placeholder('CH93 0076 2011 6238 5295 7')`; helper texts → hint tooltips (A21).
- **Tests** (`tests/Feature/BankAccountsTest.php`): invalid IBAN via `create` twice and via `createAnother` → 0 rows;
  model guard throws for `forceFill(['iban' => 'CH00…'])->save()`; edit with invalid IBAN → error, DB unchanged.

### A8 — Invoice form & view layout, live totals, number inputs (BUG-16, BUG-17, BUG-18, BUG-59)

- **Causes:** (🔎/✅) 2-column form with half-width sections (see Phase A intro) + 12-column repeater →
  unit price ≈ 8 % of the screen; `unit_price` defaults to `0`, so typing after a failed
  triple-click produces `0200`; Summary is recomputed only after `live(onBlur: true)` round-trips
  (`InvoiceForm.php:89, 97`) using float math that can drift from the BCMath totals saved by
  `InvoiceService::recalculateTotals()`; the deprecated `Placeholder` is used for computed values.
- **Changes — `app/Filament/App/Resources/Invoices/Schemas/InvoiceForm.php`:**
  1. `return $schema->columns(1)->components([...])`. Every section is full width and stacked (Bogdan's proposal in BUG-59).
  2. "Invoice details": `->columns(['default' => 1, 'md' => 2, 'xl' => 4])` (client, language, issue date, due date on one row on wide screens; reference `->columnSpanFull()`).
  3. Line items: switch to a **table repeater** (`vendor/filament/forms/docs/12-repeater.md` "Table repeaters"):
     ```php
     use Filament\Forms\Components\Repeater\TableColumn;

     Repeater::make('lineItems')
         ->relationship()
         ->hiddenLabel()
         ->table([
             TableColumn::make('Description')->markAsRequired(),
             TableColumn::make('Qty')->width('7rem')->markAsRequired(),
             TableColumn::make('Unit price (CHF)')->width('10rem')->markAsRequired(),
             TableColumn::make('VAT')->width('10rem'),
             TableColumn::make('Total')->width('9rem')->alignment(Alignment::End),
         ])
         ->reorderable()->orderColumn('sort_order')->defaultItems(1)->addActionLabel('Add line')
         ->schema([
             TextInput::make('description')->required()->maxLength(255)->tap(new ValidatesOnBlur),
             TextInput::make('quantity')->required()->numeric()->rules(['gt:0'])->default(1)->step('any')
                 ->inputMode('decimal')
                 ->extraInputAttributes(['x-on:focus' => '$event.target.select()'])   // select-all on focus
                 ->live(debounce: 400),
             TextInput::make('unit_price')->required()->numeric()->minValue(0)->step('0.01')
                 ->placeholder('0.00')                                   // no default 0 → no "0200"
                 ->inputMode('decimal')
                 ->extraInputAttributes(['x-on:focus' => '$event.target.select()'])
                 ->live(debounce: 400),
             Select::make('vat_rate')->options(self::VAT_RATES)->default(fn (): string => self::defaultVatRate())
                 ->selectablePlaceholder(false)->live()
                 ->visible(fn (): bool => self::tenantIsVatRegistered()),    // A14
             TextEntry::make('line_total')->hiddenLabel()->alignEnd()
                 ->state(fn (Get $get): string => self::money(InvoiceTotals::lineNet($get('quantity'), $get('unit_price')))),
         ])
     ```
     (`Filament\Support\Enums\Alignment`; `TextEntry` = `Filament\Infolists\Components\TextEntry`.)
     If the table layout proves too narrow on < `md` screens, fall back to `->columns(['default' => 1, 'md' => 2, 'xl' => 12])` with
     description `xl:5`, qty `xl:2`, price `xl:2`, VAT `xl:2`, total `xl:1`.
  4. Summary: replace the 4 `Placeholder`s with `TextEntry::make('subtotal_display')->state(...)`, etc.; section `->columns(['default' => 1, 'md' => 3])`; the VAT breakdown `TextEntry` `->html()`.
  5. **Shared BCMath totals** — new `app/Services/Invoicing/InvoiceTotals.php` (`make:class`), final, static, pure:
     - `numeric(mixed $value): string` → `is_numeric($value) ? (string) $value : '0'`
     - `round(string $value, int $scale = 2): string` (move `InvoiceService::round()` here)
     - `lineNet(mixed $qty, mixed $price): string` = round(bcmul(qty, price, 6))
     - `lineVat(string $net, mixed $rate): string` = round(bcdiv(bcmul(net, rate, 6), '100', 6))
     - `normalizeRate(mixed $rate): string` (move from `InvoiceService`)
     - `forLines(iterable $lines): array{subtotal: string, vat: string, total: string, breakdown: array<string, array{rate: string, base: string, vat: string}>}`
     Use it in `InvoiceService::recalculateTotals()` / `vatBreakdown()` **and** in `InvoiceForm::totals()` / `vatRows()`
     (delete the float math at `InvoiceForm.php:166-212`). Preview and persisted totals are now identical by construction.
- **`CreateInvoice::getRedirectUrl()`** and **`EditInvoice`**: redirect to the `view` page of the record (so Send/PDF are one click away — see A9).
- **View page (BUG-18)** — `InvoiceInfolist.php`: `$schema->columns(1)`; line items:
  ```php
  use Filament\Infolists\Components\RepeatableEntry\TableColumn;

  RepeatableEntry::make('lineItems')->hiddenLabel()
      ->table([
          TableColumn::make('Description'),
          TableColumn::make('Qty')->width('6rem'),
          TableColumn::make('Unit price')->width('9rem'),
          TableColumn::make('VAT')->width('6rem'),
          TableColumn::make('Amount')->width('9rem'),
      ])
      ->schema([
          TextEntry::make('description')->wrap(),
          TextEntry::make('quantity')->numeric(decimalPlaces: 2),
          TextEntry::make('unit_price')->money('CHF'),
          TextEntry::make('vat_rate')->suffix('%'),
          TextEntry::make('line_total')->money('CHF')->alignEnd(),
      ])
  ```
  "Invoice" section `->columns(['default' => 1, 'sm' => 2, 'lg' => 3])`; "Parties" `['default' => 1, 'md' => 2]`.
- **Tests** (`tests/Feature/InvoiceResourceTest.php`, use `Repeater::fake()`):
  - two lines (2 × 100.00 @ 8.1 %, 1 × 800.45 @ 8.1 %) → rendered summary shows `CHF 1'081.49` (`assertSee("CHF 1'081.49")`) and after `create` the DB `total` is `1081.49`.
  - quantity `0` → error `gt`; unit price `-1` → error `min`; missing description → `required`.
  - `InvoiceTotals` unit tests (dataset): `lineNet('3', '19.99') === '59.97'`, `lineVat('59.97', '8.1') === '4.86'`, non-numeric input → `'0.00'`.
  - `create` redirects to the view page.

### A9 — Invoice actions on the view page; to-do links (BUG-19, BUG-27)

- **Cause (✅):** `ViewInvoice::getHeaderActions()` (`ViewInvoice.php:16-25`) only defines "Download PDF" and hides it for drafts;
  send / mark paid / cancel / edit live only in the list's `ActionGroup` (`InvoicesTable.php:59-68`).
  `ToDoWidget.php:83-90` links "Send invoice …" to the **edit** page.
- **Changes:**
  1. New `app/Filament/App/Resources/Invoices/Actions/InvoiceActions.php` (final class, static builders) — move
     `sendAction()`, `markPaidAction()`, `cancelAction()`, `downloadPdfAction()` from `InvoicesTable` here unchanged
     (rename to `send()`, `markPaid()`, `cancel()`, `downloadPdf()`), and add `->authorize('send'|'markPaid'|'cancel')`.
  2. `app/Policies/InvoicePolicy.php` new abilities:
     - `send(User $user, Invoice $invoice): bool` → `owns && canWrite && status === Draft`
     - `markPaid(...)` → `owns && canWrite && in_array(status, [Sent, Overdue])`
     - `cancel(...)` → `owns && canWrite && ! in_array(status, [Paid, Cancelled])`
  3. `InvoicesTable::configure()` uses `InvoiceActions::*`.
  4. `ViewInvoice::getHeaderActions()`:
     ```php
     return [
         InvoiceActions::send()->button(),              // primary CTA for drafts
         InvoiceActions::markPaid()->button(),
         EditAction::make()->visible(fn (Invoice $record): bool => $record->status->isEditable()),
         InvoiceActions::downloadPdf(),
         ActionGroup::make([InvoiceActions::cancel(), DeleteAction::make()]),
     ];
     ```
     After `send`/`markPaid`/`cancel` on the view page, refresh the record: `->after(fn (ViewInvoice $livewire) => $livewire->record->refresh())`
     (the actions are shared, so pass this `after()` only on the page).
     For drafts show a small info line in the infolist header: "Send the invoice to generate the Swiss QR-bill PDF."
  5. `ToDoWidget::buildItems()`: draft item label → `"Review & send invoice {$number}"`, URL →
     `InvoiceResource::getUrl('view', ['record' => $invoice], tenant: $entity)`; overdue item URL → `view` as well.
- **Tests:** `tests/Feature/InvoiceViewTest.php` — draft: `assertActionVisible('send')`, `assertActionVisible(EditAction::class)`,
  `callAction('send')` → status `sent`, notified; sent: `assertActionHidden('send')`, `assertActionVisible('markPaid')`,
  `callAction('markPaid', data: ['paid_at' => now()->toDateString(), 'method' => 'bank_transfer'])` → paid;
  another owner cannot call `send` (policy). `DashboardWidgetsTest`: draft to-do URL ends with `/invoices/{id}` (no `/edit`).

### A10 — Expense form validation, VAT auto-calculation, layout (BUG-20, BUG-21, BUG-22, BUG-24)

- **Causes (✅):** `amount` is `required` + `minValue(0)` + `default(0)` (`ExpenseForm.php:63-70`), so `0` passes;
  vendor/category optional; `vat_rate` has no max; no link between rate and VAT amount; 2-column form + 2-column section → 25 % wide category select.
- **Changes — `app/Filament/App/Resources/Expenses/Schemas/ExpenseForm.php`:**
  1. `$schema->columns(1)`; "Details" `->columns(['default' => 1, 'md' => 2])`; "Deductibility": `category_id` `->columnSpanFull()`,
     radio full width, `deductible_pct` + preview on one row.
  2. `receipt_path` FileUpload: add `->live()` (so required-ness below re-evaluates); helper text → hint tooltip.
  3. `vendor`: `->required(fn (Get $get): bool => blank($get('receipt_path')))->maxLength(255)->tap(new ValidatesOnBlur)`.
  4. `amount`: label **"Total amount (incl. VAT)"**; remove `->default(0)`;
     `->required(fn (Get $get): bool => blank($get('receipt_path')))`,
     `->rules(fn (Get $get): array => blank($get('receipt_path')) ? ['numeric', 'gt:0'] : ['nullable', 'numeric', 'min:0'])`,
     `->live(debounce: 500)->afterStateUpdated(fn (Get $get, Set $set) => self::fillVatAmount($get, $set))`,
     hint tooltip "Leave empty when you upload a receipt — Settlo reads it for you."
  5. `vat_rate`: label **"VAT rate (%)"**, `->numeric()->minValue(0)->maxValue(100)->datalist(['8.1', '2.6', '3.8', '0'])->default('8.1')`,
     `->live(debounce: 500)->afterStateUpdated(fn (Get $get, Set $set) => self::fillVatAmount($get, $set))`.
  6. `vat_amount`: `->numeric()->minValue(0)->lte('amount')`, hint tooltip "Calculated from the amount and rate — change it if your receipt shows a different VAT amount."
  7. `category_id`: `->required(fn (Get $get): bool => blank($get('receipt_path')))`; keep `afterStateUpdated`; `->tap(new ValidatesOnBlur)` (adds live()).
  8. `private static function fillVatAmount(Get $get, Set $set): void` — Swiss receipts show gross amounts, so
     `vat = amount × rate / (100 + rate)` with BCMath, rounded to 0.01 (use `InvoiceTotals::round()`); only when both are numeric and rate > 0; when rate is 0 set `0`.
  9. `CreateExpense::handleRecordCreation()`: `'amount' => $data['amount'] ?? 0` inside `forceFill` (the DB column is NOT NULL); if `vat_amount` is blank and rate > 0 compute it server-side with the same helper (move `fillVatAmount`'s math to `App\Services\Expenses\ExpenseService::vatFromGross(string $gross, string $rate): string`).
  10. `EditExpense::handleRecordUpdate()`: same server-side VAT fallback.
- **Tests** (`tests/Feature/ExpenseResourceTest.php`, dataset pattern):
  - manual entry: amount `0` → `gt`; amount `-50` → `gt`; missing vendor → `required`; missing category → `required`; vat_rate `150` → `max`; vat_amount > amount → `lte`.
  - with an uploaded fake receipt (`Storage::fake('receipts')`, `UploadedFile::fake()->image('r.jpg')`) amount/vendor/category may be empty → created with amount `0`, processing `pending`.
  - `->set('data.amount', '108.10')->set('data.vat_rate', '8.1')` → `assertSchemaStateSet(['vat_amount' => '8.10'])`.
  - edit with −50 / 150 % → errors, DB unchanged (BUG-21).

### A11 — Make "confirm expense" visible and explain what's excluded (BUG-23, BUG-44)

- **Causes (✅):** `DeductibilityStatus::Uncertain` and `ExpenseStatus::PendingReview` are both labelled
  **"Review needed"** (`DeductibilityStatus.php:21`, `ExpenseStatus.php:17`); "Confirm" is hidden inside the row's
  `ActionGroup` (`ExpensesTable.php:55-61`); `VatSummary::getVatRows()` (`VatSummary.php:58-62`) and the tax engine
  (`TaxEngine.php:191-194`) silently use only `reviewed` expenses.
- **Changes:**
  1. Labels: `ExpenseStatus::PendingReview` → **"Awaiting confirmation"** (warning, icon `Heroicon::OutlinedClock` — implement `HasIcon`);
     `Reviewed` → "Confirmed" (success, `Heroicon::OutlinedCheckCircle`); `DeductibilityStatus::Uncertain` → **"Deductibility unclear"** (color `gray`).
  2. `ExpensesTable`:
     - columns order: date, vendor, category, amount, **status** (badge + icon), deductibility, processing (toggleable, hidden by default when `manual`).
     - `->recordActions([self::confirmAction()->button()->size(Size::Small), ActionGroup::make([EditAction::make()->label('Review'), self::viewReceiptAction(), DeleteAction::make()])])`
       (`Filament\Support\Enums\Size`).
     - confirm action: before confirming, if `blank($record->category_id) || bccomp((string) $record->amount, '0', 2) <= 0` → danger notification
       "Add an amount and a category before confirming." with an action button linking to the edit page, then `$action->cancel()`.
     - new bulk action `BulkAction::make('confirmSelected')->label('Confirm selected')->icon(Heroicon::OutlinedCheckCircle)->requiresConfirmation()`
       → confirms only `PendingReview` records that pass the same check; notification "N expenses confirmed, M skipped (missing amount or category)".
       `->deselectRecordsAfterCompletion()`.
     - `->description('Only confirmed expenses count towards your tax estimate and VAT summary.')` on the table.
     - `SelectFilter::make('status')` default: none (keep), but add a `->persistFiltersInSession()`.
  3. `ExpenseResource::getNavigationBadge()` → count of `pending_review` expenses for the tenant (null when 0),
     `getNavigationBadgeColor()` → `'warning'`, `getNavigationBadgeTooltip()` → "Awaiting confirmation".
  4. `EditExpense::getFormActions()`: add `Action::make('saveAndConfirm')->label('Save & confirm')->color('success')`
     visible when status is `PendingReview`; it calls `$this->save(shouldRedirect: false)` then `ExpenseService::confirm()` and redirects to the index.
     `CreateExpense::getFormActions()`: add "Create & confirm" (only for manual entries — hidden when a receipt is uploaded).
  5. Notices: new `ExpenseService::pendingSummary(BusinessEntity $entity, int $fiscalYear): array{count: int, gross: string}`.
     - `resources/views/filament/app/pages/vat-summary.blade.php` and `tax-overview.blade.php`: when `count > 0`, render at the top
       ```blade
       <div class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-300">
           {{ trans_choice(':count expense totalling CHF :gross is|:count expenses totalling CHF :gross are', $pending['count'], [...]) }}
           awaiting confirmation and not included below.
           <a href="{{ $pendingUrl }}" class="font-semibold underline">Review expenses</a>
       </div>
       ```
       `$pendingUrl = ExpenseResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'pending_review']]])`.
     - `TaxBreakdownWidget` view: same one-line notice.
- **Tests:** `ExpensesTable` inline confirm visible for pending (`assertActionVisible(TestAction::make('confirm')->table($expense))`);
  confirm blocked without category; bulk confirm skips invalid ones; `VatSummaryTest` shows the notice with count and link;
  `TaxOverviewTest` shows the notice; navigation badge returns the count.

### A12 — Ask Settlo UI: layout overlap, `?q=` deep links (BUG-25, BUG-49, BUG-26)

- **Causes:**
  - (✅ by arithmetic, 🔎 visually) the island has three panes: `ConversationList` `w-72 shrink-0` (`ConversationList.jsx:51`) +
    `AccountantPanel` `w-80 shrink-0` (`AccountantPanel.jsx:11`) = **608 px fixed**, inside a Filament page that already
    has a 20 rem sidebar. At a 1114 px window the chat pane gets ≈ 120 px, at 1280 px ≈ 290 px; its header
    (`ChatPanel.jsx:153-159`: title + three pills, the VAT pill reads "VAT: Not registered (under CHF 100k)")
    and bubbles overflow under the neighbouring panes → "badges overlap the header and the AI bubble".
  - (✅) `Index.jsx` never reads `?q=`; additionally `AskSettloController::index()` (line 61-66) redirects the legacy
    `/ask-settlo/{entity}?q=…` URL (used by `ToDoWidget.php:70`) **without** the query string.
- **Changes:**
  1. `resources/js/Pages/AskSettlo/Index.jsx` root container: add `@container` (Tailwind v4 container queries) and `relative isolate`.
     - Conversation list: `hidden @3xl:flex` (≥ 48 rem container); Accountant panel: `hidden @6xl:flex` (≥ 72 rem).
     - Add a header toolbar in `ChatPanel` with two icon buttons (visible only when the pane is hidden: `@3xl:hidden` / `@6xl:hidden`)
       that open the list / accountant panel as an absolutely positioned drawer (`absolute inset-y-0 left-0 z-20 w-72 shadow-xl` /
       `right-0 w-80`) with a backdrop button to close. State: `const [drawer, setDrawer] = useState(null) // 'list' | 'accountant' | null`.
     - `ChatPanel` section: `min-w-[20rem] min-h-0`; message list div: add `min-h-0`.
  2. `ChatPanel.jsx` header → two rows:
     ```jsx
     <header className="flex shrink-0 flex-col gap-2 border-b … px-5 py-3">
         <div className="flex min-w-0 items-center justify-between gap-3">
             <div className="min-w-0"> …title / subtitle… </div>
             {/* drawer toggle buttons */}
         </div>
         <ContextPills context={context} />
     </header>
     ```
     `ContextPills` wrapper: `flex flex-wrap gap-1.5` with every pill `whitespace-nowrap`.
  3. Shorter VAT pill: new `App\Enums\VatStatus::getShortLabel()` ("Not registered", "Registered", "Registered", "Exempt");
     `AskSettloController::presentContext()` → `'vatStatus' => $snapshot['vat_status_short'] ?? 'Not registered'`;
     `ChatContextAssembler::assemble()` adds `vat_status_short` to the snapshot.
  4. `?q=` auto-ask — `Index.jsx`:
     - `sendMessage(content, { forceNew = false } = {})`: `let conversationId = forceNew ? null : activeId;` and when `forceNew`
       replace messages instead of appending (`setMessages([...])`).
     - on mount:
       ```js
       const autoAsked = useRef(false);
       useEffect(() => {
           if (autoAsked.current) return;
           const url = new URL(window.location.href);
           const q = (url.searchParams.get('q') ?? '').trim();
           if (q === '') return;
           autoAsked.current = true;
           url.searchParams.delete('q');
           window.history.replaceState(window.history.state, '', url);
           setConversationTitle(q.slice(0, 50));
           sendMessage(q.slice(0, 4000), { forceNew: true });
       }, []); // eslint-disable-line react-hooks/exhaustive-deps
       ```
  5. `AskSettloController::index()`: `AskSettloPage::getUrl(['tenant' => $businessEntity, ...$request->only('q')], panel: 'app')`
     (extra parameters become the query string).
  6. `npm run build`; take screenshots at 1114, 1280, 1600 px with an active conversation and an escalation
     (log in manually — automated agents must not type passwords).
- **Tests:** `AskSettloHttpTest`: legacy URL with `?q=Hello` redirects to the panel URL **containing** `q=Hello`;
  bootstrap payload `context.vatStatus === 'Not registered'`. (No JS test runner exists; verify the island manually.)

### A13 — AHV shown vs AHV deducted (BUG-43)

- **Root cause (✅):** `TaxCalculator::computeCore()` (`app/Services/Tax/TaxCalculator.php:119-132`) replaces `$totalSI`
  with the CHF 514 minimum but keeps `$ahv` at the percentage value and deducts `$ahv × 0.5`.
  For net income 966.41: `966.41 × 10.6 % × 50 % = 51.22` → taxable 915.19 (exactly the tester's numbers), while the page
  shows AHV/IV/EO = 514.00. For 750.21 the deduction is 39.76 → 710.45 (second report). The page also never shows the
  deduction line, so the maths is not traceable.
- **Change (spec-conformant, Q1):**
  1. When the minimum applies, apportion it across AHV/IV/EO by their rate weights so the parts sum to the minimum:
     ```php
     if (bccomp($netForAhv, '0', self::SCALE) > 0 && bccomp($ahvBase, '0', self::SCALE) > 0
         && bccomp($totalSI, (string) $si->ahv_minimum, self::SCALE) < 0) {
         $rateSum = bcadd(bcadd((string) $si->ahv_rate, (string) $si->iv_rate, self::SCALE), (string) $si->eo_rate, self::SCALE);
         $ahv = bcdiv(bcmul((string) $si->ahv_minimum, (string) $si->ahv_rate, self::SCALE), $rateSum, self::SCALE);
         $iv  = bcdiv(bcmul((string) $si->ahv_minimum, (string) $si->iv_rate, self::SCALE), $rateSum, self::SCALE);
         $eo  = bcsub(bcsub((string) $si->ahv_minimum, $ahv, self::SCALE), $iv, self::SCALE);
         $totalSI = (string) $si->ahv_minimum;
         $minimumApplied = true;
     }
     $ahvDeduction = bcmul($ahv, '0.5', self::SCALE); // now based on the AHV actually charged
     ```
     Note the added `ahvBase > 0` condition: a 65+ person whose income is fully below the CHF 16,800 exemption owes no
     minimum contribution (today they are charged 514). Flag this behaviour change in the PR description.
     Expected for 966.41 (ZH, single): AHV share = 514 × 10.6 / 12.5 = 435.872 → deduction 217.94 → taxable 748.47.
  2. Also return the other deductions so the UI can show them: add to `computeCore()`'s result `pillar3a` and `childDeduction`;
     add `TaxResult` properties `pillar3aDeduction`, `childDeduction`, `minimumContributionApplied` (not persisted as columns —
     put them in `ratesSnapshot` under a `deductions` key: `['ahv' => …, 'pillar3a' => …, 'children' => …, 'minimum_contribution_applied' => bool]`).
  3. `resources/views/filament/app/pages/tax-overview.blade.php` breakdown groups:
     - Social insurance: `AHV`, `IV`, `EO`, **Total AHV / IV / EO** (+ badge "Minimum contribution" when applied, with a hint:
       "Self-employed people pay at least CHF 514 per year").
     - Income tax: `Net income`, `− AHV deduction (50 % of AHV)`, `− Pillar 3a`, `− Child deductions`, `+ Other income`,
       **`= Taxable income`**, then federal/cantonal/communal/church, total.
     Read the deduction figures from `$estimation->rates_snapshot['deductions']` (fallback to `ahv_deduction` column for old rows).
- **Tests** (`tests/Unit/TaxCalculatorTest.php`):
  - net 966.41, ZH, single, no 3a/children: `totalSocialInsurance = 514.00`; `ahv + iv + eo = 514.00`;
    `ahvDeduction = round(ahv × 0.5, 2) = 217.94`; `taxableIncome = 748.47`; `minimumContributionApplied = true`.
  - net 100,000: unchanged vs today's expectations (no minimum).
  - 65+ with net 10,000: `totalSocialInsurance = 0`.
  - Update existing expectations only where they encoded the old (inconsistent) values — list them in the PR.

### A14 — No VAT on invoices of non-registered businesses; tax on net revenue (BUG-46)

- **Cause (✅):** `InvoiceForm` always offers 8.1 % (`InvoiceForm.php:99-105`) regardless of VAT status;
  `TaxEngine::gather()` (line 186-189), `BusinessOverview` (line 19-22), `ChatContextAssembler::revenueYtd()` (line 86-92)
  and the VAT threshold use the **gross** `total`, so collected VAT inflates revenue.
- **Changes:**
  1. `BusinessEntity::isVatRegistered(): bool` — Phase A: `$this->taxProfile?->vat_status?->isRegistered() || filled($this->mwst_number)`;
     Phase B (B2) switches it to the new `business_entities.vat_status` column.
  2. `InvoiceForm`: `self::tenantIsVatRegistered()` helper; when **not** registered hide `vat_rate` (A8) and show a `TextEntry`
     note above the repeater: "Your business is not VAT-registered, so this invoice is issued without VAT." Default rate `'0'`.
     Enforce server-side on the repeater:
     `->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::tenantIsVatRegistered() ? $data : [...$data, 'vat_rate' => 0])`
     and the same with `->mutateRelationshipDataBeforeSaveUsing(...)`.
  3. `InvoiceService::send()`: after `recalculateTotals()`, if `! $entity->isVatRegistered() && bccomp((string) $invoice->vat_amount, '0', 2) > 0`
     → `throw new RuntimeException('Your business is not VAT-registered — remove VAT from the line items before sending.')`.
  4. PDF (`resources/views/invoices/pdf.blade.php`): when `! $invoice->businessEntity->isVatRegistered()` hide the "VAT %" column
     (line 84/94), the VAT breakdown table (line 101-120) and the VAT total row (line 124); show `{{ __('invoice.not_vat_registered') }}` under the totals.
     Add `'not_vat_registered'` to `lang/{en,de,fr,it}/invoice.php`: EN "Not subject to VAT", DE "Nicht mehrwertsteuerpflichtig",
     FR "Non assujetti à la TVA", IT "Non assoggettato all'IVA". Eager-load `businessEntity.taxProfile` in `InvoicePdfService`.
  5. Revenue = **net** (`subtotal`) everywhere: `TaxEngine::gather()` and the largest-invoice lookup (`max('subtotal')`),
     `BusinessOverview` ("Revenue YTD" description "… invoiced, excl. VAT"), `ChatContextAssembler::revenueYtd()`, and D-phase widgets.
  6. `ViewInvoice`: if the invoice has VAT > 0 but the business is not registered, show a warning `TextEntry` at the top of the infolist.
- **Tests:** not-registered tenant → `CreateInvoice` has no `vat_rate` field (`assertSchemaComponentDoesNotExist` or hidden) and saved lines have rate 0;
  registered tenant → field visible, 8.1 default; `send()` throws for a legacy draft with VAT while not registered;
  `TaxEngineTest` revenue uses `subtotal` (invoice 1000 + 81 VAT → gross revenue 1000); PDF renders "Not subject to VAT".

### A15 — Registration throttling only counts real attempts (BUG-05)

- **Cause (✅):** `Filament\Auth\Pages\Register::register()` calls `$this->rateLimit(2)` **before** validation
  (`vendor/filament/filament/src/Auth/Pages/Register.php:67-79`), so two submits with typos lock the form for a minute.
- **Change** — `app/Filament/App/Auth/Register.php`:
  ```php
  public function register(): ?RegistrationResponse
  {
      // Validation errors never consume a throttle attempt.
      $this->form->validate();

      return parent::register();
  }

  /**
   * Allow a few genuine attempts per minute per IP (Filament's default is 2).
   */
  protected function rateLimit($maxAttempts, $decaySeconds = 60, $method = null, $component = null): void
  {
      // The parent derives the throttle key from the *calling* method via debug_backtrace(),
      // which would now be this override — pass the real caller ("register") explicitly.
      $method ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, limit: 2)[1]['function'];

      parent::rateLimit(max($maxAttempts, 5), $decaySeconds, $method, $component);
  }
  ```
  (`Filament\Auth\Http\Responses\Contracts\RegistrationResponse`; signature from `vendor/danharrin/livewire-rate-limiting/src/WithRateLimiting.php:41`.)
- **Tests:** 6 invalid submits → 6× validation errors and `assertNotNotified()`; then a valid submit succeeds.

### A16 — Swiss format rules: postal code, UID, VAT number (BUG-29, BUG-39, BUG-40, BUG-58 part 1)

- New validation rules (`php artisan make:rule SwissPostalCode --no-interaction`, same for `SwissUid`, `SwissVatNumber`):
  - `app/Rules/SwissPostalCode.php`: `/^[1-9]\d{3}$/` → "Enter a 4-digit Swiss postal code." (covers Liechtenstein 9485–9498).
  - `app/Rules/SwissUid.php`: accepts `CHE-123.456.789`, `CHE123456789`, `CHE 123 456 789`; normalises to digits and checks the
    **eCH-0097 check digit**: weights `5,4,3,2,7,6,5,4` on digits 1–8; `check = 11 − (sum mod 11)`; `11 → 0`; `10 → invalid`.
    Static helpers `normalize(string): ?string` (returns `CHE-123.456.789`) and `isValid(string): bool`.
    Verified examples: `CHE-105.829.940` (Migros) ✔, `CHE-148.830.302` (demo seed) ✔, `CHE-123.456.789` ✘ (check digit should be 8).
  - `app/Rules/SwissVatNumber.php`: `SwissUid` + optional suffix `MWST|TVA|IVA` (case-insensitive); normalises to `CHE-123.456.789 MWST`.
- Apply:
  - `ClientForm`: `postal_code` → `->rule(new SwissPostalCode, fn (Get $get): bool => in_array(strtoupper((string) $get('country_code')), ['CH', 'LI'], true))->maxLength(20)`;
    `vat_number` → `->rule(new SwissVatNumber, fn (Get $get): bool => in_array(…CH/LI…))`, otherwise `->regex('/^[A-Z]{2}[0-9A-Z]{2,13}$/')` for foreign VAT IDs;
    normalise with `->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : (SwissVatNumber::normalize($state) ?? strtoupper(str_replace(' ', '', $state))))`;
    `country_code` → `->required()->length(2)->alpha()->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state))` + `->live()`;
    `default_payment_term_days` → `->integer()->minValue(0)->maxValue(365)->suffix('days')`.
    Layout: `$schema->columns(1)`; sections full width; "Client" `['default' => 1, 'md' => 2]`, "Address" `['default' => 1, 'md' => 4]`.
  - Business profile (wizard + settings): `postal_code` → `->rule(new SwissPostalCode)`; `uid` → `->rule(new SwissUid)->mask('CHE-999.999.999')->dehydrateStateUsing(fn (?string $s) => $s ? SwissUid::normalize($s) : null)` (replaces the regex at `RegisterBusinessEntity.php:110` and `BusinessSettings.php:144`).
  - `tests/Feature/OnboardingTest.php:36` uses `CHE-123.456.789` (invalid check digit) → change to `CHE-148.830.302`.
- **Tests:** `tests/Unit/SwissRulesTest.php` datasets for the three rules; `ClientResourceTest` validation dataset:
  `ZZ99`/`80001` invalid for CH, `8001` valid, `10115` valid for DE, `INVALIDVAT` invalid for CH, `CHE-105.829.940 MWST` valid, payment term `-1` invalid.

### A17 — Client deletion warning; search reset (BUG-41, BUG-42)

- **BUG-41 (✅):** `ClientsTable` only has `DeleteBulkAction`/`ForceDeleteBulkAction` (line 46-52) and `EditClient` has
  `DeleteAction`/`ForceDeleteAction` (line 17-21) — no mention of invoices. `invoices.client_id` is `restrictOnDelete`, so a
  force-delete of a client with invoices would throw a DB error (500).
  - `ClientsTable::recordActions()` → `[EditAction::make(), DeleteAction::make()->modalDescription(fn (Client $record): string => self::deleteWarning($record->invoices_count))]`
    (`invoices_count` is already loaded by the `counts('invoices')` column).
    `deleteWarning(int $count)`: `0` → "This client will be moved to the trash. You can restore it later.";
    `>0` → "This client has {n} invoice(s). The invoices are kept and will still show this client. The client is moved to the trash and can be restored."
  - `DeleteBulkAction::make()->modalDescription(fn (Collection $records): string => …sum of invoices_count…)`.
  - `ForceDeleteAction` / `ForceDeleteBulkAction`: hide for clients with invoices — `ClientPolicy::forceDelete()` returns false
    when `$client->invoices()->withTrashed()->exists()`; bulk: `->authorizeIndividualRecords('forceDelete')`; plus a modal description
    "Permanently deletes the client. Clients with invoices can't be deleted permanently."
  - `Invoice::client()` → `->withTrashed()` so invoices of a trashed client still show the name (`app/Models/Invoice.php:53-57`).
- **BUG-42 (🔎):** server-side search reset works (Livewire probe: `searchTable('zzz')` → 0 rows, `searchTable('')` → rows back).
  The table search input is `type="search"` with `wire:model.live.debounce` (`vendor/filament/tables/resources/views/components/search-field.blade.php:13-39`);
  the browser's native "×" clear button may not emit an `input` event, so Livewire never receives the empty value.
  - First, check `composer outdated filament/filament` — if a newer 5.x patch exists, ask before updating (dependency change).
  - Otherwise register a tiny script in `AppServiceProvider::boot()`:
    ```php
    FilamentView::registerRenderHook(PanelsRenderHook::SCRIPTS_AFTER, fn (): string => <<<'HTML'
        <script>
            document.addEventListener('search', (event) => {
                if (event.target.matches('.fi-ta-search-field input[type=search]')) {
                    event.target.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }, true);
        </script>
    HTML);
    ```
    (verify the wrapper class name in the rendered HTML first and adjust the selector).
- **Tests:** `ClientResourceTest`: delete modal description mentions the invoice count (`assertActionExists(TestAction::make(DeleteAction::class)->table($client), fn (DeleteAction $a) => str_contains($a->getModalDescription(), '2 invoice'))`);
  force delete hidden when invoices exist; invoice list shows a trashed client's name; `searchTable('')` restores rows (regression).

### A18 — Children & Pillar 3a inputs (BUG-35, BUG-36)

- `number_of_children`: `->integer()->minValue(0)->maxValue(10)->tap(new ValidatesOnBlur)`; hint tooltip
  "Each child reduces your taxable income by the cantonal child deduction (CHF 6,500–9,000)."
- Pillar 3a:
  - new `RateRepository::pillar3aCap(bool $hasPillar2, int $year): int` → `socialInsuranceRate($year)->pillar3a_max_with_p2` or `->pillar3a_max_se`
    (replaces the hard-coded `PILLAR_3A_CAP = 35280` in `RegisterBusinessEntity.php:51` and `BusinessSettings.php:75`, which ignores Pillar 2).
  - add `Toggle::make('has_pillar2')->label('I pay into a pension fund (Pillar 2)')->live()` next to the 3a input (column exists on `tax_profiles`).
  - `pillar3a_amount`: `->numeric()->minValue(0)->prefix('CHF')->live(onBlur: true)->afterStateUpdated(...)`:
    if the value exceeds the cap → `$set('pillar3a_amount', $cap)` and send `Notification::make()->title("Pillar 3a is capped at CHF {cap}")->body('We reduced the amount to the legal maximum.')->info()`;
    also re-clamp when `has_pillar2` changes. Keep the server-side clamp on save (using the new cap).
  - hint tooltip: "Up to CHF 35,280 per year without a pension fund, CHF 7,056 with one (2026). Higher amounts are reduced automatically."
- **Tests:** children `-5` → `min`; `2.5` → `integer`; pillar 999999 → state becomes `35280` after blur and saved as 35280;
  with `has_pillar2 = true` → 7056.

### A19 — Communes for every canton (BUG-12, BUG-34, BUG-58 part 3)

Implemented in **F3** — do it together with Phase A (it is data only).

### A20 — Tariff C and the new "Residence status" list (BUG-13, BUG-14)

- `app/Enums/MaritalStatus.php`: cases in this order —
  `Single = 'single'` "Single (Tariff A)", `Married = 'married'` **"Married, single income (Tariff B)"**,
  `MarriedDualIncome = 'married_dual_income'` **"Married, dual income (Tariff C)"**, `SingleParent = 'single_parent'` "Single parent (Tariff H)".
  `tariff()`: `Single → 'A'`, all others → `'B'` (Q3). No migration (string column).
- `app/Enums/ResidencePermit.php` (keep class and column names; the UI label becomes **"Residence status"**):
  | case | value | label |
  |---|---|---|
  | `SwissCitizen` | `swiss` | 🇨🇭 Swiss citizen |
  | `EuEftaPermitB` | `eu_efta_b` | 🇪🇺 EU/EFTA citizen – Permit B |
  | `EuEftaPermitC` | `eu_efta_c` | 🇪🇺 EU/EFTA citizen – Permit C |
  | `EuEftaPermitL` | `eu_efta_l` | 🇪🇺 EU/EFTA citizen – Permit L |
  | `NonEuPermitB` | `non_eu_b` | 🌍 Non-EU/EFTA – Permit B |
  | `NonEuPermitC` | `non_eu_c` | 🌍 Non-EU/EFTA – Permit C |
  | `NonEuPermitL` | `non_eu_l` | 🌍 Non-EU/EFTA – Permit L |
  | `CrossBorderPermitG` | `cross_border_g` | Cross-border commuter (Permit G) |

  `triggersQuellensteuer()`: true for all B, L and G cases (Q2). Warning text in forms:
  "With this residence status your income tax is usually withheld at source (Quellensteuer). Settlo doesn't estimate income tax for it — your accountant will."
- Migration `php artisan make:migration migrate_residence_permit_values_on_tax_profiles_table --no-interaction`:
  `swiss_or_c → swiss`, `b_permit → eu_efta_b` (best guess; both are handled identically by the engine), and
  `$table->string('residence_permit')->default('swiss')->change();` (down: reverse mapping + old default).
- Replace every `ResidencePermit::SwissOrCPermit` / `::BPermit` reference (grep `app`, `database`, `tests`): factories, `DemoSeeder`, `TaxEngine.php:219`, `TaxInput.php:24`, forms, tests.
- Forms: label "Residence status"; options `ResidencePermit::class`; `->searchable()` off (8 options); visible warning on `triggersQuellensteuer()`.
- **Tests:** `TaxCalculatorTest` dataset: every B/L/G case returns `quellensteuerRegime = true`; Swiss/C cases calculate;
  `MarriedDualIncome` uses tariff B brackets; migration maps legacy values (`DataLayerSeedTest` or a new migration test using `DB::table` inserts before running the migration class' `up()`).

### A21 — Onboarding & settings copy/fields (BUG-50, BUG-51, BUG-54, BUG-55, BUG-56, BUG-58 part 5)

Apply to `RegisterBusinessEntity` and `BusinessSettings` now; C5 reuses the same field builders.
- **Business type (BUG-50):** remove the helper text (`RegisterBusinessEntity.php:106`). Options = Sole proprietorship, GmbH, AG (drop Association from the picker);
  unsupported options disabled and rendered with a badge via `Select::allowHtml()`:
  ```php
  ->allowHtml()
  ->options(fn (): array => collect([BusinessEntityType::SoleProprietorship, BusinessEntityType::GmbH, BusinessEntityType::AG])
      ->mapWithKeys(fn (BusinessEntityType $type): array => [$type->value => $type->isSupported()
          ? e($type->getLabel())
          : e($type->getLabel()).' <span class="ms-2 rounded-full border border-danger-400 px-2 py-0.5 text-xs font-medium text-danger-600 dark:text-danger-400">Coming soon</span>'])
      ->all())
  ->disableOptionWhen(fn (string $value): bool => ! BusinessEntityType::from($value)->isSupported())
  ```
  (If `Select::allowHtml()` renders poorly, use `Radio::make('type')->options(...)->descriptions([...'Coming soon'])->disableOptionWhen(...)->inline()`.)
- **"Legal name" → "Trading name" (BUG-54):** label only (column stays `legal_name`), hint tooltip
  "The name you use with clients, if different from your registered business name. Optional."
  `InvoiceService::send()` (line 140) sets `creditor_name` to `legal_name ?: name` — change to **`$entity->name`** (registered name
  is the legal creditor on the QR-bill); the PDF header shows "{name}" and, when filled, "Trading as {legal_name}" (grep `legal_name` in `resources/views/invoices/pdf.blade.php`).
- **Payment terms (BUG-55):** `TextInput::make('default_payment_term_days')->label('Payment terms')->required()->integer()->minValue(1)->maxValue(365)->suffix('days')->default(30)->datalist(['10', '15', '30', '45', '60', '90'])->tap(new ValidatesOnBlur)`
  (replaces the `Select` in `RegisterBusinessEntity.php:147-152` and `BusinessSettings.php:178-182`).
- **UID (BUG-56, BUG-58):** label **"Swiss business registration number (UID)"**, `->helperText('Optional if your business is not registered in the Swiss Commercial Register yet.')`
  (explicitly requested as small text), `->placeholder('CHE-123.456.789')`, rules from A16, `->tap(new ValidatesOnBlur(debounceMs: 500))`.
- **Subtitle (BUG-51):** `RegisterBusinessEntity::getSubheading(): ?string` → "We'll use this information to configure invoicing, accounting and tax settings for your business. It only takes about a minute."
- **Tooltips instead of long helper texts (BUG-58 part 5):** replace `->helperText($long)` with
  `->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: $long)` (`vendor/filament/forms/src/Components/Concerns/HasHint.php:109`) on:
  Register phone; IBAN (wizard, settings, bank account); children; pillar 3a; church tax; expense receipt; expense amount; bank account label;
  bank "default account" toggle; logo upload. Keep UID's short helper text.
- **Tests:** `OnboardingTest` — type field disables `gmbh`/`ag` (`assertSchemaComponentExists('type', checkComponentUsing: fn (Select $f): bool => $f->isOptionDisabled('gmbh', 'GmbH'))`);
  custom payment term `21` is saved; `0` → `min`; UID with a bad check digit → error visible while typing (`set('data.uid', …)` → `assertHasErrors`).

### A22 — Autofill hints on auth forms (BUG-01, BUG-02 — ❌ not reproducible)

- No default values exist in code (`Register.php:47`, Filament `Login::mount()` → `$this->form->fill()`), so the tester's
  browser autofilled saved credentials. Add hints only:
  `Register`: email `->autocomplete('email')`, password & confirmation `->autocomplete('new-password')`,
  first/last name `->autocomplete('given-name')` / `('family-name')`, phone `->autocomplete('tel-national')`.
- **Test:** `get('/app/register')->assertSee('autocomplete="new-password"', false)`.

### A23 — Fresh-setup test failure (found while setting up this machine)

- **Cause (✅):** `.env.example` ships `LIVEWIRE_TMP_DISK=` (empty). `config/livewire.php:132` reads `env('LIVEWIRE_TMP_DISK')` → `''`
  instead of `null`, so `tests/Feature/CloudStorageConfigTest.php:91` fails on every fresh clone (the local `.env` was patched by commenting the line).
- **Change:** `config/livewire.php:132` → `'disk' => env('LIVEWIRE_TMP_DISK') ?: null,`; in `.env.example` comment the line out (`# LIVEWIRE_TMP_DISK=`).
- **Test:** existing `CloudStorageConfigTest` passes with the variable set to an empty string (add a dataset case that sets `putenv('LIVEWIRE_TMP_DISK=')` and reloads the config file, mirroring the file's existing `loadFilesystemsConfigWithEnv` helper).

---

## 5. Phase B — User-centric architecture

> Email §1/§4 and BUG-50.5: the **person** is the root; each business is a workspace under it.
> Execution order for the remaining phases is **B → E → C → D → F1/F2** (C's plan step and D's
> workspace cards need E's per-workspace subscriptions). F3 (communes) should already be done.

### B0 — Safety first

- Production (Vercel) has tester data. Before deploying B/E migrations: take a DB backup (managed Postgres snapshot or `pg_dump`).
- All data migrations below are written to be **idempotent and reversible** and must be run once against a copy of production first.

### B1 — Two owner panels

**Target URLs**

| Area | Panel id | Path | Tenancy | Examples |
|---|---|---|---|---|
| Personal | `app` | `/app` | none | `/app` (personal dashboard), `/app/login`, `/app/register`, `/app/profile`, `/app/tax-profile`, `/app/tax`, `/app/businesses`, `/app/businesses/new`, `/app/billing`, `/app/verify-phone` |
| Business workspace | `workspace` | `/app/w` | `BusinessEntity` (UUID in URL) | `/app/w/{uuid}` (workspace dashboard), `/app/w/{uuid}/invoices`, `/app/w/{uuid}/ask-settlo`, `/app/w/{uuid}/billing` |
| Accountants | `firm` | `/firm` | `AccountingFirm` | unchanged |
| Superadmin | `admin` | `/admin` | none | unchanged |

**`app/Providers/Filament/AppPanelProvider.php`** (becomes the personal panel):
```php
return $panel
    ->id('app')
    ->path('app')
    ->viteTheme('resources/css/filament/theme.css')
    ->brandName('Settlo')
    ->login()
    ->registration(Register::class)                 // App\Filament\Personal\Auth\Register
    ->passwordReset()
    ->emailVerification()                           // C1
    ->simplePageMaxContentWidth(Width::TwoExtraLarge) // C6
    ->databaseNotifications()
    ->databaseNotificationsPolling('30s')
    ->colors([...unchanged...])
    ->discoverResources(in: app_path('Filament/Personal/Resources'), for: 'App\Filament\Personal\Resources')
    ->discoverPages(in: app_path('Filament/Personal/Pages'), for: 'App\Filament\Personal\Pages')
    ->pages([PersonalDashboard::class])
    ->discoverWidgets(in: app_path('Filament/Personal/Widgets'), for: 'App\Filament\Personal\Widgets')
    ->widgets([])
    ->navigationGroups(['Overview', 'Businesses', 'Personal', 'Account'])
    ->middleware([...unchanged...])
    ->authMiddleware([
        Authenticate::class,
        EnsurePhoneIsVerified::class,               // C4 (no-op while the flag is off)
    ]);
```
Remove `->tenant(...)`, `->tenantRegistration(...)`.

**New `app/Providers/Filament/WorkspacePanelProvider.php`** (`php artisan make:filament-panel workspace --no-interaction`, then edit; register it in `bootstrap/providers.php` right after `AppPanelProvider::class`):
```php
return $panel
    ->id('workspace')
    ->path('app/w')
    ->viteTheme('resources/css/filament/theme.css')
    ->brandName('Settlo')
    ->tenant(BusinessEntity::class)
    ->tenantMiddleware([RememberLastWorkspace::class], isPersistent: true)
    ->tenantMenuItems([
        Action::make('allBusinesses')->label('All businesses')->icon(Heroicon::OutlinedSquares2x2)
            ->url(fn (): string => PersonalDashboard::getUrl(panel: 'app')),
        Action::make('setUpBusiness')->label('Set up another business')->icon(Heroicon::OutlinedPlusCircle)
            ->url(fn (): string => SetUpBusiness::getUrl(panel: 'app')),
    ])
    // Phase E:
    // ->tenantBillingProvider(new WorkspaceBillingProvider)
    // ->requiresTenantSubscription()
    ->databaseNotifications()
    ->databaseNotificationsPolling('30s')
    ->colors([...same as app...])
    ->discoverResources(in: app_path('Filament/Workspace/Resources'), for: 'App\Filament\Workspace\Resources')
    ->discoverPages(in: app_path('Filament/Workspace/Pages'), for: 'App\Filament\Workspace\Pages')
    ->pages([Dashboard::class])                    // App\Filament\Workspace\Pages\Dashboard
    ->discoverWidgets(in: app_path('Filament/Workspace/Widgets'), for: 'App\Filament\Workspace\Widgets')
    ->widgets([])
    ->navigationGroups(['Overview', 'Finance', 'Insights', 'Support', 'Settings'])
    ->navigationItems([
        NavigationItem::make('All businesses')
            ->url(fn (): string => PersonalDashboard::getUrl(panel: 'app'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->group('Overview')
            ->sort(-100),
    ])
    ->userMenuItems([
        Action::make('personalProfile')->label('Personal profile')->icon(Heroicon::OutlinedUserCircle)
            ->url(fn (): string => PersonalProfile::getUrl(panel: 'app')),
        Action::make('taxProfile')->label('Tax profile')->icon(Heroicon::OutlinedCalculator)
            ->url(fn (): string => EditTaxProfile::getUrl(panel: 'app')),
    ])
    ->middleware([...same list as app...])
    ->authMiddleware([
        AuthenticateWorkspace::class,
        'verified:filament.app.auth.email-verification.prompt',   // C1 — Laravel's EnsureEmailIsVerified alias
        EnsurePhoneIsVerified::class,                              // C4
    ]);
```
(No `->login()`/`->registration()` on this panel — authentication lives in the personal panel.)

**New middleware** (`php artisan make:middleware …`):
- `app/Http/Middleware/AuthenticateWorkspace.php`
  ```php
  class AuthenticateWorkspace extends \Filament\Http\Middleware\Authenticate
  {
      protected function redirectTo($request): ?string
      {
          return Filament::getPanel('app')->getLoginUrl();
      }
  }
  ```
  After login, `PanelScopedLoginResponse` (`app/Http/Responses/PanelScopedLoginResponse.php`) honours an intended `/app/w/...` URL because it starts with `/app/` — keep that class as is and add a test.
- `app/Http/Middleware/RememberLastWorkspace.php`: if `Filament::getTenant()` is a `BusinessEntity` and differs from `auth()->user()->last_business_entity_id`, `forceFill([...])->saveQuietly()`.

**`/app/w` without a tenant:** bind a custom redirect controller in `AppServiceProvider::register()`:
```php
$this->app->bind(\Filament\Http\Controllers\RedirectToTenantController::class, \App\Http\Controllers\RedirectToWorkspaceController::class);
```
`RedirectToWorkspaceController extends RedirectToTenantController` and overrides `redirectToTenantRegistration(Panel $panel)`:
workspace panel → `redirect(SetUpBusiness::getUrl(panel: 'app'))`; any other panel → `parent::redirectToTenantRegistration($panel)`.

**`app/Models/User.php`:**
- `implements FilamentUser, HasName, HasTenants, HasDefaultTenant, MustVerifyEmail` (`Filament\Models\Contracts\HasDefaultTenant`, `Illuminate\Contracts\Auth\MustVerifyEmail` — the trait is already in `Illuminate\Foundation\Auth\User`).
- `canAccessPanel()`: `'app', 'workspace' => $this->role === UserRole::Owner`.
- `getTenants()`: `'workspace' => $this->ownedEntities()->orderBy('name')->get()`, `'firm'` unchanged.
- `getDefaultTenant(Panel $panel): ?Model` → workspace: `$this->lastBusinessEntity ?? $this->ownedEntities()->oldest()->first()`; firm: `$this->accountingFirms()->first()`; else null.
- `lastBusinessEntity(): BelongsTo` (`last_business_entity_id`).

### B2 — Data model changes

Create with `php artisan make:migration <name> --no-interaction`. Use the query builder in PHP loops for data moves (works on SQLite and Postgres).

**Migration `add_personal_fields_to_users_table`**
| Column | Type |
|---|---|
| `street` | string, nullable |
| `street_number` | string(20), nullable |
| `postal_code` | string(10), nullable |
| `city` | string, nullable |
| `country_code` | string(2), default `'CH'` |
| `canton_id` | foreignUuid → cantons, nullable, nullOnDelete |
| `commune_id` | foreignUuid → communes, nullable, nullOnDelete |
| `phone_country` | string(2), nullable (ISO of the phone number, C3) |
| `phone_verified_at` | timestamp, nullable (C4) |
| `terms_accepted_at` | timestamp, nullable (C2) |
| `privacy_acknowledged_at` | timestamp, nullable (C2) |
| `terms_version` | string(20), nullable (C2) |
| `last_business_entity_id` | foreignUuid → business_entities, nullable, nullOnDelete (B1) |

Data: `UPDATE users SET email_verified_at = now() WHERE email_verified_at IS NULL` (existing testers must not be locked out by C1);
`phone_country = 'CH'` where `phone` starts with `+41`/`0`.

**Migration `move_tax_profiles_to_users`** (up):
1. `business_entities`: add `vat_status` string default `'not_registered'`, `estimated_annual_revenue` decimal(18,2) nullable.
2. Copy `tax_profiles.vat_status` / `estimated_annual_revenue` onto the matching `business_entities` row.
3. `tax_profiles`: add `user_id` foreignId nullable → users, cascadeOnDelete; set it from `business_entities.owner_id`.
4. Dedupe: per `user_id` keep the most recently `updated_at` row, delete the others (log the deleted ids with `Log::info`).
5. `tax_profiles`: `dropForeign(['business_entity_id'])`, `dropUnique(['business_entity_id'])`, `dropColumn(['business_entity_id', 'vat_status', 'estimated_annual_revenue'])`;
   make `user_id` NOT NULL and `unique`.
6. Copy the profile's canton/commune onto `users.canton_id/commune_id` when the user has none.
Down: reverse (re-add columns, attach each profile to the user's oldest business, copy VAT fields back).

**Migration `add_user_id_to_tax_estimations_table`**: add `user_id` foreignId nullable → users cascadeOnDelete; backfill from
`business_entities.owner_id`; make NOT NULL; `business_entity_id` → `->nullable()->change()`; index `(user_id, fiscal_year)`.
Rows with `business_entity_id = NULL` are **personal (consolidated)** estimations.

**Models**
- `TaxProfile`: `$fillable` = `canton_id, commune_id, marital_status, number_of_children, residence_permit, pillar3a_amount, has_pillar2, kirchensteuer, birth_year, employment_income, employment_rate, employment_taxed_at_source, other_income` (`user_id` is guarded → `forceFill`); remove `vat_status`/`estimated_annual_revenue` casts; replace `businessEntity()` with `user(): BelongsTo`.
- `User`: `taxProfile(): HasOne`, `canton(): BelongsTo`, `commune(): BelongsTo`, `soleProprietorships(): HasMany` (`ownedEntities()->where('type', BusinessEntityType::SoleProprietorship)`),
  `personalTaxEstimation(?int $year = null): ?TaxEstimation` (latest row with `business_entity_id` null);
  `$fillable` add `street, street_number, postal_code, city, country_code, canton_id, commune_id, phone_country`;
  casts `phone_verified_at, terms_accepted_at, privacy_acknowledged_at` → `datetime`.
- `BusinessEntity`: `$fillable` add `vat_status, estimated_annual_revenue, mwst_number` (mwst already there);
  casts `vat_status => VatStatus::class`, `estimated_annual_revenue => 'decimal:2'`;
  **remove `taxProfile()`**; add `ownerTaxProfile(): ?TaxProfile` (`$this->owner?->taxProfile`);
  `isVatRegistered()` (A14) → `$this->vat_status?->isRegistered() || filled($this->mwst_number)`.
- `TaxEstimation`: add `user_id` to fillable, `user(): BelongsTo`.
- Factories: `TaxProfileFactory` → `'user_id' => User::factory()->owner()`, drop `business_entity_id`, `vat_status`;
  `BusinessEntityFactory` → `'vat_status' => VatStatus::NotRegistered`; `UserFactory` → verified email + `terms_accepted_at` by default, state `unverified()`.

### B3 — Tax engine: consolidated personal estimate (P3)

`app/Services/Tax/TaxEngine.php`:
- `estimateForUser(User $user, ?int $fiscalYear = null): ?TaxEstimation`
  1. `$profile = $user->taxProfile()->with(['canton', 'commune'])->first()`.
  2. Canton code: `$profile?->canton?->code ?? $user->canton?->code ?? $user->soleProprietorships()->with('canton')->oldest()->first()?->canton?->code`; null → return null.
  3. Gather across `$user->soleProprietorships()` (one query each for revenue and expenses using `whereIn('business_entity_id', $ids)`):
     net revenue = `sum(subtotal)` of revenue-counting invoices in the year (A14), deductible = `sum(deductible_amount)` of `reviewed` expenses in the year; also a per-business breakdown.
  4. `buildInput(?TaxProfile $profile, string $cantonCode, …)` (signature change — it no longer takes an entity).
  5. Persist `TaxEstimation` with `user_id`, `business_entity_id = null`, and `inputs.businesses = [uuid => ['name', 'net_revenue', 'deductible_expenses', 'net_income']]`.
- `estimateFor(BusinessEntity $entity, ?int $fiscalYear = null): ?TaxEstimation` (keeps its callers):
  1. `$personal = $this->estimateForUser($entity->owner, $fiscalYear)` (null → still evaluate VAT, persist a business row with zero tax).
  2. Business share = this business' positive net income ÷ sum of positive net incomes (BCMath; 0 when the sum is 0).
  3. Persist the business row: its own gross/expenses/net, **tax fields = personal × share** (new `TaxResult::scaled(string $share): TaxResult`), VAT threshold figures for this business (unchanged logic, now on `subtotal`), `inputs.share = $share`, `inputs.personal_estimation_id`.
  4. `syncVatAlert()` unchanged; `taxPageUrl()` → `TaxOverview::getUrl(tenant: $entity, panel: 'workspace')`.
- `compareCantons(User $user, array $codes, ?int $year)` — consolidated figures (used by the personal tax page, D4).
- New job `app/Jobs/RecalculatePersonalTaxEstimation.php` (`ShouldQueue, ShouldBeUnique`, `uniqueId = user id`): runs `estimateFor()` for every sole-prop workspace (which refreshes the personal row once per call — acceptable; or call `estimateForUser()` once and then the business rows with a `$personal` argument to avoid repeats). Dispatch it after personal profile / tax profile saves (D2, D3).
- `RecalculateTaxEstimation` unchanged (entity id).
- `ChatContextAssembler::assemble()` reads `$entity->owner->taxProfile` (eager-load) and `$entity->vat_status`.

### B4 — Move the code

Run from the project root (macOS `sed`):
```bash
git mv app/Filament/App app/Filament/Workspace
grep -rl 'App\\Filament\\App\\' app bootstrap config database routes tests resources \
  | xargs sed -i '' 's/App\\Filament\\App\\/App\\Filament\\Workspace\\/g'

git mv resources/views/filament/app resources/views/filament/workspace
grep -rl "filament\.app\.\(pages\|widgets\)\." app resources \
  | xargs sed -i '' "s/filament\.app\.\(pages\|widgets\)\./filament.workspace.\1./g"

mkdir -p app/Filament/Personal/{Auth,Pages,Widgets,Resources} app/Filament/Shared/{Fields,Schemas} app/Filament/Support
git mv app/Filament/Workspace/Auth/Register.php app/Filament/Personal/Auth/Register.php
sed -i '' 's/namespace App\\Filament\\Workspace\\Auth;/namespace App\\Filament\\Personal\\Auth;/' app/Filament/Personal/Auth/Register.php
```
- Keep `app/Filament/Workspace/Tenancy/RegisterBusinessEntity.php` until C5 replaces it, but **remove it from the panel** (no tenant registration).
- The Tax-profile tab of `App\Filament\Workspace\Pages\BusinessSettings` is deleted (A6's `taxForm`, `saveTax`, `$taxData`, `TAX_FIELDS`);
  the profile form gains **VAT status** (`vat_status` Select, `mwst_number` visible when registered, `SwissVatNumber` rule) and
  **Estimated annual revenue**; replace the tab with a `Callout`/link "Your tax profile is personal → Open tax profile".
- `resources/css/filament/theme.css` `@source` globs already cover `app/Filament/**` and `resources/views/filament/**`.
- `composer dump-autoload`, `php artisan filament:optimize-clear`, `npm run build`.

### B5 — Update every hard-coded panel/tenant reference

| File:line (today) | Change |
|---|---|
| `app/Filament/Workspace/Widgets/AskSettloPreview.php:50` | `panel: 'workspace'` |
| `app/Http/Controllers/AskSettlo/AskSettloController.php:65` | `panel: 'workspace'` |
| `app/Services/Ai/EscalationService.php:183` | `->url(AskSettlo::getUrl(tenant: $conversation->businessEntity, panel: 'workspace'))` (eager-load) |
| `app/Http/Controllers/FirmInvitationController.php:68` | `redirect(Dashboard::getUrl(tenant: $entity, panel: 'workspace'))` |
| `app/Services/Tax/TaxEngine.php:149` | `TaxOverview::getUrl(tenant: $entity, panel: 'workspace')` |
| `app/Filament/Admin/Resources/Users/Tables/UsersTable.php:138` | owners → `/app` (personal dashboard) — unchanged |
| `app/Filament/Workspace/Widgets/TaxBreakdownWidget.php:48` & `ToDoWidget.php:109` | "Complete your tax profile" → `EditTaxProfile::getUrl(panel: 'app')`; `taxProfileIncomplete()` reads `$entity->owner->taxProfile` |
| `app/Filament/Workspace/Pages/TaxOverview.php:72, 87` | canton from `$entity->owner->taxProfile?->canton` |
| `app/Filament/Workspace/Pages/VatSummary.php:34-37` | `isRegistered()` → `Filament::getTenant()?->isVatRegistered()`; add the multi-sole-prop callout (Q4) |
| `app/Filament/Workspace/Pages/Dashboard.php` | heading = business name; greeting moves to the personal dashboard (subheading keeps "Good morning, Anna") |
| `routes/channels.php:7-9` | `App.Models.User.{id}` compares `getKey() === $id` with an int vs string → always false; use `(string) $user->getKey() === $id` (side fix) |
| `database/seeders/DemoSeeder.php:95-122` | personal tax profile on Anna (`user_id`), VAT fields on the entity, Anna's personal address (e.g. Seefeldstrasse 12, 8008 Zürich, commune Zürich 261), `terms_accepted_at`, `phone`/`phone_country` |

Search for leftovers: `grep -rn "panel: 'app'\|getPanel('app')\|'/app/'\|->taxProfile\b\|TaxProfile::factory()->for(\$entity" app tests database`.

### B6 — Authorization

- `BusinessEntityPolicy::create()` → owner **and** `hasVerifiedEmail()` (and phone verified when the flag is on).
- Workspace resources keep their tenant scoping; Filament's `canAccessTenant()` (`User.php:123-136`) stays the hard boundary.
- Personal pages: `canAccess()` → `auth()->user()?->isOwner()`.
- Personal `BusinessResource` (D5) → `getEloquentQuery()->where('owner_id', auth()->id())`; policy `view` = owner.
- Subscription-based write access moves to the workspace in E5.

### B7 — Tests to update / add

- Add helpers to `tests/Pest.php`:
  ```php
  /** @return array{0: User, 1: BusinessEntity} */
  function workspaceOwner(string $planCode = 'pro', string $cantonCode = 'ZH'): array { … creates verified owner + personal tax profile + entity + (Phase E: entity subscription) … }

  function actAsWorkspace(User $user, BusinessEntity $entity): void
  {
      test()->actingAs($user);
      Filament::setCurrentPanel(Filament::getPanel('workspace'));
      Filament::setTenant($entity);
  }
  ```
- Mechanical updates: every test that calls `Filament::getPanel('app')` **and** `setTenant()` → `'workspace'`; `panel: 'app'` → `'workspace'`;
  `TaxProfile::factory()->for($entity…)` → `->for($user)`; `vat_status` expectations move to the entity.
  Files: `AskSettloHttpTest, AskSettloWidgetTest, BankAccountsTest, BusinessSettingsTest, ClientResourceTest, DashboardWidgetsTest, ExpenseResourceTest, FirmInvitationTest, InvoiceResourceTest, InvoiceViewTest, TaxOverviewTest, VatSummaryTest, TaxEngineTest, PanelAccessTest, PanelLoginRedirectTest, OnboardingTest (rewritten in C5), DataLayerSeedTest, MassAssignmentGuardTest`.
- New tests:
  - `PanelAccessTest`: matrix gains `workspace` (owner ✔, accountant ✘, superadmin ✘).
  - guest → `/app/w/{uuid}` redirects to `/app/login`; after login lands on the intended workspace URL.
  - owner without businesses → `/app/w` redirects to `/app/businesses/new`.
  - owner A cannot open owner B's workspace (`canAccessTenant`).
  - `RememberLastWorkspace` stores the id; `/app/w` redirects to it.
  - migration test: a legacy tax profile attached to a business ends up on the owner, VAT fields on the business; duplicate profiles deduped.
  - `TaxEngineTest`: two sole-prop workspaces (net 60k + 40k) → personal row taxes 100k once; business rows carry 60 % / 40 % shares; a loss-making business gets share 0.
---

## 6. Phase C — Registration & onboarding redesign

> Target flow (email §3): **Register (1 screen) → verify email → [verify phone by SMS — flag, off for now] → personal dashboard**.
> Everything else (personal address, tax profile, businesses) happens inside the app, guided by the onboarding checklist (D1).

### C1 — Email verification

- `User implements MustVerifyEmail` (B1). Personal panel `->emailVerification()` (B1) — Filament sends
  `Filament\Auth\Notifications\VerifyEmail` after registration (`vendor/filament/filament/src/Auth/Pages/Register.php:101`)
  and registers `/app/email-verification/prompt` + `/app/email-verification/verify/{id}/{hash}`.
- Workspace panel uses Laravel's `verified:filament.app.auth.email-verification.prompt` middleware (B1).
- Mail locally: `MAIL_MAILER=log` (link appears in `storage/logs/laravel.log`). Optional: add a Mailpit service to
  `docker-compose.yml` on ports **1026/8026** (1025/8025 are taken by another project) and set `MAIL_MAILER=smtp`, `MAIL_PORT=1026`.
- Production: set a real mailer before deploying C (Q5). `MAIL_FROM_ADDRESS` → `hello@settlo.ch`, `MAIL_FROM_NAME` → `Settlo`.
- **Tests** (`tests/Feature/RegistrationTest.php`, new): `Notification::fake()`; registering sends `VerifyEmail`;
  unverified user → `/app` redirects to the prompt; unverified → `/app/w/{uuid}` redirects to `/app/email-verification/prompt`;
  the signed verify URL marks the user verified and redirects to `/app`.

### C2 — Registration form (BUG-03/04/05/06/07, email §3)

`app/Filament/Personal/Auth/Register.php`:
- `protected Width|string|null $maxWidth = Width::TwoExtraLarge;` (C6) and `form()` → `$schema->columns(['default' => 1, 'sm' => 2])`.
- Fields (in order):
  | Field | Component | Validation / config |
  |---|---|---|
  | `first_name` | `TextInput` | required, max:255, `->autocomplete('given-name')`, `->autofocus()`, `->tap(new ValidatesOnBlur)` |
  | `last_name` | `TextInput` | required, max:255, `->autocomplete('family-name')`, `->tap(new ValidatesOnBlur)` |
  | `email` | `$this->getEmailFormComponent()` | + `->autocomplete('email')->columnSpanFull()->tap(new ValidatesOnBlur)` |
  | phone | `PhoneNumberField::make()` (C3) | `->columnSpanFull()` |
  | `preferred_language` | `Select` | unchanged; `->columnSpanFull()` |
  | `password` | rebuilt (below) | |
  | `passwordConfirmation` | rebuilt (below) | |
  | `accept_terms` | `Checkbox` | see below, `->columnSpanFull()` |
- Password pair (moves the `same` rule to the confirmation so on-blur validation doesn't complain early — A3 gotcha):
  ```php
  protected function getPasswordFormComponent(): Component
  {
      return TextInput::make('password')
          ->label(__('filament-panels::auth/pages/register.form.password.label'))
          ->password()
          ->revealable(filament()->arePasswordsRevealable())
          ->required()
          ->rule(Password::default())
          ->showAllValidationMessages()
          ->autocomplete('new-password')
          ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
          ->validationAttribute(__('filament-panels::auth/pages/register.form.password.validation_attribute'))
          ->tap(new ValidatesOnBlur);
  }

  protected function getPasswordConfirmationFormComponent(): Component
  {
      return TextInput::make('passwordConfirmation')
          ->label(__('filament-panels::auth/pages/register.form.password_confirmation.label'))
          ->password()
          ->revealable(filament()->arePasswordsRevealable())
          ->required()
          ->same('password')
          ->autocomplete('new-password')
          ->dehydrated(false)
          ->validationAttribute('password confirmation')
          ->tap(new ValidatesOnBlur);
  }
  ```
  (`Illuminate\Validation\Rules\Password`, `Illuminate\Support\Facades\Hash`.)
- Terms consent (BUG-07 — exact wording from Bogdan):
  ```php
  Checkbox::make('accept_terms')
      ->label(fn (): HtmlString => new HtmlString(sprintf(
          'I agree to the <a href="%s" target="_blank" rel="noopener" class="font-medium text-primary-600 underline dark:text-primary-400">Terms of Service</a> and acknowledge that I have read the <a href="%s" target="_blank" rel="noopener" class="font-medium text-primary-600 underline dark:text-primary-400">Privacy Notice</a>.',
          e(config('settlo.legal.terms_url')),
          e(config('settlo.legal.privacy_url')),
      )))
      ->accepted()
      ->validationMessages(['accepted' => 'Please accept the Terms of Service to create your account.'])
      ->dehydrated(false)
      ->columnSpanFull()
  ```
- `config/settlo.php` → new block:
  ```php
  'legal' => [
      'terms_url' => env('SETTLO_TERMS_URL', 'https://settlo.ch/terms'),
      'privacy_url' => env('SETTLO_PRIVACY_URL', 'https://settlo.ch/privacy'),
      'version' => env('SETTLO_TERMS_VERSION', '2026-09'),
  ],
  ```
- `handleRegistration()`: keep role/status `forceFill`; add
  `'terms_accepted_at' => now(), 'privacy_acknowledged_at' => now(), 'terms_version' => config('settlo.legal.version'), 'phone_verified_at' => null`.
- A15 throttling override stays.
- **Tests** (`RegistrationTest`): dataset — first/last name required; email format & unique; phone invalid (C3); password min 8;
  confirmation mismatch → error on `passwordConfirmation`; terms not accepted → `accepted` error with the custom message;
  valid → user created as owner/active with `terms_accepted_at`, `terms_version = '2026-09'`, E.164 phone, redirect.

### C3 — Phone number with country code (BUG-06, BUG-52 — D3)

- **Dependency (needs approval if not already given with D3):** `composer require propaganistas/laravel-phone:^6.0`
  (libphonenumber). **Fallback if refused:** validate against `^\+[1-9]\d{6,14}$` after prefixing the dial code, with a per-country
  national length table for CH (9 digits, mobile prefixes 74–79), LI (7), DE (10–11), AT (10–13), FR (9), IT (9–10).
- New `app/Filament/Shared/Fields/PhoneNumberField.php`:
  ```php
  final class PhoneNumberField
  {
      public static function make(bool $required = true): FusedGroup
      {
          return FusedGroup::make([
              Select::make('phone_country')
                  ->hiddenLabel()
                  ->options(fn (): array => PhoneCountries::options())
                  ->default('CH')
                  ->searchable()
                  ->selectablePlaceholder(false)
                  ->required($required)
                  ->live()
                  ->columnSpan(1),
              TextInput::make('phone')
                  ->hiddenLabel()
                  ->tel()
                  ->required($required)
                  ->placeholder(fn (Get $get): string => PhoneCountries::example((string) ($get('phone_country') ?: 'CH')))
                  ->rule(fn (Get $get) => (new \Propaganistas\LaravelPhone\Rules\Phone)->country((string) ($get('phone_country') ?: 'CH'))->type('mobile'))
                  ->dehydrateStateUsing(fn (?string $state, Get $get): ?string => blank($state) ? null
                      : (new \Propaganistas\LaravelPhone\PhoneNumber($state, (string) ($get('phone_country') ?: 'CH')))->formatE164())
                  ->formatStateUsing(fn (?string $state): ?string => blank($state) ? null
                      : rescue(fn () => (new \Propaganistas\LaravelPhone\PhoneNumber($state))->formatNational(), $state, report: false))
                  ->autocomplete('tel-national')
                  ->tap(new ValidatesOnBlur)
                  ->columnSpan(2),
          ])
              ->label('Mobile phone')
              ->columns(3);
      }
  }
  ```
  Import classes normally (FQCNs above only for clarity).
- New `app/Support/PhoneCountries.php`: `options(): array<string, string>` — first CH `🇨🇭 +41`, LI `🇱🇮 +423`, DE `🇩🇪 +49`, AT `🇦🇹 +43`, FR `🇫🇷 +33`, IT `🇮🇹 +39`, then every other region from
  `libphonenumber\PhoneNumberUtil::getInstance()->getSupportedRegions()` sorted by name, label `"{flag} {name} +{code}"`;
  flag = the two regional-indicator characters (`mb_chr(0x1F1E6 + ord($letter) - 65)`); country names via `Locale::getDisplayRegion('-'.$iso, 'en')` (ext-intl is present).
  `example(string $iso): string` → national format of `getExampleNumberForType($iso, PhoneNumberType::MOBILE)` or `''`.
- Phone is **required** (it's needed for OTP; Bogdan marks it valuable for sales). Mobile numbers only.
- Users store `phone` (E.164) and `phone_country` (ISO2). `phone` is already fillable; add `phone_country` (B2).
- **Tests:** `abcde` → error; CH `079 123 45 67` → saved `+41791234567`; CH landline `044 123 45 67` → error (not mobile);
  DE `0151 23456789` with DE selected → `+4915123456789`; `PhoneCountries::options()` starts with CH.

### C4 — SMS one-time code (feature flag, **off** — D3)

- `config/settlo.php`:
  ```php
  'phone_verification' => [
      'enabled' => (bool) env('SETTLO_PHONE_VERIFICATION', false),
      'driver' => env('SETTLO_PHONE_VERIFICATION_DRIVER', 'log'),
      'code_ttl_minutes' => 10,
      'max_attempts' => 5,
      'resend_cooldown_seconds' => 60,
  ],
  ```
- Contract `app/Services/Phone/PhoneVerifier.php`: `sendCode(User $user): void`, `verify(User $user, string $code): bool`.
- `app/Services/Phone/LogPhoneVerifier.php` (default): 6-digit `random_int` code; store `Hash::make($code)` + attempt counter in cache key
  `phone-otp:{user id}` for `code_ttl_minutes`; **only in the `local` environment** `Log::info('Phone OTP', ['user' => id, 'code' => $code])`;
  `verify()` → false after `max_attempts`, `Hash::check`, clears the key on success. A real SMS driver (Twilio Verify / ASPSMS) is added later behind the same contract (Q6).
- Bind in `AppServiceProvider::register()` by `settlo.phone_verification.driver`.
- Page `app/Filament/Personal/Pages/VerifyPhone.php` (`php artisan make:filament-page VerifyPhone --panel=app --no-interaction`):
  `extends Filament\Pages\SimplePage`, slug `verify-phone`, `shouldRegisterNavigation(): false`,
  `canAccess()` → flag on and user not verified. Form: masked phone text (`+41 79 *** ** 67`),
  `Filament\Forms\Components\OneTimeCodeInput::make('code')->length(6)->required()` (exists in v5: `vendor/filament/forms/src/Components/OneTimeCodeInput.php`).
  Actions: "Verify" (submit → `verify()`; success → `forceFill(['phone_verified_at' => now()])`, redirect to dashboard; failure → field error "The code is invalid or expired."),
  "Send a new code" (rate limited with `RateLimiter::attempt('phone-otp-resend:'.$id, 1, …, cooldown)`), "Change number" (link to Personal profile).
  `mount()` sends the first code if none is pending.
- Middleware `app/Http/Middleware/EnsurePhoneIsVerified.php`: when the flag is on, the user is an owner, `phone_verified_at` is null and the
  route is not `VerifyPhone`, `PersonalProfile`, logout or email verification → redirect to `VerifyPhone::getUrl(panel: 'app')`. Registered in both owner panels (B1).
- Changing the phone in Personal profile resets `phone_verified_at` (D2).
- **Tests:** flag off → no redirect; flag on → redirect; correct code verifies; 5 wrong codes → locked; resend cooldown enforced;
  use `config(['settlo.phone_verification.enabled' => true])` and bind a fake verifier that exposes the last code.

### C5 — "Set up a business" (replaces tenant registration; BUG-09/11/50/51/53/54/55/56/57/58, email §3/§5)

**Why a custom stepper instead of `Wizard`:** Filament's wizard keeps the current step only in Alpine
(`vendor/filament/schemas/resources/views/components/wizard.blade.php:154-184`), so the server can't disable
"Next" for the visible step (BUG-53) and can't add per-step "Skip for now" buttons. A small Livewire stepper with three
schemas gives full control.

- Page `app/Filament/Personal/Pages/SetUpBusiness.php` — `php artisan make:filament-page SetUpBusiness --panel=app --no-interaction`
  - `protected static ?string $slug = 'businesses/new';`, navigation group `Businesses`, label **"Set up a business"**, icon `Heroicon::OutlinedPlusCircle`, sort 2.
  - `protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;`
  - `getTitle()` → "Set up your business"; `getSubheading()` → "We'll use this information to configure invoicing, accounting and tax settings for your business. It only takes about a minute." (BUG-51)
  - `canAccess()` → owner with verified email (and verified phone when the flag is on).
  - State: `public int $step = 1;` `public bool $invoicingSkipped = false;` `public ?array $businessData = [];` `public ?array $invoicingData = [];` `public ?array $planData = [];`
  - `private const STEPS = [1 => 'Business profile', 2 => 'Invoicing & banking', 3 => 'Plan'];`
  - `mount()`: prefill `businessData` from the personal address (`street`, `street_number`, `postal_code`, `city`, `canton_id`) and `type = sole_proprietorship`;
    `invoicingData` = `['default_payment_term_days' => 30, 'default_language' => $user->preferred_language ?? 'en', 'invoice_number_prefix' => 'INV-']`;
    `planData` = `['plan_id' => Plan::where('code', 'pro')->value('id'), 'billing_interval' => BillingInterval::Month->value]`.
  - Schemas:
    ```php
    public function businessForm(Schema $schema): Schema  { return $schema->statePath('businessData')->columns(['default' => 1, 'md' => 2])->components(BusinessProfileFields::components(withUidLookup: true)); }
    public function invoicingForm(Schema $schema): Schema { return $schema->statePath('invoicingData')->columns(['default' => 1, 'md' => 2])->components(InvoicingDefaultsFields::components(withBankName: true)); }
    public function planForm(Schema $schema): Schema      { return $schema->statePath('planData')->components($this->planFields()); }
    ```
  - `content(Schema $schema)`:
    ```php
    return $schema->components([
        View::make('filament.personal.components.setup-stepper')
            ->viewData(fn (): array => ['steps' => self::STEPS, 'current' => $this->step]),
        Form::make([EmbeddedSchema::make(match ($this->step) { 1 => 'businessForm', 2 => 'invoicingForm', default => 'planForm' })])
            ->id('set-up-business')
            ->livewireSubmitHandler($this->step < 3 ? 'next' : 'create')
            ->footer([
                Actions::make([
                    Action::make('back')->label('Back')->color('gray')->action('back')->visible(fn (): bool => $this->step > 1),
                    Action::make('skip')->label($this->step === 1 ? "I'll do it later" : 'Skip for now')->link()->color('gray')->action('skip'),
                    Action::make('next')
                        ->label(fn (): string => match (true) {
                            $this->step < 3 => 'Next',
                            ! auth()->user()->hasUsedTrial() => 'Start 14-day free trial',
                            default => 'Continue to payment',
                        })
                        ->submit($this->step < 3 ? 'next' : 'create')
                        ->disabled(fn (): bool => ! $this->stepIsValid($this->step)),
                ])->alignment(Alignment::End)->key('set-up-business-actions'),
            ]),
    ]);
    ```
    (`Filament\Schemas\Components\View`, `…\Form`, `…\EmbeddedSchema`, `…\Actions`, `Filament\Support\Enums\Alignment`.)
  - `stepIsValid(int $step): bool` — runs the step's rules silently:
    ```php
    $schema = $this->getSchema(self::SCHEMA_BY_STEP[$step]);
    return Validator::make(
        ['businessData' => $this->businessData, 'invoicingData' => $this->invoicingData, 'planData' => $this->planData],
        $schema->getValidationRules(),
    )->passes();
    ```
    (`getValidationRules()` — `vendor/filament/schemas/src/Concerns/CanBeValidated.php:75`.) All text inputs in the three schemas use
    `->tap(new ValidatesOnBlur(debounceMs: 400))` so the button state follows typing.
  - `next()`: `$this->{self::SCHEMA_BY_STEP[$this->step]}->validate(); $this->step++;`
  - `back()`: `$this->step = max(1, $this->step - 1);`
  - `skip()`:
    - step 1 → nothing is created; notification "You can set up your business any time from your dashboard."; redirect to `PersonalDashboard::getUrl()`.
    - step 2 → `$this->businessForm->validate()`; `$this->invoicingSkipped = true`; call `create(planSkipped: true)` (invoicing defaults, no IBAN, default plan).
    - step 3 → `create(planSkipped: true)`.
  - `create(bool $planSkipped = false)`:
    ```php
    $business  = $this->businessForm->getState();
    $invoicing = $this->invoicingSkipped ? null : $this->invoicingForm->getState();
    $plan      = $planSkipped ? null : $this->planForm->getState();

    $entity = app(WorkspaceProvisioner::class)->create(auth()->user(), $business, $invoicing, $plan);
    $subscription = $entity->subscription;

    if ($subscription->status === SubscriptionStatus::Incomplete) {
        $this->redirect(app(SubscriptionService::class)->checkoutUrl(
            $subscription,
            successUrl: Dashboard::getUrl(tenant: $entity, panel: 'workspace'),
            cancelUrl: Billing::getUrl(['workspace' => $entity->getKey()], panel: 'app'),
        ));

        return;
    }

    Notification::make()->title("{$entity->name} is ready")->body('Your 14-day free trial has started — no credit card needed.')->success()->send();
    $this->redirect(Dashboard::getUrl(tenant: $entity, panel: 'workspace'));
    ```
- Stepper view `resources/views/filament/personal/components/setup-stepper.blade.php` (BUG-57): an `<ol>` with one `<li>` per step —
  completed: primary circle with `heroicon-m-check`; current: primary ring + bold label; upcoming: gray circle with the number;
  labels visible from `sm`; a right-aligned "Step {{ $current }} of {{ count($steps) }}" caption; connecting lines between items.
  Use Tailwind classes only from Filament's palette (`bg-primary-600`, `text-gray-500`, `dark:` variants). The ticks are shown **only** for steps
  `< $current` (fixes BUG-09's "all ticks green").
- **Shared field builders** (`php artisan make:class Filament/Shared/Schemas/BusinessProfileFields --no-interaction`, etc.):
  - `BusinessProfileFields::components(bool $withUidLookup = false): array` — in this order:
    1. `uid` (A16/A21 config; with F2 suffix action when `$withUidLookup`)
    2. `type` (A21 "Coming soon" select; `->rule(Rule::in([BusinessEntityType::SoleProprietorship->value]))`)
    3. `name` — label "Business name", required, max:255, hint tooltip "Your registered business name. It appears on invoices and the QR-bill."
    4. `legal_name` — label "Trading name" (A21)
    5. `SwissAddressFields::make(required: true)` (F1) → address search, `street`, `street_number`, `postal_code`, `city`, `canton_id` — all required (BUG-11)
    6. `vat_status` — `Select` `VatStatus::class`, default `not_registered`, `->live()`
    7. `mwst_number` — label "VAT number", visible when `VatStatus::from($get('vat_status'))->isRegistered()`, required then, `SwissVatNumber`
    8. `estimated_annual_revenue` — optional numeric ≥ 0, prefix CHF, hint "Helps us warn you before you reach the CHF 100,000 VAT threshold."
  - `InvoicingDefaultsFields::components(bool $withBankName = false, bool $ibanRequired = true): array` — `iban` (A6/A7 config, required),
    `bank_name` (only when `$withBankName`, optional, placeholder "e.g. UBS"), `default_payment_term_days` (A21), `default_language`,
    `invoice_number_prefix` (required, max:20, `alpha_dash`), `default_invoice_notes` (Textarea, settings only).
  - `BusinessSettings` (workspace) reuses both builders (A6 forms).
- **Plan step** (`planFields()`):
  - `ToggleButtons::make('billing_interval')->options(BillingInterval::class)->inline()->grouped()->default('month')->live()` — `BillingInterval` enum (E2) labels "Monthly", "Yearly (2 months free)".
  - `Radio::make('plan_id')->options(fn () => activePlans()->pluck('name', 'id'))->descriptions(fn (Get $get): array => … WorkspacePricing::describe($plan, interval, discount) . ' · ' . implode(' · ', marketing_features))->columns(['default' => 1, 'lg' => 3])->required()`.
  - `TextEntry::make('pricing_note')->hiddenLabel()->state(fn (): string => ! auth()->user()->hasUsedTrial()
        ? 'No credit card required · Cancel anytime · 14 days free.'
        : 'Your first business already used the free trial. You\'ll continue to secure payment with Stripe. Multi-business discount: '.$discount.' %.')`.
- **`WorkspaceProvisioner`** (`app/Services/Workspaces/WorkspaceProvisioner.php`) — one DB transaction:
  1. `BusinessEntity`: fill safe fields; `forceFill(['owner_id' => $user->id, 'canton_id' => …, 'iban' => normalized or null])`;
     invoicing defaults when `$invoicing === null` (30 days, user's language, `INV-`).
  2. Default `BankAccount` when an IBAN is present (`bank_name` = input or "Primary account", `account_name` = business name, `is_default = true`).
  3. Plan = `$plan['plan_id']` or Pro; interval = `$plan['billing_interval']` or month → `SubscriptionService::startWorkspaceSubscription()` (E3).
  4. `onboarding_completed_at` on the user when null; `last_business_entity_id` = new entity.
  5. After commit: `RecalculateTaxEstimation::dispatch($entity->id)`.
- **Delete** `app/Filament/Workspace/Tenancy/RegisterBusinessEntity.php` and its test; `OnboardingTest` becomes `SetUpBusinessTest`.
- **Tests** (`tests/Feature/SetUpBusinessTest.php`):
  - page requires verified owner; accountant forbidden.
  - `assertActionDisabled('next')` on an empty step 1; filling required fields → `assertActionEnabled('next')`.
  - step navigation next/back; values survive.
  - business address prefilled from the personal address.
  - GmbH submitted → validation error (`in`).
  - invalid UID check digit → error while typing; invalid IBAN keeps "Next" disabled on step 2.
  - skip on step 1 → redirect to the personal dashboard, no entity.
  - skip on step 2 → entity without IBAN, no bank account, trial on Pro monthly, redirect to the workspace dashboard.
  - full flow → entity + default bank account + trial on the chosen plan/interval; `hasUsedTrial()` becomes true.
  - second business → subscription `incomplete`, `discount_percent = 20`, redirect to the (dummy) checkout URL.
  - third business → `discount_percent = 30`.

### C6 — Desktop layout (email §5, BUG-57)

- Register: `Width::TwoExtraLarge` + 2-column grid (C2). Login/password pages keep Filament's default simple width.
- Set up a business: full panel layout (sidebar visible), `Width::FiveExtraLarge`, 2-column field grids, visible stepper (C5).
- Personal/tax profile pages: full layout, sections in 2 columns on `md+`.
- Branding (optional polish): copy `settlo-specs/settlo-logos/` SVG/PNG into `public/images/` and set on all panels
  `->brandLogo(asset('images/settlo-logo.svg'))->darkModeBrandLogo(asset('images/settlo-logo-dark.svg'))->brandLogoHeight('1.75rem')->favicon(asset('images/settlo-icon-32.png'))`.
- Native mobile app: out of scope (email §5 — future).
- Verify with screenshots at 1280 px and 1600 px (manual login).

### C7 — First-run experience

- After email verification the user lands on the personal dashboard with the onboarding checklist (D1) whose first
  open item is "Set up your business".
- Registration no longer asks for business data (BUG-50.5/51); the old tenant-registration redirect is gone (B1).

---

## 7. Phase D — Personal area & dashboards

### D0 — One source for business numbers

New `app/Services/Reporting/BusinessMetrics.php` + readonly DTO `app/Services/Reporting/BusinessMetricsResult.php`
(`make:class`). All widgets use it so the personal and workspace dashboards always agree.

| Metric | Definition (fiscal year = `config('settlo.current_fiscal_year')`) |
|---|---|
| `revenueNet` | `sum(invoices.subtotal)` where status ∈ {sent, paid, overdue} and `year(issue_date)` = year |
| `vatCollected` | `sum(invoices.vat_amount)` same filter |
| `expensesGross` | `sum(expenses.amount)` where status = reviewed and `year(expense_date)` = year |
| `deductibleExpenses` | `sum(expenses.deductible_amount)` same filter |
| `profit` | `revenueNet − deductibleExpenses` (tax basis; matches `tax_estimations.net_income`) |
| `cashReceived` | `sum(invoice_payments.amount)` for the entity's invoices with `year(paid_at)` = year |
| `receivablesOpen` | `sum(invoices.total)` where status ∈ {sent, overdue} |
| `receivablesOverdue` | `sum(invoices.total)` where status = overdue **or** (sent and `due_date` < today) |
| `pendingExpenses` | count + gross of `pending_review` expenses in the year |

API: `forEntity(BusinessEntity $entity, ?int $year = null): BusinessMetricsResult`, `forUser(User $user, ?int $year = null): BusinessMetricsResult` (sum over owned entities),
`perEntity(User $user, ?int $year = null): Collection<string, BusinessMetricsResult>` (keyed by entity id, 3 grouped queries total — no N+1).
Money as BCMath strings. Unit/feature tests with a dataset of invoices/expenses/payments.

### D1 — Personal dashboard (`/app`)

`app/Filament/Personal/Pages/PersonalDashboard.php` extends `Filament\Pages\Dashboard`:
- heading = time-of-day greeting (move `greeting()` from the workspace dashboard) + first name; subheading "Here's everything across your businesses."
- header actions: `Action::make('setUpBusiness')->label('Set up a business')->icon(Heroicon::OutlinedPlusCircle)->url(SetUpBusiness::getUrl())`,
  `Action::make('taxProfile')->label('Tax profile')->color('gray')->url(EditTaxProfile::getUrl())`.
- `getColumns()` → `['md' => 2, 'xl' => 3]`.

Widgets (`php artisan make:filament-widget <Name> --panel=app --no-interaction`, add `--stats-overview`/`--table`/`--chart` where noted):

| Widget | Type | Sort | Span | Content |
|---|---|---|---|---|
| `OnboardingChecklist` | custom view | 0 | full | Items: *Verify your email* (done), *Verify your mobile number* (only when C4 flag on), *Add your home address* (`street`, `postal_code`, `city`, `canton_id` filled), *Complete your tax profile* (profile exists with canton, marital status, and commune when the canton has communes), *Set up your first business*, *Add an IBAN to every business* (only when ≥ 1 business). Each open item links to its page. Header "Get started — {done} of {total}", progress bar. `canView()` → false when everything is done. |
| `WorkspacesOverview` | custom view | 1 | full | Card grid (`grid gap-4 md:grid-cols-2 xl:grid-cols-3`), one card per owned business ordered by name: name, type badge, subscription badge (Trial — N days left / Active / Payment required / Expired), plan + interval, `revenueNet`, `profit`, `receivablesOpen` (D0 `perEntity`), buttons **Open** (`Dashboard::getUrl(tenant: $e, panel: 'workspace')`) and **Billing** (`Billing::getUrl(['workspace' => $e->id])`, highlighted when payment is required). Last card: dashed "Set up another business" + "GmbH & AG — coming soon". Empty state: large CTA "Set up your business". |
| `ConsolidatedFinancials` | `--stats-overview` | 2 | full | Revenue YTD (excl. VAT), Deductible expenses YTD, Profit YTD, Open receivables — `BusinessMetrics::forUser()`; descriptions "across N businesses". |
| `PersonalTaxSummary` | custom view | 3 | 1 | From `$user->personalTaxEstimation()`: total tax burden, monthly reserve, effective rate, AHV/IV/EO; "Consolidated across N businesses"; link "View personal tax" (D4); pending-expenses notice (sum over workspaces). No estimation → CTA "Complete your tax profile". Gate: `$user->hasFeatureInAnyWorkspace(PlanFeature::TaxEngine)`; otherwise an upsell line. |
| `TaxProfileCard` | custom view | 4 | 1 | Canton + commune, residence status, marital status/tariff, children, Pillar 3a; "Edit tax profile". Missing profile → CTA. |
| `RecentActivity` (optional) | `--table` | 5 | 1 | Latest 5 invoices across all businesses (business name column). |

### D2 — Personal profile (`/app/profile`)

`app/Filament/Personal/Pages/PersonalProfile.php` (`make:filament-page PersonalProfile --panel=app`), slug `profile`, group `Personal`, icon `Heroicon::OutlinedUserCircle`, sort 1.
Three independent forms (pattern from A6):
- `profileForm` (`profileData`): `first_name`, `last_name` (required), `email` (`TextInput` disabled + `->dehydrated(false)` + hint "Contact support to change your email address"),
  `PhoneNumberField::make()`, `preferred_language`. `saveProfile()` — if the E.164 phone changed: `phone_verified_at = null` and, when C4 is on, redirect to `VerifyPhone`.
- `addressForm` (`addressData`): `SwissAddressFields::make(withCommune: true, required: true)` (F1) + `country_code` (disabled, `CH`).
  `saveAddress()` → `$user->fill(...)`; `forceFill(['canton_id', 'commune_id'])`; if the tax profile has no canton → copy canton/commune into it (BUG-58 part 4);
  `RecalculatePersonalTaxEstimation::dispatch($user->id)`.
- `passwordForm` (`passwordData`): `current_password` (`->password()->required()->rule('current_password')`), `password` (`Password::default()`), `password_confirmation` (`same:password`).
  `updatePassword()` → `forceFill(['password' => Hash::make(...)])->save()` then keep the session alive:
  `session()->put(['password_hash_'.Filament::getAuthGuard() => $user->getAuthPassword()])` (the panels run `AuthenticateSession`).
- **Tests:** each form validates independently; phone change resets verification; address save fills the missing tax canton; wrong current password rejected.

### D3 — Tax profile (`/app/tax-profile`) — BUG-12/13/14/35/36/58, email §1

`app/Filament/Personal/Pages/EditTaxProfile.php` (class name avoids clashing with the model), slug `tax-profile`, label **"Tax profile"**, group `Personal`, icon `Heroicon::OutlinedCalculator`, sort 2.
- Form `data`, `$schema->columns(1)`, fields from `app/Filament/Shared/Schemas/TaxProfileFields.php` (`components(): array`):
  - Section **"Tax residence"** (`->columns(['default' => 1, 'md' => 2])`, description "Defaults to your home address. Change it only if you're taxed somewhere else."):
    - `canton_id` — `CantonSelect::make()` (`app/Filament/Shared/Fields/CantonSelect.php`: options `"{code} — {name_en}"` ordered by name, searchable), required, `->live()`, `afterStateUpdated` → `$set('commune_id', null)`.
    - `commune_id` — `CommuneSelect::make('commune_id', cantonField: 'canton_id')`: options = communes of the canton with `effective_to` null, ordered by name, `->searchable()`; `->required(fn (Get $get): bool => Commune::where('canton_id', $get('canton_id'))->exists())`;
      hint when `multiplier_is_estimated` (F3): "Communal tax rate estimated from the canton average."
    - `residence_permit` — label **"Residence status"** (A20), required, `->live()`; `TextEntry` warning visible when `triggersQuellensteuer()`.
  - Section **"Household"**: `marital_status` (A20, required), `number_of_children` (A18), `birth_year` (`->integer()->minValue(1900)->maxValue((int) date('Y'))`, hint "Used for the AHV exemption from age 65"), `kirchensteuer` (Toggle, hint tooltip "About 8–15 % surcharge on cantonal tax for registered church members").
  - Section **"Pension"**: `has_pillar2`, `pillar3a_amount` (A18).
  - Section **"Other income"**: `other_income` (`->numeric()->minValue(0)->prefix('CHF')`, hint "Yearly income outside your businesses, e.g. salary or rent").
  - Section **"Income from your businesses"** (read-only): `TextEntry::make('business_income')->html()->state(...)` — table of sole-prop workspaces
    with revenue (excl. VAT), deductible expenses and profit YTD from `BusinessMetrics::perEntity()`, plus a total row and the note
    "Salaries and dividends from your GmbH/AG will appear here once those business types are supported."
- `mount()`: fill from `$user->taxProfile`; defaults: canton/commune from the home address (fallback: oldest business canton), `marital_status = single`, `residence_permit = swiss`, `has_pillar2 = false`.
- `save()`: `$profile = $user->taxProfile ?? new TaxProfile;` fill allowed fields; `forceFill(['user_id' => $user->id, 'pillar3a_amount' => min(input, RateRepository::pillar3aCap(...))])->save()`;
  `RecalculatePersonalTaxEstimation::dispatch($user->id)`; notification "Tax profile saved — your estimate is being updated."
- **Tests:** AG shows communes (after F3); commune required when the canton has communes; canton change clears commune; Tariff C saved;
  every residence status saves; B/L/G show the warning; Pillar 3a clamp (35,280 / 7,056); children −5 → error;
  business income table lists only sole-prop workspaces with correct numbers; saving dispatches the personal recalculation job.

### D4 — Personal tax (`/app/tax`)

`app/Filament/Personal/Pages/PersonalTax.php`, slug `tax`, label **"Personal tax"**, group `Personal`, sort 3.
- Extract the breakdown table of `resources/views/filament/workspace/pages/tax-overview.blade.php` (after A13) into
  `resources/views/filament/shared/tax-breakdown.blade.php` (props: `$estimation`, `$pending`) and include it here with the **personal** estimation.
- "Per business" table from `inputs.businesses` (name, revenue, deductible expenses, profit, share %).
- Canton comparison (moved from the workspace page): `TaxEngine::compareCantons($user, ['ZG','ZH','LU','BE','GE','JU'] + current)`.
- Header action "Recalculate" → `estimateForUser()` synchronously + dispatch the workspace refresh job; notification.
- Gate: content only when `hasFeatureInAnyWorkspace(TaxEngine)`; otherwise upsell with a link to Billing.
- Workspace `TaxOverview` (`app/Filament/Workspace/Pages/TaxOverview.php`): show the business row (allocated) with a callout
  "This business is {share} % of your self-employment income → CHF {x} of your estimated personal tax. See your full personal tax →";
  remove its canton comparison; gate via `Filament::getTenant()->hasFeature(TaxEngine)` (E5).
- **Tests:** page shows consolidated totals; per-business shares sum to 100 %; recalculation creates a new personal row; gated for Solo-only users.

### D5 — My businesses (`/app/businesses`)

`php artisan make:filament-resource BusinessEntity --panel=app --no-interaction`, then rename to `App\Filament\Personal\Resources\Businesses\BusinessResource`
(`$slug = 'businesses'`, `$navigationLabel = 'My businesses'`, group `Businesses`, sort 1, icon `Heroicon::OutlinedBuildingOffice2`, `$recordTitleAttribute = 'name'`).
- Pages: `index` only (`ListBusinesses`). `canCreate()` → false (creation happens in C5); no edit/view/delete pages (deleting a business is out of scope — note for later).
- `getEloquentQuery()` → `->where('owner_id', auth()->id())->with(['subscription.plan'])->withSum(['invoices as revenue_ytd' => fn (Builder $q) => $q->countsAsRevenue()->whereYear('issue_date', $year)], 'subtotal')`.
- Table columns: `name` (searchable, sortable, `->description(fn ($record) => $record->type->getLabel())`), `subscription.plan.name` (label Plan), `subscription.status` (badge),
  `subscription.trial_ends_at` (label "Trial ends", `->since()`, toggleable), `revenue_ytd` (`->money('CHF')`, label "Revenue YTD (excl. VAT)"), `created_at` (date, toggleable hidden).
- Record actions: `Action::make('open')->label('Open')->icon(Heroicon::OutlinedArrowRightCircle)->url(fn (BusinessEntity $record) => Dashboard::getUrl(tenant: $record, panel: 'workspace'))`,
  `Action::make('settings')->url(fn ($record) => BusinessSettings::getUrl(tenant: $record, panel: 'workspace'))`,
  `Action::make('billing')->url(fn ($record) => Billing::getUrl(['workspace' => $record->id]))`.
  `recordUrl` → Open.
- Header action (on `ListBusinesses`): "Set up a business" → `SetUpBusiness::getUrl()`.
- Empty state: heading "No businesses yet", description "Create your first business workspace to start invoicing.", action "Set up a business".
- Authorization: `BusinessEntityPolicy::viewAny()` (owner); query scoping is the boundary.
- **Tests:** lists only own businesses; open action URL points to the workspace; empty state action.

### D6 — Workspace dashboard (`/app/w/{uuid}`) — email §4

`app/Filament/Workspace/Pages/Dashboard.php`: heading = business name; subheading `"{greeting}, {first name} · {type label}"`;
header actions "Upload receipt", "New invoice" (existing) + **"New client"**; `getColumns()` → `['md' => 2, 'xl' => 3]`.

| Widget (workspace) | Type | Sort | Span | Change |
|---|---|---|---|---|
| `BusinessOverview` | stats | 1 | full | Rework with `BusinessMetrics::forEntity()`: **Revenue YTD** (excl. VAT), **Expenses YTD** (confirmed, gross), **Profit YTD**, **Estimated tax (this business)** (allocated row) + monthly reserve in the description. |
| `ToDoWidget` | view | 2 | full | Existing + new items: "Add your IBAN to send QR invoices" (entity has no IBAN → Business settings, Invoicing tab), "Your trial ends in N days — choose a plan" (≤ 5 days → Billing), "Payment failed — update your payment method" (PastDue → Billing). Links fixed per A9. |
| `CashOverview` (new, `--stats-overview`) | stats | 3 | full | **Cash received YTD**, **Open receivables**, **Overdue** (danger color when > 0), **VAT collected YTD** (only when VAT-registered). |
| `ProfitChart` (new, `--chart`) | bar/line | 4 | `['md' => 2, 'xl' => 2]` | 12 monthly buckets for the fiscal year: revenue (excl. VAT), deductible expenses, profit line. Group in PHP (small volumes, DB-agnostic). `getType()` → `'bar'`; profit dataset `type: 'line'`. |
| `VatThresholdWidget` | view | 5 | 1 | Unchanged (now on net revenue — A14). |
| `TaxBreakdownWidget` | view | 6 | 1 | Allocated figures + link to personal tax; gate via entity feature (E5). |
| `BankAccountsWidget` (new, `--table`) | table | 7 | 1 | The entity's bank accounts: label, bank, IBAN masked (`CH93 •••• •••• 5295 7`), default icon; header action "Manage" → BankAccountResource. |
| `RecentInvoices` | table | 8 | `['md' => 2, 'xl' => 2]` | Existing; add record URL → invoice view page. |
| `RecentExpenses` (new, `--table`) | table | 9 | 1 | Latest 5 expenses: date, vendor, amount, status badge; record URL → edit. |
| `AskSettloPreview` | view | 10 | full | Unchanged (links fixed in A12/B5). |

- All new widgets filter by `Filament::getTenant()` explicitly (tenant isolation convention).
- **Tests** (`DashboardWidgetsTest`): each new widget renders with and without data; numbers match `BusinessMetrics`; another tenant's data never appears;
  IBAN to-do item appears only without IBAN; trial-ending item appears at ≤ 5 days.

### D7 — Navigation summary

| Personal panel (`/app`) | Workspace panel (`/app/w/{uuid}`) |
|---|---|
| Overview: Dashboard | Overview: ← All businesses, Dashboard |
| Businesses: My businesses, Set up a business | Finance: Invoices, Expenses, Clients, Bank accounts |
| Personal: Profile, Tax profile, Personal tax | Insights: Tax estimate, VAT summary |
| Account: Billing | Support: Ask Settlo |
| user menu: Profile, Log out | Settings: Business settings; tenant menu: switch business, All businesses, Set up another business, Billing; user menu: Personal profile, Tax profile |

---

## 8. Phase E — Per-workspace billing with Stripe

> Email §2/§6 + D1/D2/D4: **one Stripe customer per user**, **one subscription per business workspace**,
> price tier by the number of active workspace subscriptions, 14-day trial only for the first workspace.
> Built in **Stripe test mode**; the account's legal entity is still open (Q7).

### E1 — Install Cashier without clashing with the domain `subscriptions` table

```bash
composer require laravel/cashier:^16.8
php artisan vendor:publish --tag="cashier-migrations" --no-interaction
php artisan vendor:publish --tag="cashier-config" --no-interaction
```
- Cashier 16 only *publishes* its migrations (no auto-loading). Edit the published files:
  - `…_create_customer_columns.php` — keep (adds `users.stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at`).
  - `…_create_subscriptions_table.php` — table name **`stripe_subscriptions`** (up and down).
  - `…_create_subscription_items_table.php` — table **`stripe_subscription_items`**, rename `foreignId('subscription_id')` → **`foreignId('stripe_subscription_id')`**, index `['stripe_subscription_id', 'stripe_price']`.
  - `…_add_meter_id_to_subscription_items_table.php`, `…_add_meter_event_name_to_subscription_items_table.php` — table `stripe_subscription_items`.
  - Rename the published files' timestamps to run **after** `2026_07_18_105633_convert_notifications_data_to_jsonb.php`.
- Models (`make:model`, no migration):
  ```php
  class StripeSubscription extends \Laravel\Cashier\Subscription { protected $table = 'stripe_subscriptions'; }
  class StripeSubscriptionItem extends \Laravel\Cashier\SubscriptionItem { protected $table = 'stripe_subscription_items'; }
  ```
  Cashier derives the item foreign key from the parent model (`vendor/laravel/cashier/src/SubscriptionItem.php` → `(new $model)->getForeignKey()` = `stripe_subscription_id`).
- `AppServiceProvider::boot()`: `Cashier::useSubscriptionModel(StripeSubscription::class); Cashier::useSubscriptionItemModel(StripeSubscriptionItem::class);`
- `User` uses `Laravel\Cashier\Billable`. **Remove** `User::subscription()` (`app/Models/User.php:161-165`) — Cashier's trait defines
  `subscription(string $type = 'default')` and `subscriptions()`; the domain relation becomes `workspaceSubscriptions()` (E2).
- `.env.example` / `.env`:
  ```
  STRIPE_KEY=pk_test_...
  STRIPE_SECRET=sk_test_...
  STRIPE_WEBHOOK_SECRET=whsec_...
  CASHIER_CURRENCY=chf
  CASHIER_CURRENCY_LOCALE=de_CH
  SETTLO_PAYMENT_GATEWAY=stripe        # "dummy" in tests / when no keys
  STRIPE_COUPON_WORKSPACE_20=
  STRIPE_COUPON_WORKSPACE_30=
  ```
- Webhook route: Cashier registers `POST /stripe/webhook` outside the `web` group (no CSRF). For safety add
  `$middleware->preventRequestForgery(except: ['stripe/*']);` in `bootstrap/app.php` (Laravel 13 name; `validateCsrfTokens` also exists).
  Vercel already routes everything to `api/index.php`.

### E2 — Data model

**Migration `make_subscriptions_workspace_scoped`**
1. `subscriptions`: add `business_entity_id` (foreignUuid → business_entities, nullable, cascadeOnDelete), `billing_interval` string default `'month'`,
   `discount_percent` unsignedTinyInteger default 0, `unit_price` decimal(18,2) nullable (price after discount, snapshot),
   `stripe_subscription_type` string nullable (Cashier "type", format `workspace:{uuid}`); drop the unique index on `user_id` (keep a normal index).
2. Backfill: each existing subscription → the owner's **oldest** business. For every other business of that owner create a copy with the same plan/status/dates
   (so current testers keep access) and log it. Subscriptions whose user has no business are deleted.
3. `business_entity_id` → NOT NULL + unique.
4. `users`: add `trial_used_at` timestamp nullable; backfill `now()` for users that had `trial_used = true`.
5. `plans`: add `price_yearly` decimal(18,2) nullable (backfill `price_monthly × config('settlo.billing.yearly_multiplier')`),
   `stripe_product_id`, `stripe_price_monthly_id`, `stripe_price_yearly_id` (strings, nullable).
6. Down: reverse.

**Enums**
- `app/Enums/BillingInterval.php` (`php artisan make:enum BillingInterval --string --no-interaction`): `Month = 'month'` ("Monthly"), `Year = 'year'` ("Yearly (2 months free)"); implements `HasLabel`.
- `SubscriptionStatus`: add `Incomplete = 'incomplete'` — label "Payment required", color `danger`, `grantsAccess()` false.

**Config** `config/settlo.php`:
```php
'billing' => [
    'trial_days' => 14,
    'yearly_multiplier' => 10,
    // Discount (%) by position of the workspace among the owner's active subscriptions; the last entry applies to all further ones.
    'workspace_discounts' => [1 => 0, 2 => 20, 3 => 30],
    'stripe_coupons' => [
        20 => env('STRIPE_COUPON_WORKSPACE_20'),
        30 => env('STRIPE_COUPON_WORKSPACE_30'),
    ],
],
'payment_gateway' => env('SETTLO_PAYMENT_GATEWAY', 'dummy'),
```

**Models**
- `Subscription`: `businessEntity(): BelongsTo`; casts `billing_interval => BillingInterval::class`, `discount_percent => 'integer'`, `unit_price => 'decimal:2'`;
  `stripeSubscription(): ?StripeSubscription` → `$this->user?->subscription((string) $this->stripe_subscription_type)`.
- `BusinessEntity`: `subscription(): HasOne`; `planFeatures(): array` (moved from `User::planFeatures()`, trial = + Pro features);
  `hasFeature(PlanFeature $feature): bool` (respects `settlo.enforce_feature_gates`); `canWrite(): bool` → `$this->subscription?->grantsAccess() ?? false`.
- `User`: remove `planFeatures()`, `hasFeature()`, `canWrite()`; add `workspaceSubscriptions(): HasMany` (domain `Subscription` via `user_id`),
  `hasFeatureInAnyWorkspace(PlanFeature $feature): bool`, `activeWorkspaceSubscriptionCount(?BusinessEntity $except = null): int`
  (status ∈ trialing/active/past_due), `hasUsedTrial(): bool` (`trial_used_at !== null`).
- `Plan`: `priceFor(BillingInterval $interval): string`, `stripePriceId(BillingInterval $interval): ?string`.

### E3 — Services

**`app/Services/Billing/WorkspacePricing.php`**
- `discountFor(User $user, ?BusinessEntity $except = null): int` → position = `activeWorkspaceSubscriptionCount($except) + 1`; returns the tier (≥ last key → last value).
- `unitPrice(Plan $plan, BillingInterval $interval, int $discount): string` → BCMath, 2 decimals.
- `describe(Plan $plan, BillingInterval $interval, int $discount): string` → e.g. `"CHF 49 / month"`, `"CHF 490 / year · 2 months free"`, `"CHF 39.20 / month · 20 % multi-business discount"`.
- `couponFor(int $discount): ?string` → `config("settlo.billing.stripe_coupons.$discount")`.

**`app/Billing/PaymentGateway.php`** (contract — extend):
```php
public function name(): string;
public function ensureCustomer(User $user): string;
public function checkoutUrl(Subscription $subscription, string $successUrl, string $cancelUrl): string;
public function swap(Subscription $subscription, Plan $plan, BillingInterval $interval): void;
public function cancel(Subscription $subscription): void;
public function resume(Subscription $subscription): void;
public function billingPortalUrl(User $user, string $returnUrl): ?string;
public function charge(User $user, Plan $plan): ChargeResult;   // dummy renewals only
```
- **`app/Billing/StripeGateway.php`** (Cashier):
  - `ensureCustomer()` → `$user->createOrGetStripeCustomer(['name' => $user->getFilamentName(), 'email' => $user->email, 'preferred_locales' => [$user->preferred_language]])->id`.
  - `checkoutUrl()`:
    ```php
    $subscription->stripe_subscription_type ??= 'workspace:'.$subscription->business_entity_id;
    $subscription->save();

    $builder = $user->newSubscription($subscription->stripe_subscription_type, $plan->stripePriceId($subscription->billing_interval))
        ->withMetadata(['business_entity_id' => $subscription->business_entity_id, 'settlo_subscription_id' => $subscription->id]);

    if ($coupon = $this->pricing->couponFor($subscription->discount_percent)) {
        $builder->withCoupon($coupon);
    }

    if ($subscription->status === SubscriptionStatus::Trialing && $subscription->trial_ends_at?->isFuture()) {
        $builder->trialUntil($subscription->trial_ends_at);   // Cashier enforces the 48 h minimum itself
    }

    return $builder->checkout([
        'success_url' => $successUrl.'?checkout=success',
        'cancel_url' => $cancelUrl,
        'client_reference_id' => $subscription->id,
        'billing_address_collection' => 'required',
    ])->url;
    ```
    (`SubscriptionBuilder::withMetadata()` / `trialUntil()` / `checkout()` — `vendor/laravel/cashier/src/SubscriptionBuilder.php:191, 246, 376`; `Checkout::__get` proxies `url`.)
  - `swap()` → `$subscription->stripeSubscription()?->swap($plan->stripePriceId($interval))`.
  - `cancel()` → `->cancel()` (at period end); `resume()` → `->resume()`; `billingPortalUrl()` → `$user->billingPortalUrl($returnUrl)`.
  - `charge()` → throw `LogicException` (Stripe renews itself).
- **`DummyGateway`**: implement the new methods locally — `checkoutUrl()` returns `URL::signedRoute('billing.dummy-checkout', ['subscription' => $subscription->id, 'return' => $successUrl])`;
  swap/cancel/resume no-ops; portal `null`.
- Bind by `settlo.payment_gateway` in `AppServiceProvider::register()` (`'stripe' => app(StripeGateway::class)`, default dummy). Tests keep `dummy`.
- **Dummy checkout route** (`routes/web.php`, only when the gateway is dummy): `Route::middleware(['auth', 'signed'])->get('/billing/dummy-checkout/{subscription}', DummyCheckoutController::class)->name('billing.dummy-checkout');`
  controller: owner check → `SubscriptionService::activate($subscription)` → redirect to `return`.

**`app/Services/Billing/SubscriptionService.php`** (rework):
- `startWorkspaceSubscription(BusinessEntity $entity, Plan $plan, BillingInterval $interval): Subscription`
  - `$discount = $pricing->discountFor($entity->owner, except: $entity)`; `$unit = $pricing->unitPrice(...)`.
  - First workspace and `! $owner->hasUsedTrial()` → status **Trialing**, `trial_starts_at`/`trial_ends_at` (+14 d), quota from plan, `forceFill(['trial_used_at' => now()])` on the owner.
  - Otherwise → status **Incomplete** (no access until checkout completes), quota 0.
  - Always: `user_id` = owner, `business_entity_id`, `plan_id`, `billing_interval`, `discount_percent`, `unit_price`, `gateway` = gateway name.
- `checkoutUrl(Subscription $s, string $successUrl, string $cancelUrl): string` → gateway.
- `changePlan(Subscription $s, Plan $plan, BillingInterval $interval)`: Stripe → `gateway->swap()` (Stripe prorates; the webhook syncs); dummy → existing upgrade/downgrade logic; always update `plan_id`, `billing_interval`, `unit_price`, quota.
- `cancel()` / `resume()` → gateway + local flags (existing).
- `syncFromStripe(Subscription $s, array $stripeObject): Subscription` — map `status`:
  `trialing→Trialing`, `active→Active`, `past_due|unpaid→PastDue`, `incomplete→Incomplete`, `incomplete_expired|canceled→Cancelled` (and `Expired` once `ended_at` is past);
  periods from `items.data.0.current_period_start/end` (newer Stripe API versions) falling back to top-level `current_period_*`;
  `cancel_at_period_end`, `canceled_at`; plan from the price id (`Plan::where('stripe_price_monthly_id', …)->orWhere('stripe_price_yearly_id', …)`); reset quota when the period changes.
- `recordStripePayment(Subscription $s, array $invoice)` → `SubscriptionPayment` (`amount = amount_paid / 100`, currency upper, `gateway = 'stripe'`, `gateway_reference = invoice id`, `paid_at`, period from `lines.data.0.period`). Idempotent on `gateway_reference`.
- `consumeHumanAnswer()` / `resetQuota()` unchanged (now per workspace).

**Webhooks** — `app/Listeners/HandleStripeWebhook.php` listening to `Laravel\Cashier\Events\WebhookHandled` (auto-discovered):
- `customer.subscription.created|updated|deleted` → find the domain subscription by `data.object.metadata.settlo_subscription_id` (fallback: `stripe_subscription_type` = `metadata.type`) → `syncFromStripe()`.
- `invoice.payment_succeeded` → `recordStripePayment()` (subscription found via `data.object.parent.subscription_details.metadata` or the Cashier row's type).
- `invoice.payment_failed` → status PastDue + database notification to the owner ("Payment failed for {business}. Update your payment method." with a Billing link).
- Stripe dashboard (test mode) webhook events: `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`,
  `customer.updated`, `customer.deleted`, `payment_method.automatically_updated`, `invoice.payment_action_required`, `invoice.payment_succeeded`, `invoice.payment_failed`
  (`php artisan cashier:webhook` creates the endpoint). Local: `stripe listen --forward-to http://localhost:8001/stripe/webhook` (Stripe CLI isn't installed yet — Homebrew is missing; download the binary from stripe.com).

**Scheduled commands** (`app/Console/Commands`):
- `RenewSubscriptions` → only `gateway = 'dummy'`.
- `ExpireTrials` → trialing, `trial_ends_at` past, **no** `stripe_subscription_type` with an active Cashier subscription → `Expired` + owner notification "Your trial for {business} ended — choose a plan to keep working" (Billing link).
- `ResetHumanAnswerQuotas` unchanged.

**Plan sync command** — `php artisan make:command SyncStripePlans --no-interaction` → `settlo:stripe-sync-plans`: for each active plan create/update a Stripe product
(`metadata.settlo_plan_code`) and two prices (`lookup_key` `settlo_{code}_month` / `settlo_{code}_year`, CHF, recurring, unit amount in Rappen); create the
coupons `settlo-workspace-20` / `-30` (percent_off, duration forever) if missing and print their ids; store ids on `plans`. Idempotent.

### E4 — Filament billing surfaces

- **Workspace panel** (B1): `->tenantBillingProvider(new WorkspaceBillingProvider)->requiresTenantSubscription()`.
  `app/Billing/WorkspaceBillingProvider.php implements Filament\Billing\Providers\Contracts\BillingProvider`:
  - `getRouteAction()` → `App\Http\Controllers\WorkspaceBillingRedirectController::class` (redirects `/app/w/{uuid}/billing` to `Billing::getUrl(['workspace' => $tenant->id], panel: 'app')`).
  - `getSubscribedMiddleware()` → `App\Http\Middleware\EnsureWorkspaceSubscription::class`: if the tenant has no subscription or it is **Incomplete** → redirect to the billing redirect URL with a warning flash;
    Expired/Cancelled → allowed (read-only; policies block writes) and a render hook shows a banner.
  - Filament adds a "Billing" tenant-menu entry automatically.
- **Expired banner**: `FilamentView::registerRenderHook(PanelsRenderHook::CONTENT_START, …, scopes: …)` on the workspace panel → amber bar
  "Your subscription for {business} has ended. Your data is read-only. [Choose a plan]" when `! $tenant->canWrite()`.
- **Personal Billing page** `app/Filament/Personal/Pages/Billing.php`, slug `billing`, group `Account`, icon `Heroicon::OutlinedCreditCard`:
  - Intro text: "Each business has its own plan. Your 2nd business gets 20 % off, your 3rd and later 30 %." (values from config).
  - Table (implement `HasTable` on the page, query `Subscription::where('user_id', auth()->id())->with(['businessEntity', 'plan'])`):
    columns business name, plan, interval, `unit_price` (money) + discount badge, status badge, `trial_ends_at` / `current_period_end` ("Renews"/"Ends").
  - Row actions:
    - **Choose plan** (visible for Trialing/Incomplete/Expired/Cancelled): modal with the plan `Radio` + interval `ToggleButtons` (C5 fields) →
      update plan/interval/unit price/discount (recomputed with `except: entity`) → `redirect()->away(checkoutUrl(...))`.
    - **Change plan** (Active/PastDue): same modal → `changePlan()`; notification.
    - **Cancel** (Active, not already cancelling) → `requiresConfirmation()` → `cancel()`; **Resume** when `cancel_at_period_end`.
  - Header action **Payment methods & invoices** → `redirect()->away(gateway->billingPortalUrl($user, Billing::getUrl()))` (hidden for dummy).
  - `mount()`: `?workspace={uuid}` → `$this->mountTableAction('choosePlan', $subscriptionId)`; `?checkout=success` → info notification "Payment received — your business will be active in a few seconds."
  - Authorization: owner; every action re-checks `$record->user_id === auth()->id()`.
- **Policies & gates** (write access now per workspace):
  - New `app/Support/CurrentWorkspace.php` → `entity(): ?BusinessEntity` (`Filament::getTenant()` when it is a `BusinessEntity`).
  - `ClientPolicy`, `ExpensePolicy`, `InvoicePolicy`, `AiConversationPolicy`, `AiMessagePolicy`, `AiEscalationPolicy`:
    `create()` → `$user->isOwner() && (CurrentWorkspace::entity()?->canWrite() ?? false)`;
    record abilities → replace `$this->owns($user, $record) && $user->canWrite()` with
    `($entity = $this->ownedEntity($user, $record->business_entity_id)) !== null && $entity->canWrite()` (`ownedEntity()` returns the owned `BusinessEntity` or null).
  - `TaxOverview::canAccess()`, `TaxBreakdownWidget::canView()` → `CurrentWorkspace::entity()?->hasFeature(PlanFeature::TaxEngine) ?? false`.
  - `AskSettloController`: `authorizeEntityAccess()` → `abort_unless($businessEntity->subscription?->grantsAccess() ?? false, 403)`;
    `presentQuota(User)` → `presentQuota(BusinessEntity)` using the entity subscription; `canEscalate` → `$businessEntity->hasFeature(PlanFeature::AccountantAccess)`.
  - `EscalationService::escalate()` → feature + quota from `$conversation->businessEntity->subscription` (lock that row).
- **Admin panel**:
  - `SubscriptionsTable` / `SubscriptionInfolist`: add Business, Interval, Discount, Unit price, Stripe type columns; filters by status/interval; existing actions (extend trial, comp, cancel) keep working per workspace.
  - `SubscriptionPaymentsTable`: add `subscription.businessEntity.name`.
  - `MrrOverview`: MRR = Σ `unit_price` of active monthly + Σ (`unit_price` / 12) of active yearly.
  - `UsersTable`: column "Businesses" (`ownedEntities` count).
- **Seeds/factories**: `SubscriptionFactory` → `'business_entity_id' => BusinessEntity::factory()`, `user_id` from the entity owner (closure attribute), states `forEntity(BusinessEntity $e)`, `incomplete()`, `yearly()`;
  `PlanSeeder` adds `price_yearly` (190/490/990); `DemoSeeder` attaches Anna's trial to her entity and sets `trial_used_at`.

### E5 — Tests (`tests/Feature/BillingTest.php`, `WorkspaceBillingTest.php`, `StripeWebhookTest.php`)

- first workspace → Trialing 14 days, `trial_used_at` set, discount 0; second → Incomplete, discount 20, unit price 39.20 (Pro monthly); third → 30 % (34.30); yearly Pro first → 490.
- cancelling the 2nd subscription doesn't change the 3rd's locked discount (P5).
- Incomplete workspace: every workspace URL redirects to billing; Expired: pages load, create/edit actions forbidden (policies), banner visible.
- dummy checkout: signed URL activates and redirects; unsigned → 403; other user → 403.
- plan change / cancel / resume via the Billing page actions (`callAction(TestAction::make('changePlan')->table($subscription), data: [...])`).
- webhook: post a `customer.subscription.updated` JSON to `/stripe/webhook` (no secret in tests → Cashier skips the signature check) for a user with `stripe_id`
  → domain status/period synced; `invoice.payment_succeeded` creates one `SubscriptionPayment` (idempotent on repeat); `invoice.payment_failed` → PastDue + notification.
- policies: owner of an expired workspace can view but not create invoices; another active workspace of the same owner is unaffected.
- Ask Settlo quota is per workspace (escalation on workspace A doesn't consume B's quota).
- `StripeGateway` is not called in tests; add a unit test with a mocked `User` builder only if practical — otherwise document the manual test-mode run:
  1) `settlo:stripe-sync-plans`, 2) register, 3) create a 2nd business → Stripe Checkout with card `4242 4242 4242 4242`, 4) webhook arrives → business active, 5) Billing portal opens.

---

## 9. Phase F — Swiss data lookups

### F3 — Full commune dataset (BUG-12, BUG-34, BUG-58 part 3) — *do together with Phase A*

- **Cause (✅):** `database/seeders/CommuneSeeder.php:18-25` seeds only six communes (Zürich 261, Küsnacht 154, Winterthur 230, Zug 1711, Genève 6621, Basel 2701), so every other canton (AG included) shows "No options available".
- **Source (verified 2026-09-17):** BFS *Schweizerisches Gemeindeverzeichnis* snapshot API
  `https://www.agvchapp.bfs.admin.ch/api/communes/snapshot?date=01-01-2026` → CSV header
  `HistoricalCode,BfsCode,ValidFrom,ValidTo,Level,Parent,Name,ShortName,Inscription,Radiation,Rec_Type_fr,Rec_Type_de`;
  `Level 1` = 26 cantons (`ShortName` = canton code), `Level 2` = 144 districts, `Level 3` = **2,110 communes**;
  a commune's `Parent` is its district's `HistoricalCode`, whose `Parent` is the canton's `HistoricalCode`. Example: `Aarau`, BFS `4001`, canton AG.
- **Migration** `add_multiplier_is_estimated_to_communes_table`: `boolean('multiplier_is_estimated')->default(false)`.
- **Command** `php artisan make:command ImportCommunes --no-interaction` → signature
  `settlo:import-communes {--date=01-01-2026} {--file= : read a local CSV instead of downloading} {--export= : write database/data/communes.csv}`:
  1. Download with `Http::timeout(30)->get(...)` (or read `--file`); parse with `str_getcsv` (strip the UTF-8 BOM).
  2. Build maps `HistoricalCode → row`; resolve each Level-3 row's canton code by walking `Parent` twice.
  3. Upsert `communes` on (`canton_id`, `bfs_number`): `name`, `effective_from = --date`, `effective_to = null`;
     `tax_multiplier` = existing value when the row exists, otherwise the canton's `canton_fiscal_configs.communal_multiplier_default` (fiscal year from the date) with `multiplier_is_estimated = true`.
  4. Communes that disappeared from the snapshot → `effective_to = date − 1 day` (never delete: tax profiles reference them).
  5. `--export` writes `database/data/communes.csv` (`bfs_number,name,canton_code`) sorted by canton then name.
- **Seeder:** run the command once with `--export` and commit `database/data/communes.csv` (~2,110 lines). Rewrite `CommuneSeeder` to read that file
  (offline, deterministic in CI) and upsert as above, then apply the six known multipliers (existing array) with `multiplier_is_estimated = false`.
- **UI:** commune selects only list `effective_to IS NULL`; estimated multipliers show the hint from D3; Admin `CommuneResource` gets a `TernaryFilter` on `multiplier_is_estimated` and an editable toggle.
- **Tax engine:** unchanged (`communeMultiplier` from the commune); pages show the "estimated" hint (Q11). A later task can import real Steuerfüsse from ESTV.
- **Tests:** `DataLayerSeedTest` → ≥ 2,100 communes, every canton has ≥ 1 commune, `Aarau` (4001) belongs to AG, the six known multipliers are intact and not estimated;
  importer unit test with a 6-line CSV fixture (1 canton, 1 district, 2 communes + a removed one) → canton resolution, estimated flag, `effective_to` on removal.

### F1 — Address autocomplete & postal-code autofill (BUG-11)

**F1a — Address search (street + number → postal code, city, canton, commune)**
- **Source (verified):** swisstopo GeoAdmin SearchServer (free, no key)
  `GET https://api3.geo.admin.ch/rest/services/api/SearchServer?searchText={q}&type=locations&origins=address&limit=8&sr=4326`.
  Each result has `attrs.label` = `"Bahnhofstrasse 10 <b>5000 Aarau</b>"`, `attrs.detail` = `"bahnhofstrasse 10 5000 aarau 4001 aarau ch ag"` (lower-case,
  … `{BFS number} {commune} ch {canton}`), `attrs.featureId` = `"522931_0"`.
- Service `app/Services/Geo/SwissAddressSearch.php` + DTO `app/Services/Geo/AddressSuggestion.php` (`id, street, streetNumber, postalCode, city, cantonCode, bfsNumber, label`):
  - `search(string $query, int $limit = 8): array` — ignore queries shorter than 3 chars; `Http::timeout(5)->retry(1, 200, throw: false)`; on failure return `[]` (never block the form);
    cache results 1 day (`geo:address:{md5}`) and each suggestion 1 day by id (`geo:address:item:{featureId}`).
  - Parse: `label` → `strip_tags` → `/^(?<street>.+?)(?:\s+(?<number>\d+\w*))?\s+(?<zip>\d{4})\s+(?<city>.+)$/u`;
    `detail` → `/\s(?<bfs>\d{1,4})\s.+\sch\s(?<canton>[a-z]{2})$/` → upper-case canton.
  - `find(string $id): ?AddressSuggestion` → from cache.
- Field builder `app/Filament/Shared/Fields/SwissAddressFields.php` → `make(bool $withCommune = false, bool $required = true): array`:
  ```php
  Select::make('address_search')
      ->label('Search your address')
      ->placeholder('Start typing street and number…')
      ->searchable()
      ->getSearchResultsUsing(fn (string $search): array => collect(app(SwissAddressSearch::class)->search($search))->mapWithKeys(fn (AddressSuggestion $s) => [$s->id => $s->label])->all())
      ->getOptionLabelUsing(fn (?string $value): ?string => $value ? app(SwissAddressSearch::class)->find($value)?->label : null)
      ->live()
      ->dehydrated(false)
      ->afterStateUpdated(function (?string $state, Set $set) use ($withCommune): void {
          $s = $state ? app(SwissAddressSearch::class)->find($state) : null;
          if ($s === null) { return; }
          $set('street', $s->street); $set('street_number', $s->streetNumber); $set('postal_code', $s->postalCode); $set('city', $s->city);
          $canton = Canton::where('code', $s->cantonCode)->first();
          $set('canton_id', $canton?->getKey());
          if ($withCommune) { $set('commune_id', Commune::where('canton_id', $canton?->getKey())->where('bfs_number', $s->bfsNumber)->value('id')); }
      })
      ->columnSpanFull(),
  TextInput::make('street')->required($required)->maxLength(255)->autocomplete('address-line1')->tap(new ValidatesOnBlur),
  TextInput::make('street_number')->label('No.')->required($required)->maxLength(20)->tap(new ValidatesOnBlur),
  TextInput::make('postal_code')->required($required)->rule(new SwissPostalCode)->autocomplete('postal-code')->live(onBlur: true)->afterStateUpdated(/* F1b */)->tap(new ValidatesOnBlur),
  TextInput::make('city')->required($required)->maxLength(255)->autocomplete('address-level2')->tap(new ValidatesOnBlur),
  CantonSelect::make('canton_id')->required($required),
  // + CommuneSelect::make('commune_id', cantonField: 'canton_id') when $withCommune
  ```
  Used by: Personal profile (with commune), Set up a business step 1, Business settings profile form, Client form (without canton; `required: false`).
- **Tests:** `Http::fake()` with the sample JSON (Appendix 11.2) → parsed suggestion; selecting it fills street/no./PLZ/city/canton(/commune);
  HTTP 500 → no options and no exception; query < 3 chars → no HTTP call.

**F1b — Postal code → city, canton, commune (offline)**
- **Source (verified):** swisstopo *Amtliches Ortschaftenverzeichnis* CSV
  `https://data.geo.admin.ch/ch.swisstopo-vd.ortschaftenverzeichnis_plz/ortschaftenverzeichnis_plz/ortschaftenverzeichnis_plz_4326.csv.zip`
  (file `AMTOVZ_CSV_WGS84/AMTOVZ_CSV_WGS84.csv`, `;`-separated, 5,718 rows) with header
  `Ortschaftsname;PLZ4;Zusatzziffer;ZIP_ID;Gemeindename;BFS-Nr;Kantonskürzel;Adressenanteil;E;N;Sprache;Validity`.
- Migration `create_postal_codes_table`: `id`, `postal_code` string(4) index, `locality` string, `bfs_number` string, `canton_code` string(2), `address_share` decimal(5,2), timestamps; unique (`postal_code`, `locality`, `bfs_number`).
- Command `settlo:import-postal-codes {--file=} {--export=}` (download zip → unzip in `storage/app/tmp` → upsert; `Adressenanteil` "100 %" → 100.00) and a committed
  `database/data/postal_codes.csv` read by a new `PostalCodeSeeder` (add to `ReferenceDataSeeder`).
- `PostalCode::bestMatch(string $plz): ?PostalCode` → highest `address_share`.
- `postal_code` `afterStateUpdated`: when valid and `city`/`canton_id` are empty (or were auto-filled before), set `city = locality`, `canton_id` from `canton_code`, and (`$withCommune`) `commune_id` from `bfs_number`.
- **Tests:** seeder imports rows; `5000` → Aarau / AG / 4001; typing `8001` fills Zürich / ZH; existing manual city isn't overwritten.

### F2 — UID register lookup (BUG-58 part 2)

- **Source (verified):** UID-Register public web service (SOAP 1.1, no key, ~20 requests/min per IP)
  `POST https://www.uid-wse.admin.ch/V5.0/PublicServices.svc`, headers `Content-Type: text/xml; charset=utf-8`,
  `SOAPAction: "http://www.uid.admin.ch/xmlns/uid-wse/IPublicServices/GetByUID"`, body in Appendix 11.3.
  The response (Appendix 11.3) contains `organisationName`, `organisationLegalName`, `legalForm` (eCH-0097 code, e.g. `0101` sole proprietorship,
  `0106` AG, `0107` GmbH, `0108` cooperative), address (`street`, `houseNumber`, `swissZipCode`, `town`, `municipalityId` = BFS no., `cantonAbbreviation`)
  and `vatRegisterInformation` (`vatStatus`, `uidVat`).
- Service `app/Services/Registry/UidRegister.php` + DTO `UidRecord`:
  - `lookup(string $uid): ?UidRecord` — `SwissUid::normalize()` first (invalid → null); raw SOAP via `Http::withHeaders(...)->withBody($xml, 'text/xml; charset=utf-8')->timeout(8)->post(...)` (no `ext-soap` needed — Vercel's PHP runtime may not have it);
    parse with `DOMDocument` + `DOMXPath` using `//*[local-name()="organisationName"]` style queries; SOAP fault / no organisation → null; HTTP error → throw `UidRegisterUnavailable`.
  - Cache 1 day per UID; per-user limiter `RateLimiter::attempt('uid-lookup:'.$userId, 5, …, 60)`.
  - `UidRecord::businessType(): ?BusinessEntityType` → `0101 → SoleProprietorship`, `0106 → AG`, `0107 → GmbH`, else null.
  - `UidRecord::vatNumber(): ?string` → `CHE-xxx.xxx.xxx MWST` when `uidVat` present and `vatStatus` is active (treat `2` as active — verify against the eCH-0108 code list before release).
- UI (in `BusinessProfileFields` when `$withUidLookup`): `uid` gets
  `->suffixAction(Action::make('lookupUid')->icon(Heroicon::OutlinedMagnifyingGlass)->tooltip('Fill from the Swiss UID register')->action(fn (Get $get, Set $set) => self::fillFromRegister($get, $set)))`
  and an `afterStateUpdated` that calls the same method once the UID is valid (debounced by `ValidatesOnBlur(500)`).
  `fillFromRegister()`: sets `name` (legal name), `type` (if supported; else a warning notification "GmbH and AG are coming soon — you can continue as a sole proprietorship later"),
  address fields, `canton_id`, `vat_status` (`RegisteredMandatory` when VAT active) and `mwst_number`; success notification "Filled from the UID register — please check the details."
  Not found → warning "We couldn't find this UID. Please fill in the details manually." Unavailable → warning "The UID register isn't reachable right now…".
- **Tests:** `Http::fake()` with the XML sample → DTO values (name, legal form, address, canton, BFS, VAT); SOAP fault → null; 503 → exception → warning notification;
  wizard: setting a valid UID fills the fields; rate limit → warning.

---

## 10. Test plan summary & regression checklist

**Automated (Pest)** — new/updated files:

| File | Covers |
|---|---|
| `tests/Feature/FormValidationUxTest.php` | A1–A3 (novalidate, acronym attributes, on-blur validation) |
| `tests/Feature/AskSettloHttpTest.php` | A4 (escalation name, no `name` SQL), A12 (`?q=` passthrough, short VAT label), B5 URLs, E5 quota per workspace |
| `tests/Feature/AskSettloServiceTest.php` | A5 (token budget, thinking level, MAX_TOKENS notice, SAFETY) |
| `tests/Feature/BusinessSettingsTest.php` | A6 (IBAN reload, independent forms), B4 (VAT fields on profile) |
| `tests/Feature/BankAccountsTest.php` | A7 |
| `tests/Feature/InvoiceResourceTest.php`, `InvoiceViewTest.php`, `tests/Unit/InvoiceTotalsTest.php` | A8, A9, A14 |
| `tests/Feature/ExpenseResourceTest.php`, `VatSummaryTest.php`, `TaxOverviewTest.php` | A10, A11 |
| `tests/Unit/TaxCalculatorTest.php`, `tests/Feature/TaxEngineTest.php` | A13, A14, A20, B3 |
| `tests/Feature/RegistrationTest.php` | A15, A22, C1, C2, C3 |
| `tests/Unit/SwissRulesTest.php`, `ClientResourceTest.php` | A16, A17 |
| `tests/Feature/PhoneVerificationTest.php` | C4 |
| `tests/Feature/SetUpBusinessTest.php` (replaces `OnboardingTest.php`) | A21, C5 |
| `tests/Feature/PanelAccessTest.php`, `PanelLoginRedirectTest.php`, `WorkspaceTenancyTest.php` | B1, B6, B7 |
| `tests/Feature/PersonalAreaTest.php`, `DashboardWidgetsTest.php`, `tests/Feature/BusinessMetricsTest.php` | D0–D6 |
| `tests/Feature/BillingTest.php`, `WorkspaceBillingTest.php`, `StripeWebhookTest.php` | E1–E5 |
| `tests/Feature/DataLayerSeedTest.php`, `ImportCommunesTest.php`, `SwissAddressSearchTest.php`, `UidRegisterTest.php` | F1–F3 |

Use `callAction(TestAction::make('name')->table($record), data: [...])` for table actions and `Repeater::fake()` for repeaters.
Run the full suite after each phase, plus the Postgres run (A4.3) before deploying.

**Manual checks (log in yourself — agents must not type passwords):**
1. Register → verification mail link (log) → personal dashboard checklist.
2. Personal profile address search; tax profile for an AG commune; Tariff C; B-permit warning.
3. Set up a business: Next disabled until valid; skip on each step; UID autofill; first trial; second business → Stripe test checkout (`4242 4242 4242 4242`) → active after webhook.
4. Workspace dashboard at 1280 / 1600 px; invoice create with two lines (live total), view page actions, send, PDF (non-VAT business shows "Not subject to VAT").
5. Expenses: manual entry validation, VAT auto-calc, inline confirm, VAT summary notice.
6. Ask Settlo at 1114 / 1280 / 1600 px: no overlap; `?q=` from dashboard starts a chat; long answer complete; "Verify with accountant" works on Postgres.
7. Billing page: change plan, cancel/resume, portal link; expired workspace is read-only with banner.

**Docs to update when done:** `PROGRESS.md` (phases, test count, new env vars), `.env.example`, and `PLAN.md` §1 (panels table).

---

## 11. Appendix

### 11.1 Gemini request after A5

```json
{
  "system_instruction": { "parts": [{ "text": "…Settlo AI system prompt…" }] },
  "contents": [{ "role": "user", "parts": [{ "text": "What business expenses can I deduct?" }] }],
  "generationConfig": { "maxOutputTokens": 8192, "thinkingConfig": { "thinkingLevel": "low" } }
}
```
Observed on 2026-09-17 (gemini-3.5-flash): with `maxOutputTokens: 1024` → `finishReason: MAX_TOKENS`, `thoughtsTokenCount: 982`, `candidatesTokenCount: 38`;
with the config above → `finishReason: STOP`, `candidatesTokenCount: 448`, `thoughtsTokenCount: 1173`.

### 11.2 GeoAdmin SearchServer sample (trimmed)

```json
{
  "results": [
    {
      "id": 2873011,
      "attrs": {
        "origin": "address",
        "label": "Dammstrasse 1 <b>8037 Zürich</b>",
        "detail": "dammstrasse 1 8037 zuerich 261 zuerich ch zh",
        "featureId": "167002_0",
        "lat": 47.39091110229492,
        "lon": 8.528008460998535
      }
    },
    {
      "id": 522931,
      "attrs": {
        "origin": "address",
        "label": "Bahnhofstrasse 10 <b>5000 Aarau</b>",
        "detail": "bahnhofstrasse 10 5000 aarau 4001 aarau ch ag",
        "featureId": "522931_0"
      }
    }
  ]
}
```

### 11.3 UID-WSE `GetByUID`

Request:
```xml
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:uid="http://www.uid.admin.ch/xmlns/uid-wse"
                  xmlns:ns="http://www.ech.ch/xmlns/eCH-0097/5">
  <soapenv:Header/>
  <soapenv:Body>
    <uid:GetByUID>
      <uid:uid>
        <ns:uidOrganisationIdCategorie>CHE</ns:uidOrganisationIdCategorie>
        <ns:uidOrganisationId>105829940</ns:uidOrganisationId>
      </uid:uid>
    </uid:GetByUID>
  </soapenv:Body>
</soapenv:Envelope>
```
Response (namespaces stripped, trimmed):
```xml
<GetByUIDResponse><GetByUIDResult><organisationType>
  <organisation>
    <organisationIdentification>
      <uid><uidOrganisationIdCategorie>CHE</uidOrganisationIdCategorie><uidOrganisationId>105829940</uidOrganisationId></uid>
      <organisationName>Migros-Genossenschafts-Bund</organisationName>
      <organisationLegalName>Migros-Genossenschafts-Bund</organisationLegalName>
      <legalForm>0108</legalForm>
    </organisationIdentification>
    <address>
      <addressCategory>LEGAL</addressCategory>
      <street>Limmatstrasse</street><houseNumber>152</houseNumber>
      <town>Zürich</town><swissZipCode>8005</swissZipCode>
      <municipalityId>261</municipalityId><cantonAbbreviation>ZH</cantonAbbreviation>
      <countryIdISO2>CH</countryIdISO2>
    </address>
  </organisation>
  <uidregInformation><uidregStatusEnterpriseDetail>3</uidregStatusEnterpriseDetail><uidregPublicStatus>1</uidregPublicStatus></uidregInformation>
  <vatRegisterInformation>
    <vatStatus>2</vatStatus><vatEntryStatus>1</vatEntryStatus><vatEntryDate>1968-01-01</vatEntryDate>
    <uidVat><uidOrganisationIdCategorie>CHE</uidOrganisationIdCategorie><uidOrganisationId>105829940</uidOrganisationId></uidVat>
  </vatRegisterInformation>
</organisationType></GetByUIDResult></GetByUIDResponse>
```

### 11.4 UID check digit (eCH-0097)

```
digits  d1..d9  (e.g. 1 0 5 8 2 9 9 4 | 0)
weights 5 4 3 2 7 6 5 4
sum = Σ d_i × w_i            → 1×5 + 0×4 + 5×3 + 8×2 + 2×7 + 9×6 + 9×5 + 4×4 = 165
check = 11 − (sum mod 11)    → 11 − 0 = 11 → 0      (10 = invalid UID)
valid when check == d9       → CHE-105.829.940 ✔ ; CHE-148.830.302 ✔ ; CHE-123.456.789 ✘ (expects 8)
```

### 11.5 BFS commune snapshot (first lines)

```
HistoricalCode,BfsCode,ValidFrom,ValidTo,Level,Parent,Name,ShortName,Inscription,Radiation,Rec_Type_fr,Rec_Type_de
1,1,12.09.1848,,1,,Zürich,ZH,,,,
10053,101,12.09.1848,,2,1,Bezirk Affoltern,Affoltern,100,,,
10575,13,12.09.1848,,3,10053,Stallikon,Stallikon,,,,
15385,4001,01.01.2010,,3,10026,Aarau,Aarau,3167,,,
```

### 11.6 Files created by this plan (overview)

```
app/Filament/Support/ValidatesOnBlur.php
app/Filament/Shared/Fields/{PhoneNumberField,SwissAddressFields,CantonSelect,CommuneSelect}.php
app/Filament/Shared/Schemas/{BusinessProfileFields,InvoicingDefaultsFields,TaxProfileFields}.php
app/Filament/Personal/Auth/Register.php                      (moved)
app/Filament/Personal/Pages/{PersonalDashboard,PersonalProfile,EditTaxProfile,PersonalTax,SetUpBusiness,Billing,VerifyPhone}.php
app/Filament/Personal/Resources/Businesses/BusinessResource.php (+ Pages/ListBusinesses.php)
app/Filament/Personal/Widgets/{OnboardingChecklist,WorkspacesOverview,ConsolidatedFinancials,PersonalTaxSummary,TaxProfileCard}.php
app/Filament/Workspace/**                                     (moved from app/Filament/App)
app/Filament/Workspace/Resources/Invoices/Actions/InvoiceActions.php
app/Filament/Workspace/Widgets/{CashOverview,ProfitChart,BankAccountsWidget,RecentExpenses}.php
app/Providers/Filament/WorkspacePanelProvider.php
app/Http/Middleware/{AuthenticateWorkspace,RememberLastWorkspace,EnsurePhoneIsVerified,EnsureWorkspaceSubscription}.php
app/Http/Controllers/{RedirectToWorkspaceController,WorkspaceBillingRedirectController,DummyCheckoutController}.php
app/Billing/{StripeGateway,WorkspaceBillingProvider}.php
app/Enums/BillingInterval.php
app/Models/{StripeSubscription,StripeSubscriptionItem,PostalCode}.php
app/Rules/{SwissPostalCode,SwissUid,SwissVatNumber}.php
app/Services/Invoicing/InvoiceTotals.php
app/Services/Billing/WorkspacePricing.php
app/Services/Workspaces/WorkspaceProvisioner.php
app/Services/Reporting/{BusinessMetrics,BusinessMetricsResult}.php
app/Services/Phone/{PhoneVerifier,LogPhoneVerifier}.php
app/Services/Geo/{SwissAddressSearch,AddressSuggestion}.php
app/Services/Registry/{UidRegister,UidRecord,UidRegisterUnavailable}.php
app/Support/{PhoneCountries,CurrentWorkspace}.php
app/Jobs/RecalculatePersonalTaxEstimation.php
app/Listeners/HandleStripeWebhook.php
app/Console/Commands/{ImportCommunes,ImportPostalCodes,SyncStripePlans}.php
database/data/{communes,postal_codes}.csv
resources/views/filament/personal/**, resources/views/filament/shared/tax-breakdown.blade.php
```
