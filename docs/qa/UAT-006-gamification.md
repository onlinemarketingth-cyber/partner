# UAT-006: Gamification (XP, Level, Badge, Leaderboard)

Run after `php artisan migrate` and `php artisan db:seed` (idempotent —
safe even if you already seeded Phases 1-5; this adds `GamificationSeeder`
with platform-default XP rules and 3 illustrative badges). Backend on
:8010, Agent Portal on :5173. No Admin UI screens this phase (rules and
badges are API-only for now) — see TASK-008's "Out of scope".

**Farming-prevention check first, always:** the two highest-risk
assertions in this phase are "repeating a module completion doesn't
award XP twice" and "retaking-and-repassing an already-passed exam
doesn't award XP twice." If either fails, treat it as high priority —
it's a real, exploitable free-XP bug, not a cosmetic issue.

## 1. Automated tests (run first)

- [ ] `php artisan test --filter=Gamification` — all 34 tests pass across `XpAwardingTest` (8), `GamificationRuleTest` (9), `XpLedgerTest` (5), `LeaderboardTest` (5), `UserBadgeTest` (5), `BadgeTest` (2)
- [ ] `./vendor/bin/pint --test` — clean
- [ ] If anything fails, stop and fix before manual UAT — pay special attention to `test_retaking_and_repassing_an_already_passed_exam_does_not_award_xp_again` and `test_completing_a_module_awards_xp_once`'s repeat-call assertion.

## 2. XP from learning (agent@thailife.test)

- [ ] Go to "Academy", mark a module as complete (or use a fresh module if all seeded ones are already done). The "XP จากการเรียน" KPI should increase by the seeded `module_completed` platform-default value (placeholder, BR-7 — check `gamification_rules` table or the seeder for the current number).
- [ ] Try marking the same module complete again (if the UI allows re-clicking, or via `POST /api/v1/module-completions` directly with the same `module_id`) — confirm the KPI does NOT increase a second time.
- [ ] Submit a passing exam attempt for a certification you haven't passed yet — confirm the KPI increases by the `exam_passed` value.
- [ ] Submit another attempt on the SAME exam (retake), passing again — confirm the KPI does NOT increase again. This is the farming-prevention behavior; if XP increases here, stop and report it as a bug.

## 3. XP from sales activity (agent@thailife.test)

- [ ] Submit a new SWS Referral — go to "Leaderboard", confirm your XP total increased by the `referral_submitted` value.
- [ ] Go to "Pipeline", advance that referral one stage — confirm your XP total increased by the `pipeline_stage_advanced` value.
- [ ] Keep advancing until it reaches "Complete Payment" — confirm this specific advance increased your XP by BOTH `pipeline_stage_advanced` AND `payment_complete` (i.e. a bigger jump than the earlier plain-stage advances).

## 4. Credit-to-the-right-person (needs admin@thailife.test + a second agent)

- [ ] As `admin@thailife.test`, submit a referral on behalf of an agent who has already passed Basic cert (`agent_id` explicitly set in the request — the Agent Portal UI may not expose this field for Admin; use the API directly if needed: `POST /api/v1/referrals`).
- [ ] Check that agent's XP total increased, and confirm `admin@thailife.test`'s own XP did NOT change (an admin acting on someone's behalf must never accidentally take their sales credit).

## 5. Leaderboard (agent@thailife.test, or two agents in the same company)

- [ ] Go to "Leaderboard" — confirm agents are ranked highest-XP-first, your own row is visually highlighted, and rank numbers are sequential starting at 1.
- [ ] Confirm there is no "Level" anywhere on this page — this is intentional (no level formula exists yet), not a missing feature.
- [ ] If you have access to a second company's data, confirm agents from other companies never appear in your leaderboard.

## 6. Badges (needs admin@thailife.test)

- [ ] As the agent, `GET /api/v1/badges` — confirm you see the 3 seeded illustrative badges.
- [ ] As `admin@thailife.test`, award one to an agent: `POST /api/v1/user-badges` with `{"user_id": <agent id>, "badge_id": <badge id>}` — confirm 201, and the agent's "Leaderboard" page now shows it under "Badge ที่ได้รับ".
- [ ] Award the SAME badge to the SAME agent again — confirm it succeeds (201) but does NOT create a duplicate row (idempotent, DB-enforced unique constraint).
- [ ] As the agent, try `POST /api/v1/user-badges` awarding a badge to yourself — confirm **403** (an agent can never self-award).

## 7. Security & scoping checks (Postman/Insomnia/curl)

- [ ] As the agent, `GET /api/v1/gamification-rules` — confirm **403** (config table, same restriction as `/commission-rules`).
- [ ] As `admin@thailife.test`, `POST /api/v1/gamification-rules` with a `company_id` belonging to a DIFFERENT company — confirm **422** (validation rejects it, an admin can only ever create rules for their own company).
- [ ] As the agent, `GET /api/v1/xp-ledger` — confirm you only see your own entries.
- [ ] As the agent, try `POST /api/v1/xp-ledger` (any body) — confirm **405** (fully read-only, no write endpoint exists at all).
- [ ] As `admin@thailife.test`, `GET /api/v1/gamification-rules/<id>` for a rule belonging to another company — confirm **403** (not 404 — `gamification_rules` isn't tenant-scoped, so this is a Policy rejection, not a missing-row rejection; both are valid per CLAUDE.md §5 rule 5, this is just the specific mechanism used here).

## Known gaps at this stage (not bugs — out of scope per TASK-008)

- No Admin UI screens for managing `gamification_rules` or awarding badges yet — both fully usable via the API (steps 6-7 above) until dedicated Admin screens are built.
- No automatic badge-awarding based on conditions — only the manual award action exists; `condition_config` on the seeded badges is empty/unused.
- No "Level" anywhere — deliberately not built, no threshold formula/config exists.
- Leaderboard has no weekly/monthly period filtering — it's an all-time total only.
- XP values are still placeholders (BR-7) — replace via `PUT /api/v1/gamification-rules/<id>` (or a future Admin screen) once real amounts are confirmed.

## Sign-off

- [ ] Tested by: _____________  Date: _____________
- [ ] Result: ☐ Pass  ☐ Pass with known gaps above  ☐ Fail (list blocking issues)
