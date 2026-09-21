# Settlo — Gap analysis of the September 2026 implementation

**Date:** 2026-09-17
**Compared against:** `latest_requirements/IMPLEMENTATION_PLAN.md`, `email_feedback.txt` and the testing-feedback CSV.
**Code reviewed:** the working tree on `master`; nothing is committed yet (243 changed paths).

## 1. Summary

Every planned task from Phase A to Phase F has been built.

- **Tests and style:** all 636 tests pass (2,832 assertions) and Pint reports no style issues. The Phase B migration, tenancy and tax tests also pass on the Postgres test database.
- **What's left:**
  - **Two high-risk billing problems:** a free-plan loophole when Stripe isn't configured, and the possibility of paying twice for one workspace.
  - **About eight medium problems**, mostly in billing, tax shares and form re-validation.
  - **A set of low-severity items and visual checks.**
  - **Open decisions for you**, listed in section 5.

| Phase | Status | Open problems (High / Med / Low) |
|---|---|---|
| A — form foundations and bug fixes | Done; 2 tasks partial | 0 / 3 / 9 |
| B — user-centric architecture | Done | 0 / 1 / 6 |
| C — registration and onboarding | Done; C4 partial; C6 and C7 differ from the plan | 0 / 1 / 2 |
| D — personal area and dashboards | Done | 0 / 0 / 4 |
| E — per-workspace billing (Stripe) | Done, with gaps | 2 / 4 / 3 |
| F — Swiss data lookups | Done | 0 / 1 / 2 |

## 2. Status by task

Legend: ✅ done · 🟡 partial or different from the plan · 👁 needs a visual check · ❌ missing

### Phase A

| Task | Status | Note |
|---|---|---|
| A1 Native browser validation off | ✅ 👁 | Check in a real browser that the browser's own error tooltips no longer appear. |
| A2 Abbreviations kept in error messages | ✅ | |
| A3 Re-check a field when the user leaves it | 🟡 | `CantonSelect` and `CommuneSelect` never re-check, so a fixed canton keeps its old error (problem M1). |
| A4 "Verify with accountant" error 500 | ✅ | |
| A5 AI answers cut off | ✅ | The "shortened" notice shows raw `_…_` because the chat displays plain text (problem L5). |
| A6 Business settings: save each tab separately, IBAN kept after reload | ✅ | The tax tab now links to the personal tax profile (Phase B). |
| A7 Extra IBAN checks on bank accounts | ✅ | BUG-37 was never reproduced (Q9). |
| A8 Invoice layout and live totals | ✅ 👁 | The view page formats money differently from the form (L6). |
| A9 Invoice view actions and to-do links | ✅ | |
| A10 Expense validation and VAT calculation | ✅ 👁 | Check the width of the category field. |
| A11 Confirm-expense button and exclusion notices | 🟡 | Confirming expenses skips the permission check (M2). The dashboard notice leaves out the CHF total (L7). |
| A12 Ask Settlo layout and `?q=` links | ✅ 👁 | The screenshots at 1114, 1280 and 1600 px weren't taken; the chat may be cut off on phones narrower than about 352 px (L8). |
| A13 AHV shown vs AHV deducted | 🟡 | The calculation is fixed, but the **workspace** breakdown mixes the owner's full personal deductions with amounts scaled to the business (M3). |
| A14 No VAT for non-registered businesses | ✅ | The breakdown still says "Gross revenue" (L1). |
| A15 Registration rate limit | ✅ | |
| A16 Swiss postal code, UID and VAT number rules | ✅ | The MWST suffix is added only if the user typed one (L9). `ProfileFields::postalCode()` is unused. |
| A17 Client delete warning and search reset | ✅ 👁 | The search-reset fix (BUG-42) is untested in a real browser. |
| A18 Children and Pillar 3a | ✅ | |
| A19 Communes for every canton | ✅ | |
| A20 Tariff C and residence status | ✅ 👁 | Check that the flag emoji show in the dropdown. |
| A21 Onboarding copy | ✅ 👁 | Check the "Coming soon" badge and the UID input mask. |
| A22 Autofill hints | ✅ | |
| A23 Fresh-setup test failure | ✅ | |

