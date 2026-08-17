Task: Badge auto-award interpreter (ERD-001 open question #9)
Owner: ag-dev — executed directly by ag-lead, no separate session running yet
Goal: Close ERD-001 open question #9. Human-confirmed decision: build a basic condition-evaluation engine recognizing simple thresholds (xp_total, modules_completed_count, sales/referral count), not a general expression language — Admin-authored condition_config remains the source of real numbers (BR-7), this only adds the code that can read it.
Related: BR-5 (badge conditions live in config, never hardcoded), BR-7, Section 5 (badge company_id nullable = platform default, same shape as GamificationRule — write access split Company Admin/Super Admin accordingly)

Input: `Badge.condition_config` (json, nullable) already existed from Phase 6 but was completely inert — no Store/Update endpoint existed at all (`BadgeController` was index-only) and nothing ever read the column. `UserBadgeService::award()`'s manual-award idempotency pattern (`firstOrCreate` + DB unique(user_id, badge_id)) was reused as-is for the auto-award path.

Expected output:
- `BadgeConditionEvaluator` — whitelists exactly 3 metrics (`xp_total`, `modules_completed_count`, `referrals_completed_count`) and 5 operators (`>=`, `>`, `==`, `<=`, `<`). condition_config is a JSON array, ALL entries must pass (AND). Unknown metric/operator/non-numeric value => fails closed (never awards from a config it doesn't fully understand). Empty/null condition_config => never auto-awards (stays manual-only, same behavior as every badge before this task).
- `BadgeAutoAwardService::checkAndAwardForUser()` — checks a user's own-company + platform-default badges with non-null condition_config, awards any newly-qualifying one via the same firstOrCreate/unique-constraint idempotency as the manual path. Never throws (logs and continues on a per-badge evaluation error).
- Hooked into `GamificationService::awardXp()` — the single funnel already used by all 4 XP-triggering Services (ModuleCompletionService, ExamAttemptService, ReferralService, PipelineService), each of which already guards it to fire exactly once per genuine achievement. The check runs whether or not XP was actually awarded (so a badge based purely on modules_completed_count/referrals_completed_count still fires even if no gamification_rule happens to be configured for that event).
- Badge CRUD added (`BadgePolicy::create/update/delete`, `Store/UpdateBadgeRequest` with condition_config validated against the same whitelist, `BadgeService`, `BadgeController` — full apiResource now, was index-only). Same "company override or platform default" write-access shape as GamificationRule: Company Admin authors their own company's badges, only Super Admin can author/edit the platform default.
- Frontend (`GamificationConfigView.vue`, Badges tab): "+ สร้าง Badge" form with a dynamic condition-row editor (metric/operator/value, add/remove rows); existing badges show their auto-award condition in plain language or "มอบเองเท่านั้น"; edit/delete gated by the same company-ownership rule as the backend Policy.

Acceptance Criteria:
  - A badge with condition_config null is never auto-awarded, regardless of any metric value (manual-award-only, unchanged from before this task)
  - A badge whose condition_config is fully satisfied gets auto-awarded the moment the relevant XP-triggering event fires, exactly once (idempotent on repeat events)
  - Multiple conditions in one badge use AND semantics — one failing condition blocks the whole badge
  - An unsupported metric/operator in condition_config is rejected at authoring time (StoreBadgeRequest/UpdateBadgeRequest validation), not silently accepted and later ignored
  - Cross-tenant: a badge belonging to another company is never awarded to a user outside that company
  - `eslint`/`vue-tsc --build`/`vite build` clean for `frontend-admin`

Out of scope (future tasks):
  - Nested/OR conditions, arbitrary formulas — deliberately a whitelist, not a general expression evaluator (safety/predictability over flexibility)
  - Real per-badge threshold values — still 100% Admin-authored config, this task only makes that config functional, not decides any actual number
  - Retroactive badge sweep (checking ALL existing users against ALL badges when a new condition_config is saved) — auto-award only fires going forward from the next XP-triggering event, not immediately on badge save

Design notes (flag if wrong): metric set kept intentionally small (3 metrics) to match what the human approved ("basic conditions... xp_total, modules_completed, sales_count"); adding more metrics later is a small, additive change to BadgeConditionEvaluator::SUPPORTED_METRICS + metricValue(), not a redesign.
