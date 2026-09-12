<?php

namespace App\Enums;

/**
 * 2026-09-12 (owner): "ทำแผน PV กับการตั้งค่าแบบคอม ขายตรง เก็บการคิดแบบ %
 * และ Fix จำนวนเงิน ไว้กับค่าคอมปรกติ".
 *
 * ── WHAT THIS IS, AND WHAT IT IS NOT ──
 *
 * This is NOT a third rate type. `percentage` and `fixed_satang`
 * (CommissionRateType) are untouched and keep meaning exactly what they
 * have always meant. This enum answers a different question, one level
 * up: WHICH NUMBER does a percentage apply to.
 *
 *   Price → the amount the customer actually paid, after any active
 *           ProductPricePromotion. Today's behaviour, and the default
 *           for every existing company, forever.
 *   PointValue → the product's own PV (commissionable value), a figure
 *           a Super Admin sets per product and that does not move when
 *           the price does.
 *
 * ── WHY A COMPANY-LEVEL SWITCH AND NOT A PER-RULE ONE ──
 *
 * Research + the owner's own ruling (2026-09-12, after
 * "ความเป็นไปได้น้อยมาก ถึงจะไม่เกิดขึ้นเลยกับบริษัทที่คิดค่าคอมต่างชนิดกัน"):
 * a compensation plan is a company-level promise. An agent must be able
 * to answer "how am I paid" once, not per product. Two products paying
 * off two different bases is the same defect as two products on two
 * different plan types — the upline is paid by rules that change
 * depending on what happened to be sold, and nobody can explain the
 * payout. Per-PRODUCT variation is expressed by the PV figure itself,
 * which is precisely what PV is for.
 *
 * ── WHY PV IS STORED IN SATANG ──
 *
 * A percentage of points has to become money at some conversion, and an
 * industry PV table is almost always denominated in the currency it
 * converts to. Storing PV on the same integer satang scale as price
 * (BR-3) means the conversion is 1:1 and explicit, the existing
 * CommissionRateCalculator needs no change at all, and no float is ever
 * introduced to bridge two scales. A company that wants "1 PV = 10 THB"
 * expresses it by setting PV to a tenth of the price, not by a hidden
 * multiplier this system would then own.
 */
enum CommissionBasis: string
{
    case Price = 'price';
    case PointValue = 'pv';
}
