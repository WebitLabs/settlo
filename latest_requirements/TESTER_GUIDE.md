# Settlo — tester guide

**Environment:** https://settlo-one.vercel.app
**Build:** `1122dc4`, deployed 2026-09-21
**Contains:** the September 2026 feedback round (BUG-01…59 and the restructuring email), plus a full gap round.

> **This file holds the demo passwords.** They belong to three throwaway demo accounts on a test environment and are rotatable — re-running the demo seeder issues a fresh set. Still, don't post the file publicly.

---

## 1. Accounts

Log in at the panel URL for the role. All three accounts are demo fixtures with their email already verified.

**Business owner — Anna Müller**
- Sign in: https://settlo-one.vercel.app/app
- Email: `anna@test.ch`
- Password: `OWNER_PASSWORD`

**Accountant — Maria Schneider, Müller Treuhand AG**
- Sign in: https://settlo-one.vercel.app/firm
- Email: `maria@test.ch`
- Password: `ACCOUNTANT_PASSWORD`

**Platform admin**
- Sign in: https://settlo-one.vercel.app/admin
- Email: `admin@settlo.ch`
- Password: `ADMIN_PASSWORD`

The passwords are letters and digits only, so they survive copying out of this document.

Use **`https://settlo-one.vercel.app`** and nothing else: build-specific addresses (`settlo-<hash>-dmi92.vercel.app`) sit behind a Vercel login and freeze to a single build, so testers on those would be locked out or testing old code.

**Registering your own account works**, but the verification email cannot be delivered yet (no mail provider is configured), so you would be stuck on the "verify your email" screen. Use the accounts above until that's set up.

---

## 2. What changed in this release

### The big structural change
The product used to put the *business* at the centre. It now puts the **person** at the centre: one account, any number of business workspaces.

- **`/app` is your personal area** — dashboard, profile, tax profile, your businesses, billing.
- **`/app/w/{id}` is one business** — invoices, clients, expenses, bank accounts, VAT, Ask Settlo.
- **Your tax profile belongs to you, not to a business.** Income tax and AHV are levied on the person, so Settlo estimates your total across all of your sole proprietorships and shows each business its share.

### Registration and onboarding
- One short registration screen, with terms and privacy consent and a phone field with a country code. The phone is optional.
- Email verification is required (see the limitation above).
- "Set up a business" is a three-step flow with a progress indicator, every step skippable, and a wider desktop layout.
- Business type offers GmbH and AG as "coming soon"; only sole proprietorships can be created.

### Money and tax
- **Invoices:** live totals that follow what you type, a "Preview PDF" before sending, per-rate VAT, due dates from your stored payment terms, and your logo on the PDF. An issued invoice is frozen — later edits to your business details no longer rewrite past invoices.
- **No VAT is charged** when the business isn't VAT-registered, and revenue is counted excluding VAT.
- **Expenses:** amount must be above zero, VAT is calculated from the rate as you type, the confirm step is a visible button, and "awaiting confirmation" items are called out wherever they're excluded from a total.
- **Tax:** the AHV shown now matches the AHV deducted. The dashboard shows both "tax owed so far" and what to "set aside monthly" (based on the projected year). Tariff C and eight residence statuses are available, all 2,110 Swiss communes are selectable, and Pillar 3a is capped as you type.
- **VAT threshold is assessed across all of your sole proprietorships**, as Swiss law requires, not per business.
- **New:** a VAT declaration summary (Form 300) and a year-end export.

### Swiss data
Address autocomplete fills postal code, city and canton; a UID fills your business's legal name and address from the public business register.

### Billing — simulated
Payments are **simulated** in this environment. The first business gets a 14-day trial; a second or third is discounted (20%/30%). Pressing pay activates the business immediately, in the page — no card, no redirect, no charge. The screens say so.

### Ask Settlo
Answers are no longer cut off mid-sentence, the layout no longer overlaps at any width, a question from the dashboard opens and sends itself, and "verify with accountant" works (it used to fail outright).

### Accountant and admin
Accountants can open an escalation and read the AI answer they're asked to verify — previously that page was blocked for them. Escalations assigned to a real firm now wait for a human instead of being auto-answered. Admin gained an audit trail for human answers, and revenue figures are labelled as simulated.

---

## 3. Test plan

Work through these in order. For each, note what you expected, what happened, and the URL.

### A. Personal area (as Anna)
| # | Steps | Expected |
|---|---|---|
| A1 | Log in at `/app` | Personal dashboard: a greeting, your businesses, a tax summary, a first-run checklist |
| A2 | Open **Profile** | Your name, email (not editable), phone, address with autocomplete — type a street and pick a suggestion | 
| A3 | In the address field, enter postal code `1000` | City fills as **Lausanne**, canton as Vaud |
| A4 | Open **Tax profile** → set canton **Aargau** | The commune list fills (about 196 options, starting with Aarau) |
| A5 | Set marital status to **Married, dual income (Tariff C)** | Accepted; the estimate recalculates |
| A6 | Type `999999` into Pillar 3a, then click another field | It clamps to the legal maximum with a notice |
| A7 | Enter `-5` children | A styled error appears (not a browser tooltip), before saving |
| A8 | Open **My businesses** | Both demo businesses listed with status badges |
| A9 | Open **Personal tax** | Both "tax owed so far" and "set aside monthly", plus a full-year estimate, and each business's share |

