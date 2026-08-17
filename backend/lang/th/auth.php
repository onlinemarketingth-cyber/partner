<?php

// Thai translation of the same auth.php lines (see lang/en/auth.php).
// Not wired to auto-switch yet — APP_LOCALE is 'en' and there is no
// Accept-Language negotiation middleware. Frontend currently maps known
// error shapes to Thai copy at the UI layer instead (see LoginView).
// Kept here so enabling locale negotiation later is a config change only,
// not a missing-translation bug.
return [
    'failed' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง',
    'password' => 'รหัสผ่านไม่ถูกต้อง',
    'throttle' => 'พยายามเข้าสู่ระบบบ่อยเกินไป กรุณาลองใหม่ในอีก :seconds วินาที',
];
