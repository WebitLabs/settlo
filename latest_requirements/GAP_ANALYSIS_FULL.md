# Settlo — full gap analysis

**Date:** 2026-09-21
**Scope:** the whole product — the original specs and backlog, the July gap list (G1–G53), the September feedback (BUG-01…59 and the email topics), the tax engine against its algorithm spec, billing under simulated payments, and production readiness.
**Method:** five parallel read-only reviews, each verifying claims in code rather than trusting the documents.
**Baseline:** 764 tests pass on both SQLite and Postgres (3,384 assertions), Pint clean, 256 paths uncommitted.

**Standing decision respected throughout:** payments are simulated on purpose. "No real Stripe connection" is not a gap. Billing is judged only on whether the simulation is complete, honest and reversible.

---

## 1. The short version

The product is feature-complete against the plans and the September feedback round genuinely landed: the tester's own bug numbers check out in code, and the tax arithmetic behind BUG-43 was recomputed by hand and is right.

What this review found is a different class of problem. **The application is in good shape; the deployed instance is not.** Nothing in the repository loads reference data or runs scheduled jobs in production, and a deploy that follows `.env.example` silently ships fake AI answers. Separately, two access rules are simply wrong (both confirmed by running them), the accountant workflow is broken end to end, and two tax figures disagree with the authoritative spec.

| Severity | Count | Theme |
|---|---|---|
| Critical | 5 | The live deployment: no reference data, no scheduled jobs, silent fake AI, inline OCR on a 60-second limit, simulated money shown as real revenue |
| High | 7 | Two access denials, the accountant loop, invoice PDF immutability, VAT threshold law, the monthly-reserve headline figure, a crash on the client form |
| Medium | ~25 | Spec drift, missing gating decisions, performance, security hardening, stale docs |
| Low | ~40 | Copy, formatting, small drifts |

---

## 2. Critical — the live deployment

These are not code defects. They are things no part of the repository does, so they fail silently.

### C1 — Reference data is never loaded, so the commune lists the tester complained about are still empty

`vercel.json` runs only `npm run build`; no deploy step seeds anything. The commune importer resolves cantons by code and counts unresolved rows as "skipped", so on a fresh database `settlo:import-communes` reports success with all 2,110 rows skipped.

- **Impact:** BUG-12, BUG-34 and BUG-58(3) still reproduce in production, with an empty canton table underneath.
- **Fix:** add `php artisan db:seed --class=ReferenceDataSeeder --force` to the deploy, and make the importer fail loudly when there are no cantons or when everything is skipped.

### C2 — No scheduled job has ever run in production

The four commands are scheduled in code and exposed over HTTP for serverless, but `vercel.json` has no cron block, no external pinger is documented, and `CRON_SECRET` is empty (with no secret the endpoints refuse anyway).

- **Impact:** trials never expire, subscriptions never renew, AI-answer quotas never reset, invoices never go overdue. The dashboard's to-do list is built on states that never arrive.
- **Fix:** add the cron block plus `CRON_SECRET`, or document the external pinger.

### C3 — Without a Gemini key the app serves canned answers that look real

`GEMINI_API_KEY` is read by config but missing from `.env.example`. With no key the app silently uses fake AI and fake receipt scanning — every chat answer is the same paragraph and every receipt becomes the same CHF 87.50 train ticket, both reported at 94 % confidence with a real model name attached.

- **Impact:** a deploy following `.env.example` looks like it works. It would also make the AI bugs look fixed during re-testing.
- **Fix:** document the key and fail loudly outside local and test environments, exactly as the payment gateway now does.

### C4 — Receipt scanning runs inside the web request against a 60-second limit

Production is configured for inline job execution while functions are capped at 60 seconds; the scanning call alone can take about 141 seconds with its retries. Delayed jobs also fire immediately, so the "pending → answered" behaviour never appears.

- **Impact:** slow receipts time out; the progress indicator is decorative.
- **Fix:** run a real worker, or move scanning to a cron-drained queue and shorten the timeouts.

### C5 — Simulated money is presented as real revenue

The admin MRR figure and the payments ledger show simulated subscriptions with no marker, and simulated mode is now the default.

