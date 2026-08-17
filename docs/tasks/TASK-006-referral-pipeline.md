Task: Referral & Pipeline — SWS Referral submission, BR-4.3 pipeline state machine
Owner: ag-dev (backend), ag-ui (Agent Portal screens) — executed directly by ag-lead, no separate ag-dev/ag-ui sessions running yet
Goal: Connect Client + Agent + Product into a Referral, enforce BR-1 (Basic cert gate) on submission against the actual referring agent, and implement the CLAUDE.md §4.3 sequential-only pipeline state machine with a full audit trail.
Related: BR-1 (access gate — enforced against the resolved referring agent, not just the actor), Section 4.3 (pipeline state machine: 5 sequential stages, no skipping/reverse, every change audit-logged), Section 5 rule 4 (Agent sees only their own referrals), Section 6 (never trust client input — most sharply demonstrated here by the `/advance` endpoint accepting no target-stage input at all), ERD-001 §"Referral & Pipeline"

Input: `Referral`/`PipelineStageLog` models, `PipelineStage` enum (with `allowedNextStages()` already defined), and their migrations — all built in an earlier schema-only pass, not touched this session except for verification. Phase 2's `User::hasPassedCertTier()` (BR-1 gate check) and Phase 3's Client (with `referring_agent_id`).

