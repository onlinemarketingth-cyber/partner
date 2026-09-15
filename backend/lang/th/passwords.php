<?php

/*
 * 2026-09-15 — Laravel's password-broker messages, in Thai.
 *
 * Added alongside lang/th/validation.php for the same reason and with one
 * extra care: these four sentences are the entire feedback a password reset
 * gives, and two of them ('token' and 'user') are refusals a reader can do
 * nothing about unless the sentence says what to do next. Both therefore end
 * with the action rather than the diagnosis.
 *
 * 'user' deliberately does NOT confirm whether the address exists. Laravel's
 * English wording ("We can't find a user with that email address") is an
 * account-existence oracle on an unauthenticated endpoint; the Thai wording
 * below says the request could not be processed and leaves it there.
 */

return [
    'reset' => 'ตั้งรหัสผ่านใหม่เรียบร้อยแล้ว',
    'sent' => 'หากอีเมลนี้มีอยู่ในระบบ เราได้ส่งลิงก์ตั้งรหัสผ่านใหม่ไปให้แล้ว',
    'throttled' => 'ขอลิงก์ถี่เกินไป กรุณารอสักครู่แล้วลองใหม่',
    'token' => 'ลิงก์ตั้งรหัสผ่านใหม่หมดอายุหรือถูกใช้ไปแล้ว กรุณาขอลิงก์ใหม่อีกครั้ง',
    'user' => 'ไม่สามารถดำเนินการคำขอนี้ได้ กรุณาตรวจสอบอีเมลแล้วลองใหม่',
];