- **Impact:** an internal or investor demo reads "MRR CHF x / n paying customers" as collected revenue.
- **Fix:** label or exclude simulated revenue while the gateway isn't Stripe.

---

## 3. High

### H1 — Superadmins get a 403 on the business-entities list *(verified)*
The policy allows only owners and accountants, and the admin resource lacks the override its sibling resources have. No test covers it.

### H2 — Accountants cannot open the escalation they are asked to verify *(verified)*
The view permission requires ownership, so the firm detail page returns 403 and its button silently hides. The AI answer appears **only** on that page, so an accountant is asked to verify an answer they cannot read. Every existing test goes through the list page.

### H3 — The canned "accountant answer" hijacks real escalations
The simulated answer is dispatched unconditionally, even when a real firm is assigned, and lands about 4 seconds later. It burns the customer's paid answer credit, notifies the owner, and then hides the firm's answer action. Combined with H2, the whole accountant queue is unreachable in practice.

### H4 — Issued invoice PDFs are not immutable
Sending an invoice freezes a snapshot of the business details, but the PDF renders the live business. Rename or move the business and every past invoice re-renders differently — and the printed address can then disagree with the payment code on the same page. For a Swiss payment document this is a correctness problem.

### H5 — The VAT threshold is tracked per business, but for sole proprietors it is per person
The threshold is only ever evaluated per workspace; the app's own VAT page states the opposite rule. An owner with two businesses at CHF 60k each sees two calm indicators while registration became mandatory months earlier. The same applies to the single-invoice rule.

### H6 — "Set aside each month" is computed from the year so far, not the projected year
The authoritative spec says the displayed reserve comes from re-running the calculation on annualised figures. The code divides the year-to-date tax by 12, which understates the headline figure roughly threefold early in the year. (The spec contradicts itself in its worked example, which is what the current test pins — see the questions.)

### H7 — Creating a client crashes when the payment term is cleared
Payment term and language have defaults but aren't required, and the columns don't accept empty values, so clearing the field produces a server error instead of the styled message the tester asked for. This is one of the three fields BUG-38 named.

---

## 4. Medium (grouped)

**Product promises not backed by code**
- "VAT declaration (Form 300)" and "Year-end export" are sold on the plan cards; neither exists and neither plan feature is enforced anywhere.
- Expense categorisation is a text match against category names, not AI categorisation: the reasoning field is never written, and an unmatched hint leaves the expense unconfirmable.
- Feature gating is on by default, contradicting the backlog's "no hard paywalls for the POC".
- The business logo is uploaded and stored but never rendered on anything.
- "Send invoice" changes a status; no email is ever sent, though the button and message say sent.
- No "Preview PDF" before sending: you must send irreversibly to see the document.

**Structure and data**
- The onboarding flow has three skippable steps and no tax step, so a user can finish with no canton and therefore no tax estimate.
- Demo data has no tax estimate and seeds only one business, and isn't seeded in production at all — so the deployed demo has no demo user, and the owner's core "several businesses" request can't be seen.
- Bank accounts never reach the payment code; the business IBAN is always used.
- Commune multipliers have no effective dating, so a prior-year recalculation isn't reproducible.
- VAT rates are hard-coded in the forms while the editable rate table is never read.
- The fiscal year is pinned to 2026 and undocumented; on 1 January everything silently reverts.

**Security hardening**
- Registration validation runs before the rate limit, so addresses can be probed for existing accounts.
- Password reset reveals whether an address exists.
- PDF generation, receipt upload and invitation acceptance are unthrottled.
- Stopping impersonation doesn't re-check that the restored account is still a superadmin and still active.
- 21 of 32 models have no policy; Filament allows by default, so this rests entirely on panel-level checks.
- No security headers are set anywhere.

**Performance and correctness**
- Missing eager-loading on the invoice and expense tables and the recent-invoices widget; permission checks re-query per row.
- Missing indexes on invoice issue date, escalation conversation, and the last-workspace column read on every workspace entry.
- Tax recalculation jobs have no retries or failure handling, on a queue configured to try once.
- Money is formatted three different ways in the same flow, including on the client's PDF.
- "Overdue" is defined one day earlier in the table than everywhere else.
- Re-inviting a previously revoked firm client hits a uniqueness error.

