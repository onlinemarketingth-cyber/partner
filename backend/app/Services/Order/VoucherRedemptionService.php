<?php

namespace App\Services\Order;

use App\Enums\VoucherStatus;
use App\Models\OrderVoucher;
use App\Models\User;
use App\Models\VoucherRedemption;
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
        $voucher = OrderVoucher::query()
            ->where('code', $code)
            ->with([
                'order' => fn ($q) => $q->withoutGlobalScopes(),
                'order.product' => fn ($q) => $q->withoutGlobalScopes(),
                'order.client' => fn ($q) => $q->withoutGlobalScopes(),
                'order.company',
            ])
            ->first();

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

            return $voucher->fresh(['order.product', 'order.client', 'order.company']);
        });
    }

    /**
     * §5 rule 5 (IDOR) — a voucher belonging to another company is refused
     * with 404, matching every other cross-tenant lookup in this codebase,
     * so an actor cannot distinguish "wrong code" traffic from "right code,
     * wrong company" traffic.
     */
    private function assertSameTenant(OrderVoucher $voucher, User $actor): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        abort_unless($voucher->order->company_id === $actor->company_id, 404);
    }
}
