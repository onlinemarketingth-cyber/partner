# ADR-049 — Who may recruit is a per-company setting

- **Status:** Accepted
- **Date:** 2026-09-28
- **Decided by:** the owner (KreangYot): "ผมเห็นด้วยทั้ง 1-3 ทำเลยครับ"
- **Supersedes:** ADR-025 §1 (only an admin-designated team leader may mint a recruit link) and the same flag check in ADR-025 §2/§7
- **Keeps:** ADR-025 §2's split between recruiting and the team monitor, ADR-025 §7's approval scope, and BR-1

## Context

During UAT-017 the owner could not find "ทีมของฉัน" on an agent's home screen. The agent had passed Basic but held no `is_team_leader` flag and had no direct reports. So the entry was hidden and "ชวนเข้าทีม" was unreachable.

The owner then asked whether a recruit button belongs in every MLM plan, and what the standard is. The answer given:

- In unilevel, stairstep, binary and matrix plans, any active distributor may sponsor new people.
- Leadership (rank) comes from recruiting and volume. It is not a prerequisite for recruiting.
- ADR-025's flag reversed that order. A stairstep leader could only exist after an admin had already named them one.

## Decision

The owner agreed to all three proposals.

1. **Open by default.** Any active agent who has passed Basic may recruit.
2. **A per-company choice** on the ตั้งค่าทีม page, labelled "ใครชวนเข้าทีมได้". It has two options:
   - `all_certified` ("ทุกคนที่ผ่าน Basic แล้ว"), which is the default;
   - `designated` ("เฉพาะคนที่แอดมินเปิดสิทธิ์"), which behaves exactly like ADR-025.
3. **Approval is unchanged.** A recruit is `pending` until the recruiter or an admin approves them. They still need Basic before they can sell (BR-1).

## Implementation

- **Setting storage.** `team_visibility_settings.recruit_policy` is a `string(32)` column with default `all_certified`. The migration is `2026_10_08_090000`. Enum: `App\Enums\RecruitPolicy`.
  - A company with no row reads as the default.
  - An unknown stored value reads as `designated`, so it fails closed.
- **One rule for every gate.** `User::canRecruit()` answers "may this user recruit":
  - false for a removed user;
  - true if `is_team_leader` is set, under either policy (no one loses a right they had);
  - otherwise the user must be an agent, the company policy must be `all_certified`, and the user must hold a Basic certification. That certification is read without TenantScope, because registration asks this question with nobody signed in.
- **The four gates that now ask it** (each previously read the flag directly):
  - `AgentInviteLinkService::create` — minting a link. It still returns a 422 keyed `is_team_leader`, now with a Thai message.
  - `RegistrationService::resolveActiveInviter` — whether a link still admits sign-ups.
  - `LeaderRecruitScope::mayApprove` — whether the recruiter may approve their own recruits.
  - `AgentApprovalController::myRecruits` — the recruiter's pending list.
- **Portal.** `UserResource` sends `can_recruit` on `forOwner()` responses only (login and `/me`). Rosters never compute it per row. The portal's Home entry and the "ชวนเข้าทีม" block now read `can_recruit`, falling back to `is_team_leader` for a user cached before the field existed.
- **Audit.** Changing the policy writes `team_settings.recruit_policy_changed` with the old and new values (§6).
- **Admin page.** The page is renamed "ตั้งค่าทีม" (it was "การมองเห็นข้อมูลทีม") so an admin can find the new setting. It is saved in the same request as the visibility level. The long explanation sits behind the ⓘ (§7).

## Follow-up from UAT (same day)

The owner checked this on production and saw a pending recruit twice on the recruiter's ทีมของฉัน page:
- once under "รออนุมัติเข้าทีม";
- again in "สายงานของฉัน", counted in "ลูกทีมทั้งสาย".

This happened because registering through a link sets `manager_id` straight away.

The owner ruled: **"ไม่นับจนกว่าจะอนุมัติ"** (not counted until approved).

`DownlineService::companyScoped()` now keeps only `agent_approval_status = approved`. Pending and rejected applicants appear only in the approval queue. This applies to:
- the team monitor;
- its KPIs;
- `/me/home`'s `direct_reports_count`.

The approval queue (`LeaderRecruitScope`) is unchanged. Admin rosters are unaffected, and commission walks are unaffected.

Two wording fixes shipped with this:
- the portal note that names the admin page now says "ตั้งค่าทีม";
- the pending-login hint now says "ผู้ที่ชวนคุณเข้าทีม" instead of "หัวหน้าทีม".

## Consequences

- **Existing companies start on `all_certified`.** After deploy, every agent there who has passed Basic sees "ทีมของฉัน" and may mint links. A company that wants the old behaviour selects "เฉพาะคนที่แอดมินเปิดสิทธิ์" once.
- **Narrowing the policy takes effect immediately.**
  - Links owned by people without the right stop admitting sign-ups.
  - Those people can no longer approve or list their recruits.
  - Admins can still approve anyone already pending.
- **`is_team_leader` keeps a job.** It is now the per-person override: always yes, under either policy.
- **Stairstep rank is untouched.** It stays volume-based (ADR-043).

## Tests

- `tests/Feature/Registration/RecruitPolicyTest.php` has 17 tests. `DownlineServiceTest` adds `test_only_approved_people_are_in_the_downline`. They cover:
  - the default;
  - an agent without Basic;
  - designated mode on all four gates;
  - the flag overriding the policy;
  - policy read per company;
  - audit;
  - validation;
  - the agent 403;
  - a cross-tenant write being ignored;
  - `can_recruit` absent from another user's profile;
  - a pending recruit sitting in the queue, not on the team.
- Existing ADR-025 suites pass unchanged.
- Portal spec: `HomeRecruitEntry.spec.ts`. Admin spec: `TeamSettingsRecruitPolicy.spec.ts`.
- Mutation checks were run on each gate, the Basic check, the policy check, the owner-only field and the audit condition. All were killed.
