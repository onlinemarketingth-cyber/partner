<?php

namespace App\Enums;

// ADR-033 (TASK-189) §2.2/B3 — COMPUTED, never stored (see OrderVoucher::status()).
// `exhausted` when usage_quota is set and used_count has reached it;
// `expired` when expires_at is set and in the past; else `active`. Kept as
// a small fixed vocabulary (not a free string) for the same reason as
// OrderStatus — the UI needs a label per state, and staff must be told
// WHICH refusal reason applies (exhausted vs expired), not just "no".
enum VoucherStatus: string
{
    case Active = 'active';
    case Exhausted = 'exhausted';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'ใช้งานได้',
            self::Exhausted => 'ใช้สิทธิ์ครบจำนวนแล้ว',
            self::Expired => 'หมดอายุแล้ว',
        };
    }
}
