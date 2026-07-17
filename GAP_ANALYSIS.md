# Settlo Gap Analysis & Build Plan

Swiss self-employed platform · Laravel 13 + Filament v5 · repo `/Applications/AMPPS/www/settlo`. Synthesis of six scanner reports (app, chat, firm, admin, data-model, business-specs, mockups) against spec.

---

## 1. What is done

**Data & domain layer (near-complete)**
- All core tables/models exist: users, business_entities, tax_profiles, cantons/communes, canton_fiscal_configs, social_insurance_rates, federal_tax_brackets, vat_configs, expense_categories, plans/subscriptions/subscription_payments, clients, invoices/line_items/payments, expenses, tax_estimations, bank_accounts, notifications, accountant_assignments, accounting_firms/members, firm_client_invitations.
- Full AI chat data model present but unused by UI: ai_conversations, ai_messages (role, confidence, tokens, context_snapshot JSON), ai_escalations (full PENDING→ANSWERED→RESOLVED columns + SLA + accountant fields), knowledge_base_entries.
- audit_logs table + append-only `AuditLog` model (actor, impersonator, subject morph, properties, ip/agent).

**Owner app panel (`/app`) — the built product**
- Invoices: list with status badges/overdue coloring, create form (tenant-scoped client selector, line-item repeater with per-line VAT 8.1/2.6/3.8/0, live summary), auto-increment numbering under row lock, draft→Send (freezes creditor snapshot, mints QR reference, dispatches tax recalc), Swiss QR-bill PDF (dompdf), markPaid/cancel/markOverdue (scheduled daily 02:00).
- Expenses: list, private-disk receipt upload → async OCR on Horizon `files` queue (`ProcessReceiptUpload`, tries=3), Gemini + Fake extractors, AI category/VAT/deductibility suggestion, confirm flow recomputes deductible amount + dispatches tax recalc, Reverb broadcast + Filament DB notifications + table poll.
- Tax engine: `TaxCalculator`/`TaxEngine` persist immutable `TaxEstimation` (inputs + rates_snapshot), AHV/IV/EO, federal/cantonal/communal/church, monthly reserve, annualisation, Quellensteuer; `/tax` page gated on `PlanFeature::TaxEngine` with breakdown table + canton comparison + Recalculate action.
- VAT threshold: `VatThresholdService` ladder (info/warning/critical/mandatory), single-invoice ≥100k rule, crossing-date projection, stored on estimation.
- Dashboard: `BusinessOverview` (Revenue YTD, Deductible expenses, Estimated tax, Monthly reserve) + `RecentInvoices` widget.
- Clients CRUD; multi-tenant entity switcher (`->tenant(BusinessEntity::class)`).

**Subscriptions & plans (complete)**
- Solo/Pro/Confidence at CHF 19/49/99 seeded; feature matrix + gating via `User::hasFeature()`/`PlanFeature`; 14-day trial (`startTrial`), trial→read-only expiry (scheduled 01:00), lifecycle (upgrade immediate / downgrade deferred / cancel-at-period-end / hourly renew), human-answer quotas (0/1/3) with atomic `consumeHumanAnswer()` + `QuotaExceededException`, monthly quota reset command.

**Panels scaffolded**
- All three panel providers registered with brand colors/tenancy/gates: `/app` built, `/firm` (AccountingFirm tenancy, Accountant-role gate, tested) empty, `/admin` (Superadmin gate, suspended-user block) empty.

---

## 2. Gap register (deduplicated)

