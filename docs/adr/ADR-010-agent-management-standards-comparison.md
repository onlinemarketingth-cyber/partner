# ADR-010: Agent-Management Model — Comparison Against Industry Standards (Affiliate / Insurance / Direct-Selling / PRM)

- **Date:** 2026-07-22
- **Status:** Proposed — research + options only. No BR-7 value (rate, threshold, criterion) is decided or invented here; every option below is presented for the human to choose from, per Guardrail 7.
- **Author:** ag-lead

## Context

The human asked ag-lead to research how "standard" systems manage agents across four models — e-commerce affiliate (Shopee-style), life insurance agents, direct-selling/MLM, and general partner-management platforms — as input before deciding how Sync Vision Agent's own agent-management model should evolve. This ADR widens the lens beyond commission math: **ADR-006 already did a deep, decided comparison of commission-payout structures** (Unilevel override, Binary matched-volume, renewal, split) — that work is not repeated here. This ADR instead looks at the full agent **lifecycle**: recruitment/onboarding, licensing/compliance gates, hierarchy shape, and engagement/retention mechanics.

## Research Summary

| Dimension | Affiliate (Shopee) | Insurance Agent | Direct-Selling (MLM) | PRM / SaaS Partner Platform |
|---|---|---|---|---|
| Entry gate | Content-quality review, ~500-1,000 followers, 1-5 day approval — no exam | Government licensing exam (Thailand: OIC/IIQE) required before any sale is legal | Sign a distributor agreement; no license required in most markets | Company-defined onboarding journey by partner segment/tier, often no external license |
| Hierarchy shape | None — flat, one affiliate per link | Flat-to-shallow (captive) or FMO→MGA→GA→Agent (independent); career path to Sales Manager | Deep network (upline/downline) — 6 standard plan taxonomies: Unilevel, Binary, Matrix, Stairstep/Breakaway, Generation, Hybrid | Flat-to-shallow; tiers (Bronze/Silver/Gold) are enablement levels, not a recruiting network |
| Commission trigger | Per-sale (CPS), attributed by last-click within a time window | Per-policy, front-loaded (60-110% of first-year premium), then a small trailing % on renewal | Per-sale plus network overrides/matched-volume, depending on plan type | Usually a deal-registration/deal-desk process, not click attribution |
| Attribution mechanism | Unique tracked link, **7-day last-click attribution window**, differentiates new vs. returning customer commission rate | None needed — the agent is the one who wrote the policy, recorded directly | None needed — sale is recorded directly against the distributor | Deal registration (claim a lead before working it, prevents channel conflict) |
| Compliance/ethics layer | Platform content-policy review | Government-regulated license, mandatory continuing education/renewal | Trade-association code of conduct (Thailand: TDSA) — bans unsubstantiated earnings claims | SOC 2/ISO 27001-style data-handling standards; less about individual-agent conduct |
| Engagement/retention tool | Payout-rate escalation only | Manager career ladder, contests | Rank advancement, contests, recognition events | Gamification — points, leaderboards, badges tied to certification completion; certification-gated feature unlock (deal reg, MDF) raises completion rate 40%+ |