### Phase B and F3

| Task | Status | Note |
|---|---|---|
| B0 Backup before migrating | ❓ | Nothing in the repo shows it was done (see section 5). |
| B1 Two owner panels | ✅ | The `app` panel doesn't set `simplePageMaxContentWidth`, so login and password reset stay narrow (L10). |
| B2 Data model | ✅ | The email-verified and phone-country backfills have no tests. |
| B3 Combined tax estimate | 🟡 | Shares on the owner's other workspaces aren't recalculated (M4). |
| B4 Move the code | ✅ | `app/Filament/Workspace/Tenancy` is an empty folder. |
| B5 Hard-coded panel references | ✅ | Old `/app/{uuid}` links now return 404 (M5). |
| B6 Authorization | ✅ | Another user's workspace returns 404 (tested). The phone check lives in middleware, not in the policy (L11). |
| B7 Tests | ✅ | `WorkspaceTenancyTest` and `PersonalAreaTest` don't exist; their coverage is split across other test files. |
| F3 Full commune list | ✅ | Re-running the import overwrites commune multipliers that an admin edited and those communes' dates (L12). |

### Phase C

| Task | Status | Note |
|---|---|---|
| C1 Email verification | ✅ | A production mail provider is still needed (Q5). |
| C2 Registration form | ✅ | |
| C3 Phone number with country code | ✅ | |
| C4 SMS one-time code (switched off) | 🟡 | Only the log driver exists. Changing the number keeps the old code valid (M6). |
| C5 Set up a business | ✅ | "14-day" is hard-coded instead of read from config. Unsynced Stripe prices leave a half-created business (M7). |
| C6 Desktop layout | 🟡 👁 | The logo wasn't added (the repo has no wordmark), and login is still narrow (L10). |
| C7 First-run experience | 🟡 | The checklist starts with "Add your home address" (per D1), not "Set up your business" (per C7). The plan contradicts itself here. |

### Phase D and F1/F2

| Task | Status | Note |
|---|---|---|
| D0 Business numbers | ✅ | |
| D1 Personal dashboard | ✅ 👁 | |
| D2 Personal profile | ✅ | Phone is optional here (L13). Saving an address doesn't create a missing tax profile. |
| D3 Tax profile | ✅ | |
| D4 Personal tax | ✅ | With 3 or more businesses the shares can add up to 99.9 % (L14). |
| D5 My businesses | ✅ | |
| D6 Workspace dashboard | ✅ 👁 | Check the widget grid at 1280 and 1600 px. |
| D7 Navigation | ✅ | |
| F1 Address search and postal-code autofill | ✅ | Postal code 1000 fills "Lausanne 25" (L15). |
| F2 UID register lookup | 🟡 | The VAT "active" code `'2'` hasn't been checked against eCH-0108 (M8). |

### Phase E

| Task | Status | Note |
|---|---|---|
| E1 Cashier install | ✅ | An empty `STRIPE_WEBHOOK_SECRET` means webhooks aren't signature-checked (H3). |
| E2 Data model | ✅ | Discount tiers are 0/20/30 % and yearly = 10 × monthly, both set in config. |
| E3 Services, webhooks, commands | 🟡 | H1, H2, H4 |
| E4 Filament billing screens | 🟡 | Expired workspaces aren't fully read-only (M9). "Billing" may appear twice in the menu (L16). |
| E5 Tests | 🟡 | There's no `StripeGateway` test and no written Stripe test-mode steps (L17). |

## 3. Problems, most severe first

### High

- **H1 — Production can hand out paid plans for free.** With `SETTLO_PAYMENT_GATEWAY=stripe` but no `STRIPE_SECRET`, the app quietly falls back to `DummyGateway` (`app/Providers/AppServiceProvider.php:51-57`).
  - **Effect:** the signed dummy checkout activates 2nd and 3rd workspaces without payment, creates fake "paid" payment rows that count toward MRR, and `RenewSubscriptions` keeps renewing them.
  - **Fix:** allow the dummy gateway only in `local` and `testing`; anywhere else, fail loudly.