| # | Gap | Priority | Phase |
|---|-----|----------|-------|
| G1 | Claude API service — real `claude-sonnet-4-20250514` streaming call; system prompt on every call; full history sent (no truncation); "never mention Claude/Anthropic" | High | 6 |
| G2 | Per-call AI context assembly (firstName, canton, revenueYTD, vatStatus, marital status, children, pillar3a) written to `context_snapshot`; chat header context pills | High | 6 |
| G3 | Conversation/message routes + controllers + Inertia React pages (GET/POST /conversations, messages, streaming /ai) | High | 6 |
| G4 | 3-pane Ask Settlo chat UI: conversation list (search, New, Today/This week/Earlier, badges) + chat bubbles/avatar/confidence label; auto-title from first message | High | 6 |
| G5 | Suggested-question chips (4 sampled from fixed pool of 8, re-randomise per conversation, click sends) | Medium | 6 |
| G6 | Escalation create flow ("Verify with accountant" button, quota check, calls `consumeHumanAnswer()`, gated on AccountantAccess; Solo blocked; over-quota toast) | High | 6 |
| G7 | Simulated accountant answer: delayed job (3.5s) PENDING→ANSWERED with Maria Schneider canned text + broadcast | High | 6 |
| G8 | Escalation card UI states (pending yellow / answered green / resolved) + "Mark as resolved" + list badge flip | Medium | 6 |
| G9 | Accountant panel column (Maria card, quota tracker bar "X of Y used", escalation history) | Medium | 6 |
| G10 | Quota display binding + upgrade upsell path | Medium | 6 |
| G11 | Verify/wire scheduled monthly quota reset actually fires (scanners disagree — reset command exists per business-specs but chat scan found none; confirm end-to-end) | Medium | 6 |
| G12 | Notification/broadcast on escalation answered (owner DB notification + To-do item); SLA (24 business-hours) computation + breach handling | Medium | 6 |
| G13 | Seed 4 demo AI conversations + seeded VAT-registration Q&A + seeded escalation for dashboard | Medium | 6 |
| G14 | Escalation must be secondary/never-default UI behavior (design constraint) | Medium | 6 |
| G15 | Dashboard "Ask Settlo" preview widget (last Q&A, quick-question chips, Open chat) | Medium | 6/9 |
| G16 | Firm panel UI entirely absent (`app/Filament/Firm` does not exist) | High | 7 |
| G17 | Firm client list resource (owner, canton, revenue YTD, VAT status, assigned accountant) | High | 7 |
| G18 | Read-only client books (invoices/expenses/clients/tax) with assignment-scoped authorization | High | 7 |
| G19 | Firm escalation queue: list PENDING, claim, write answer/notes, mark answered (answered_at, accountant_id, firm_id, SLA), resolve | High | 7 |
| G20 | Firm-scoped authorization policies (accountant restricted to non-revoked `AccountantAssignment` under their firm) | High | 7 |
| G21 | Firm client invitations: create+send (hash token, email), accept route/flow → creates AccountantAssignment, expiry/already-accepted handling | High | 7 |
| G22 | Firm members & roles management (list, invite accountant, toggle is_owner, remove) | Medium | 7 |
| G23 | Firm dashboard widgets (pending escalations, SLA-at-risk, active clients, recently answered) | Medium | 7 |
| G24 | Firm profile/settings page (name, branding, contact) | Low | 7 |
| G25 | Firm-panel feature tests | Medium | 7 |
| G26 | Knowledge-base capture: flag answered escalation → create KnowledgeBaseEntry; approval action | Low | 7/8 |
| G27 | Admin panel UI entirely absent (`app/Filament/Admin` does not exist) | High | 8 |
| G28 | User + BusinessEntity admin resources (list/search/suspend/reactivate, cross-tenant view) | High | 8 |
| G29 | Firm admin resources (AccountingFirm/Member/Assignment/Invitation provisioning) | High | 8 |
| G30 | Subscription + SubscriptionPayment admin resources (comps, extend trials, reconcile dummy payments) | High | 8 |
| G31 | Plan admin resource (CRUD price/trial/quota/features/active) | High | 8 |
| G32 | Tax-config admin resources (CantonFiscalConfig, FederalTaxBracket, SocialInsuranceRate, VatConfig, Canton, Commune) with effective-dating (SETTLO-16) | High | 8 |
| G33 | Metrics dashboard widgets (MRR, growth, trial→paid, churn, tier mix, AI escalation rate, SLA compliance) | High | 8 |
| G34 | Impersonation with audit trail (session-swap, banner, stop control) | High | 8 |
| G35 | `AuditLogger` service (writer) + admin audit-log viewer resource | High | 8 |
| G36 | KnowledgeBaseEntry + AiEscalation admin oversight (quality control) | Medium | 8 |
| G37 | Admin per-resource authorization policies | Medium | 8 |
| G38 | Onboarding wizard (5 steps: account, business profile, banking, tax profile, plan) wiring `startTrial()` | High | 9 |
| G39 | Settings page (business profile, logo, banking defaults, invoice defaults, tax profile — "editable anytime") | Medium | 9 |
| G40 | Bank accounts resource/page + nav item | Medium | 9 |
| G41 | Dashboard tax-estimation breakdown widget (income tax / AHV / IV-EO / VAT / total) | Medium | 9 |
| G42 | Dashboard VAT threshold progress bar (colored, crossing date) | Medium | 9 |
| G43 | VAT alert banner + `user.vatAlertLevel` persisted; proactive DB notification at 75%/90% crossing | Medium | 9 |
| G44 | Invoice per-rate VAT breakdown (form + PDF) | Medium | 9 |
| G45 | Multi-language invoice PDF body (DE/FR/IT labels, not just QR part) | Medium | 9 |
| G46 | Expense VAT input-deduction section (rate/amount/net/input tax, registration-aware label, /expenses/vat-summary) | Medium | 9 |
| G47 | Dashboard To-do widget (colored-dot items incl. "accountant answered") + topbar quick-actions (Upload receipt / New invoice) + greeting header | Low | 9 |
| G48 | Tax page polish: 4-step layout, reserve progress bars, rates-applied grid, VAT alert card, "Updated today" badge | Low | 9 |
| G49 | Invoice "Your details"/logo/QR strip/Preview-PDF; read-only invoice detail view with status history | Low | 9 |
| G50 | Demo receipt shortcuts (Digitec/SBB/Restaurant) + staged OCR status messages | Low | 9 |
| G51 | Deductibility 3-card UI (100/50/0) + expense success screen | Low | 9 |
| G52 | Net-margin dashboard sub-label; RecentInvoices limit 4 vs 5; nav regrouping (Overview/Finance/Support); brand logo mark + nav badge counts | Low | 9 |
| G53 | Documents feature (VAT declaration Form 300, year-end export) — reconcile against spec (possible scope creep) | Low | 9 / Later |
| L1 | Real Stripe integration (dummy gateway shipped; contract Stripe-ready) | — | Later |
| L2 | Currency + ExchangeRate tables + CurrencyType enum (currency-ready schema) | — | Later |
| L3 | Monetary precision Decimal(18,8) for crypto/multi-currency (currently 18,2) | — | Later |
| L4 | BusinessEntity.fiscal_year_start column | — | Later |
| L5 | Invoice reminder/dunning columns, viewed_at/pdfUrl/pdfGeneratedAt; Client iban/qrIban | — | Later |
| L6 | RAG / KB retrieval into AI context + embeddingModel column | — | Later |
| L7 | Real (non-simulated) escalation answering fully wired firm↔owner in production | — | Later |

