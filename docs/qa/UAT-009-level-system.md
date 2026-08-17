# UAT-009: Level system (XP -> Level)

Backend has migrations to run: none new (level_thresholds table already existed). Reseed with `php artisan db:seed --class=GamificationSeeder` to get the placeholder curve, or run it fresh via `php artisan migrate:fresh --seed`.

## 1. Admin — Level tab (admin@thailife.test)

- [ ] Go to "ตั้งค่า Gamification" → "Level" tab — confirm the seeded curve (Level 1 @ 0 XP, Level 2 @ 100 XP, ... Level 10 @ 5200 XP) appears.
- [ ] Click "แก้ไข" on a level — confirm the value updates.
- [ ] Click "+ เพิ่ม Level" — create a new level number, confirm it appears sorted correctly.
- [ ] Try creating a duplicate level_number — confirm it's rejected.
- [ ] As `agent@thailife.test`, confirm the Level tab is visible (read-only) but "แก้ไข"/"ลบ"/"+ เพิ่ม Level" do NOT appear (only Super Admin edits).
- [ ] As a Company Admin who is not Super Admin, confirm the same read-only behavior.

## 2. Agent Portal — Leaderboard (agent@thailife.test)

- [ ] Go to Leaderboard — confirm "Level ปัจจุบัน" KPI shows a real "Lv.N" value (not "—").
- [ ] Confirm each row in the ranking shows a "Lv.N" badge next to its XP total.
- [ ] Earn enough XP to cross a threshold (e.g. complete another module) — confirm the level badge updates on next Leaderboard load.

## Known gaps at this stage (not bugs — out of scope per TASK-011)

- No level-up notification/celebration UI.
- No per-company Level curve override — one curve for the whole platform.

## Sign-off

- [ ] Tested by: _____________  Date: _____________
- [ ] Result: ☐ Pass  ☐ Pass with known gaps above  ☐ Fail (list blocking issues)
