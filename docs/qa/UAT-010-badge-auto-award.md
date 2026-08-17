# UAT-010: Badge auto-award interpreter

No new migrations. Existing seeded badges (`first_sale`, `certified_agent`, `top_performer`) still have `condition_config = null` (unchanged) — this UAT creates a new test badge with a real condition to exercise the auto-award path.

## 1. Create a conditional badge (admin@thailife.test or a Super Admin)

- [ ] Go to "ตั้งค่า Gamification" → "Badge" tab → "+ สร้าง Badge".
- [ ] Fill Key/Name/Description/Icon, add one condition row: metric "XP รวม", operator ">=", value e.g. 50.
- [ ] Save — confirm the badge appears in the list showing "มอบอัตโนมัติ: XP รวม >= 50".

## 2. Trigger auto-award (agent@thailife.test)

- [ ] As the agent, complete an Academy module (or otherwise earn XP) until their total XP reaches/exceeds 50.
- [ ] Go to Leaderboard — confirm the new badge now appears under "Badge ที่ได้รับ" without anyone manually awarding it.
- [ ] Confirm it appears exactly once even after further XP-earning actions (idempotent — no duplicates).

## 3. Manual-award badges still work (admin@thailife.test)

- [ ] Confirm the original 3 seeded badges (condition_config still null) show "มอบเองเท่านั้น (ยังไม่ตั้งเงื่อนไข)" and can still be manually awarded via "+ มอบ Badge (manual)", same as before this task.

## 4. Ownership/authoring rules

- [ ] As a Company Admin, create a badge — confirm it's scoped to your own company (not visible as "(ทั้งแพลตฟอร์ม)").
- [ ] As a Company Admin, confirm you can only edit/delete your own company's badges — a platform-default badge's "แก้ไข"/"ลบ" buttons don't appear.
- [ ] As Super Admin, confirm you can create a platform-wide badge (check "ค่า default ทั้งแพลตฟอร์ม") and edit/delete any badge.

## Known gaps at this stage (not bugs — out of scope per TASK-012)

- No OR conditions or nested logic — only AND across a flat list of conditions.
- No retroactive sweep — a badge's condition only gets checked on the next XP-triggering event, not immediately when the condition_config is saved.

## Sign-off

- [ ] Tested by: _____________  Date: _____________
- [ ] Result: ☐ Pass  ☐ Pass with known gaps above  ☐ Fail (list blocking issues)
