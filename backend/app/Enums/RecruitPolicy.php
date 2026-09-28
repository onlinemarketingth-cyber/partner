<?php

namespace App\Enums;

/**
 * 2026-09-28 — WHO IN A COMPANY MAY INVITE PEOPLE INTO THEIR TEAM.
 *
 * Owner, after reviewing how MLM plans usually work: every member recruits
 * from day one on every plan type, and "leader" is something a person earns
 * from results (Stairstep's rank) rather than a title an admin hands out. He
 * agreed to open recruiting by default and keep the old behaviour available
 * per company (ADR-049).
 *
 *   AllCertified  any active agent who has passed Basic (BR-1: somebody who
 *                 cannot sell yet should not be building a sales team), plus
 *                 anyone an admin has marked is_team_leader.
 *   Designated    only agents an admin has marked is_team_leader — the rule
 *                 ADR-025 introduced, for a company that wants it.
 *
 * Approving the people who sign up stays exactly as it was: they arrive
 * pending, the recruiter or an admin approves them, and they sell only after
 * passing Basic.
 */
enum RecruitPolicy: string
{
    case AllCertified = 'all_certified';
    case Designated = 'designated';

    public static function default(): self
    {
        return self::AllCertified;
    }

    public function label(): string
    {
        return match ($this) {
            self::AllCertified => 'ทุกคนที่ผ่าน Basic แล้ว',
            self::Designated => 'เฉพาะคนที่แอดมินเปิดสิทธิ์หัวหน้าทีม',
        };
    }
}