- **H2 — A customer can pay twice for one workspace.**
  - **Trial case:** "Choose plan" stays available for `trialing` workspaces (`app/Filament/Personal/Pages/Billing.php:57-62`), and `StripeGateway::checkoutUrl` doesn't check for an existing Stripe subscription of the same type.
  - **After paying for a new workspace:** Stripe returns the owner to the dashboard. If the webhook hasn't arrived yet, the middleware sends them back to Billing, which opens "Choose plan" again.
  - **Fix:** refuse checkout when an active or trialing Stripe subscription exists, and point the success URL to `Billing?checkout=success` without opening "Choose plan" again.
- **H3 — Unsigned Stripe webhooks are accepted** when `STRIPE_WEBHOOK_SECRET` is empty, which is the default in `.env.example`. An owner could forge an "active" event for their own subscription.
  - **Fix:** fail at startup in production when the Stripe secret is set but the webhook secret isn't.
  - I rated this high because it lets someone get a plan without paying.
- **H4 — Stripe can charge full price while the app shows the discount.** When `STRIPE_COUPON_WORKSPACE_20` / `_30` aren't set, checkout runs without a coupon (`app/Services/Billing/WorkspacePricing.php:79-88`).
  - **Fix:** default the coupon ids to the `settlo-workspace-{n}` ids that `settlo:stripe-sync-plans` creates, or throw when a discount is due but no coupon is set.

### Medium

- **M1 — A fixed canton keeps its "required" error** (BUG-31 is only partly fixed). `CantonSelect` and `CommuneSelect` should re-check when they change, and need a test.
- **M2 — Confirming an expense skips the permission check** (`ExpensesTable.php:94-165`), so read-only (expired) workspaces can still confirm expenses. **Fix:** add `->authorize('update')`.
- **M3 — The workspace tax breakdown doesn't add up for owners with 2 or more businesses.** `TaxEngine::estimateBusiness()` scales the amounts to the business's share but copies the unscaled personal deductions.
- **M4 — Tax shares on sibling workspaces go stale.** An invoice or expense change only recalculates that workspace and the personal estimate. **Fix:** recalculate all of the owner's sole-proprietorship workspaces (`estimateAllFor($owner)`).
- **M5 — Old `/app/{uuid}/…` links return 404.** This includes links already stored in database notifications. **Fix:** add a redirect route to `/app/w/{uuid}/…`.
- **M6 — Changing the phone number keeps the old code valid.** `PersonalProfile::saveProfile` doesn't clear `phone-otp:{id}`.
- **M7 — Unsynced Stripe prices leave a half-created business.** `StripeGateway` throws after provisioning has already been saved, so the owner gets a 500 and an incomplete workspace.
- **M8 — The UID VAT "active" code `'2'` is unconfirmed** (`app/Services/Registry/UidRecord.php:13`). **Fix:** check it against the eCH-0108 code list.
- **M9 — Expired workspaces can still edit bank accounts and business settings.** There's no `BankAccountPolicy`, and `BusinessSettings` only checks ownership.

### Low