### B. A second business and simulated payment
| # | Steps | Expected |
|---|---|---|
| B1 | `/app/businesses` → **Set up a business** | Three steps with a progress indicator; wide layout on desktop |
| B2 | Enter a UID, e.g. `CHE-148.830.302` | Formats as you type; name and address fill from the register |
| B3 | Skip the invoicing step | Allowed — "I'll do it later" |
| B4 | At the plan step, press **Pay now (simulated)** | Text says no card is charged; the business activates **in the page**, no redirect; a 20% discount is shown |
| B5 | Try paying again from **Billing** | No second charge is possible; payment history shows one entry |
| B6 | Press **Skip for now** instead (on a further business) | The business is created but locked until paid |

### C. Invoicing (inside a business)
| # | Steps | Expected |
|---|---|---|
| C1 | Invoices → Create; add a line `3 × 100.00`, another `1 × 800.45` | The summary updates within about half a second as you type |
| C2 | Click into Unit price, type over the existing value | The number is replaced, not appended (no "0200") |
| C3 | Save as draft → **Preview PDF** | A watermarked draft PDF, without sending |
| C4 | Send it, then open the view page | Send / Mark paid / Edit actions present; QR payment part correct |
| C5 | Change the business name in settings, re-open the sent invoice PDF | The PDF still shows the **old** name — issued invoices are frozen |
| C6 | Check the invoice at 768 px browser width | Line items readable, nothing overflowing |

### D. Expenses and VAT
| # | Steps | Expected |
|---|---|---|
| D1 | Expenses → Create with amount `0` | Rejected with a visible message |
| D2 | Enter `108.10` and VAT rate `8.1` | VAT amount fills as `8.10` automatically |
| D3 | Pick a long category (e.g. Office equipment) | Label fits on one line, full width |
| D4 | Leave one expense unconfirmed, open **VAT summary** and **Tax** | Both say how many expenses and how much CHF are excluded |
| D5 | Open **VAT declaration** | Output VAT, input VAT and the net payable per rate and period |
| D6 | Open **Year-end export** | Downloads a CSV of invoices, expenses and totals |

### E. Clients
| # | Steps | Expected |
|---|---|---|
| E1 | Clients → Create, submit empty | Styled errors under each field — no grey browser bubble |
| E2 | Clear **Payment term**, save | A styled error, not a server error page |
| E3 | Enter postal code `ZZ99`, VAT number `abc` | Both rejected; a valid VAT number gains " MWST" |
| E4 | Search for `zzz`, then clear with the **×** | The full list comes back (try Chrome and Safari) |
| E5 | Delete a client that has invoices | A warning naming the invoices; deletion is refused |

### F. Ask Settlo
| # | Steps | Expected |
|---|---|---|
| F1 | Dashboard → click a suggested question | A conversation opens and sends automatically |
| F2 | Ask something long, e.g. "Explain Swiss VAT registration in detail" | The answer completes; if shortened, it says so in plain text |
| F3 | Resize between roughly 1100 and 1600 px | No overlapping panels or hidden buttons |
| F4 | Click **Verify with accountant** | The escalation is accepted (this used to fail with an error) |

### G. Accountant (as Maria, `/firm`)
| # | Steps | Expected |
|---|---|---|
| G1 | Open the escalation queue | Anna's escalation is listed |
| G2 | Open the escalation itself | **The AI answer is visible** — this was blocked before |
| G3 | Claim and answer it | Anna is notified; the answer appears in her chat |
| G4 | Open a client's books | Read-only invoices, expenses and tax figures |

### H. Admin (`/admin`)
| # | Steps | Expected |
|---|---|---|
| H1 | Open **Business entities** | The list loads (it returned "forbidden" before) |
| H2 | Look at the revenue widget | Labelled as **simulated** — no real money was collected |
| H3 | Open **Audit logs** | Human answers and firm actions are recorded |
| H4 | Impersonate Anna, then stop | The banner shows throughout; stopping returns you to admin |
| H5 | Open **Communes**, edit a multiplier | The "estimated" flag clears automatically |

---

## 4. Known limitations in this environment

These are expected, not bugs:

1. **Verification emails are not delivered** — no mail provider is configured, so self-registration can't be completed. Use the accounts above.
2. **Uploaded receipts are not scanned automatically.** The background worker has no trigger on the current hosting plan, so a receipt stays "processing". Everything else about expenses works.
3. **Payments are simulated.** No card is charged, and no Stripe account is involved.
4. **SMS verification is off**; the phone number is validated for format only.
5. **Only sole proprietorships** can be created — GmbH and AG show "coming soon".
6. **The interface is English only** for now. Invoice PDFs are produced in German, French, Italian and English.
7. **Scheduled jobs run only twice a day** (trial expiry, overdue invoices). Subscription renewals and quota resets are not running yet.

---

## 5. Reporting

Please include: the account you used, the URL, what you expected, what happened, browser and window width, and a screenshot. Layout problems especially need the width.

The previous round's bug numbers (BUG-01…59) are in `latest_requirements/`; referencing them when something reappears makes it much quicker to trace.
