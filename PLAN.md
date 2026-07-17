# Settlo — Build Plan (Laravel 13 + Filament v5)

Swiss business platform for self-employed professionals. Invoicing (Swiss QR-bill), expenses (OCR + AI categorization), canton-aware tax engine, AI assistant with accountant escalation, subscription packages (dummy billing for now), accounting-firm (Treuhand) access, superadmin control panel with impersonation.

Sources: `settlo-specs/` (business spec, backlog, tax engine v2.0, prisma schema, mockups). Where sources conflict, **Tax Engine Algorithms v2.0 is authoritative** for all tax math; the product owner's instructions override stack choices (Laravel + Filament panels, not NestJS/React).

---

## 1. Architecture

### Panels (Filament v5)
| Panel | Path | Users | Tenancy |
|---|---|---|---|
| **App** | `/app` | Self-employed owners (`role: owner`) | Tenant = `BusinessEntity` (multi-entity ready, one entity in POC UI) |
| **Firm** | `/firm` | Accountants (`role: accountant`) | Tenant = `AccountingFirm` |
| **Superadmin** | `/admin` | Platform staff (`role: superadmin`) | None (global) |

- Auth: Laravel session auth via Filament. Single `users` table, `role` enum: `owner`, `accountant`, `superadmin`.
- Panel access via `FilamentUser::canAccessPanel()` per role. Impersonation bypasses for superadmin.
- Queue: `database` driver + scheduler (overdue invoices daily, quota reset monthly, trial expiry daily, weekly tax refresh, dummy billing renewals).
- Money: `DECIMAL(18,2)`, BCMath for all tax math (never float). Rates `DECIMAL(8,4)`.
- Branding: primary `#00A878`, dark `#0D1F2D`, warn `#F59E0B`, danger `#E24B4A`; Settlo logo from `settlo-specs/settlo-logos/`; custom Filament theme per panel (app = green/light, superadmin = distinct dark accent).

### New dependencies (require approval)
- `sprain/swiss-qr-bill` — SIX-compliant Swiss QR-bill generation (the PHP standard for this).
- `barryvdh/laravel-dompdf` — invoice PDF rendering (QR-bill payment part embedded as HTML/SVG).
- Anthropic API via Laravel `Http` client directly — **no SDK dependency**. Key in `.env` (`ANTHROPIC_API_KEY`); graceful fixture fallback when absent.

## 2. Data model (≈30 tables)

**Identity & tenancy**: `users` (role, status, language, phone) · `business_entities` (owner, name, type=sole_prop, UID, address, banking defaults: IBAN, payment terms, currency, invoice language + prefix, logo) · `tax_profiles` (1:1 entity: canton, commune, marital status → tariff A/B/H, children, residence permit incl. B-permit stop, pillar 3a + has_pillar2, kirchensteuer, birth year, VAT status [not_registered / registered_voluntary / registered_mandatory / exempt], employment income fields, estimated annual revenue) · `accounting_firms` · `accounting_firm_members` · `accountant_assignments` (firm ↔ business grant, optional named accountant, revocable) · `firm_client_invitations` (token-based email invite → owner registers, business auto-assigned).

**Billing (packages)**: `plans` (code solo/pro/confidence, CHF 19/49/99, features JSON, human_answers_quota 0/1/3, trial_days 14, active, sort) · `subscriptions` (user, plan, status: trialing→active→past_due→cancelled→expired, trial dates, period dates, cancel_at_period_end, quota used/reset, gateway + gateway refs nullable for Stripe later) · `subscription_payments` (dummy ledger: amount, status, paid_at, reference).
- `PaymentGateway` contract + `DummyGateway` (instant success; monthly renewal command writes ledger rows). Stripe drops in later behind the same contract.
- Gating: `PlanFeature` enum (tax_engine, accountant_access, year_end_export, vat_form_300, annual_review, priority_response) checked via policies/middleware + Filament visibility. **Gates enforced**; plan features editable live in superadmin. Trial = full Pro features regardless of chosen plan (per spec). Trial expiry → `expired` read-only lock + upgrade modal. Quotas: calendar-month reset, no rollover.

**Invoicing**: `clients` · `invoices` (number `INV-YYYY-NNNN` unique per entity, status draft/sent/paid/overdue/cancelled, language, dates, totals, QR fields incl. 27-digit QR reference, internal + client notes) · `invoice_line_items` (qty supports "3h", unit price, VAT rate 0/2.6/3.8/8.1, live totals) · `invoice_payments` (manual mark-paid records).

**Expenses**: `expenses` (status pending_review/reviewed/flagged, OCR fields + raw JSON, AI suggestion + confidence + reasoning, deductibility % + overrides, VAT amount/rate always stored) · `expense_categories` (19 categories from tax spec §6 with default deductibility %, legal basis notes, DE/FR/IT/EN names).

