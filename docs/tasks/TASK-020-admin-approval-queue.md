Task: Admin — Pending Agent Approvals queue + notification
Owner: ag-dev + ag-ui
Goal: Give a Company Admin a place to see and act on self-registered Agents awaiting approval (ADR-005 decision 3), and notify them by email the moment a registration is actually ready for review (email verified, or social-verified).
Related: ADR-005, CLAUDE.md Section 5 rule 4 (Company Admin manages their own company only — Super Admin sees across companies, same pattern as every other Admin screen), ADR-004 (Notification Infrastructure — reused as-is, not redesigned)

Input: TASK-017's `agent_approval_status`, TASK-018's "email verification completed" hook, TASK-019's "social registration completed" path, ADR-004's existing queue/scheduler infrastructure (no new notification architecture needed — this is the second consumer of the same pattern TASK-016 built)

Expected output:
- `App\Notifications\NewAgentRegistrationNotification` — `via() => ['mail']`, sent to every Company Admin of the registrant's company the moment the registrant becomes reviewable (email verified for the email path; immediately for the social path, subject to TASK-019's LINE-email-fallback design note). Dispatched as a queued job from inside `RegistrationService`/`SocialLoginController`, not synchronously in the request — same "don't block the HTTP response on a mail send" principle as ADR-004.
- `GET /api/v1/agent-approvals` (Company Admin/Super Admin only) — lists `users` where `agent_approval_status = pending` in-scope (own company for Company Admin, all for Super Admin — mirrors every other Admin listing already in this codebase).
- `PUT /api/v1/agent-approvals/{user}/approve` — sets `agent_approval_status = Approved`.
- `PUT /api/v1/agent-approvals/{user}/reject` — body `{ reason? }`, sets `agent_approval_status = Rejected`, `approval_rejection_reason`.
- A new `AgentApprovalPolicy` (or an added method on the existing Agent-management authorization path, whichever the actual `AgentManagementView.vue`/its controller already uses — ag-dev to confirm which fits without duplicating rules) restricting both actions to Company Admin (own company) / Super Admin.
- `frontend-admin`: new **"รออนุมัติ Agent"** screen (or a new tab on the existing `AgentManagementView.vue`, whichever keeps the UI closer to the existing "one place to manage agents" mental model — ag-lead recommends a tab on the existing screen over a brand-new nav item, but this is a UI judgment call, not a business rule) listing pending registrants with approve/reject buttons, reject requiring a short optional reason text field.
- Feature tests: Company Admin sees only their own company's pending registrants (cross-tenant → 404/403); approve/reject actually change `agent_approval_status`; a Company Admin cannot approve/reject another company's pending user; the notification is sent to the right Company Admin(s) and not sent again on a duplicate check (using `Notification::fake()`, mirroring TASK-016's test style).

Acceptance Criteria:
  - A newly-verified registration triggers exactly one notification email to the correct company's Admin(s)
  - Company Admin's approval queue never shows another company's pending registrants (BR-6)
  - Approve/reject actually flips the stored status and (for reject) stores the optional reason
  - `eslint` / `vue-tsc --build` / `vite build` clean (`frontend-admin` only); `php artisan test` passes

Out of scope (this task):
  - The registrant-facing login block itself (TASK-021 reads `agent_approval_status`, this task only writes it)
  - Any re-notification/reminder if an Admin ignores a pending approval for a long time (could reuse TASK-016's scheduled-reminder pattern later — flag if wanted; not asked for)

Design notes (flag if wrong):
  - Notification goes to *every* Company Admin of that company (there can be more than one), not just the first/oldest — flag if a different targeting rule (e.g. only whoever created the invite code) is preferred.
  - Whether this becomes a new nav item or a tab on the existing Manage Agents screen is a UI judgment call ag-lead is making, not a business rule — happy to change on request.

Depends on: TASK-017, TASK-018, TASK-019
Blocks: none (TASK-021 depends on TASK-017/018/019's schema/state, not on this task's UI)