Sources: [Shopee Commissions Structure](https://help.shopee.sg/portal/10/article/191914-Commissions-Structure-(W.e.f.-2-January-2026)), [Shopee Affiliate Program Guide](https://reacheffect.com/blog/shopee-affiliate-program/), [OIC — สำนักงาน คปภ.](https://www.oic.or.th/th/insurance-agent-broker), [Life Insurance Agent Commission Structure](https://insuranceproagencies.com/insurance-agent-commission-structures), [NerdWallet — Life Insurance Agent Commissions](https://www.nerdwallet.com/insurance/life/learn/life-insurance-agent-commissions), [MLM Compensation Plans 2026](https://www.epixelmlmsoftware.com/mlm-plans), [สมาคมการขายตรงไทย TDSA](https://marketeeronline.co/archives/381936), [Channel Partner Gamification](https://www.introw.io/blog/channel-partner-gamification), [PRM Onboarding Software Guide](https://www.zinfi.com/blog/best-prm-onboarding-software-partner-success-2026/), [Insurance Agent Onboarding SaaS (AgencyBloc/SuranceBay)](https://www.agencybloc.com/agency-management-system/insurance-agent-management-software/)

## Current State of Sync Vision Agent (verified against the real schema, not assumed)

- **BR-1 (cert-tier gate before selling)** already matches the insurance-license gate AND the PRM "certification-gated feature unlock" pattern — this is standard practice on both the most-regulated (insurance) and most-modern (PRM/SaaS) ends of the comparison.
- **BR-5 (XP/Level/Badge)** already matches PRM-style gamification (points, badges) used to drive certification completion.
- **`users.manager_id` self-referencing chain + `commission_override_rules`** (ADR-006/TASK-025) already implements a Unilevel-shape hierarchy — the closest MLM pattern to how real insurance agencies structure FMO→MGA→GA→Agent (a deliberate appointment, never automatic placement). Binary schema exists (ADR-006 Round 4) but is inert (no calculation engine) and marked "under development" in the UI.
- **An Agent belongs to exactly one Company** (`users.company_id`, not nullable) — this is the "captive agent" model from the insurance research, not "independent/multi-carrier." This was never explicitly decided as a captive-vs-independent choice; it's simply how multi-tenancy (Section 5, BR-6) was built from the start. Flagged here as a design fact worth confirming is still intended, not something ag-lead is proposing to change.
- **Referral commission trigger is deterministic, not attribution-based**: an Agent manually submits an SWS Referral naming the client directly (`referrals.agent_id`), so there is no "who gets credit if two links compete" problem the way Shopee's 7-day last-click window solves. **The affiliate attribution model does not map onto this system as currently designed** — flagged as not applicable rather than silently ignored.
- **No ongoing agent compliance/license-verification workflow exists.** TASK-017/TASK-020 added a one-time signup **approval status** (pending/approved/rejected, admin-reviewed once at registration) — this is different from an insurance agency's ongoing license-number/expiry tracking (SuranceBay/AgencyBloc-style), which this system has no equivalent of at all.
- **No agent lifecycle status beyond signup approval** — there is no active/suspended/terminated state for an agent after they're approved; TASK-013's "move between companies" and the existing soft-delete/deactivate pattern from TASK-009 are the closest things, but nothing insurance-license-specific.

## Gaps Identified (research findings only — none of these are decided)

1. **No ongoing license/compliance tracking.** Real insurance-agent platforms (SuranceBay, AgencyBloc) track license number, issuing authority, expiry date, and renewal status per agent, separate from the internal BR-1 exam. This system currently only has the internal exam (BR-1) — it does not model a real external insurance license at all.
2. **No agent lifecycle status distinct from onboarding approval.** "Approved" (TASK-017) is a one-time signup gate; there's no ongoing "active/suspended (e.g. license lapsed, under investigation)/terminated" status that would block selling without deleting the account.
3. **No trackable-link/attribution-window referral mode.** Only relevant if the business ever wants an Agent to share a public link directly with a prospect (Shopee-style) instead of always filling in the SWS Referral form themselves — not needed under the current "agent always personally submits the referral" flow.
4. **No "unlockable feature by level" beyond the single BR-1 gate.** PRM platforms often gate multiple things (deal registration, MDF, advanced training) at different tiers, not just one pass/fail line. Sync Vision Agent currently has exactly one gate (Basic cert → selling rights) plus the separate, informational Level/Badge system (BR-5) that unlocks nothing itself.
5. **TDSA's "no unsubstantiated earnings claims" compliance clause** has no analogue in this system — not obviously relevant unless Agents are given marketing materials that reference potential earnings, which isn't a feature that exists today.

## Options for a Human Decision (independent — pick any, none, or ask to explore further)

**Option 1 — Agent license tracking (insurance-standard).** Add `license_number`, `issuing_authority`, `license_expiry_date` to the agent profile, plus an admin verification step and an expiry-approaching notification (reuses ADR-004's notification infrastructure). Turns BR-1 from "passed an internal exam" into "passed the internal exam AND holds a currently-valid real license," closer to how SuranceBay/AgencyBloc model it.
- Trade-off: meaningfully bigger scope (new table, verification UI, expiry job); only valuable if real regulatory license numbers are something the business actually wants tracked in-system (vs. handled outside the platform, as today).

**Option 2 — Agent lifecycle status (active/suspended/terminated).** A new status field on `users` (or a small `agent_statuses` history table for an audit trail), independent of the existing one-time approval status, so a Company Admin can suspend an agent's selling rights without touching their account/login.
- Trade-off: small, low-risk addition; needs a decision on exactly which states are wanted and what each one blocks (selling only? login too?).

**Option 3 — Multi-gate tiered feature unlock (PRM-standard).** Extend past BR-1's single gate: e.g. Intermediate cert tier unlocks something beyond just a higher commission rate (a feature, a badge-only benefit, an internal training resource). Right now Level/Badge (BR-5) is purely cosmetic/informational — nothing in the app actually checks a Level or Badge to gate a feature.
- Trade-off: needs the human to specify what (if anything) should be gated beyond commission rate — otherwise this is scope without a concrete target.

**Option 4 — Trackable-link referral mode (affiliate-standard).** A parallel, optional way for an Agent to generate a shareable link (with a short attribution window) that a prospect can act on directly, alongside the existing agent-fills-the-form flow.
- Trade-off: the biggest structural change of the four — introduces public-facing tracked links, click logging, and an attribution-window decision that doesn't exist anywhere in this system today. Only worth it if the business wants a self-serve/social-sharing sales motion in addition to the current agent-led one.

None of these four are chosen. Per CLAUDE.md Guardrail 7, ag-lead is presenting them as independent options with trade-offs rather than picking one or proceeding at length on an assumption — say which (if any) to turn into a task spec.

## Out of Scope

- Commission-payout mechanics (Unilevel override %, Binary matched-volume engine, Matrix, renewal) — already fully covered by ADR-006; not revisited here.
- Any specific rate, threshold, license-field list, or gate criterion (BR-7) — none proposed; every number above is from external research citations, not a recommendation for this system's own config.