**Tax config (all DB, never hardcoded, effective-dated)**: `cantons` (static codes/names) · `canton_fiscal_configs` (per year: cant_rate, comm_mult_default, kirch_rate, child_ded — 26 rows for 2026 from spec §7) · `communes` (BFS number, steuerfuss, effective range) · `federal_tax_brackets` (2026: 11 Tariff A + 12 Tariff B rows from spec §3.4/3.5) · `social_insurance_rates` (AHV 10.6 / IV 1.4 / EO 0.5, pillar 3a caps 35,280 / 7,056, AHV minimum 514, age-65 exemption 16,800) · `vat_configs` (8.1 / 2.6 / 3.8, threshold 100,000).

**Tax output**: `tax_estimations` (full breakdown snapshot + inputs JSON + rates_snapshot JSON; historical rows never recalculated).

**AI**: `ai_conversations` · `ai_messages` (role, content, confidence, context snapshot, model meta) · `ai_escalations` (status pending→in_progress→answered→closed, question + AI answer, accountant answer + internal notes, SLA deadline 24 business hours Europe/Zurich, KB flag) · `knowledge_base_entries` (internal only).

**Platform**: `audit_logs` (actor, action, subject morph, data, IP — impersonation, superadmin writes, accountant answers) · Laravel `notifications` · `bank_accounts` (CRUD stub, no sync).

Skipped for now: currencies/exchange-rate tables (CHF-only; `currency_code` string columns keep the door open), payment reminders, recurring invoices, bLink, pgvector.

## 3. Tax engine (`app/Services/Tax/`)

`TaxCalculator` — pure 10-step service (BCMath), per Tax Engine v2.0:
1. netBizIncome = revenue − deductible expenses (loss year → 0 for AHV, actual for tax)
2. AHV 10.6% / IV 1.4% / EO 0.5% on net; **only 50% of AHV deductible**; 65+ exemption on first 16,800; AHV minimum 514
3. taxableIncome = net − ahvDeduction − pillar3a(capped) − childDeduction + otherIncome
4. Federal tax: progressive bracket lookup (tariff by marital status), `base + (income − from) × rate/100`
5. Cantonal simple tax = taxable × cant_rate
6. Communal = cantonalSimple × steuerfuss/100 (commune fallback → canton capital + notice)
7. Church tax = cantonalSimple × kirch_rate (if member)
8–9. Totals, monthly reserve (/12), effective rate
10. Annualisation (revenue/daysElapsed×365) → re-run for "expected full year"; guards for day 0 / zero revenue

B-permit → STOP with `QUELLENSTEUER_REGIME` flag. `VatThresholdService`: 5-level alert ladder (green<60 / info / yellow / orange / red≥100), crossing-date projection, single-invoice ≥100k immediate red.
Event-driven: queued recalc job on invoice send/status change, expense confirm, tax profile update. Result cached 24h.

