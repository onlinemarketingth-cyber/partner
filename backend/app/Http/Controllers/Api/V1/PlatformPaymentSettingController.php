<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Enums\PaymentAccountScope;
use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ActivatePaymentGatewayRequest;
use App\Http\Requests\Payment\UpdatePaymentGatewayRequest;
use App\Http\Requests\Payment\UpdatePlatformPaymentModeRequest;
use App\Http\Requests\Payment\UpdatePlatformTransferAccountRequest;
use App\Models\PlatformPaymentSetting;
use App\Services\Payment\CompanyPaymentGatewayService;
use App\Services\Payment\PaymentAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-050 — "ระบบชำระเงินกลาง": the platform switch and the platform's own
 * payment channels.
 *
 * Gated on Ability::SettingsPaymentGatewayUpdate — Super Admin only,
 * INCLUDING the read, for the reason CompanyPaymentGatewayController gives:
 * this is a map of where money goes. Every response is the same overview
 * shape, so the screen re-renders from what the server now holds rather
 * than from what it sent. No secret is ever in it.
 */
class PlatformPaymentSettingController extends Controller
{
    public function __construct(
        private readonly CompanyPaymentGatewayService $gateways,
        private readonly PaymentAccountService $accounts,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizeGateway($request);

        return $this->overview();
    }

    public function updateMode(UpdatePlatformPaymentModeRequest $request): JsonResponse
    {
        $this->authorizeGateway($request);

        $this->accounts->setMode(PaymentAccountScope::from($request->validated('mode')), $request->user());

        return $this->overview();
    }

    public function updateTransferAccount(UpdatePlatformTransferAccountRequest $request): JsonResponse
    {
        $this->authorizeGateway($request);

        $this->accounts->saveTransferAccount($request->validated(), $request->user());

        return $this->overview();
    }

    public function updateGateway(UpdatePaymentGatewayRequest $request, string $provider): JsonResponse
    {
        $this->authorizeGateway($request);

        $row = $this->gateways->savePlatform(
            $this->providerOr404($provider),
            $request->validated('credentials', []),
            $request->boolean('is_live'),
            $request->user(),
        );

        return $this->overview($row->verified_note);
    }

    public function activateGateway(ActivatePaymentGatewayRequest $request): JsonResponse
    {
        $this->authorizeGateway($request);

        $this->gateways->activatePlatform($this->providerOr404($request->validated('provider')), $request->user());

        return $this->overview();
    }

    public function deactivateGateway(Request $request): JsonResponse
    {
        $this->authorizeGateway($request);

        $this->gateways->deactivatePlatformOnlineGateway($request->user());

        return $this->overview();
    }

    private function overview(?string $message = null): JsonResponse
    {
        $settings = PlatformPaymentSetting::current();
        $mode = $settings->mode ?? PaymentAccountScope::default();

        return response()->json([
            'data' => [
                'mode' => $mode->value,
                'mode_label' => $mode->label(),
                // Why switching to the platform would be refused right now.
                'platform_ready_problems' => $this->accounts->platformReadinessProblems($settings),
                'transfer_account' => [
                    'promptpay_id' => $settings->promptpay_id,
                    'bank_name' => $settings->bank_name,
                    'bank_account_number' => $settings->bank_account_number,
                    'bank_account_name' => $settings->bank_account_name,
                ],
                'active_provider' => $settings->activeProvider()?->value,
                'gateways' => $this->gateways->platformOverview(),
            ],
            'message' => $message,
        ]);
    }

    private function authorizeGateway(Request $request): void
    {
        abort_unless($request->user()->can(Ability::SettingsPaymentGatewayUpdate), 403);
    }

    private function providerOr404(string $provider): PaymentProvider
    {
        return PaymentProvider::tryFrom($provider) ?? abort(404);
    }
}
