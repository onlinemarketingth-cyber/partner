<?php

namespace App\Services\Compliance;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * TASK-041 (4.3) — PDPA/Compliance report. CLAUDE.md Section 6: "PDPA
 * (Thailand): client health data is sensitive — requires consent."
 * Surfaces consent completeness so an Admin can chase down clients who
 * were referred in without ever giving consent.
 *
 * $actor is not queried directly here — Client::query() is already
 * TenantScope'd (see Client's own docblock), and that scope resolves
 * the CURRENT authenticated user internally (auth()->user()), which is
 * the same user as $actor in every real request. The param is kept on
 * the signature per this task's spec and to make the dependency
 * explicit/testable, not because it's read directly.
 *
 * Judgment call (disclosed to ag-lead per this task's instructions):
 * for a Super Admin, this report shows CROSS-COMPANY totals (no
 * company_id filter) — TenantScope already bypasses its company_id
 * filter entirely for super_admin (see TenantScope::apply()), so a
 * plain Client::query() naturally returns every company's clients. The
 * literal controller shape specified for this task
 * (`$service->buildReport($request->user())`, no query-string filter
 * threaded through) doesn't pass a ?company_id= override, so this is
 * the simplest option consistent with that signature — a Super Admin
 * wanting one company's PDPA numbers can cross-reference company_id on
 * individual client rows elsewhere (e.g. Manage Clients), or a
 * follow-up task can add an explicit filter if that's wanted later.
 */
class ComplianceReportService
{
    public function buildReport(User $actor): array
    {
        $totalClients = Client::query()->count();
        $clientsWithConsent = Client::query()->whereNotNull('consent_given_at')->count();
        $clientsWithoutConsent = $totalClients - $clientsWithConsent;
        $consentRatePercent = $totalClients > 0
            ? round($clientsWithConsent / $totalClients * 100, 2)
            : 0.0;

        $clientsMissingConsent = Client::query()
            ->whereNull('consent_given_at')
            ->with('referringAgent')
            // Oldest-first = most overdue (been waiting on consent the longest).
            ->orderBy('created_at')
            ->limit(20)
            ->get()
            ->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => $client->name,
                'referring_agent' => $client->referringAgent?->name,
                'created_at' => $client->created_at,
            ])
            ->values();

        return [
            'total_clients' => $totalClients,
            'clients_with_consent' => $clientsWithConsent,
            'clients_without_consent' => $clientsWithoutConsent,
            'consent_rate_percent' => $consentRatePercent,
            'clients_missing_consent' => $clientsMissingConsent,
        ];
    }
}