**Verification fixture (authoritative, spec §8)**: Anna Müller ZH → totalTax **CHF 14,825.05**, reserve 1,235.42/mo, effective 21.7%. (Mockup's 22,100 is stale — ignored.) Canton comparison fixtures from §9 as additional tests (ZG 10,223 · NE 16,740 · JU 18,260, no-3a variant).

## 4. Feature build per panel

### App panel
- **Onboarding**: Filament register → 5-step wizard (business, banking w/ live Swiss IBAN validation, tax profile w/ B-permit warning + income summary box, plan cards w/ Pro preselected). Returning users skip.
- **Dashboard widgets**: Revenue YTD, Expenses YTD (+gross margin), Est. total tax, Monthly reserve (dark card), Recent invoices, Tax estimation breakdown, VAT threshold bar, Ask Settlo (last Q&A + chips), To-do (real signals: overdue invoices, review-needed expenses, VAT alert, answered escalations).
- **Invoices**: resource + repeater line items with live totals, language tabs, send/mark-paid/cancel actions, PDF with embedded SIX QR-bill (27-digit reference), status badges, daily overdue job.
- **Clients**: resource (used by invoice picker).
- **Expenses**: resource + 4-state upload flow (idle → processing w/ staged messages → review [OCR-filled fields, VAT card, deductibility cards 100/50/0, AI suggestion + confidence + reasoning] → confirmed). OCR: 3 demo fixtures always; Claude vision when API key present. Categorization: Claude w/ category catalog, fixture fallback.
- **Tax engine page**: step-by-step breakdown, reserve progress, VAT threshold card, rates-applied grid, canton comparison table (color-coded).
- **Ask Settlo**: 3-pane chat (conversation list w/ search + badges, chat w/ context pills + suggested chips, accountant panel w/ quota bar + escalation history). Server-side Claude call (queued), Settlo AI persona system prompt w/ user context, never reveals Claude. Escalation: quota check → pending card → simulated answer after delay (POC) *or* real firm answer when assigned; toast + badge updates.
- **Settings**: business profile, banking defaults, tax profile, billing (plan switch via dummy gateway — upgrade immediate, downgrade at period end; cancel; payment history).
- **My accountant**: assigned firm/accountant card, escalation history.

### Firm panel (Treuhand)
- Firm dashboard: pending escalations, SLA countdown, client stats.
- **Clients**: assigned businesses list → read-only client books (invoices, expenses, tax estimations, profile); invite client by email (token → registration auto-links + assigns).
- **Escalation queue**: pending/in-progress for the firm's clients; claim → answer (rich editor) → answered; internal notes; propose-to-KB flag; SLA breach highlighting.
- **Members**: invite/manage firm accountants.

### Superadmin panel
- **Resources**: Users (suspend, role, **Impersonate** action), Business entities, Firms + members + assignments, Subscriptions (change plan, extend trial, comp, cancel), Plans CRUD (prices, features, quotas), Payments ledger, Invoices/Expenses/Estimations (read), Escalations (view all, assign to firm, answer as Settlo), Knowledge base CRUD, AI conversations (read), Audit logs.
- **Tax config management**: canton fiscal configs, communes, federal brackets, SI rates, VAT config, expense categories — full CRUD with effective-dating (annual October update flow).
- **Dashboards**: MRR + revenue chart (payments ledger), user growth, trial→paid conversion, plan distribution, escalation SLA compliance, VAT-alert distribution, churn.
- **Impersonation (native, no package)**: action stores `impersonator_id` in session → login as target → redirect to their panel → persistent banner "Impersonating X — Stop" (Filament render hook in app + firm panels) → stop restores superadmin. Audit-logged both ways. Cannot impersonate superadmins.

## 5. Seed data
- Reference data: 26 cantons + 2026 fiscal configs, federal brackets, SI + VAT rates, 19 expense categories, 3 plans, key communes (capitals + Zürich city 119 + Küsnacht 70).
- Demo: **Anna Müller** (anna@test.ch / password, ZH, Pro trial, 5 invoices CHF 68,400 mixed statuses incl. Berg & Partner overdue, 4 expenses CHF 14,200 incl. Digitec review-needed, 4 seeded AI conversations, 1 answered escalation) · **Müller Treuhand AG** firm + accountant Maria Schneider (maria@test.ch) assigned to Anna · superadmin (admin@settlo.ch).

## 6. Testing (Pest)
- Unit: TaxCalculator (Anna fixture 14,825.05; §9 canton fixtures; bracket edges; 3a caps; loss year; 65+; B-permit stop; guards), VAT alert ladder + crossing date + single-invoice rule, QR reference generator (27-digit mod-10), quota service, IBAN validator.
- Feature: panel access per role, onboarding completion, invoice lifecycle + numbering + PDF, expense confirm → recalc, escalation flow + quota block, subscription lifecycle (trial → active → expired lock; upgrade/downgrade), impersonation (start/stop/audit/deny non-superadmin), firm invitation + client access scoping (firm sees only assigned clients).

## 7. Build order
| Phase | Scope |
|---|---|
| 0 | Deps install, 3 panels scaffold, theme + branding, roles + access |
| 1 | All migrations, models, factories, reference + demo seeders |
| 2 | Billing: plans/subscriptions/dummy gateway/gating/quotas + superadmin plan mgmt |
| 3 | Invoicing: clients, invoices, QR-bill PDF, statuses, overdue job |
| 4 | Expenses: upload flow, OCR fixtures + Claude vision, categorization, VAT tracking |
| 5 | Tax engine: services, jobs, dashboard widgets, tax page, VAT alerts |
| 6 | Ask Settlo: chat, Claude integration, escalations, quotas |
| 7 | Firm panel: firms, members, invitations, client books, escalation queue |
| 8 | Superadmin: all resources, tax-config mgmt, dashboards, impersonation, audit |
| 9 | Onboarding wizard, settings, polish, full test pass, pint |

## 8. Deferred (v2)
Stripe (replaces DummyGateway), invoice email delivery + reminders, recurring invoices, bank sync/CSV import, VAT Form 300, year-end export, annual return review workflow, real OCR at scale, pgvector KB retrieval, multi-entity UI switching, GmbH/AG, app localization DE/FR/IT, native mobile, communal precision per municipality, Quellensteuer calculation.
