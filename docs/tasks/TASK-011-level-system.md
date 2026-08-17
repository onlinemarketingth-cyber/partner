Task: Level system (XP -> Level, Admin-configurable)
Owner: ag-dev + ag-ui — executed directly by ag-lead, no separate sessions running yet
Goal: Close the last open item from Phase 6 ("Level" was explicitly out of scope — no threshold config/schema existed, LeaderboardController/LeaderboardView both said so in their own docblocks). Human-confirmed decision: "Admin กรอกค่าเอง" — Admin fills in the XP->Level curve directly via config, no auto-computed formula.
Related: BR-5 (gamification), BR-7 (never hardcode business values — level_thresholds is Admin-editable config, seeded only with a placeholder curve), Section 5 is N/A here (level_thresholds has no company_id at all — platform-wide, see design note below)

Input: `level_thresholds` migration + `LevelThreshold` model already existed from the original Phase 6 schema pass (`2026_07_09_100010_create_level_thresholds_table.php`, columns `level_number` unique + `xp_required`) but had zero logic reading them and no CRUD API — this task builds both.

Expected output:
- `LevelThresholdPolicy` — viewAny/view: any authenticated user (read-only visibility, agents need to see their own level); create/update/delete: Super Admin only (no company_id column exists to scope a Company Admin's write to their "own" rows — a write here changes every tenant's XP->Level curve at once).
- `Store/UpdateLevelThresholdRequest`, `LevelThresholdResource`, `LevelThresholdService` (thin CRUD), `LevelThresholdController` (full apiResource).
- `LevelService::currentLevelForTotalXp()`/`currentLevelForUser()` — reads level_thresholds (memoized per-request instance to avoid N+1 across leaderboard rows), returns level_number/xp_required/total_xp/next_level_xp_required. Level 0 (no xp_required floor) if no thresholds configured or XP hasn't reached the first one — never throws.
- `LeaderboardController` — now injects `LevelService`, adds `level_number`/`next_level_xp_required` to every ranked row.
- `GamificationSeeder` — seeds a placeholder 10-level curve (0/100/300/600/1000/1500/2200/3000/4000/5200 XP), marked `TODO: CONFIRM (BR-7)`.
- Route: `Route::apiResource('level-thresholds', LevelThresholdController::class)`.
- Frontend: `LeaderboardView.vue` (Agent Portal) shows "Level ปัจจุบัน" KPI + a per-row Lv.N badge, both previously hardcoded to "—". `GamificationConfigView.vue` (Admin) gained a 3rd "Level" tab — full CRUD, edit/delete gated to Super Admin only (Company Admin sees the curve read-only).

Acceptance Criteria:
  - Any authenticated user can `GET /level-thresholds`; only Super Admin can `POST`/`PUT`/`DELETE` — Company Admin gets 403
  - `level_number` must be unique (validated both on create and update, ignoring self on update)
  - `/leaderboard` response includes `level_number`/`next_level_xp_required` per row, computed from level_thresholds, defaulting to level 0 when unconfigured
  - No level formula/threshold value was invented outside the explicitly-marked placeholder seed (CLAUDE.md Section 8 rule 1/2)
  - `eslint`/`vue-tsc --build`/`vite build` clean for both `frontend` and `frontend-admin`

Out of scope (future tasks):
  - Level-up notifications/celebratory UI
  - Per-company Level curve overrides (human explicitly chose the platform-wide-only design; revisit only if asked)

Design notes (flag if wrong): level_thresholds intentionally has NO company_id — a single curve for the whole platform, not "own override or platform default" like GamificationRule/Badge. This was the simplest reading of "Admin กรอกค่าเอง" that didn't require inventing a scoping rule nobody asked for; if per-tenant Level curves are wanted later, that's a new migration + a different Policy shape, not a small patch to this one.