1. **L1** — The breakdown says "Gross revenue" although the amount now excludes VAT; rename it to "Revenue (excl. VAT)".
2. **L2** — `PLAN.md` still says "216 tests" and doesn't list the new panels; `PROGRESS.md` has no summary of Phases A–F or the new environment variables.
3. **L3** — A trial can be granted twice if the owner sets up two businesses at the same moment; lock the user row.
4. **L4** — Ask Settlo routes don't require a verified email and don't reject suspended users.
5. **L5** — Chat replies show raw markdown.
6. **L6** — Invoice view money format differs from the form's; the timeline is half-width when there are no payments.
7. **L7** — The dashboard's pending-expenses notice leaves out the CHF total.
8. **L8** — `min-w-[20rem]` can cut off the chat on very small phones.
9. **L9** — The VAT-number suffix is kept as typed instead of defaulting to "MWST".
10. **L10** — Login and password reset are still narrow; add `simplePageMaxContentWidth` to the `app` panel.
11. **L11** — `BusinessEntityPolicy::create()` ignores the phone-verification flag.
12. **L12** — Re-running the commune import overwrites admin-edited multipliers that are still marked as estimated, and resets `effective_from` on existing communes.
13. **L13** — Phone is optional on the personal profile; clearing it with SMS verification on sends the user to "verify phone" with no number.
14. **L14** — Rounding can make the shares add up to 99.9 %.
15. **L15** — Postal code 1000 fills "Lausanne 25".
16. **L16** — "Billing" may appear twice in the workspace menu.
17. **L17** — There's no `StripeGateway` test and no written Stripe test-mode steps.
18. **L18** — Unused code: `ProfileFields::postalCode()` and the empty `Workspace/Tenancy` folder.
19. **L19** — Missing tests: the multi-business VAT notice, the channel authorization fix, the user backfills, and the commune admin filter.

## 4. Visual checks still to do (need a logged-in browser)

1. Styled errors instead of the browser's own tooltips on the register, client and expense forms.
2. The invoice line-item table on mobile and tablet, and selecting a number when the field gets focus.
3. Ask Settlo at 1114, 1280 and 1600 px, including the drawers.
4. The search reset (the "×" clear button) in Chrome and Safari.
5. The "Coming soon" badge (light and dark mode), the UID mask, and the flag emoji.
6. The personal and workspace dashboard grids at 1280 and 1600 px.
7. Whether "Billing" appears twice in the workspace menu.
8. The seven manual checks in section 10 of the plan.

## 5. Decisions (Marius, 2026-09-17)

| # | Question | Decision |
|---|---|---|
| 1 | What to fix | Everything: High, Medium and Low. |
| 2 | Checklist order | "Set up your business" comes first (C7). |
| 3 | UID lookup | Keep as built: register data overwrites what the user typed. |
| 4 | Old `/app/{uuid}` links | A 404 is fine, so M5 is **won't do**. |
| 5 | "Skip for now" on a 2nd or later business | Create the workspace as `incomplete` (locked) and go to the dashboard; the owner pays later from Billing. |
| 6 | Discount counting | Keep as built: trialing and past-due workspaces count. |
| 7 | Pillar 3a for self-employed people without a pension fund | Cap at the lower of 20 % of net income and CHF 35,280. |
| 8 | Expense VAT rate | Default to 0 % for businesses that aren't VAT-registered. |
| 9 | IBAN | Accept letters in the account part of CH/LI IBANs (ISO 13616). |
| 10 | VAT number | Add " MWST" when the user types no suffix. |
| 11 | GmbH, AG or Association businesses in production | None exist; settings stay limited to sole proprietorships. |

## 6. Still open (not blocking)

- Production backup before deploying (B0), and how communes and postal codes are loaded in production (seeder or import command).
- Production mail provider (Q5), SMS provider (Q6), and the Stripe account's legal entity and real discount percentages (Q7).
- BUG-37: the exact steps to reproduce (Q9).

## 7. Resolution (2026-09-17)

Every problem from section 3 is fixed, except M5, which you decided not to fix. The full suite passes (**743 tests, 3,264 assertions**, up from 636) and Pint reports no style issues. Nothing is committed.

