<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\IndexAccountDeletionRequestsRequest;
use App\Http\Requests\Account\RejectAccountDeletionRequestRequest;
use App\Http\Resources\AccountDeletionRequestResource;
use App\Models\AccountDeletionRequest;
use App\Services\Account\AccountDeletionImpactService;
use App\Services\Account\AccountDeletionService;
use App\Support\CompanyScopeFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * MOB-12 (2026-10-02) — the admin side of in-app account deletion
 * ("คำขอลบบัญชี" in the admin console).
 *
 * Tenancy, in the order it applies (CLAUDE.md §5):
 *   1. TenantScope on AccountDeletionRequest narrows both the list query and
 *      the {accountDeletionRequest} route binding to a Company Admin's own
 *      company — another company's id is a 404 before anything here runs.
 *   2. AccountDeletionRequestPolicy::viewAny / decide — Company Admin (own
 *      company) or Super Admin; an agent is a 403.
 *   3. CompanyScopeFilter — the Super Admin's header company, NARROWING only.
 *
 * Thin by design: the decisions live in AccountDeletionService, the warning
 * counts in AccountDeletionImpactService.
 */
class AccountDeletionRequestController extends Controller
{
    private const RELATIONS = ['user', 'company', 'decidedBy'];

    public function index(IndexAccountDeletionRequestsRequest $request, AccountDeletionImpactService $impact): AnonymousResourceCollection
    {
        // Authorized by the Form Request (Policy::viewAny).
        $query = AccountDeletionRequest::query()
            ->where('status', $request->status()->value)
            ->with(self::RELATIONS)
            ->orderByDesc('requested_at')
            ->orderByDesc('id');

        // Before paginate(): narrowing a page after the fact would page over
        // the unfiltered set (TASK-209).
        CompanyScopeFilter::apply($query, $request);

        $page = $query->paginate();
        $impacts = $impact->forUsers($page->getCollection()->pluck('user_id'));

        return AccountDeletionRequestResource::collection(
            $page->through(fn (AccountDeletionRequest $row) => new AccountDeletionRequestResource($row, $impacts->get($row->user_id))),
        );
    }

    public function approve(
        Request $request,
        AccountDeletionRequest $accountDeletionRequest,
        AccountDeletionService $service,
        AccountDeletionImpactService $impact,
    ): AccountDeletionRequestResource {
        $this->authorize('decide', $accountDeletionRequest);

        $decided = $service->approve($accountDeletionRequest, $request->user())->load(self::RELATIONS);

        return new AccountDeletionRequestResource($decided, $impact->forUsers([$decided->user_id])->get($decided->user_id));
    }

    public function reject(
        RejectAccountDeletionRequestRequest $request,
        AccountDeletionRequest $accountDeletionRequest,
        AccountDeletionService $service,
        AccountDeletionImpactService $impact,
    ): AccountDeletionRequestResource {
        // Authorized by the Form Request (Policy::decide).
        $decided = $service->reject($accountDeletionRequest, $request->user(), $request->validated('note'))->load(self::RELATIONS);

        return new AccountDeletionRequestResource($decided, $impact->forUsers([$decided->user_id])->get($decided->user_id));
    }
}
