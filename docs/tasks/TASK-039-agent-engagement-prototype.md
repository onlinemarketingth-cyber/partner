# TASK-039 — Agent-view IA items 1.4/1.5/1.6 (prototype)

Owner: ag-dev (backend hardening) / ag-ui (UI polish) / ag-qa (tests)
Status: prototype built by ag-lead 2026-07-22, pending human sign-off on the BR-7 items below before this is considered production-ready.

## Goal

Ship the 3 Agent-view sub-features that were explicitly left as "ยังไม่มีในระบบ" gap-notes in TASK-038 (Agent Overview dashboard): targeted Promotion campaigns (1.4), reward-points redemption (1.5), and an Agent Portal newsfeed (1.6).

## What was built (this pass)

- Backend: migrations, models, policies, Form Requests, Services, Controllers, API Resources, and versioned routes for `agent_promotions` (+ `agent_promotion_agent` pivot), `reward_items` + `reward_redemptions`, and `announcements`. All tenant-scoped per Section 5 (BR-6); all money/points values stored as integers (BR-3 spirit).
- Frontend (Admin only): `AgentPromotionsView.vue`, `RewardCenterView.vue`, `AnnouncementsView.vue`, reached via 3 new link-out cards on `AgentManagementView.vue`'s Overview tab (no top-nav change).
- Not built this pass: Agent Portal (`/frontend`) UI — Admin can author promotions/rewards/announcements, but there is no Agent-facing screen yet to see them. That's a natural follow-up task, not done here to keep this pass reviewable.

## Related

BR-3 (money as integer satang), BR-4 (immutable ledger discipline — applied to `reward_redemptions.points_spent`), BR-5 (XP/Gamification), BR-6 (tenant isolation), BR-7 (unfinalized business values), Section 5 (multi-tenancy), Section 7 (layered architecture).

## Open BR-7 questions — human must confirm before real rollout

1. **Reward points currency (reward_items.cost_points / reward_redemptions.points_spent)**: CLAUDE.md's glossary defines only one point-like currency — XP (BR-5). This prototype assumes redemption spends against the Agent's cumulative XP total (`SUM(xp_ledger.xp_awarded)` minus points already reserved by non-rejected redemption requests — see `RewardRedemptionService::calculateAvailablePoints()`). **This is not confirmed** — spending XP changes what the Leaderboard/Level system actually measures once XP becomes withdrawable. Alternative: a separate, XP-independent points ledger. Needs a decision before this goes further.
2. **Reward fulfillment**: are catalog items physical (needs a real-world handover step, which is why `Approved` and `Fulfilled` are separate statuses) or digital/instant? Affects whether the `Fulfilled` status/step is even needed.
3. **Promotion bonus payout mechanism**: `agent_promotions.bonus_value` is captured and queryable, but nothing currently *pays* it — there is no Service that reads active promotions at Complete Payment time and adds a bonus line to `commission_ledger` (BR-4). That calculation Service is a separate, not-yet-scoped task once the targeting/bonus shape here is confirmed.
4. **Cert-tier targeting is exact-match, not hierarchy-aware**: `AgentPromotion::appliesToAgent()`/announcement audience filtering treat cert tiers as a flat set (an Agent matches a promotion/announcement only if they've passed that *exact* tier), not "this tier or above". No tier ordering rule is confirmed in CLAUDE.md to justify otherwise.
5. **Announcement per-agent targeting**: `AnnouncementAudience` only has `all_agents`/`cert_tier` (no `specific_agents`, unlike `PromotionTargetType`) — judged out of scope for this pass. Flag if the human actually wants per-agent-targeted announcements.

## Acceptance criteria (for the eventual "done" state, not yet all met)

- [ ] Human has answered the 5 open questions above
- [ ] Agent Portal UI exists so an Agent can actually see promotions/rewards/announcements targeted at them, and submit a redemption request
- [ ] A Service pays out promotion bonuses into `commission_ledger` at the confirmed trigger point
- [ ] Feature tests cover tenant isolation (cross-company 403/404) for all 3 resources, the redemption status-transition guard, and the stock/points-race condition (`lockForUpdate`)
- [ ] ag-qa has run a full novice-user UAT pass
- [ ] Reviewed and approved by ag-lead against Section 9 Definition of Done

## Out of scope (this pass)

Agent Portal UI, promotion payout calculation, notification delivery (LINE/email/push) for new announcements, image upload for reward catalog items.
