<?php

namespace App\Enums;

/**
 * 2026-09-15 — WHO STARTED THIS PAYOUT.
 *
 * Owner: "ระบบทำงานได้ 2 ทาง คือ ตัวแทนขอเบิกผ่านหน้า frontend … กับถึงกำหนด
 * ทำจ่ายค่าคอม ถึงไปขั้นตอนทำจ่ายจริง คือการกดยืนยันจ่ายจริง".
 *
 * Until now those two were different objects in different tables reached from
 * different menus: an agent's request went through commission_withdrawal_requests
 * and its three states, while an admin paying somebody flipped
 * commission_ledger.payment_status straight to Paid in one click. Same money,
 * same bank transfer, two shapes — and only one of them had room for the step
 * that actually takes the time, which is accounting moving the money.
 *
 * So both are now the SAME object, and this column is the only thing that
 * differs. That is deliberate: everything downstream of "somebody decided to
 * pay this agent this amount" — the allocation across ledger rows, the reserved
 * balance, the bank snapshot, the transfer reference, the audit trail, the
 * notifications — is identical, and a second implementation of any of it is a
 * second place for the money to go wrong.
 *
 * ── WHAT THE SOURCE ACTUALLY CHANGES ──
 *
 * Only two things, both at creation:
 *
 *   1. THE STARTING STATE. An agent's request begins at PendingReview because
 *      a human still has to agree to it. A company payout begins at Approved,
 *      because the admin pressing the button IS that agreement — asking them
 *      to approve their own decision on the next screen would be a rubber
 *      stamp, and a rubber stamp teaches people to click through queues.
 *   2. THE COMPANY MINIMUM. It refuses an AGENT asking for a trivial amount;
 *      it has nothing to say about the company choosing to settle what it
 *      owes. See CommissionWithdrawalService::open().
 *
 * After creation the two are indistinguishable to every piece of code that
 * moves money, and the label below exists so the queue can still tell a
 * reviewer which kind of work is in front of them.
 */
enum WithdrawalSource: string
{
    /** The agent asked, from the agent portal. Needs a decision. */
    case AgentRequest = 'agent_request';

    /** An admin raised it from จ่ายเงิน. Already decided by the act of raising it. */
    case CompanyPayout = 'company_payout';

    public function label(): string
    {
        return match ($this) {
            self::AgentRequest => 'ตัวแทนขอเบิก',
            self::CompanyPayout => 'บริษัทตั้งจ่าย',
        };
    }
}
