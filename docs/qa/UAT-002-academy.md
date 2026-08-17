# UAT-002: Academy (BR-1 gate)

Run after `php artisan migrate` (if new tables aren't applied yet) and
`php artisan db:seed` (idempotent — safe even if you already seeded
Phase 1). Backend on :8010, Admin app on :5273, Agent Portal on :5173.

## 1. Automated tests (run first)

- [ ] `php artisan test --filter=Academy` — all of ExamAttemptTest, ModuleTest, ExamResourceVisibilityTest pass
- [ ] `./vendor/bin/pint --test` — clean
- [ ] If anything fails, stop and fix before manual UAT — same lesson as Phase 1 (the first Catalog test run caught two real bugs static review missed).

## 2. Admin — author content (admin@thailife.test)

- [ ] Admin home now shows an **Academy** card (not just Product catalog). Click it.
- [ ] **โมดูล tab**: 2 seeded modules visible ("Platform Onboarding", "Introduction to Standard Package" — the second one shows a linked product). Add a new module, confirm it appears without reload.
- [ ] **แบบทดสอบ tab**: 3 seeded exams visible (Basic/Intermediate/High), each showing its passing score (70/75/80). Add a new exam.
- [ ] **ความคืบหน้าตัวแทน tab**: empty at this point (no agent has passed anything yet) — confirm it shows the empty state, not an error.

## 3. Agent — the actual BR-1 gate (agent@thailife.test)

This is the part that matters most — walk through it exactly, don't skip steps:

- [ ] Log into the Agent Portal, go to Academy. KPI "ระดับใบรับรองปัจจุบัน" shows "ยังไม่ผ่าน Basic".
- [ ] Mark "Platform Onboarding" as complete — it should flip to "จบแล้ว" without a page reload, and the "โมดูลที่จบแล้ว" KPI increments.
- [ ] Scroll to "แบบทดสอบใบรับรอง", find the Basic exam. Enter a score **below** 70 (e.g. `40`) and submit — confirm it shows "ไม่ผ่าน" and the KPI still says "ยังไม่ผ่าน Basic".
- [ ] Submit again with a score **at or above** 70 (e.g. `85`) — confirm it shows "ผ่าน" and the KPI updates to show "Basic" as the current tier.
- [ ] Refresh the page fully (hard reload) — confirm the passed state persists (it's reading from `/api/v1/user-certifications`, not just local component state).

## 4. Security checks (the ones a UI click-through won't naturally catch — use Postman/Insomnia/curl)

- [ ] As the agent, `POST /api/v1/exam-attempts` with `{"exam_id": <basic exam id>, "score": 10, "passed": true}` — confirm the response shows `"passed": false` (the client-supplied `passed` must be ignored) and that `hasPassedCertTier` is NOT flipped by this request.
- [ ] As the agent, `GET /api/v1/exams/<id>` — confirm the response's `data.config` is `null`, even though the exam has a config value (check the same request as admin — `config` should appear there).
- [ ] Create a second company (or reuse one from Phase 1's UAT), get its exam ID, then as Thai Life's agent try `POST /api/v1/exam-attempts` with that foreign exam_id — expect **422**, not 201.
- [ ] As the agent, `POST /api/v1/modules` — expect **403** (Agent cannot author content).

## 5. Cross-check with Admin's progress tab

- [ ] After the agent passes the Basic exam in step 3, go back to Admin's Academy > ความคืบหน้าตัวแทน tab — the agent's passed certification should now appear there.

## Known gaps at this stage (not bugs — out of scope per TASK-003)

- "ส่งผลสอบ" (submit exam) just takes a typed-in score — there's no real quiz/question UI. This is intentional until the exam engine shape (BR-7 open question) is answered.
- No XP is actually awarded yet for completing modules or passing exams — that's the Gamification phase.
- Module/Exam management has no edit/delete UI yet (API supports it, screen doesn't expose it) — add if needed before this is handed to real Company Admins.

## Sign-off

- [ ] Tested by: _____________  Date: _____________
- [ ] Result: ☐ Pass  ☐ Pass with known gaps above  ☐ Fail (list blocking issues)
