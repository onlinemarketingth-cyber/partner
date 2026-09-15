<?php

// Thai translation of the same auth.php lines (see lang/en/auth.php).
//
// 2026-09-15 — THESE ARE LIVE NOW. This file sat unused for months behind a
// note that said "not wired to auto-switch yet — APP_LOCALE is 'en'";
// config/app.php now defaults the locale to 'th', so these are the sentences
// the API actually returns. LoginView's own Thai copy still wins on the
// screen (it branches on status and on attempts_remaining/lockout_seconds,
// never on this text), so the two cannot disagree in front of a user — but
// any OTHER caller of a 401/429 now gets Thai instead of English.
return [
    'failed' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง',
    'password' => 'รหัสผ่านไม่ถูกต้อง',
    'throttle' => 'พยายามเข้าสู่ระบบบ่อยเกินไป กรุณาลองใหม่ในอีก :seconds วินาที',
];