Expected output:
- `ReferralPolicy` — `viewAny`/`create` open (narrowed at query level / in the Service respectively), `view` mirrors ClientPolicy's shape (Super Admin all, Company Admin own-company, Agent own-referral-only), custom `advance` ability with the same shape as `view`. No `update`/`delete` — a referral is a sales-audit record, edited only by moving forward through the pipeline, never freely edited or removed.
- `StoreReferralRequest` — `client_id`/`product_id` tenant-scoped `exists` rules; `agent_id` prohibited for Agent actors (422 if sent at all, mirroring `StoreClientRequest`'s `referring_agent_id` pattern) and required for Company Admin/Super Admin; a `withValidator` closure rejects an Agent submitting a `client_id` belonging to a colleague's client (422), since the base tenant-scoped `exists` rule alone can't catch that.
- `ReferralService::create()` — resolves `agent_id` to the actor (Agent) or the submitted value (Admin), then checks BR-1 (`hasPassedCertTier('basic')`) against that *resolved* agent — so a Company Admin can't bypass BR-1 by submitting on behalf of an uncertified agent either. Creates the Referral at `CompleteRegistered` plus its first `PipelineStageLog` row (`from_stage: null`), in one transaction.
- `PipelineService::advance()` — always moves a referral to `current_stage->allowedNextStages()[0]`, the one and only allowed next stage; never reads a target stage from the request (there's nothing for a client to choose, so nothing is accepted). Tracks `meeting_number` (set to 2 on first entry into `OngoingNextMeeting`, incremented on every further self-loop advance). Writes a `PipelineStageLog` row for every transition, in the same transaction as the state mutation.
- `ReferralResource`, `PipelineStageLogResource` — nested client/agent/product summaries; `current_stage`/`to_stage`/`from_stage` returned as `{key, label}`; money (`product.price_satang`) stays integer satang (BR-3), UI divides by 100 only at display.
- `ReferralController` — `index` (Agent-scoped), `store`, `show` via `authorizeResource`; `advance` and `stageLogs` as explicit custom actions with their own `$this->authorize(...)` calls (not folded into `authorizeResource`, since "advance" isn't a standard REST verb).
- Routes: `apiResource('referrals', ...)->only(['index','store','show'])`, `POST /referrals/{referral}/advance`, `GET /referrals/{referral}/stage-logs`.
- `ReferralFactory`, `PipelineStageLogFactory` — closure-attribute idiom (same as `ClientDocumentFactory`) deriving `company_id`/`agent_id`/`referral_id`-dependent fields from a lazily-created parent.
- `ReferralSeeder` — conditional on `agent@thailife.test` having *actually* passed Basic cert (not faked); creates sample referrals + their initial `PipelineStageLog` for the Phase 3 seeded clients if so, silently skips (with an info message) if not.
- Agent Portal: `ReferralsView.vue` rebuilt from placeholder — BR-1-gated submission form (client/product pickers, branch, preferred time), KPIs, tab-filtered list. `PipelineView.vue` rebuilt from placeholder — tab-filtered tracking board with a one-click "ขั้นถัดไป" (advance) action and a slide-in audit-trail drawer per referral.
- Feature tests: `tests/Feature/Referral/{Referral,Pipeline}Test.php` (16 tests total).

Acceptance Criteria:
  - An Agent without a passed Basic certification cannot submit a referral for themselves — 422 on `agent_id` (verified: `test_agent_without_basic_cert_cannot_submit_referral`)
  - A Company Admin cannot bypass BR-1 by submitting a referral on behalf of an agent who hasn't passed Basic — 422 on `agent_id`, same as above (verified: `test_company_admin_cannot_submit_referral_for_an_uncertified_agent`)
  - An Agent can only submit a referral for a client they themselves referred in — submitting a colleague's client_id is rejected at validation, 422 (verified: `test_agent_cannot_submit_referral_for_a_colleagues_client`)
  - An Agent's `GET /referrals` returns only their own referrals; viewing a colleague's referral directly is 403 (not 404 — same-company-wrong-agent, distinct from cross-company which 404s) (verified: `test_agent_only_sees_own_referrals`, `test_agent_cannot_view_a_colleagues_referral`, `test_cross_tenant_referral_access_is_404`)
  - `POST /referrals/{id}/advance` always moves exactly one stage forward per CLAUDE.md §4.3's defined sequence, regardless of any `to_stage` value a client sends in the body — skipping stages is structurally impossible, not just validated against (verified: `test_advancing_moves_through_stages_sequentially`, `test_advance_ignores_a_client_supplied_target_stage`)
  - `meeting_number` is set to 2 on first entry into `OngoingNextMeeting` and increments on every further self-loop advance (verified: `test_advancing_moves_through_stages_sequentially`, `test_advancing_again_within_ongoing_next_meeting_increments_meeting_number`)
  - Every stage transition — including the initial one at referral creation — writes exactly one `PipelineStageLog` row with correct `from_stage`/`to_stage`/`changed_by_user_id`/`changed_at` (verified: `test_each_advance_creates_a_pipeline_stage_log_entry`, `test_stage_logs_endpoint_returns_the_audit_trail_in_order`, and the creation-time check inside `test_agent_with_basic_cert_can_submit_referral_for_own_client`)
  - No BR-7 value hardcoded (no commission %/pricing decided here at all — that's explicitly out of scope, see below)

Post-hoc bug fix (found when the human finally ran
`php artisan test` — this phase's `PipelineTest` had never actually
been executed until now): `ReferralController::stageLogs()` sorted
`orderByDesc('changed_at')` with no tie-breaker. `changed_at` only has
second-level precision, so a referral advanced twice within the same
second (which `test_stage_logs_endpoint_returns_the_audit_trail_in_order`
does, and which is entirely plausible in real fast-paced usage too)
could come back in an undefined order instead of true newest-first.
Fixed by adding `->orderByDesc('id')` as a secondary sort key. A
second, test-only issue was found in the same run:
`PipelineTest::makeCertifiedAgentReferral()` creates its Referral
directly via `Referral::create()` rather than through
`ReferralService::create()`, so it was silently missing the initial
"creation" `PipelineStageLog` row that the real creation path always
writes — fixed by adding that row to the test helper directly, matching
what the Service does.

Verification status:
  - Structural review passed via independent subagent (12/12 checks), further independently re-verified by ag-lead directly reading the 5 highest-risk files (`ReferralService`, `PipelineService`, `StoreReferralRequest`, `ReferralPolicy`, `ReferralController`) — no discrepancies, no bugs found in either pass. This is the first phase where zero bugs were found at the structural-review stage (Phase 1 found 2 real bugs only via an actual test run, Phase 2 found 1 via an actual test run, Phase 3 found 1 at structural-review time) — that track record means this phase's `php artisan test --filter=Referral` run should still be treated as the real verification, not this review.
  - Frontend (`frontend` — Agent Portal only, no Admin-side Referral/Pipeline screen, see Out of scope): `eslint . --cache` clean, `vue-tsc --build` clean (exit 0), `vite build` clean (exit 0, `dist/` produced including `ReferralsView`/`PipelineView` chunks) — all three actually run and confirmed in this session.
  - Backend: 16 tests written (`ReferralTest.php` x9, `PipelineTest.php` x7), structurally reviewed twice, but **not yet run by the human**. Every phase so far has had at least one real bug surface only once `php artisan test` actually ran — treat this phase the same way. See `docs/qa/UAT-004-referral-pipeline.md`.

Out of scope (future tasks):
  - Commission Ledger creation at the `Complete Payment` stage (BR-4) — CLAUDE.md §4.3 names this trigger point, but the actual calculation (commission_rules lookup by cert tier × product, snapshotting rate/amount into an immutable ledger entry) is its own Service with its own Policy/tests, deliberately deferred to the next phase ("Commission Ledger"). A `// TODO: Phase 5` marks the exact spot in `PipelineService::advance()` — reaching Complete Payment in this phase does NOT yet create a `CommissionLedger` row.
  - Admin-side Referral/Pipeline screens (`frontend-admin`) — Company Admin/Super Admin can already submit on behalf of an agent and view/advance any referral in their company via the API (Policy already allows it), but there's no dedicated Admin UI screen yet, same scope decision as Phase 3's Clients screen.
  - A hard cap on `meeting_number` (CLAUDE.md §4.3 says "2nd → 3rd → 4th", which reads like a typical range, not a stated limit) — `// TODO: CONFIRM (business rule)` left in `PipelineService`, not guessed at.
  - A generic `audit_log` table write for pipeline transitions — `pipeline_stage_logs` already satisfies §4.3's specific audit requirement (who/when/from→to) with better typing than the generic table; no phase so far writes to the generic `AuditLog` model at all (flagged as a cross-cutting gap to revisit, not invented here just for this one domain).
  - Editing/cancelling a submitted referral — CLAUDE.md doesn't define this; not built.

Design notes (not in CLAUDE.md, decided here — flag if wrong):
  - `/referrals/{id}/advance` takes no request body at all — a deliberate design choice, not an oversight, since `allowedNextStages()` currently returns exactly one stage for every case (safest possible interpretation of "never trust client input": don't even accept the input).
  - ReferralsView.vue (submission log) and PipelineView.vue (tracking board + advance + audit trail) stay split, matching the two pre-existing separate nav items — submission and stage-tracking are different workflows even though they share the same underlying `Referral` records.
