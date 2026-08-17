<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Voucher\RedeemVoucherRequest;
use App\Http\Resources\VoucherResource;
use App\Services\Order\VoucherRedemptionService;
use Illuminate\Http\Request;

// ADR-033 (TASK-189) §2.1/C4-C5 — authenticated voucher redemption, gated
// by Ability::VoucherRedeem (CompanyAdmin/SuperAdmin only, NOT Agent — the
// interim grant ADR-033 §2.1 describes). show() is the lookup C5 asks for
// so the redeem screen can display order/product/customer before staff
// commit to a POST; redeem() is the actual redemption.
class VoucherController extends Controller
{
    /** GET /vouchers/{code} */
    public function show(string $code, Request $request, VoucherRedemptionService $service): VoucherResource
    {
        abort_unless($request->user()->can(Ability::VoucherRedeem), 403);

        $voucher = $service->find($code, $request->user());

        return new VoucherResource($voucher);
    }

    /** POST /vouchers/redeem */
    public function redeem(RedeemVoucherRequest $request, VoucherRedemptionService $service): VoucherResource
    {
        $voucher = $service->redeem(
            $request->validated('code'),
            $request->user(),
            $request->validated('branch'),
        );

        return new VoucherResource($voucher);
    }
}
