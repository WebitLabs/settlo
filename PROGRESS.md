# Settlo — Build Progress

Living document. Updated after every phase. Specs live in `settlo-specs/`.

## Status: READY — all phases complete, 216 tests passing (748 assertions)

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

Tests: 216 passing (748 assertions). Demo logins (all password `password`): `anna@test.ch` (owner, /app), `maria@test.ch` (accountant, /firm), `admin@settlo.ch` (superadmin, /admin). Queue: Horizon (Redis, port 6380). OCR: Gemini (`GEMINI_API_KEY` in `.env`, empty → FakeExtractor). Real-time: Reverb + Filament DB notifications + table polling.

## Remaining

Nothing in POC scope. Deferred (Later bucket, see GAP_ANALYSIS.md L1–L7): real Stripe, multi-currency/exchange rates, RAG knowledge-base retrieval into AI context, invoice dunning, production firm↔owner escalation routing beyond the demo, demo receipt shortcuts, Form 300 document export.

## Gap analysis

Done 2026-07-17 via 8-agent workflow (7 spec-vs-code scanners + synthesis). Full report: [GAP_ANALYSIS.md](GAP_ANALYSIS.md) — 53 gaps (G1–G53) mapped to Phases 6–9 plus a Later bucket (L1–L7: real Stripe, multi-currency, RAG, dunning, mobile).
