# ADR-041: The Company as a Commission Payee — Which Plans Honour the House Account

- **Date:** 2026-09-23
- **Status:** Accepted for Unilevel and Affiliate. The other four plan types do **not** honour it and the decision on whether they should is **open (BR-7)** — see §4.
- **Author:** ag-lead
- **Depends on:** ADR-011 (multi-model agent architecture), ADR-035 (certification is an access gate, not a rate key), BR-1, BR-4
- **Supersedes nothing.** The house account shipped on 2026-09-15 without an ADR; this records the rule it established and the correction made to it.

## Context

Owner, 2026-09-15: *"หัวหน้าทีมในที่นี้มีได้ 2 ความหมาย คือหัวหน้าทีมที่เป็น user จริงในระบบ กับหัวหน้าทีมที่เป็นตัวบริษัทเองที่ได้ค่าคอมจากการขาย เช่น Thailife"*.

Owner, 2026-09-23, on the same feature from the other end: *"ที่ผมอยากได้คือ ผู้แนะนำ = บริษัท ทำให้ setup ได้"*.

Both sentences ask for one thing: the company itself must be able to occupy an upline position and be paid for it, so a sale made by somebody with no human above them still earns the company its margin instead of earning nobody anything.

## 1. The Mechanism — a `users` Row, Not a Second Kind of Payee

The company gets a seat in its own hierarchy: one `users` row, `role = company_admin`, `manager_id = null`, pointed at by `companies.commission_house_user_id`. Turning it on also sets `manager_id` on every agent in that company who had none, and anyone who joins later without an upline is attached the same way.

Four designs were weighed (a nullable `agent_id` beside a `payee_company_id`, a separate earnings table, a snapshot column on the seller's row, and this). The three alternatives all teach the money code a second shape of recipient, and every query that sums, allocates, reverses or withdraws has to learn it — `availableSatang()` getting it wrong means an agent can withdraw the company's margin. This design teaches the money code nothing: the payout walk pays the seat by walking the chain it already walks.

**The cost is moved, not removed.** A row that looks like a person now exists, so it has to be kept out of every list of people and off every control a person gets: no login, no password reset, no manager of its own, no withdrawal request, excluded from the agent roster and the upline picker. Those guards are tested in `CommissionHouseAccountTest`.

## 2. The Certification Exemption — What It Does and Does Not Widen

BR-1/ADR-035: certification is a gate on being paid an override — you are eligible because you passed something. A company cannot sit an exam, so applying that gate to the seat means the company is never paid whatever anybody configures, and the seller is never charged for it either.

**Rule:** the payout walk skips the certification check for, and only for, the row the company points at — `User::isCommissionHouseAccount()`, never `isCompanyAdmin()` and never "has no tier". An ordinary company admin who never certified stays skipped; widening the test to a role would start paying real people who never qualified.

**The ledger records `cert_tier_id_at_time = NULL` on the company's rows.** The rejected alternative was to write the seat a certification row and touch no code — it puts a qualification on a money row for an entity that never earned one, and the column would read "Basic" forever. The column is nullable; null is the honest answer.

## 3. The Correction (2026-09-23)

Affiliate reaches its payee through a **second resolver** — one hop, resolved before the seller's own row is written, because two of the three payout modes carve the payee's share out of that row and BR-4 forbids editing it afterwards. That resolver was written before the house account existed and carried the cert gate unconditionally.

The result was a silent, total failure of the feature on that plan: a company running Affiliate could switch the seat on, see the card report "บริษัทรับด้วย", and be paid nothing forever. The one case the seat exists for — a seller whose only upline **is** the company — was exactly the case that resolver refused, and it refused it with no error, no zero row and nothing on any screen.

`resolveAffiliateOverride()` now applies the same narrowly-worded exemption as the Unilevel walk, and its `managerTier` is `?CertTier`.

**Affiliate still walks exactly one hop.** The seat sits at the top of the tree, so under Affiliate the company is paid on sellers who have no human introducer, and not on sellers who have one — unlike Unilevel, where the seat sits above the human leader and both are paid. This is a property of the plan, not a limitation of the seat.

**The payout mode stays a setup choice** (step 4.1 / `companies.commission_override_mode`, overridable per rate rule). The owner was asked which mode should apply when the introducer is the company and answered that it must be settable, so all three are pinned by test rather than one being made the blessed path:

| mode | seller keeps | company is paid | company pays out |
|---|---|---|---|
| Additive | full rate | introducer rate, on top | both |
| DeductFromSale | rate − introducer rate | introducer rate (of the sale) | the seller's rate only |
| DeductFromCommission | rate − introducer rate applied to the rate | introducer rate **of the seller's commission** | the seller's rate only |

Which one a company promises its agents is a business rule and remains the owner's (BR-7). Note for whoever configures it: under Additive with the company as the introducer, the company pays itself — a real ledger row and a real payout queue entry into its own bank account, netting to nothing. That is a legitimate arrangement for bookkeeping reasons and is not blocked, but it is unlikely to be what somebody means.

## 4. Known Gap — Four Plans Do Not Honour the Seat

Turning the seat on has no effect under Binary, Matrix, Stairstep/Breakaway or Generation, each for a reason of its own:

| plan | why the seat earns nothing |
|---|---|
| Binary | pays from matched leg volume; the seat is on no leg |
| Matrix | walks `matrix_placements`, a tree the seat is not placed in |
| Stairstep/Breakaway | pays the difference between two ranks; the seat holds no rank |
| Generation | counts breakaway-ranked ancestors; the seat holds no rank |

The screen does not say so. A company on one of those four can switch the seat on, be told it is on, and never be paid — the same class of silent failure §3 fixed for Affiliate, with four more instances of it.

**This is left open deliberately.** Three of the four would need a business answer before any code is written — what rank does a company hold, which leg is it on — and inventing one would breach BR-7. The owner chose on 2026-09-23 to fix Affiliate alone and revisit the rest.

**Interim recommendation for ag-ui:** hide or disable the step 4.2 card on those four plan types, with a line saying the plan does not pay a company seat. Telling somebody a setting is on when it cannot do anything is worse than not offering it.

## Consequences

- Commission paid to a company is an ordinary ledger row with an ordinary payee, so reporting, reversal, withholding tax and the payout queue all reached it for free — and will reach any future plan that starts honouring the seat, with no further work.
- Anything that assumes `commission_ledger.agent_id` points at a person is wrong, and was already wrong before this ADR. `cert_tier_id_at_time` being null on a row with a non-null `agent_id` is normal, not a data defect.
- The seat is never deleted. `commission_ledger.agent_id` restricts deletes and must (BR-4), so disabling detaches everyone and forgets the pointer; the row stays, carrying its history.
