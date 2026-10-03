<?php

namespace App\Enums;

/**
 * MOB-12 (2026-10-02) — where an agent's "delete my account" request stands.
 *
 * Apple App Store guideline 5.1.1(v) requires that an account can be deleted
 * from inside the app. The owner's decision is that the agent's press is a
 * REQUEST and a Company Admin decides it — not an instant delete — because an
 * agent may still be owed commission, may have a downline hanging under them
 * and may own clients, and each of those has to be handled by a person
 * before the account becomes unrecognisable.
 *
 *   pending  — asked for. The agent is signed out everywhere and the login
 *              gate refuses them (LoginBlockReason::DeletionRequested).
 *   approved — an admin approved it and the account was anonymised
 *              (AccountDeletionService::approve). Terminal.
 *   rejected — an admin declined it. The login block lifts; the agent signs
 *              in again normally and may ask again later.
 *
 * The `value` strings cross the wire to both frontends and are stored in
 * account_deletion_requests.status — treat them as a published contract.
 */
enum AccountDeletionRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * Statuses that keep the account out of the system.
     *
     * Approved is in the list even though an approved account has no usable
     * credentials left (its email and password are both replaced): the gate
     * answering "no" for it as well is what makes a later กู้คืน — or any
     * other path that hands the row back a working password — fail closed
     * instead of resurrecting an account its owner asked us to delete.
     *
     * @return list<self>
     */
    public static function blockingLogin(): array
    {
        return [self::Pending, self::Approved];
    }
}
