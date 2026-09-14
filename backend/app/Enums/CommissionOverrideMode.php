<?php

namespace App\Enums;

/**
 * 2026-09-13 — WHERE THE TEAM LEADER'S SHARE COMES FROM.
 *
 * The owner asked for this after reading a worked example: "2% ไม่ได้หักจาก
 * 300 ของสมชาย เรื่องนี้ต้องทำให้ชัดเจน และปรับได้ทั้งหักจากสมชายปิดการขาย
 * และบริษัทจ่ายเพิ่ม".
 *
 * Until today the answer was hardcoded per plan: Unilevel always paid the
 * leader ON TOP, Affiliate could carve it out of the seller's own commission
 * (TASK-194's AffiliateOverrideMode). Neither was a choice a company could
 * make, and nothing on any screen said which one was in force — so the single
 * most misunderstood number in the whole system was also the least visible.
 *
 * ── THE THREE, WITH THE SAME EXAMPLE ──
 *
 * Sale 10,000 · agent rate 3% (=300) · leader rate 2%.
 *
 *   Additive              leader 200 (2% of the SALE), paid ON TOP.
 *                         Agent keeps 300. Company pays 500.
 *
 *   DeductFromSale        leader 200 (2% of the SALE), taken OUT of the
 *                         agent's 300. Agent keeps 100. Company pays 300.
 *                         One pool, split down the line — the insurance
 *                         brokerage shape.
 *
 *   DeductFromCommission  leader 6 (2% of the AGENT'S 300), taken out of it.
 *                         Agent keeps 294. Company pays 300.
 *                         This is the one TASK-194 already shipped as
 *                         AffiliateOverrideMode::Deductive.
 *
 * The two deduct modes differ by 33x on the same inputs. That is precisely
 * why the owner asked for the UI to explain before anybody chooses, and why
 * this enum spells the base into the case NAME rather than calling one of
 * them "deductive" and leaving the reader to guess which base.
 *
 * ── THE DANGER THAT LIVES IN DeductFromSale ──
 *
 * Unilevel pays EVERY manager up the chain, with no depth cap. At 2% each,
 * two managers already take 400 out of a 300 pool and the seller's commission
 * goes negative. DeductFromCommission does not have this problem at any
 * realistic depth (50 levels before it bites), and Additive cannot have it at
 * all.
 *
 * Two defences, and both are needed:
 *   1. CONFIGURATION — the override rate is refused at save time when
 *      rate x (deepest chain in this company) would exceed the smallest agent
 *      rate it has to come out of. Owner's decision, 2026-09-13:
 *      "ห้ามตั้งเรทที่หักเกิน".
 *   2. RUNTIME — the chain can deepen after the rate was approved (somebody
 *      is given a manager), so CommissionService also stops paying once the
 *      pool is exhausted and logs it. A seller's commission may never go
 *      negative, whatever the configuration says.
 */
enum CommissionOverrideMode: string
{
    case Additive = 'additive';
    case DeductFromSale = 'deduct_from_sale';
    case DeductFromCommission = 'deduct_from_commission';

    /** True when the leader's share comes out of the seller's own commission. */
    public function deductsFromSeller(): bool
    {
        return $this !== self::Additive;
    }
}
