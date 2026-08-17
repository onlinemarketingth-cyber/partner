# TASK-174 — switch off the co-agent commission split (TASK-026) for early rollout

- **Owner:** ag-lead (spec) → ag-dev (backend + switch) → ag-ui (both apps) → ag-qa
- **Date:** 2026-08-12
- **Human:** *"ซ่อนระบบนี้ ทั้ง admin และ frontend เพราะทำให้สับสนตรวจสอบยากในช่วงขึ้นระบบแรกๆ และสามารถทำกันเองนอกระบบได้ รวมถึงคนที่ไม่ใช่ Agent ก็แบ่งกันเองได้นอกระบบ"*
- **Related:** TASK-026, TASK-170, BR-3, BR-4, BR-6, BR-7

---

## 1. Why

Two reasons, both the human's:

1. **It is hard to audit during the first weeks live.** One sale produces two ledger rows, so
   every reconciliation question doubles at exactly the time nobody trusts the numbers yet.
2. **It does not cover the real cases anyway.** A split can only name another *Agent of the
   same company*. Real arrangements involve people outside that set, so teams will settle up
   informally regardless — the feature adds audit surface without removing the practice.

This is a **temporary switch-off, not a deletion.** Nothing is dropped; the capability comes
back by flipping one setting.

## 2. Human decisions (2026-08-12)

**D1 — deals with a split already set, not yet paid: DO NOT SPLIT.** The whole commission
goes to the referring agent. A split nobody can see in the UI must not move money;
"switched off" has to mean off, or the audit problem this task exists to remove simply
becomes invisible instead.

**D2 — the switch is a PER-COMPANY setting in Admin**, not a platform config. A company can
turn it on when it is ready without a deploy.

## 3. Non-negotiable, no decision needed

- **Ledger rows already written are never touched.** BR-4: the ledger is immutable. Money
  already earned or paid keeps its history exactly as recorded, split rows included.
- **`co_agent_id` / `split_percentage` are NOT dropped or nulled.** The columns and their
  data stay. Switching off stops them being *read at calculation time*; it does not destroy
  what an agent entered. Reversible means reversible.

## 4. One predicate, enforced server-side

The single most likely way to get this wrong is six scattered `v-if`s that drift, leaving
one path that still splits. So:

- **One server-side check** — "is the split enabled for this company" — consumed by the
  calculation, the write endpoints, and the read Resource alike.
- **The client only REFLECTS it.** Hiding a button while the endpoint still accepts the
  request is not switching a feature off; it is hiding it from honest users only.
- Follow the existing per-company settings convention (`company_*_settings` +
  `*SettingsService`) rather than inventing a new shape. It is a BR-7 config value.

## 5. Surfaces (surveyed 2026-08-12)

| Layer | File |
|---|---|
| **Calculation** | `CommissionService::recordDirectSale()` — the method that writes two rows |
| **Backend write** | `SetCoAgentRequest`, `StoreReferralRequest` (accepts the pair at create), `ReferralController::setCoAgent` + `coAgentOptions` |
| **Backend read** | `ReferralResource`, `TeamClientResource` |
| **Agent Portal** | `CoAgentEditor.vue` (mounted in the ClientsView drawer), the split block in `ReferralCreateForm.vue` |
| **Admin** | `ClientFileView.vue` — display only, the single occurrence |

## 6. Consequence to design for, not to discover later

Turning the switch **back on** makes every still-pending referral that kept a
`co_agent_id` resume splitting — money behaviour changing without anyone touching those
deals. That is the correct behaviour for "the data was preserved", but it must not be a
surprise: **when enabling, show how many pending referrals carry a stored split**, so the
person flipping it sees what they are switching back on.

## 7. Tests

- Split enabled → two rows summing exactly to the single-row amount (existing TASK-026 tests must still pass).
- **Split disabled, referral HAS `co_agent_id` → ONE row, full amount to the referring agent** (D1 — the new rule).
- Split disabled → `setCoAgent` and `co-agent-options` refuse; `StoreReferralRequest` rejects (or ignores) the pair.
- Split disabled → the fields are absent from both Resources.
- Ledger rows written while it was enabled are unchanged by switching it off.
- Tenant isolation on the new setting endpoint (BR-6).
- Frontend: the editor and the create-form block are absent when the setting is off, present when on.

## 8. Order of work

1. **ag-dev** — setting + one server-side predicate + calculation + endpoints + Resources + tests. **Backend first: while the UI still shows the control, the server is already the thing that decides.**
2. **ag-ui** — Agent Portal, then the one Admin occurrence, plus the Admin toggle with the §6 count.
3. **ag-qa** — §7 by name, especially the D1 case.

## 9. Blocked on

The workspace has no working shell (disk full), so nothing here can be built or verified
yet. **Do not start implementation until `php artisan test`, `vue-tsc` and `vitest` can
actually run** — this is commission code and BR-4 money behaviour, and today already
produced four unverified edits waiting on a green run.
