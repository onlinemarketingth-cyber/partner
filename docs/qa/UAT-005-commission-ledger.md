# UAT-005: Commission Ledger

Run after `php artisan migrate` (applies the new unique-index migration
on `commission_ledger` too) and `php artisan db:seed` (idempotent —
safe even if you already seeded Phase 1-4). Backend on :8010, Agent
Portal on :5173. This phase adds no Admin "mark as paid" screen — see
TASK-007's "Out of scope".

**Money check first, always:** every amount in this phase should be an
exact figure you can hand-verify (price × rate ÷ 10000 for percentage
rules). If any number looks off by even a few satang, stop and report
it — this is the first phase that touches actual money calculation.

## 1. Automated tests (run first)

- [ ] `php artisan test --filter=Commission` — all of `CommissionCalculationTest` (6 tests) and `CommissionLedgerTest` (6 tests) pass
- [ ] `./vendor/bin/pint --test` — clean
- [ ] If anything fails, stop and fix before manual UAT — this is money-calculation logic, treat any failure as high-priority.

## 2. The end-to-end flow (agent@thailife.test)

- [ ] Make sure this agent has passed Basic cert (UAT-002 §3) and has at least one Referral for the seeded "Standard Package" (8,900 THB) — either from `ReferralSeeder` (if the cert was already passed when you last ran `db:seed`) or create one fresh via SWS Referral.
- [ ] Go to "Pipeline", find that referral, click "ขั้นถัดไป" repeatedly until it reaches "Complete Payment" (Complete Registered → Waiting Appointment → Finish 1st Doctor Meeting → Complete Payment — 3 clicks).
- [ ] Go to "Commission" — a new entry should appear automatically, no extra action needed. For the seeded Basic-tier rate (3%, placeholder BR-7 value) on the 8,900 THB Standard Package: **267.00 บาท** (890,000 × 300 ÷ 10,000 = 26,700 satang = 267.00 THB). Confirm the exact number matches.
- [ ] Confirm the entry shows "รอจ่าย" (pending) status, and the KPI totals (เดือนนี้/รอจ่าย/จ่ายแล้ว) update to reflect it.

## 3. Idempotency (agent@thailife.test)

- [ ] From the same referral, click "ขั้นถัดไป" once more (moving it into "Ongoing Next Meeting"). Go back to Commission — confirm there is still only ONE entry for that referral, not two (advancing past Complete Payment must never create a second commission row).

## 4. Multiple cert tiers (needs a second agent or manually granting a higher cert — optional, can test via API if easier)

- [ ] If you have an agent who has passed both Basic and Intermediate, submit and complete a referral for them — confirm the resulting commission entry shows the Intermediate rate (5%, placeholder), not Basic's (3%), i.e. the higher tier wins.

## 5. Security & config-gap checks (Postman/Insomnia/curl)

- [ ] As the agent, `GET /api/v1/commission-ledger` — confirm you only see your own entries, never a colleague's.
- [ ] As the agent, `POST /api/v1/commission-ledger/<id>/mark-paid` on your own entry — confirm **403** (Agents can never mark their own commission paid).
- [ ] As `admin@thailife.test`, `POST /api/v1/commission-ledger/<id>/mark-paid` on any entry in the company — confirm **200**, `payment_status` becomes `"paid"`, `paid_at` is set. Refresh the Agent Portal Commission page as the agent — confirm it now shows "จ่ายแล้ว".
- [ ] (Optional, needs a product/tier combo with no seeded `commission_rules` row — or temporarily delete one via tinker) Advance a referral for that combo to Complete Payment — confirm the pipeline advance itself still **succeeds** (200), but no commission_ledger row is created. Check `storage/logs/laravel.log` for a warning message explaining why.

## Known gaps at this stage (not bugs — out of scope per TASK-007)

- No Admin UI button for "mark as paid" yet — must use the API directly (step 5 above) until an Admin screen is built.
- No way to reverse/claw back a commission once recorded (e.g. after a refund) — not defined anywhere in CLAUDE.md, not built.
- Only one commission event per referral is possible (enforced by a DB unique constraint) — if a future business rule needs renewal/repeat commissions per referral, this will need revisiting.
- Commission % values are still placeholders (BR-7, 3%/5%/8%) — replace via Admin's Product Catalog > อัตราคอมมิชชั่น tab once real rates are confirmed; this phase doesn't change how rates are configured, only how they're applied.

## Sign-off

- [ ] Tested by: _____________  Date: _____________
- [ ] Result: ☐ Pass  ☐ Pass with known gaps above  ☐ Fail (list blocking issues)
