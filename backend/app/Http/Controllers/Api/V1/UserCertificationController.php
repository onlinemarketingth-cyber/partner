<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\StoreUserCertificationRequest;
use App\Http\Resources\UserCertificationResource;
use App\Models\CertTier;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Academy\CertificatePdfService;
use App\Services\Academy\ManualCertificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// Mostly read-only — rows are normally created by ExamAttemptService as a
// side effect of a passing exam attempt (BR-1). store() is the one
// exception: a Company Admin/Super Admin manual override (human-requested
// 2026-07-30) — see ManualCertificationService's own docblock.
class UserCertificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', UserCertification::class);

        $query = UserCertification::query()->with('certTier');

        if ($request->user()->isAgent()) {
            $query->where('user_id', $request->user()->id);
        }

        return UserCertificationResource::collection($query->latest('passed_at')->paginate());
    }

    public function store(StoreUserCertificationRequest $request, ManualCertificationService $service): UserCertificationResource
    {
        // FormRequest already scoped `user_id` to role=agent and (for a
        // Company Admin) their own company — findOrFail here is a second,
        // redundant-but-harmless safety net (TenantScope also applies to
        // this query for a Company Admin caller).
        $target = User::findOrFail($request->integer('user_id'));
        $tier = CertTier::findOrFail($request->integer('cert_tier_id'));

        $certification = $service->grant($target, $tier, $request->user());

        return new UserCertificationResource($certification->load('certTier'));
    }

    /**
     * Academy Sprint 6 — on-demand certificate PDF. Uses the same `view`
     * ability as a single-record read (Super Admin, the owning agent, or
     * Company Admin of the same company) — see UserCertificationPolicy.
     */
    public function download(UserCertification $userCertification, CertificatePdfService $service): mixed
    {
        $this->authorize('view', $userCertification);

        $tierKey = $userCertification->certTier?->key ?? $userCertification->id;

        return $service->render($userCertification)->download("certificate-{$tierKey}.pdf");
    }
}