---

## 3. Build plan

Standing constraints for every phase: Horizon `files` queue for file processing, Gemini for OCR, Reverb + Filament DB notifications for real-time loaders, dummy payment gateway. **Security invariants (enforce in each deliverable):** default-deny policies, tenant isolation, guarded mass-assignment, private receipts disk, server-only API keys, immutable issued invoices, append-only audit log. Every change ships with a Pest test (`php artisan test --compact --filter=...`); run `vendor/bin/pint --dirty` after PHP edits.

### Phase 6 — Ask Settlo chat
*Goal: owner AI Q&A with confident answers, secondary human escalation, live quotas.*

1. **`AnthropicClient` / `AskSettloService`** (`app/Services/Ai/`): call `claude-sonnet-4-20250514` server-side using `config('settlo.anthropic')` (already present); stream responses. API key read only from server config — never exposed to the client (invariant).
2. **System prompt + context assembler**: build the exact spec system prompt each call, injecting live values from User/BusinessEntity/TaxProfile/TaxEstimation (firstName, lastName, cantonCode, revenueYTD, vatStatus, maritalStatus, numberOfChildren, pillar3a). Persist the assembled context to `ai_messages.context_snapshot`. Prompt must forbid mentioning Claude/Anthropic ("you are Settlo AI"). Send full conversation history (no truncation for POC).
3. **Routes + controllers**: `GET/POST /conversations`, `GET /conversations/:id/messages`, `POST /conversations/:id/messages`, `POST /conversations/:id/messages/ai` (streaming). Tenant-scope every query to the owner's business entity (invariant); default-deny policy on `AiConversation`/`AiMessage`.
4. **3-pane Inertia React chat page** (`resources/js/Pages/AskSettlo`): left = conversation list (search, "New", Today/This week/Earlier grouping, new/answered/pending badges); center = message bubbles (user dark right, AI card left with "S" avatar), typing indicator, header context pills (canton / YTD revenue / VAT status), confidence label "Based on Swiss tax law · Verify for your specific situation"; auto-title from first user message (50 chars). Add "Ask Settlo" nav item (Support group).
5. **Suggested-question chips**: 4 sampled from the fixed pool of 8; re-randomise on new conversation; click populates input and sends.
6. **Escalation create action**: yellow "Verify with accountant" button on each AI message; gated on `AccountantAccess` feature (hidden/disabled for Solo, quota 0); on click call `consumeHumanAnswer()` under lock; create `AiEscalation{status:PENDING}`; over-quota → toast "Monthly limit reached — upgrade to Confidence for 3 answers/month." Escalation is never the default/first suggestion (design constraint).
7. **Simulated answer job**: dispatch delayed (3.5s) queued job flipping PENDING→ANSWERED with Maria Schneider's spec canned text (CHF 100,000 single-invoice VAT note), set `answered_at`; broadcast via Reverb.
8. **Escalation card states**: pending (yellow), answered (green, Maria's answer, "Verified", "Mark as resolved"), resolved ("Resolved ✓"); left-list badge flips; "Mark as resolved" → Closed.
9. **Accountant panel column**: Maria card (avatar MS, "Certified Swiss accountant · Müller Treuhand AG", availability dot), quota tracker "X of Y used (plan)" progress bar bound to `human_answers_used/quota` + upgrade upsell, escalation history list (click scrolls to message).
10. **Answered notification**: owner Filament DB notification + broadcast on ANSWERED; compute `sla_deadline` (24 business hours) and set `sla_breached` on breach.
11. **Confirm scheduled quota reset fires**: reconcile scanner disagreement — ensure the monthly `resetQuota()` command is scheduled and gated on `quota_reset_at`; add a test proving reset after month boundary.
12. **Seed data**: `DemoSeeder` adds Anna's 4 conversations with history + one seeded escalation + the VAT-registration Q&A for the dashboard widget.
13. **Dashboard "Ask Settlo" widget**: last Q&A (truncated) + quick-question chips + "Open chat" (can land here or Phase 9).

**Deliverable check:** owner asks a question, gets a streamed confident answer with context pills + confidence label; can escalate (quota-metered), sees pending→answered transition, resolves it; Solo user sees chat but no escalation.

### Phase 7 — Firm panel (`/firm`)
*Goal: accountants view assigned client books read-only and answer escalations, with strict assignment-scoped authorization.*

1. **`app/Filament/Firm/` scaffolding**: create Resources/Pages/Widgets dirs the provider already discovers.
2. **Firm-scoped policies FIRST** (invariant): policy classes for Invoice/Expense/Client/TaxEstimation/AiEscalation that permit read only when a non-revoked `AccountantAssignment` links the accountant's tenant firm to that `business_entity_id`. Default-deny. Cover with tests before building UI.
3. **Client list resource**: assigned businesses (owner name, canton, revenue YTD, VAT status, assigned accountant) from `AccountingFirm::activeAssignments()`.
4. **Read-only client books**: per-client read-only viewers for invoices, expenses, clients, tax estimation (no create/edit/delete actions).
5. **Escalation queue resource**: list PENDING for the firm, claim/assign (set `accountant_id`, status In Progress), write `accountant_answer` + internal `accountant_notes`, mark Answered (`answered_at`, `accounting_firm_id`, SLA), resolve; SLA-breach indicator.
6. **Client invitations**: action to create+send invitation (generate token, store `token_hash` server-only, email it); public acceptance route/controller for owner to redeem → creates `AccountantAssignment`; handle expired/already-accepted.
7. **Firm members & roles**: resource to list members, invite accountant, toggle `is_owner` (firm admin), remove member; gate firm-admin-only actions.
8. **Firm dashboard widgets**: pending escalations count, SLA-at-risk, active clients, recently answered.
9. **KB capture**: action on an answered escalation to flag `add_to_knowledge_base` and create a `KnowledgeBaseEntry` (approval finalized in Phase 8).
10. **Firm profile/settings page** (name, branding, contact).
11. **Firm-panel feature tests** for every resource, escalation answering, invitation accept, and read-only authorization (cross-tenant read must be denied).

**Deliverable check:** accountant logs into `/firm`, sees only assigned clients' books read-only, answers a real escalation, invites/accepts a new client, cannot read a non-assigned entity.

### Phase 8 — Superadmin panel (`/admin`)
*Goal: operational control plane + audit trail (required before launch).*

1. **`app/Filament/Admin/` scaffolding** + admin per-resource policies (default-deny; only Superadmin).
2. **`AuditLogger` service + audit-log viewer**: writer that appends `AuditLog` rows (append-only invariant) for sensitive actions; read-only admin resource (actor, impersonator, action, subject, ip, timestamp). Build this early — it backs impersonation and human-answer audit.
3. **User + BusinessEntity resources**: list/search/view, suspend/reactivate (security-critical status), cross-tenant global view.
4. **Impersonation with audit**: "Impersonate" action with session-swap, persistent banner + stop control, writes `impersonator_id` audit rows.
5. **Firm resources**: AccountingFirm / Member / Assignment / Invitation provisioning.
6. **Subscription + SubscriptionPayment resources**: view/manage status, trials (comps/extend), reconcile dummy-gateway payments.
7. **Plan resource**: CRUD price_monthly / trial_days / human_answers_quota / features / marketing_features / is_active / sort_order.
8. **Tax-config resources**: CantonFiscalConfig, FederalTaxBracket, SocialInsuranceRate, VatConfig, Canton, Commune — all with `effective_from/effective_to` (SETTLO-16, rates never hardcoded).
9. **Metrics dashboard widgets**: MRR (active subscriptions × plan price), user growth, trial→paid conversion, monthly churn, % on Pro/Confidence, AI escalation rate, accountant SLA compliance.
10. **KB / escalation oversight**: KnowledgeBaseEntry approval action + AiEscalation review surface (quality control).
11. Feature tests for gating, impersonation audit writes, and effective-dated config edits.

**Deliverable check:** superadmin manages users/firms/plans/tax-config, sees live metrics, impersonates an owner with a banner, and every sensitive action lands in the append-only audit log.

### Phase 9 — Onboarding wizard, settings & polish
*Goal: complete the owner journey entry point and close remaining app-panel UI gaps.*

1. **Onboarding wizard (5 steps)**: account creation → business profile (logo, name, type, address, UID) → banking (IBAN validation, payment terms, currency, invoice language, number prefix) → tax profile (canton grid, VAT status, est. revenue, marital/tariff, children, permit, Pillar 3a) → plan choice (Solo/Pro/Confidence, 14-day trial). Wire `startTrial()` at completion (currently uncalled); set `onboarding_completed_at`. Resolve the section-5.1-vs-7 trial-scope ambiguity as a product decision, default to selected-plan features.
2. **Settings page**: edit business profile, logo, banking defaults, invoice defaults, tax profile ("editable anytime").
3. **Bank accounts resource** + Finance nav item.
4. **Dashboard widgets**: tax-estimation breakdown (income tax / AHV / IV-EO / VAT / total); VAT threshold progress bar (colored + crossing date); To-do widget (colored dots incl. "accountant answered"); greeting header + topbar quick-actions (Upload receipt / New invoice); Ask Settlo preview (if not shipped in Phase 6); net-margin sub-label.
5. **VAT alert banner + proactive notification**: persist latest level to `user.vat_alert_level`; render color-coded banner on Dashboard + Tax page with "Consider VAT registration" CTA; push DB notification when crossing 75%/90%.
6. **Invoice improvements**: per-rate VAT breakdown (form + PDF); multi-language PDF body (DE/FR/IT labels); "Your details"/logo/QR strip/Preview-PDF; read-only invoice detail view with status history.
7. **Expense improvements**: VAT input-deduction section (rate/amount/net/input tax, registration-aware label, `/expenses/vat-summary`); deductibility 3-card UI (100/50/0) + success screen; demo receipt shortcuts + staged OCR status messages.
8. **Tax page polish**: 4-step layout, reserve progress bars, rates-applied grid, VAT alert card, "Updated today" badge.
9. **Cosmetic/nav**: RecentInvoices limit 4; nav regrouping (Overview/Finance/Support); brand logo mark + nav badge counts.
10. **Documents feature decision**: reconcile Form 300 / year-end export against POC scope — build minimal export or defer to Later.

**Deliverable check:** a new user completes onboarding in ~30s into a trial, edits everything in Settings, and the dashboard surfaces tax breakdown, VAT progress, and to-dos.

### Later / out-of-POC scope
- **L1** Real Stripe integration (dummy gateway is sufficient for investor demo; contract already Stripe-ready).
- **L2/L3** Currency + ExchangeRate tables, CurrencyType enum, Decimal(18,8) precision (CHF-only for POC).
- **L4** `fiscal_year_start` column (fiscal year currently fixed).
- **L5** Invoice reminder/dunning + viewed_at/pdf columns; Client iban/qrIban.
- **L6** RAG / KB retrieval into AI context + `embedding_model` column.
- **L7** Production (non-simulated) escalation answering fully wired firm↔owner — Phase 6 simulates the 3.5s answer; Phase 7 builds the real firm-side queue, but end-to-end production SLA/answer routing beyond the demo is post-POC.
- Mobile apps and any non-web clients.