**Real-time and languages**
- The broadcast stack has no client: the front end never connects, so all three live events are unreachable and the UI relies on polling. `PROGRESS.md` claims real-time works.
- The app is English-only against a four-language target: 694 literal strings versus 3 translated ones, no locale switching, and language pickers that change nothing except which language a client's invoice is *not* produced in.

**Documentation**
- `PLAN.md` still describes the old structure, the 5-step wizard and Claude as the AI engine; `SECURITY.md` describes three panels and Claude throughout; `README.md` is the stock Laravel readme.

---

## 5. What is genuinely clean

Worth stating plainly, because it's most of the system:

- **The simulated payment work** — explicit opt-in, row locking, idempotency backed by a real uniqueness rule, honest wording, "skip" still leaving a locked business, and renewals included. The reviews called it the cleanest part of the round.
- **The tax engine's core** — all 26 cantons' comparison totals reproduce within 50 centimes, all 23 federal brackets match the spec exactly, the worked example reproduces line by line, and the per-business split is exact to the cent.
- **The Swiss payment code (QR-bill)** — checked field by field against the standard, with no violations.
- **The September fix round** — every High and Medium fix claimed in the previous report was verified in code, including the hand-checked AHV arithmetic.
- Panel access and tenant isolation, firm-scoped authorization, invitations, impersonation auditing, mass-assignment discipline, server-computed money, the webhook signing guard, and the PDF renderer lockdown.
- No leftover debug markers anywhere, no tracked secrets, and no stale references from the panel rename.

---

## 6. Corrected during this review

Your local `.env` selected Stripe with no key, which silently fell back to the **old redirect-style fake checkout** — so the pay button still said "continue to secure payment with Stripe" and then "Payment received". It now selects the simulated gateway, which is what the screens are written for. The same variable must be set on the deployment.

---

## 7. Decisions (Marius, 2026-09-21)

| # | Question | Decision |
|---|---|---|
| 1 | What to fix | Everything: Critical, High, Medium and Low. |
| 2 | Canned accountant answer | Only when no firm is assigned; a real firm always answers its own clients. |
| 3 | Form 300 and year-end export | Build minimal versions rather than removing the claims. |
| 4 | Monthly reserve | Show both: "owed so far" and "set aside monthly" (projected year ÷ 12). |
| 5 | Deploy config | Add both scheduled jobs and a reference-data seeding step to `vercel.json`. |
| 6 | Languages | Hide the language pickers for now; invoices still print in four languages. |
| 7 | Browser tests | Add the browser-testing package and automate the visual checks. |
| 8 | POC behaviour | No paywalls (gating off by default); seed demo data in production with strong generated passwords; refuse to start in production without an AI key; make the phone number optional at registration. |

## 8. Questions still open

1. **Environment variables in Vercel.** The repository can carry the cron schedule and the seeding step, but `CRON_SECRET`, `GEMINI_API_KEY`, `SETTLO_PAYMENT_GATEWAY=simulated` and the mail settings must be set in the Vercel project by you.
2. **Real-time.** The broadcast stack has no client and cannot run on Vercel, while polling already works. Keep the unused infrastructure, or remove it?
3. **AHV minimum in a loss year.** The spec charges the minimum contribution when self-employment is the person's sole activity, but the app never asks. Infer it, ask during onboarding, or always charge it?
4. **Committing.** Everything is still uncommitted across 256 paths.

---

## 9. Resolution (2026-09-21)

Everything in sections 2–4 is addressed, except the items you decided against (M5 old links, real Stripe). **981 tests pass** (4,199 assertions), up from 764, including a new browser suite. Pint is clean. Nothing is committed.

### Critical

