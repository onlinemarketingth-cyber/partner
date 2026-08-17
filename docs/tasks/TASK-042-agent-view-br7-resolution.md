# TASK-042: Agent View (มุมที่ 1) — BR-7 resolution follow-up

Follow-up to TASK-039. Four BR-7 items were flagged as unfinalized in TASK-039
and surfaced again during UAT-013. The human (KreangYot) has now confirmed all
four. This spec turns those confirmed decisions into buildable acceptance
criteria. Owner: ag-dev (backend), ag-ui (frontend).

## Confirmed decisions (source: chat, 2026-07-23)

1. **Reward Points**: Option B — a separate `reward_point_ledger`, decoupled
   from `xp_ledger` (BR-5). Points are earned automatically, mirroring every
   XP award 1:1. Spending points on redemption must NOT reduce XP — Level and
   Leaderboard (BR-5-derived) stay untouched by redemption activity.
2. **Reward fulfillment**: physical rewards must be supported. Shipping
   details are captured **at the moment the agent submits the redemption
   request** (not pulled from a stored profile address).
3. **Promotion bonus payout**: qualifying event = referral reaching
   **Complete Payment** (same trigger as BR-4 commission and bonus XP).
   Payout timing must be **admin-configurable per promotion**: immediate
   (pay the instant the referral hits Complete Payment) or monthly batch
   (accumulate, pay out on a scheduled monthly run — same pattern as the
   existing Binary/Stairstep scheduled commands).
4. **Cert-tier targeting**: admin must be able to choose, per announcement
   or promotion, between **exact tier match** (today's only behavior) and
   **this tier and above** (using `cert_tiers.sort_order`).

## Grounding facts (confirmed via code read, not assumed)

- `agent_promotions` has no goal-count/milestone field — `bonus_value` is a
  flat amount per qualifying event, not a "sell N, get X" ladder. No
  repeat-limit/cap field exists either. **Design decision (ag-lead, flagged
  below as a new open question, not silently assumed):** pay the bonus once
  per qualifying referral event, unlimited events per agent per promotion —
  this matches the schema as it exists today. If a "one bonus per agent per
  promotion" cap is wanted, that is a new field/rule requiring its own
  confirmation — not built in this pass.
- All XP awards funnel through exactly one method:
  `GamificationService::awardXp()` (`app/Services/Gamification/GamificationService.php`).
  The Reward Points mirror hook lives here — nowhere else.
- The referral pipeline already has a single choke point where BR-4
  commission and bonus XP both fire together:
  `PipelineService::advance()`, guarded on
  `$toStage === PipelineStage::CompletePayment`, inside one DB transaction.
  The new promotion-bonus check is added as a third block in this same
  method/transaction — not a new hook elsewhere.

## Acceptance criteria

**1. Reward Points ledger**
- [ ] New `reward_point_ledger` table (company-scoped, TenantScope), append-only,
      mirrors `xp_ledger` shape 1:1 (points_awarded, source_type, source_id, xp_ledger_id).
- [ ] `GamificationService::awardXp()` writes one `RewardPointLedger` row for
      every `XpLedger` row it creates — single hook, per grounding facts above.
- [ ] Backfill: existing `xp_ledger` history is mirrored into
      `reward_point_ledger` in the same migration (no agent loses retroactive
      points from before this feature existed).
- [ ] `RewardRedemptionService::calculateAvailablePoints()` reads from
      `reward_point_ledger`, not `xp_ledger`.
- [ ] Redeeming a reward does not write to `xp_ledger` — Level/Leaderboard
      unaffected (verify by reading, not just by absence of new code touching
      those tables).

**2. Physical reward fulfillment**
- [ ] `reward_items.reward_type` (physical/digital), admin-settable per item.
- [ ] `reward_redemptions` gains shipping capture fields (recipient name,
      phone, address) — required only when the item being redeemed is
      `physical`; captured on the redemption request form itself.
- [ ] `tracking_number` field, editable by Admin any time after a redemption
      is Approved, visible to the agent once set.

**3. Promotion bonus payout**
- [ ] `agent_promotions.payout_timing` (immediate/monthly_batch), required
      field, set at promotion creation.
- [ ] New Service (owns BR-4 immutable-ledger-entry discipline) evaluates
      active, matching promotions inside `PipelineService::advance()`'s
      existing Complete-Payment block.
- [ ] Every qualifying event is recorded (for audit/traceability) regardless
      of timing; `immediate` writes the `commission_ledger` entry in the same
      transaction; `monthly_batch` defers the ledger write to a new scheduled
      command (same pattern as existing Binary/Stairstep jobs), paid once a
      calendar month.
- [ ] Ledger entries created this way are distinguishable from ordinary
      product commission (BR-4 says never conflate — needs its own
      source/type marker, exact mechanism left to ag-dev to match existing
      `commission_ledger` schema conventions).
- [ ] Audit log entry per payout (Section 6: "record every action that
      affects money").

**4. Cert-tier "and above" targeting**
- [ ] `target_cert_tier_mode` (exact/and_above) on both `announcements` and
      `agent_promotions`, default `exact` (preserves current behavior for
      existing rows).
- [ ] When `and_above`, targeting compares `cert_tiers.sort_order` (agent's
      highest passed tier) `>=` the target tier's `sort_order`.
- [ ] Admin UI: a mode toggle next to the existing cert-tier picker on both
      the Announcement form and the Agent Promotion form.

**Cross-cutting**
- [ ] Tenant isolation holds for every new table/query (company_id scope).
- [ ] Feature tests cover: points mirroring, redemption deduction not
      touching XP, physical-redemption validation, promotion payout under
      both timings, tier targeting both modes.
- [ ] Money stored as integer satang (BR-3) — no floats anywhere in the new
      bonus-calculation code.

## Out of scope (this pass)

- A repeat-limit/cap on promotion bonus payouts (see grounding facts above —
  new question if wanted later).
- Digital-reward auto-delivery (e-coupon code generation/emailing) — only
  the physical/digital flag and physical shipping capture are built now.
- Reward Points earning from any source other than mirroring XP 1:1 (no
  separate points-only rules).
