# TASK-180 / TASK-181 — the remaining "has this deal closed?" answers

- **Owner:** ag-lead (spec) → ag-dev (two parallel workstreams) → ag-qa
- **Date:** 2026-08-13
- **Origin:** ag-qa's TASK-179 review (finding 2) + ag-dev's step-5 sweep. Five call sites still
  answer TASK-179's question their own way.
- **Related:** ADR-026, CLAUDE.md §4.3, BR-3, BR-5, BR-6, D1/D2/D4 of TASK-179 §2

---

## 1. The one rule

TASK-179 established **`App\Services\Referral\ClosedDealPredicate`** as the single answer to "has
this deal reached Complete Payment?", and **D1/D2** as the single answer to "what is ยอดขาย?"
(money the customer paid, from paid orders, with the uncountable part disclosed rather than
estimated).

Five places still answer one of those two questions on their own, all written before ADR-026 made
the stage sequence configurable. Each carries the same two-stage list
`[complete_payment, ongoing_next_meeting]`, so **a deal advanced into จัดส่ง / นัดใช้บริการ /
ติดตามผล stops being "closed"** in each of them.

This is the sixth through tenth copy of that list found this week. After this task there must be
**none**.

## 2. Workstream A — `MeService` (TASK-181 + finding 2's first item)

`backend/app/Services/Sales/MeService.php`. Two separate defects in one file; fix both together
because they are the same screen.

**A1 — "open deals" (lines ~128 and ~186).**

```php
$terminal = [PipelineStage::CompletePayment->value, PipelineStage::OngoingNextMeeting->value];
... ->whereNotIn('referrals.current_stage', $terminal)
```

A deal at `delivery` or `follow_up` is reported to the agent as **an open deal still to work**, on
`/me/tasks` and on the task-count badge. Invert `ClosedDealPredicate` rather than writing a
negation of your own — if it needs an `isOpen`/`scopeOpen` counterpart, add it **to that class**,
so the two can never disagree.

**A2 — the home screen's target progress (lines ~219-230).**

Month-to-date `sales_satang`, rendered as the progress bar against the admin-set target
(`frontend/src/views/HomeView.vue:252-261`), is computed from
`commission_ledger.sale_price_satang_at_time` where `payment_status = paid`, with `?? 0` for
missing rows. **An agent whose customer paid in full sees 0% progress until the company runs
payroll.** That is D1/D2's rejected source on a third screen, and it is the one an agent looks at
daily.

Rebuild on paid orders, same definition as the dashboard and the sales-team card. Month-to-date
must bucket on the **sale** date (D3), not the commission payout date.

**Disclosure:** the dashboard and the sales-team card both surface a "closed but no order" count
so the total is never silently short. Decide whether the agent's own progress bar needs the same
and **say which you chose and why** — a target bar that silently under-reports is worse than one
that says "อีก N ดีลยังไม่มีคำสั่งซื้อ". Do not just drop it because it is fiddly.

## 3. Workstream B — the three reporting call sites

**B1 — `app/Services/Catalog/ProductGradingService.php:36,52`** (`SOLD_STAGES`). The docblock
claims "reached or passed CompletePayment" and then enumerates two stages. A product whose deals
get advanced into post-sale stages loses `sold_count` and can fall to grade D. ABC grading drives
merchandising decisions, so this one changes what the business does.

**B2 — `app/Services/Platform/PlatformReportService.php:34,58`** (`SOLD_STAGES`, duplicated with a
comment admitting the duplication). The field is literally named `referrals_completed_payment`.

**B3 — `app/Services/Gamification/BadgeConditionEvaluator.php:94-97`** —
`referrals_completed_count` is `where('current_stage', CompletePayment)` **exactly**, the
strictest of the five: advancing a paid deal by one stage *removes* it from a BR-5 badge condition
an agent had already earned progress toward.

> **Flag before you finish, do not decide alone:** correcting B3 makes the count go **up**, so
> agents may become eligible for badges the evaluator previously withheld. That is them receiving
> what they earned, not an inflation — but it is a visible, user-facing change to a gamification
> outcome, so **report how many agents/badges it would affect** and let ag-lead put it to the
> human before it ships. Do not suppress the correction to avoid the awkwardness.

## 4. Leave alone — these ask genuinely different questions

Confirmed by ag-qa; listed so nobody "unifies" them later:

- `OrderService::confirmPayment()` — `hasReachedStage()` / `nextStageFor()`, a per-referral
  template-aware **gate**, not an aggregate. Right tool.
- `ReferralService::splitIsStillEditable()` — `isBeforeStage()`, "may this still be edited".
- `StairstepCommissionService:136-146` — `pipeline_stage_logs.to_stage = complete_payment AND
  changed_at >= window`. A **dated volume** question the boolean predicate cannot express.

## 5. Known asymmetry in the predicate — do not widen it here

`ClosedDealPredicate` matches `current_stage = 'complete_payment'` OR an existing
`to_stage = complete_payment` log. A row written **outside the application** at a post-payment
stage with no log is missed. Every application write path produces a log, so this is a
fixture/import-only gap. ag-qa raised it as a note; **it is out of scope for this task** — if you
believe it should be closed, say so and it becomes its own change with its own test.

## 6. Tests

For each of the five call sites, a test that **fails if the old two-stage list is restored**.
Prove it by mutation and report the observed failure count — the standard on TASK-176/177/179.

Plus:
- B1: a product whose only deals sit at `delivery` still counts as sold
- B3: an agent's badge progress does not decrease when a paid deal advances
- A2: a customer who paid but whose commission is still pending moves the progress bar
- BR-6 on every changed query, with a cross-tenant test that fails if the scope is removed —
  not empty-vs-empty

## 7. Definition of Done

CLAUDE.md §9, plus: **zero remaining copies of the two-stage closed list in `backend/app`** (grep
and show it), no second answer to D1, BR-3 integer satang throughout, and B3's user-visible
consequence reported to ag-lead before merge.
