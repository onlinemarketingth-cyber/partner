<?php

namespace App\Enums;

/**
 * MOB-12 follow-up (owner decision 2026-10-03) — how an account deletion
 * request was closed.
 *
 *   immediate — the agent owed nothing, or chose "ยินยอมไม่รับค่าคอม"; the
 *               account was anonymised in the same request that asked for it,
 *               with no admin involved (decided_by stays NULL).
 *   admin     — an admin approved or rejected it from คำขอลบบัญชี.
 *
 * NULL on the row while it is still pending. The `value` strings cross the
 * wire to the admin console — treat them as a published contract.
 */
enum AccountDeletionResolution: string
{
    case Immediate = 'immediate';
    case Admin = 'admin';
}