| Id | Result |
|---|---|
| C1 | New `settlo:deploy` command runs migrations, seeds reference data and then **verifies** cantons, communes, postal codes, categories and plans are present, failing if not. Reachable as `/cron/deploy` for a shell-less deploy. The commune and postal-code importers now fail loudly instead of silently skipping every row. |
| C2 | `vercel.json` carries a `crons` block for the four lifecycle commands plus a queue drain, and a failing cron now returns 500 and logs instead of reporting success. `CRON_SECRET` is documented. |
| C3 | `GEMINI_API_KEY` is documented and **required** outside local/testing; fake AI and fake receipt output are now marked (`model = fake`, `simulated = true`, confidence 0) so they can never be mistaken for real. |
| C4 | Scan timeouts cut from ~141 s to ~40 s to fit the 60 s function limit; jobs have retries, timeouts, backoff and failure logging; a cron-drained queue (`settlo:drain-queue`) replaces inline execution. |
| C5 | Simulated revenue is labelled as such in the admin panel ("Recurring revenue (simulated)") and in the subscription and payment tables. |

### High

| Id | Result |
|---|---|
| H1 | Superadmins can open the business-entities list again (resource-level authorization, mirroring the escalations resource). |
| H2 | Accountants can open the escalation detail page: the view permission now also allows whoever may answer it, so nobody is asked to verify an answer they cannot read. |
| H3 | The canned answer fires only when no firm is assigned, behind `SETTLO_SIMULATE_ACCOUNTANT_ANSWER`. With a firm assigned the escalation waits for a human and no credit is spent on a machine reply. |
| H4 | Issued invoice PDFs render the frozen creditor snapshot (name, address, UID, VAT status); live data is used only for drafts. Draft previews are watermarked and carry a provisional payment reference. |
| H5 | The VAT threshold is evaluated once across all of an owner's sole proprietorships — including the largest single invoice — and each workspace shows the owner-level band plus its own contribution. |
| H6 | Both figures are shown: "Tax owed so far" and "Set aside monthly" from the projected year, with the full-year estimate beside them. Annualisation now counts from the business's start when it began mid-year. |
| H7 | Clearing the payment term or language on the client form shows a styled error instead of crashing. |

### Medium and Low

- **Built:** a minimal VAT declaration (Form 300) and a year-end export, both gated by their plan feature, so the plan cards no longer sell vapour.
- **Product:** preview PDF for drafts, due dates from stored payment terms, the default bank account driving the payment code, deductible (not gross) expenses on dashboards, an "uncategorised" fallback so an unmatched receipt can still be confirmed, the business logo rendered on invoices, one money format everywhere, and a single definition of "overdue".
- **Tax:** VAT alert wording escalates per band including the mandatory one, the minimum AHV contribution applies in a loss year, loss-year and age-65 notices are surfaced, VAT rates come from the rate table, home office defaults to pro-rata, and the deductible maths is decimal throughout.
- **Security:** policies for the models that had none, audit entries for human answers and firm actions, impersonation re-verified on stop, password reset no longer reveals whether an address exists (and is rate limited), throttles on PDF, receipt upload and invitation acceptance, receipt paths validated, and re-inviting a revoked client works.
- **Performance:** eager loading on the invoice and expense tables and widgets, new indexes, and sargable date ranges.
- **Ops:** security headers, the favicon route, fiscal year defaulting to the current year, and the Horizon link hidden when the queue isn't Redis.

### Your POC decisions

Feature gating is off by default (the plan tier is still shown, and the tests prove the gate still works when switched on). Demo data can be seeded in production via `settlo:seed-demo-data`, which generates strong passwords and prints them once. The phone number is optional at registration. The language pickers are hidden while the interface is English-only; invoices still print in four languages.

### Browser tests

`pestphp/pest-plugin-browser` with Playwright now covers, in a real browser: browser validation disabled on page **and modal** forms, a password error clearing when corrected, the invoice summary following what is typed, the Pillar 3a cap applying when the field is left, and the simulated payment activating in-page with its "no card is charged" wording. **Note:** the browser suite needs Node on `PATH`; without it those tests fail with a Playwright error.

### Still manual

The remaining visual checks in section 4 — Ask Settlo at several widths, the line-item layout at 640/768/1024 px, the "x" search reset in Chrome and Safari, and the "Coming soon" badge in both themes.
