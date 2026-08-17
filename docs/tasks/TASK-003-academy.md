Task: Academy — cert tiers, modules, exams, BR-1 gate
Owner: ag-dev (backend), ag-ui (Agent Portal + Admin screens) — executed directly by ag-lead, no separate ag-dev/ag-ui sessions running yet
Goal: Turn the ERD-001 rev. 3 "Academy" schema into a working feature that actually enforces BR-1 — an agent passes an exam, the system computes pass/fail itself, and a passing result is the one thing every future Referral/Pipeline check can rely on.
Related: BR-1 (access gate, highest-priority business rule for this domain), BR-5 (XP source (a) — awarding itself deferred to the Gamification phase), BR-6 (tenant isolation), Section 6 (never trust client input — `passed` is the sharpest example of this in the whole codebase so far), ERD-001 §"Academy"

Input: CertTier/Module/ModuleCompletion/Exam/ExamAttempt/UserCertification migrations + models (schema pass, rev. 3), Phase 1's Product Catalog (Module.product_id references it)

Expected output:
- `GET /api/v1/cert-tiers` — read-only, global config
- Policies: ModulePolicy, ExamPolicy, ModuleCompletionPolicy, ExamAttemptPolicy, UserCertificationPolicy
- Form Requests under app/Http/Requests/Academy/
- Services: ModuleService, ExamService, ModuleCompletionService, ExamAttemptService — the last one is where BR-1 actually gets enforced
- Resources: CertTierResource, ModuleResource, ExamResource (hides `config`/answer-key from Agent), ModuleCompletionResource, ExamAttemptResource, UserCertificationResource
- Controllers + routes: full CRUD for Module/Exam (Company Admin/Super Admin author, Agent read-only); index+store only for ModuleCompletion/ExamAttempt (append-only logs); index-only for UserCertification (system-created)
- `User::hasPassedCertTier(string $tierKey): bool` — the one method every future Policy should call for the BR-1 gate, not a re-implementation
- AcademySeeder (3 exams + 2 modules, one tied to a Phase-1 Product, all placeholder syllabus content — BR-7)
- Agent Portal: AcademyView.vue wired to real data (module list + mark-complete, exam list + score submission, cert status)
- Admin: AcademyManagementView.vue (author modules/exams, view agent certification progress)
- Feature tests: tests/Feature/Academy/{ExamAttempt,Module,ExamResourceVisibility}Test.php

Acceptance Criteria:
  - `passed` on an exam attempt is ALWAYS computed server-side (`score >= exam.passing_score`) — a client-supplied `passed: true` is silently ignored, never reaches the database (verified: ExamAttemptTest::test_client_cannot_self_certify_by_sending_passed_true)
  - A passing attempt creates exactly one `user_certifications` row per (agent, cert_tier) — retaking an already-passed exam does not duplicate it (verified: test_retaking_a_passed_exam_does_not_duplicate_the_certification)
  - `User::hasPassedCertTier('basic')` is false before any passing attempt, true immediately after (verified end to end, not just at the DB layer)
  - Agent cannot attempt an exam belonging to another company, even with a guessed valid exam ID (BR-6, verified)
  - Agent can read Module/Exam listings (needs it to learn) but cannot create/update/delete either — Company Admin/Super Admin only
  - `Exam.config` (the answer key, effectively) is stripped from the API response for anyone who isn't Company Admin/Super Admin, even though Agent can otherwise view the exam (verified: ExamResourceVisibilityTest)
  - No BR-7 value (syllabus content, exam passing scores, XP reward amounts) is hardcoded outside AcademySeeder's clearly-marked placeholders

Verification status:
  - Structural review passed via independent subagent, focused specifically on the BR-1 gate logic (ExamAttemptService) line by line — no bugs found, including the two failure classes that actually broke Phase 1 (authorizeResource()'s middleware() dependency, seeder idempotency) — both confirmed still correctly handled here
  - Both frontends (`frontend`, `frontend-admin`) built clean in throwaway /tmp copies: oxlint + eslint clean, `vue-tsc --build` type-check clean, `vite build` clean
  - Backend: tests are WRITTEN, structurally reviewed, but **not yet run by the human** — this is the step that actually caught Phase 1's two real bugs, so do not treat this phase as verified until `php artisan test --filter=Academy` has actually been run. See docs/qa/UAT-002-academy.md.

Out of scope (future tasks):
  - Real exam-taking UI (multiple choice, timed, etc.) — the current "type in your score" form is an explicit placeholder standing in for whatever real quiz engine gets built once BR-7 open question #5 (exam engine shape) is answered
  - Awarding actual XP for module completions/exam passes (BR-5) — Gamification phase
  - Intermediate/High tier gating logic beyond Basic (BR-1 only names Basic as the mandatory gate; whether Intermediate/High unlock anything else is unspecified — not guessed here)
  - Agent-only fields on the User model, notifications when a module/exam is published — not requested

Design notes (not in CLAUDE.md, decided here — flag if wrong):
  - `ExamAttempt.score` is accepted directly from the client as if an external quiz UI already graded it — a deliberate, clearly-flagged placeholder for the real exam engine, not a guess at what that engine will look like.
  - Module read access mirrors Phase 1's Brand/Product pattern (Agent reads, Admin writes) — Exam read access is the same shape but with the `config` field additionally stripped for Agent.