| Id | Result |
|---|---|
| H1 | The dummy gateway is allowed only in local/testing (or when explicitly set to `dummy` outside production). Anywhere else, resolving the gateway throws. `RenewSubscriptions` no longer renews dummy rows outside those environments. |
| H2 | No new checkout starts while Stripe already bills the workspace (trialing, active or past due), and "Choose plan" is hidden then. After payment the owner returns to `Billing?checkout=success` and sees "Payment received, activating…". |
| H3 | New `RejectUnsignedStripeWebhooks` middleware refuses webhooks (403) outside local/testing when `STRIPE_WEBHOOK_SECRET` is empty. |
| H4 | Coupon ids default to `settlo-workspace-{n}`; an unknown discount tier throws. |
| M1 | `CantonSelect` and `CommuneSelect` now re-check when changed, and changing the canton clears the commune. |
| M2 | Confirming an expense, alone or in bulk, now checks the `update` permission. |
| M3 | Each business's tax estimate stores its own share of the deductions, so the workspace breakdown adds up. |
| M4 | Recalculating a sole proprietorship now refreshes the personal estimate and all of the owner's workspaces. |
| M5 | Won't do: old links return 404 (decision 4). |
| M6 | `PhoneVerifier::forget()` drops the old code when the number changes, and a new code is sent. |
| M7 | The Stripe price and coupon are checked before a paid business is created. |
| M8 | Confirmed against eCH-0108 V5.1 §3.2.4.1: `2` means registered. `vatEntryStatus` (deleted entries) is now read as well. |
| M9 | New `BankAccountPolicy`; business settings can't be saved in a read-only workspace. |
| L1–L19 | All fixed, including the docs (`PLAN.md`, `PROGRESS.md`, and a Stripe test-mode runbook in `PROGRESS.md`). |
| Decisions 2, 5, 7–10 | Implemented as recorded in section 5. |

**Deployment note:** production now refuses to handle billing without Stripe keys and refuses webhooks without a signing secret. Set the Stripe variables on Vercel before deploying this version; the steps are in `PROGRESS.md`.

**Still to do by hand:** the visual checks in section 4 and the Stripe test-mode runbook.

## 8. Simulated payments (2026-09-21)

**Decision:** payments are simulated for now — no Stripe redirect and no external call anywhere, including the live deployment, and the screens say plainly that no card is charged.

**Gap analysis of the payment flow as it was:**

| Area | Finding |
|---|---|
| Starting a payment | The 2nd and later businesses were sent out of the app to a checkout page: Stripe's hosted page, or a signed link to an internal fake-checkout page. |
| Becoming paid | Only Stripe's background notification set the paid state, which is why Billing showed "Payment received, activating…" and waited. |
| Live site | The fake gateway was forbidden outside local and test environments and its checkout page returned 404, so the live site could not take any payment without real Stripe keys. |
| Correctness | Activation wasn't wrapped in a transaction, had no protection against duplicate ledger rows, and a double click would write a second payment and restart the billing period. |
| Copy | The set-up screen promised "secure payment with Stripe". |

**What a payment must do**, and what the simulation now reproduces: status Active, billing period set, monthly AI-answer quota reset, any pending plan change or cancellation cleared, and exactly one `paid` ledger row (which the revenue widget reads). The workspace unlocks on its own; nothing else is tied to payment.

**Implemented:**

- New `simulated` payment mode, chosen only by an explicit `SETTLO_PAYMENT_GATEWAY=simulated`, never as a fallback. It is the default value in config and `.env.example`.
- `SubscriptionService::payNow()` activates in-process under a row lock and is idempotent; activation now runs in a transaction and the ledger has a unique index on the gateway reference.
- Billing and "Set up a business" pay in the page: no redirect, a success notification, and the paid state straight away. The Stripe "activating…" waiting state is hidden while simulating and still works for Stripe.
- "Skip for now" still creates a locked (incomplete) business with no payment.
- Wording shown to owners: the button reads "Pay now (simulated)"; the modal says no card is charged and no real payment is taken (and, on a trial, that the trial ends now); the notification reads "Payment simulated — no card was charged".
- `settlo:renew-subscriptions` renews simulated subscriptions.
- Stripe is untouched: `StripeGateway`, the webhook listener and their tests are unchanged, and setting `SETTLO_PAYMENT_GATEWAY=stripe` with keys restores real checkout.

**Result:** 764 tests pass (3,384 assertions), Pint clean, nothing committed.
