<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 2026-09-12 — wraps the plain array CommissionSettingService assembles,
 * never a raw Company model (§7). Deliberately narrow: this endpoint answers
 * "how is commission calculated here", and returning the company row would
 * hand every reader its name, slug, payment details and withdrawal minimum
 * along with it.
 *
 * `commission_basis` is never null on the wire. The column defaults to
 * 'price' and the Service coalesces, because a screen that had to handle
 * "no basis" would have to invent one — and inventing it is exactly how a PV
 * company ends up being shown 'ราคาขาย'.
 *
 * `commission_plan_type` CAN be null, and that is a different fact: it means
 * the caller asked about no company in particular (a Super Admin on
 * "ทุกบริษัท"). Kept nullable rather than defaulted for BR-7's reason — the
 * screen may not assert a business value nobody told it.
 */
class CommissionSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'commission_basis' => $this['commission_basis']->value,
            'commission_plan_type' => $this['commission_plan_type']?->value,
            'commission_override_mode' => $this['commission_override_mode']->value,
            // Never null: a company with no hierarchy answers 0, which is a
            // real number the screen uses ("no manager chain yet, so no
            // deduction can happen").
            'deepest_manager_chain' => $this['deepest_manager_chain'],
            /*
             * 2026-09-19 — how many levels of leader override this company
             * pays, and whether a skipped manager's level is inherited.
             *
             * NULLABLE, and the null must survive the wire. It is the one
             * value on this endpoint whose null is a real answer — "as far as
             * the chain goes", which is what every company does today — and
             * coalescing it to a number here would print a cap nobody set,
             * onto the very field an admin uses to set one.
             *
             * The compression flag is the opposite: a company either
             * compresses or it does not, so it is always a boolean and never
             * null, for the same reason commission_basis is never null above.
             */
            'max_override_depth' => $this['max_override_depth'],
            'override_compression' => $this['override_compression'],
            /*
             * 2026-09-21 — may the plan and the basis still be changed?
             *
             * `{ locked, paid_orders, ledger_rows, first_sale_at }` rather
             * than a bare boolean, because the screen has to SAY WHY.
             * "เปลี่ยนไม่ได้" with no reason reads as a bug or a permission
             * problem; naming what was sold and when is the difference
             * between a rule and an obstruction — and it is the same set of
             * facts the server's own refusal quotes.
             *
             * BOTH counts, not one total. Owner's ruling 2026-09-21: a paid
             * order locks the plan even when no commission has been booked
             * against it yet. A single number could not tell those apart, and
             * a screen saying "มีค่าแนะนำลงบัญชีแล้ว 0 รายการ" over fourteen
             * paid orders is what sent the owner looking for a bug in the
             * lock.
             *
             * Always present, `locked: false` when nothing has been paid yet,
             * so the screen never has to tell "not locked" from "not told".
             */
            'plan_locked_by_sales' => $this['plan_locked_by_sales'],
            /*
             * 2026-09-15 — the company's own seat in its hierarchy, or null
             * when it has none. Null is the answer for most companies and a
             * real one: nobody has decided the company should take a leader's
             * share yet.
             */
            'commission_house_account' => $this['commission_house_account'],
            /*
             * 2026-09-15 — who this plan will pay nothing, and never say so.
             *
             * `{ total, leaders[] }`, not a bare list: the list is capped, and
             * a screen holding only the sample would have to present "3 of 47"
             * as "3". Always present, `total: 0` when there is nothing to warn
             * about — an absent key would make the screen guess whether it was
             * told "none" or told nothing.
             */
            'leaders_missing_certification' => $this['leaders_missing_certification'],
        ];
    }
}
