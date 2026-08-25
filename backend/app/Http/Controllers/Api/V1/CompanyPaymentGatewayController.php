<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ActivatePaymentGatewayRequest;
use App\Http\Requests\Payment\UpdatePaymentGatewayRequest;
use App\Models\Company;
use App\Services\Payment\CompanyPaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A company's payment gateway setup (ADR-027 / TASK-139).
 *
 * Every method is gated on Ability::SettingsPaymentGatewayUpdate — Super
 * Admin only — INCLUDING the read. That is unusual here: most settings
 * screens read wider than they write. This one does not, because the read
 * lists which gateways a company has configured and in which mode, and that
 * is a map of where every tenant's money goes.
 *
 * `{company}` is explicit in the path rather than implied by the actor's own
 * company_id, because the only role allowed here has no company of its own.
 */
class CompanyPaymentGatewayController extends Controller
{
    public function __construct(private readonly CompanyPaymentGatewayService $service) {}

    private function authorizeGateway(Request $request): void
    {
        abort_unless($request->user()->can(Ability::SettingsPaymentGatewayUpdate), 403);
    }

    public function index(Request $request, Company $company): JsonResponse
    {
        $this->authorizeGateway($request);

        return response()->json([
            'data' => [
                'active_provider' => $company->payment_provider,
                'gateways' => $this->service->overview($company),
            ],
        ]);
    }

    public function update(UpdatePaymentGatewayRequest $request, Company $company, string $provider): JsonResponse
    {
        $this->authorizeGateway($request);

        $row = $this->service->save(
            $company,
            $this->providerOr404($provider),
            $request->validated('credentials', []),
            $request->boolean('is_live'),
        );

        // The row itself is never returned: `credentials` is $hidden, but
        // sending the model at all invites a future edit that unhides it.
        // The overview is the same shape the screen already renders.
        return response()->json([
            'data' => [
                'active_provider' => $company->refresh()->payment_provider,
                'gateways' => $this->service->overview($company),
            ],
            'message' => $row->verified_note,
        ]);
    }

    public function activate(ActivatePaymentGatewayRequest $request, Company $company): JsonResponse
    {
        $this->authorizeGateway($request);

        $company = $this->service->activate($company, $this->providerOr404($request->validated('provider')));

        return response()->json([
            'data' => [
                'active_provider' => $company->payment_provider,
                'gateways' => $this->service->overview($company),
            ],
        ]);
    }

    /**
     * 404 for an unknown provider, never a fallback.
     *
     * Defaulting to Manual here would let a typo in a URL quietly reconfigure
     * a company onto slip uploads.
     */
    private function providerOr404(string $provider): PaymentProvider
    {
        return PaymentProvider::tryFrom($provider) ?? abort(404);
    }
}
