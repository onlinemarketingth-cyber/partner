<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\StoreCertTierRequest;
use App\Http\Requests\Academy\UpdateCertTierRequest;
use App\Http\Resources\CertTierResource;
use App\Models\CertTier;
use App\Services\Academy\CertTierService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Academy cert tiers — CLAUDE.md §2, BR-1.
 *
 * GLOBAL / PLATFORM-WIDE: `cert_tiers` has no `company_id` column (see the
 * create migration, which says so outright). Every company shares one list.
 *
 * TASK-221 — writes added. Until then this was index-only, and the rows
 * could only be created by CatalogSeeder, a DEV-ONLY seeder. Production
 * consequently ran with zero tiers, which made the Academy Section form
 * unsavable: its Cert tier <select> is required and had nothing in it.
 *
 * Reading stays open to EVERY authenticated role (an Agent needs it to
 * render Academy progress); writing is Super-Admin-only — see
 * CertTierPolicy for why a Company Admin must not touch a list shared by
 * every tenant.
 */
class CertTierController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(CertTier::class, 'cert_tier');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        /*
         * NO CompanyScopeFilter HERE — TASK-221 removed it.
         *
         * TASK-209 added `CompanyScopeFilter::apply($query, $request)` with
         * a comment claiming "cert tiers are per-company
         * (cert_tiers.company_id)". They are not, and there is no such
         * column: the filter appended `where company_id = ?` and any caller
         * passing `?company_id=` got a 500 (verified against production,
         * 2026-08-20). It never fired in the app only because no screen
         * sends the parameter to this endpoint — a latent break, not a
         * working feature.
         *
         * A global table has nothing to narrow. Adding company_id later, if
         * a deployment ever needs per-company tiers, is a schema decision
         * with its own migration and its own blast radius (eleven FKs point
         * here) — not something to fake with a filter.
         */
        return CertTierResource::collection(
            CertTier::query()->orderBy('sort_order')->orderBy('id')->get()
        );
    }

    public function store(StoreCertTierRequest $request, CertTierService $service): CertTierResource
    {
        return new CertTierResource($service->create($request->validated()));
    }

    public function update(UpdateCertTierRequest $request, CertTier $certTier, CertTierService $service): CertTierResource
    {
        return new CertTierResource($service->update($certTier, $request->validated()));
    }

    /**
     * Deleting goes through the Service so the "still in use" check runs
     * for any caller, not only one that passed the Policy. Eleven tables
     * point at this one with restrictOnDelete; `$certTier->delete()` inline
     * would surface that as a raw QueryException — a 500 carrying an
     * SQLSTATE instead of a sentence naming what is still using the tier.
     */
    public function destroy(CertTier $certTier, CertTierService $service): Response
    {
        $service->delete($certTier);

        return response()->noContent();
    }
}
