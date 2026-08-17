# ADR-016: Agent Portal → Personal "My Work" App (Home hub, real Notifications, Personal Goals)

- **Date:** 2026-07-23
- **Status:** Accepted — human-confirmed 2026-07-23 (reference app screens provided; decisions below). Phased build; **migrations + tests run by the human** (sandbox has no PHP).
- **Author:** ag-lead
- **Related:** CLAUDE.md §2/§4.3/§5/§6, ADR-003 (two frontends), Gamification (TASK-008/009/010), Academy (ADR-009), Announcements (TASK-042/322), Client Activity follow-ups (TASK-016). TASK-053.

## Context

The human wants the **Agent Portal (`frontend`)** reworked into a personal mobile-app-style hub — the agent opens it and sees: (1) their own photo + current status / goal progress with Gamification made PERSONAL (conquer your own goal, never see others), (2) pending work (client follow-ups, unfinished/failed courses & exams), (3) news, (4) notifications from the system / saved reminders. Research (employee self-service + insurance-agent apps: InsuredMine "My Goals" widget, Decerto dashboards, gamification engagement apps) confirms the pattern: mobile-first single column, a personal "My Goals" widget, real-time notifications for approvals/updates, and personal (not leaderboard-competitive) gamification.

System audit: Academy (courses/lessons/exams + cert), Referrals/Pipeline + `client_activities.follow_up_at`, Commission, Gamification (XP/Level/Badge — but Leaderboard shows others), Announcements (index + cert-tier targeting) all EXIST. **No real notification system exists** (bell is a stub; no table/model/controller). The Agent Portal has no announcements feed yet (TASK-039). No personal sales-target concept exists.

## Decisions (human-confirmed)

1. **Personal goal = BOTH Level/XP AND an explicit sales target** (answer "1+2"). Home shows two progress rings: the existing XP→Level progress AND a new **`agent_targets`** (per-agent, per-period sales/deal target set by Admin, actual pulled from paid sales). Gamification is shown personally (my level, XP-to-next, my badges) — the competitive Leaderboard stays a separate opt-in screen, not the home.
2. **Build a real Notification system** (answer "สร้างระบบจริง"): new **`notifications`** table + model + `NotificationService` + generation on events (new announcement targeting the agent, follow-up due, exam passed/failed, commission paid, approval status change, promotion bonus / reward status) + a scheduled job for time-based ones (follow-up due) + list/unread-count/mark-read API + a real bell. Replaces the stub.
3. **Agent Portal adopts a mobile-app shell (bottom nav)** (answer chosen): restructure the Agent Portal chrome into a bottom-tab app (Home / Tasks / Academy / etc.) with single-column, thumb-reachable navigation, per the reference. Admin app is unaffected.

## New schema

- **`notifications`**: id, company_id, user_id, type (enum), title, body, link (route name/params or path), data (json, nullable), read_at (nullable), timestamps. Indexed (user_id, read_at). Tenant-scoped.
- **`agent_targets`**: id, company_id, agent_id, period (YYYY-MM), metric (enum: sales_satang | deals | clients), target_value (unsigned int; satang for money — BR-3), timestamps. Unique (agent_id, period, metric). Admin-set (BR-7: value is admin data, never hardcoded).

## New functions / endpoints

- `GET /notifications` (my recent + unread), `GET /notifications/unread-count`, `POST /notifications/{id}/read`, `POST /notifications/read-all`.
- `GET /me/home` — one aggregation for the home hub: profile, gamification summary (level, xp, xp-to-next, badges), goal(s) (level ring + target ring with actual), pending-task counts, latest announcements, unread-notification count.
- `GET /me/tasks` — pending work: due/overdue client follow-ups, deals needing the next action, incomplete/failed lessons & exams.

> **Amended 2026-08-13 (TASK-180 §2, applying TASK-179's human decisions D1/D2/D3/D4).**
> Two figures in these two payloads named a different quantity than they measured.
> `MeService`'s class docblock is the current definition:
>
> - `goals[].actual_value` (month-to-date) is now read from that agent's **paid
>   orders** — money the customer paid (D1/D2), bucketed on the **sale date** (the
>   order's `paid_at`, D3). It was `commission_ledger.sale_price_satang_at_time`
>   gated on the commission's own `payment_status`, so the target ring sat at 0%
>   until the company ran payroll. Deals and clients moved to the same source, so
>   the three rings are on one axis. New sibling
>   `closed_deals_without_order_this_month` discloses deals closed this month that
>   contribute zero baht because they have no paid order — never estimated.
> - `task_counts.open_deals` and `tasks.open_deals` mean **not closed**, per the
>   shared `ClosedDealPredicate::applyOpen()`. They were a hardcoded
>   `[complete_payment, ongoing_next_meeting]` list, which since ADR-026 handed a
>   paid deal parked at จัดส่ง / นัดใช้บริการ / ติดตามผล back to the agent as
>   outstanding work.
- Admin `agent_targets` CRUD (set/adjust an agent's target) + agent read of own target.
- Consume existing `GET /announcements` (agent feed) — no new endpoint.
- `NotificationService::notify(user, type, ...)` + event hooks + a scheduled command for follow-up-due.

## Phasing

- **Phase 1 (foundation, this session):** migrations + models + enums for `notifications` and `agent_targets`; `NotificationService`; notifications list/unread/mark-read API + agent_targets read/admin-set API; tests. No event wiring, no frontend yet.
- **Phase 2:** event generation (announcement→notify targeted agents, exam pass/fail, commission paid, approval change) + scheduled follow-up-due command; `/me/home` + `/me/tasks` aggregation endpoints + tests.
- **Phase 3:** Agent Portal mobile-app shell (bottom nav) + redesigned `HomeView` hub (profile + 2 goal rings + tasks + news + notifications bell/page + announcements feed).
- **Phase 4:** Admin UI to set `agent_targets`.

## Consequences

- **Positive:** Agents get a focused personal "what do I do next + how am I doing" app; a real reusable notification system the whole platform can push to; personal gamification without exposing peers.
- **Trade-off:** Notifications are stored + generated (not realtime push/websockets — refreshed on load/poll; a push layer can come later). `agent_targets` is a new admin data surface (must be seeded/set or the target ring shows "no target"). Multi-phase — Phase 1 ships no visible UI on its own.
- **Operational:** two migrations; run `php artisan migrate` + the new tests. Scheduled command (Phase 2) added to the existing scheduler.

## Out of scope (for now)

- Websocket/push notifications; native mobile packaging; social-competitive gamification on the home (Leaderboard stays a separate screen).
