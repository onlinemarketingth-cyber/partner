<?php

namespace App\Services\Order;

use App\Enums\SupplierReleaseTrigger;
use App\Enums\UserRole;
use App\Enums\VoucherStatus;
use App\Models\OrderVoucher;
use App\Models\User;
use App\Models\VoucherRedemption;
use App\Services\Supplier\SupplierSettlementService;
use App\Support\VoucherCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-033 (TASK-189) §2.1/§2.2 — redemption "at any branch, by staff
 * there" (human decision 2). Gated by Ability::VoucherRedeem at the
 * Controller, NOT re-checked here (Services trust their caller already
 * authorized — same shape as every other Service in this codebase).
 */
class VoucherRedemptionService
{
    public function __construct(
        // 2026-09-16 — a redemption can be the moment a supplier's money
        // becomes payable (SupplierReleaseTrigger::OnRedeemed). This service
        // decides whether it is; this class only reports that it happened.
        private SupplierSettlementService $supplierSettlements,
    ) {}

    /**
     * Resolve a voucher by its redemption code. Looks up WITHOUT
     * TenantScope on the query itself (OrderVoucher has no company_id
     * column of its own — it hangs off `order`) — same pattern as
     * PublicPaymentController::resolve(). Explicitly checks
     * $voucher->order->company_id against the actor (Super Admin
     * excepted), not a global scope.
     *
     * "Not found" is a 422 on the `code` field, same as any other typed/
     * scanned value a staff member gets wrong — NOT a 404, because this is
     * an authenticated staff action correcting their own input, not an
     * IDOR probe. Cross-tenant IS treated as an IDOR concern (404, §5
     * rule 5) — the two failures must not be distinguishable to the actor.
     */
    public function find(string $code, User $actor): OrderVoucher
    {
        // withoutGlobalScopes() on EVERY tenant-scoped relation in this
        // eager load, not just `order` itself: Order/Product/Client all
        // carry TenantScope keyed to the AUTHENTICATED actor, so loading
        // them normally while looking up another company's voucher would
        // silently come back null and make assertSameTenant() below blow
        // up on a null->company_id instead of refusing with 404 — the
        // exact bug this comment is here to stop someone reintroducing.
        /*
         * 2026-09-10 — RAW FIRST, then normalised.
         *
         * New codes are six characters from a deliberately unambiguous
         * alphabet, and VoucherCode::normalize() forgives what people actually
         * type: lower case, the dash from the printed card, and O/I/L where
         * 0/1 were meant.
         *
         * That normalisation is lossy, which is exactly why it is second. A
         * voucher issued before today carries a 40-character mixed-case token
         * that may legitimately contain those characters — normalising it
         * would turn a valid code into "ไม่พบรหัสบัตรกำนัลนี้ในระบบ" for a
         * customer holding a card that was printed last week.
         *
         * Two indexed lookups on a unique column, only when the first misses.
         */
        $voucher = $this->lookup($code);

        $normalized = VoucherCode::normalize($code);
        if ($voucher === null && $normalized !== $code && $normalized !== '') {
            $voucher = $this->lookup($normalized);
        }

        if ($voucher === null) {
            throw ValidationException::withMessages([
                'code' => 'ไม่พบรหัสบัตรกำนัลนี้ในระบบ',
            ]);
        }

        $this->assertSameTenant($voucher, $actor);

        return $voucher;
    }

