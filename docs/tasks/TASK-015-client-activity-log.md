Task: Client Activity / Communication Log
Owner: ag-dev + ag-ui
Goal: A CRM-standards comparison (2026-07-13) identified this as the standout missing CRM feature: a record of every call/chat/meeting an agent has with a client, with an optional follow-up date. Today the only trail on a client is the Referral pipeline's stage-change log (Section 4.3), which says nothing about day-to-day contact that hasn't (yet) turned into a Referral, or exists alongside one.
Related: CLAUDE.md §2 (Client), Section 5 (multi-tenant isolation — new table needs `company_id` + `TenantScope` like every other domain table), Section 6 (Audit Log spirit — "record every action that affects... status"), BR-1 not affected (this is not a selling-rights gate)

Input: `clients` table (existing), authenticated actor (`$request->user()`)

Expected output:
- New table `client_activities`: `id`, `company_id`, `client_id` (FK → clients, cascadeOnDelete), `logged_by_user_id` (FK → users, restrictOnDelete), `type` (string — `App\Enums\ClientActivityType`: `call` / `chat` / `meeting` / `other`), `summary` (text, required), `occurred_at` (datetime — when the contact happened; defaults to now but backdatable), `follow_up_at` (datetime, nullable), `follow_up_notified_at` (datetime, nullable — owned by TASK-016, NULL means "not yet notified or no follow-up set"), timestamps.
- `App\Enums\ClientActivityType` — same fixed-vocabulary style as `App\Enums\ClientStatus`.
- `ClientActivity` model — `TenantScope`, `$fillable`, `belongsTo(Client::class)`, `belongsTo(User::class, 'logged_by_user_id')`.
- `ClientActivityPolicy` (new — not reused from `ClientPolicy`, ownership rules genuinely differ): `viewAny`/`create` → same reach as `ClientPolicy::view($client)` (anyone who can see the client can log/view activity on it); `update` → only the original `logged_by_user_id` (correcting your own note); `delete` → Company Admin/Super Admin only (same asymmetry as `ClientPolicy::delete` — an agent can't erase their own contact history).
- `StoreClientActivityRequest` / `UpdateClientActivityRequest`, `ClientActivityResource`, `ClientActivityController` (`index`/`store` nested at `/clients/{client}/activities`, `update`/`destroy` flat at `/client-activities/{activity}` — same routing shape as `ClientDocumentController`/`ProductSalesMaterialController`).
- `ClientActivityService::create()` forces `company_id`/`logged_by_user_id` server-side — never trust client input, same pattern as every other Service in this codebase.
- Agent Portal `ClientsView.vue`: new "ประวัติการติดต่อ" section in the client drawer — list of past activities (newest first), a "+ บันทึกการติดต่อ" quick-add form (type dropdown, summary textarea, optional `follow_up_at` via the existing `BuddhistDateInput`), edit/delete controls shown only on the actor's own entries per the Policy.
- Admin `ClientManagementView.vue`: same list, read-only (matches this screen's existing pattern for documents/referrals).
- Feature tests: tenant isolation (cross-company activity access → 404), agent can create/view own client's activities, agent cannot edit a colleague's activity (403), only admin can delete, `follow_up_at` accepts null.

Acceptance Criteria:
  - An agent can log a call/chat/meeting/other against any client they can already view (per `ClientPolicy::view`), with a required summary and optional `occurred_at`/`follow_up_at`
  - Activities are listed newest-first on both frontends' client drawer
  - An agent can edit their own activity's summary but not a colleague's (403)
  - Only Company Admin/Super Admin can delete an activity
  - Cross-tenant access → 404 (same as every other domain table)
  - `follow_up_at` can be left blank (no reminder wanted)
  - `eslint` / `vue-tsc --build` / `vite build` clean (both apps); `php artisan test` passes

Out of scope (this task):
  - Actually sending the follow-up reminder — that's TASK-016. This task only stores `follow_up_at` and shows it as a plain date (e.g. "ติดตามอีกครั้ง: 20 ก.ค. 2569") with no "overdue" styling yet, since that's meaningless until TASK-016 ships
  - Any activity type beyond the fixed four (`call`/`chat`/`meeting`/`other`) — flag if the human wants this admin-configurable later (would become a config-table task like `commission_rules`, not a code enum)

Depends on: none (can start immediately)
Blocks: TASK-016 (reads `client_activities.follow_up_at`/`follow_up_notified_at`)
