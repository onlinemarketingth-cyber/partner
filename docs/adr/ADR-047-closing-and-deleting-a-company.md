# ADR-047 — Closing and deleting a company, and the test-company flag

- **Status:** Accepted
- **Date:** 2026-09-26
- **Decided by:** the owner (KreangYot). He chose all three recommended options below.
- **Number note:** ADR-045 is still held for the B+C1 commission-setup UI record.

## Context

The owner asked for three things on จัดการบริษัท:

> "ช่วงนี้มีการทดสอบเยอะ … หากบริษัทยังไม่มีการดำเนินการใด ให้ลบได้โดยสอบถามว่าจะลบทั้งหมดหรือไม่ ทั้ง User ค่าคอม ข้อมูลทั้งหมด (ไว้ลบในช่วงทดสอบ) และเมื่อทดสอบเสร็จ จะลบได้ต่อเมื่อไม่มีข้อมูลผู้สมัคร ค่าคอมหรือข้อมูลที่เกี่ยวข้อง ทำปุ่มปิดบริษัท ที่ปิดการใช้งานทุกระบบในบริษัทนี้ ทำเพื่อใช้งานจริง"

What existed before:

- The status chip on each row ("ใช้งานอยู่") was a hidden one-click kill switch. It sent `is_active: !is_active` with no confirmation and wrote no audit entry.
- `DELETE /companies/{id}` was a soft delete. There was no way to remove a company, so test tenants piled up.
- Closing a company (TASK-183, `Company::isOperational()`) blocked logins, every authenticated request and the public endpoints. It did **not** stop the scheduler. Renewals, binary cycles, rank recalculation, promotion credits, follow-up reminders and notification emails all kept running for a tenant nobody could log in to.

## Questions put to the owner, and his answers

1. **How is "testing period" decided?** A per-company flag, `is_test`, rather than a platform-wide switch. A global switch left on by mistake at go-live would make every real tenant wipeable.
2. **What does ปิดบริษัท stop?** Everything, including the money jobs.
3. **When may a real company be deleted?** Only when it has no agents or applicants, customers, deals, orders, commission rows or withdrawal requests. Its admins and configuration do not count.

## Decision

### The flag (`companies.is_test`, `companies.went_live_at`)

- Set at creation (`POST /companies` accepts `is_test`), or switched with `PUT /companies/{id}/test-mode`.
- **Switching it on** is allowed only for a company that is empty and has never gone live. Such a company could be deleted anyway, so the flag gives no new power.
- **Switching it off** is always allowed. It records `went_live_at` and **can never be undone**. Without this, a real company could be relabelled as a test on Tuesday and wiped on Wednesday.
- It is not in `$fillable`, so `PUT /companies/{id}` cannot set it (tested).
- Audit actions: `company.marked_test` and `company.went_live`.

### Delete (`GET /companies/{id}/removal`, `DELETE /companies/{id}/purge`)

`CompanyRemovalService::assess()` returns one of three modes. The UI only displays this answer.

| Mode | When | What delete does |
|---|---|---|
| `wipe` | `is_test` | Removes everything: users, ledger, orders, configuration |
| `empty` | Real company with no blockers | Removes the company, its admins and its configuration |
| `blocked` | Real company with any blocker | Refused (422). The UI offers ปิดบริษัท instead |

- Blockers are counted with `DB::table`, so a soft-deleted applicant still counts.
- The company's name must be typed back (`confirm_name`), in the UI and on the server.
- Everything is super-admin only (`CompanyPolicy::delete` / `update`).
- The existing soft-delete `DELETE /companies/{id}` is unchanged.

**How the wipe works.** It runs in one transaction:

- The ledger rows are deleted through the query builder, because `CommissionLedger::deleting` always throws.
- Rows are deleted from **every** table that has a `company_id` **before** the company row. Several of those keys are `nullOnDelete`, and there a null `company_id` means "shared by every company". Deleting the company first would have turned its badges, gamification rules, rewards and announcements into platform-wide rows. A test proved this, and the same leak existed in `uat:purge-commission-plans`, which now calls the same `wipe()`.
- The delete order is found at run time: repeated passes, each table in its own savepoint, until nothing is left. A pass that deletes nothing means a real cycle, and it throws. The fixed list this replaced was already wrong: it deleted products before the banners and lesson modules that point at them. The test that caught this also proves the retry loop matters.

**What survives a delete:**

- `audit_logs`. Their `company_id` is nulled; the trail is kept deliberately. So is the `company.deleted` entry, which snapshots the name, slug, mode and counts.
- Uploaded files on disk. These are the same caveat as `uat:reset`.

### Close (`is_active = false`)

- The row chip is now a label. Closing and reopening use explicit buttons behind a dialog that lists what stops.
- `CompanyService::update()` now writes `company.closed` or `company.reopened` to the audit log.
- `Company::scopeOperational()` is the SQL twin of `isOperational()`. Every scheduled job now narrows its work with it:
  - `commissions:dispatch-due-renewals`
  - `commissions:run-binary-cycles`
  - `commissions:recalculate-agent-ranks` (the sweep, and `--company` refuses a closed company with the reason)
  - `commissions:pay-promotion-credits`
  - `reminders:dispatch-due-followups`
  - `notifications:send-emails`
- **Paused, not cancelled.** Anything that fell due while the company was closed is processed on the first run after it reopens. A renewal or promotion credit is still owed. This is stated in the close dialog. The exception is notification emails older than the existing 24-hour stale window: the sweep already abandons those.

## Consequences

- Going live keeps whatever test data the company holds, and that data becomes real. The dialog says so and suggests deleting and recreating instead.
- `PruneChunkedUploads` and `model:prune` are housekeeping, not company work, so they are not narrowed.
- Tests:
  - `CompanyRemovalTest` (11 tests, using the real `uat:seed-commission-plans` fixture).
  - `CompanyOperationalScopeTest`.
  - One closed-company test in each job's existing test file.
  - `CompanyManagementLifecycle.spec.ts`.
- Each guard was mutation-checked: removing it fails its test.