    /**
     * Redeem a voucher. Refuses (422, distinct Thai messages naming WHICH
     * reason) when the voucher is exhausted or expired. On success,
     * inside a transaction: increments used_count and writes an immutable
     * voucher_redemptions row — `redeemed_at_branch` taken verbatim from
     * the request (nullable — "สาขาไหนก็ได้" means it is descriptive, not
     * a foreign key to validate against, ADR-033 §2.1).
     */
    public function redeem(string $code, User $actor, ?string $branch): OrderVoucher
    {
        $voucher = $this->find($code, $actor);

        match ($voucher->status()) {
            VoucherStatus::Exhausted => throw ValidationException::withMessages([
                'code' => 'บัตรกำนัลนี้ถูกใช้สิทธิ์ครบจำนวนแล้ว',
            ]),
            VoucherStatus::Expired => throw ValidationException::withMessages([
                'code' => 'บัตรกำนัลนี้หมดอายุแล้ว',
            ]),
            VoucherStatus::Active => null,
        };

        return DB::transaction(function () use ($voucher, $actor, $branch) {
            $voucher->increment('used_count');

            VoucherRedemption::create([
                'order_voucher_id' => $voucher->id,
                'company_id' => $voucher->order->company_id,
                'redeemed_by_user_id' => $actor->id,
                'redeemed_at_branch' => $branch,
                'redeemed_at' => now(),
            ]);

            /*
             * 2026-09-16 — a redemption is one of the three things that can
             * make a supplier's money payable (SupplierReleaseTrigger).
             *
             * Fires on EVERY redemption, and the service itself checks whether
             * this supplier's deal is the OnRedeemed kind — the alternative is
             * this method learning the supplier rules, which would put them in
             * two places.
             *
             * A no-op for every order that has no supplier, which is most of
             * them. Inside the transaction because "the service was taken" and
             * "the money for it became payable" are one fact; a crash between
             * them would leave a supplier permanently unpaid for a card that
             * says it was used.
             *
             * On a multi-use card this runs again on the second redemption and
             * finds nothing to release, because `released_at` is already set
             * and the query only touches null ones. Releasing is a one-way
             * door by construction.
             */
            $this->supplierSettlements->releaseFor(
                $voucher->order,
                SupplierReleaseTrigger::OnRedeemed,
            );

            return $voucher->fresh(['order.product', 'order.client', 'order.company']);
        });
    }

    /**
     * One exact-match read, with every tenant-scoped relation unscoped for the
     * reason the caller's comment gives.
     */
    private function lookup(string $code): ?OrderVoucher
    {
        return OrderVoucher::query()
            ->where('code', $code)
            ->with([
                'order' => fn ($q) => $q->withoutGlobalScopes(),
                'order.product' => fn ($q) => $q->withoutGlobalScopes(),
                'order.client' => fn ($q) => $q->withoutGlobalScopes(),
                'order.company',
            ])
            ->first();
    }

    /**
     * §5 rule 5 (IDOR) — a voucher belonging to another company is refused
     * with 404, matching every other cross-tenant lookup in this codebase,
     * so an actor cannot distinguish "wrong code" traffic from "right code,
     * wrong company" traffic.
     *
     * ── 2026-09-16: THE SAME-TENANT RULE WAS NOT ENOUGH ──
     *
     * A Company Partner supplies the product and very often IS the business
     * that performs the service the card entitles the customer to — the clinic
     * behind the shopfront. But the voucher hangs off an order belonging to
     * the company that SOLD it, and a partner is never that company. Under the
     * single rule below, `$voucher->order->company_id === $actor->company_id`
     * is false for every card a partner will ever be handed, so the feature
     * "partners redeem vouchers" would have returned 404 on all of them while
     * every line of code looked correct.
     *
     * So there are two ways in now, and they are genuinely different questions:
     *
     *   an ordinary actor  — "did MY company sell this?"
     *   a partner          — "did I supply what this card is for?"
     *
     * The second reads `products.supplier_company_id`, the same column every
     * other supplier-facing query filters on, and it is checked ONLY for the
     * partner role. Widening the first rule to accept either answer for
     * everybody would let a Company Admin redeem another tenant's card
     * whenever the two happened to share a supplier.
     */
    private function assertSameTenant(OrderVoucher $voucher, User $actor): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        if ($actor->role === UserRole::CompanyPartner) {
            // withoutGlobalScopes already applied on the eager load above, so
            // this reads the real product rather than a scoped-away null.
            abort_unless(
                $voucher->order?->product?->supplier_company_id !== null
                    && $voucher->order->product->supplier_company_id === $actor->company_id,
                404,
            );

            return;
        }

        abort_unless($voucher->order->company_id === $actor->company_id, 404);
    }
}
