<?php

namespace App\Services\Commission;

use App\Enums\PaymentStatus;
use App\Models\CommissionLedger;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * TASK-043 §3 — "ค่าคอมมิชชั่น" submenu under "จัดการตัวแทน": one row per
 * agent (total_paid_satang / total_pending_satang / entry_count). Pure
 * read/aggregation over existing commission_ledger rows — BR-4's "never
 * recompute commission live" is about the AMOUNT (rate x package),
 * which is never touched here; this only SUMs already-written, already
 * immutable rows, same "live aggregation of immutable rows is fine"
 * reasoning as any other historical report in this codebase (see
 * ProductGradingService for the same shape on a different table).
 *
 * Deliberately a standalone lightweight Service rather than a new
 * method on CommissionService: CommissionService owns commission
 * CALCULATION (writing ledger rows via recordForReferral() and its
 * plan-type sub-services, see that class's own docblock) — this class
 * owns REPORTING (grouping existing rows), the same separation
 * ConfigHealthReportService/PlatformReportService keep from the
 * Services whose output they report on.
 *
 * Tenant scoping: CommissionLedger already carries a TenantScope
 * global scope (Section 5 rule 2), so a Company Admin's query is
 * auto-narrowed to their own company_id with no manual `where` needed.
 * $companyId is still threaded through explicitly here (never trusted
 * from the client — only ever populated by the Controller from a
 * confirmed Super Admin's own request) for the same two reasons the
 * ConfigHealthReportService/ProductGradingService precedents do it:
 * (1) belt-and-suspenders defensive scoping for a money-adjacent
 * report, not solely relying on a global scope that resolves off
 * auth()->user() internally, and (2) it's the only way to give Super
 * Admin an explicit ?company_id= narrowing, since TenantScope exempts
 * Super Admin entirely.
 *
 * TASK-044 §2 — $dateFrom/$dateTo/$paymentStatus are additive filters,
 * layered on top of the tenant scoping above, never replacing it. All
 * three are plain optional scalar/enum method parameters (not a
 * request/DTO object) specifically so the not-yet-built CSV export
 * Controller (Phase A item 3, TASK-044) can call buildSummary()
 * directly with its own already-validated values, the same way this
 * Controller does, without constructing anything Http-specific.
 *
 * $dateFrom/$dateTo filter on commission_ledger.created_at (when the
 * ledger row was written — always populated, unlike paid_at which is
 * null for pending rows) — see TASK-044 spec for why created_at was
 * chosen as the date axis over paid_at.
 *
 * $paymentStatus, when given, is applied as a `where` before
 * aggregation so only matching rows are summed/counted at all — see
 * the inline comment at the SELECT below for why this also simplifies
 * away the CASE WHEN split rather than keeping it and filtering after.
 */
class AgentCommissionSummaryService
{
    /**
     * TASK-179 §3.7 (F-10) — NULL, NOT ZERO, for a bucket the filter
     * excluded.
     *
     * When $paymentStatus narrows the rows before aggregation, the OTHER
     * bucket has not been measured at all. It used to be reported as
     * literal 0, so filtering by "จ่ายแล้ว" rendered "รอจ่ายรวม 0 บาท" —
     * visually identical to "we owe our agents nothing", which is a
     * statement about money that nobody computed. `null` is the only
     * honest value: it forces the UI to say "ไม่ได้แสดง" rather than print
     * a number. Callers MUST NOT `?? 0` it back at the display layer.
     *
     * The CSV payout export is unaffected: it forces
     * paymentStatus = Pending and reads only total_pending_satang, which is
     * the measured side of that call.
     *
     * @return Collection<int, array{agent_id: int, agent_name: ?string, total_paid_satang: ?int, total_pending_satang: ?int, entry_count: int, bank_name: ?string, bank_account_number: ?string, bank_account_holder_name: ?string, avatar_url: ?string, cert_tier: ?array{id: int, key: string, name: string}}>
     */
    public function buildSummary(
        User $actor,
        ?int $companyId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?PaymentStatus $paymentStatus = null,
    ): Collection {
        $query = CommissionLedger::query();

        if (! $actor->isSuperAdmin()) {
            // Company Admin — hard-scoped to their own company (BR-6),
            // never trust a client-supplied company_id for this role.
            $query->where('company_id', $actor->company_id);
        } elseif ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        // TASK-044 §2 — additive date-range filter on created_at, on top
        // of the tenant scoping above (never a substitute for it).
        if ($dateFrom !== null) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // TASK-044 §2 — when a status filter is given, narrow the rows
        // BEFORE aggregation rather than aggregating everything and
        // discarding half the CASE WHEN split: every row reaching the
        // SUM below already matches $paymentStatus, so a single
        // SUM(amount_satang) is both correct and simpler than the
        // unfiltered branch's CASE WHEN. See the map() below for how
        // the single sum is placed back into the still-two-field
        // (total_paid_satang/total_pending_satang) response shape.
        if ($paymentStatus !== null) {
            $query->where('payment_status', $paymentStatus->value);
        }

        $query = $paymentStatus !== null
            ? $query->selectRaw('agent_id, SUM(amount_satang) as filtered_total_satang, COUNT(*) as entry_count')
            : $query->selectRaw(
                'agent_id, '.
                'SUM(CASE WHEN payment_status = ? THEN amount_satang ELSE 0 END) as total_paid_satang, '.
                'SUM(CASE WHEN payment_status = ? THEN amount_satang ELSE 0 END) as total_pending_satang, '.
                'COUNT(*) as entry_count',
                [PaymentStatus::Paid->value, PaymentStatus::Pending->value]
            );

        $rows = $query
            ->groupBy('agent_id')
            // TASK-045 — Admin asked to fill in an agent's bank account
            // directly from this commission screen (previously only
            // possible from the "จัดการตัวแทน" agent list). The Admin
            // needs to SEE the current (masked) value to decide whether
            // it needs updating, same as AgentManagementView already
            // shows — so bank_name/bank_account_number/
            // bank_account_holder_name are eager-loaded here too. This
            // is an additive field on the existing response shape, safe
            // for the CSV export caller (which does its own separate
            // unmasked User query and never reads these masked fields).
            // TASK-047 (follow-up) — avatar_path added so the row itself
            // can show a real avatar (or tier-colored initial-circle)
            // without the Admin having to open "ดูรายละเอียด" first —
            // human feedback: "รูป avatar ให้ขึ้นที่รายชื่อเลยไม่ต้องคลิ๊กดู
            // รายละเอียด".
            ->with(['agent:id,name,avatar_path,bank_name,bank_account_number,bank_account_holder_name'])
            ->get();

        // TASK-047 (follow-up) — bulk-load each agent's HIGHEST passed
        // cert tier (BR-2's ranking) in ONE query rather than calling
        // User::highestPassedCertTier() per row in the map() below (which
        // would be an N+1 query per agent on this list). Ties/multiple
        // passed tiers per agent are resolved by ordering
        // cert_tiers.sort_order DESC and keeping only the first row per
        // agent_id (->unique('user_id') keeps the first occurrence).
        $certTiersByAgentId = DB::table('user_certifications')
            ->join('cert_tiers', 'cert_tiers.id', '=', 'user_certifications.cert_tier_id')
            ->whereIn('user_certifications.user_id', $rows->pluck('agent_id'))
            ->orderByDesc('cert_tiers.sort_order')
            ->get(['user_certifications.user_id', 'cert_tiers.id', 'cert_tiers.key', 'cert_tiers.name'])
            ->unique('user_id')
            ->keyBy('user_id');

        return $rows
            ->map(function (CommissionLedger $row) use ($paymentStatus, $certTiersByAgentId) {
                // BR-3 — SUM() over an already-integer satang column is
                // still integer arithmetic; cast defensively since raw
                // SQL aggregates come back as strings from the PDO
                // driver, never a float.
                if ($paymentStatus !== null) {
                    // §3.7 — the excluded bucket is null ("not measured"),
                    // never 0 ("measured, and it is nothing").
                    $amount = (int) $row->filtered_total_satang;
                    $totalPaid = $paymentStatus === PaymentStatus::Paid ? $amount : null;
                    $totalPending = $paymentStatus === PaymentStatus::Pending ? $amount : null;
                } else {
                    $totalPaid = (int) $row->total_paid_satang;
                    $totalPending = (int) $row->total_pending_satang;
                }

                return [
                    'agent_id' => (int) $row->agent_id,
                    'agent_name' => $row->agent?->name,
                    'total_paid_satang' => $totalPaid,
                    'total_pending_satang' => $totalPending,
                    'entry_count' => (int) $row->entry_count,
                    // TASK-047 — human-confirmed reversal of TASK-045's
                    // masking here ("แสดงเลยครับ เพราะต้องใช้งาน" — show it
                    // directly, it's needed for actual use; a hide/show
                    // toggle is explicitly deferred to a future
                    // system-settings task, not built now). Safe to return
                    // the REAL number unmasked: every caller of
                    // buildSummary() is already gated to Company
                    // Admin/Super Admin only (AgentCommissionSummaryController
                    // ::index() aborts 403 for any other role) AND scoped to
                    // the actor's own company_id above — an Agent can never
                    // reach this method at all, so this is not the same
                    // "list/summary response must never leak the full
                    // number" surface UserResource's default masking guards
                    // (that default is unchanged for Agent-reachable
                    // endpoints — see UserResource's own docblock).
                    'bank_name' => $row->agent?->bank_name,
                    'bank_account_number' => $row->agent?->bank_account_number,
                    'bank_account_holder_name' => $row->agent?->bank_account_holder_name,
                    // TASK-047 (follow-up) — real avatar (Storage::url(),
                    // same pattern UserResource already uses) or null so
                    // the frontend falls back to a colored initial-circle
                    // — never fabricate a placeholder image URL.
                    'avatar_url' => $row->agent?->avatar_path
                        ? Storage::disk('public')->url($row->agent->avatar_path)
                        : null,
                    // Null when the agent hasn't passed any cert tier yet
                    // — frontend must render a neutral default, never
                    // invent a tier (CLAUDE.md §8 guardrail #2).
                    'cert_tier' => ($tier = $certTiersByAgentId->get($row->agent_id))
                        ? ['id' => $tier->id, 'key' => $tier->key, 'name' => $tier->name]
                        : null,
                ];
            })
            // ?? 0 here is an ORDERING fallback only — it never reaches the
            // response, where the excluded bucket must stay null (§3.7).
            ->sortByDesc(fn (array $row) => ($row['total_paid_satang'] ?? 0) + ($row['total_pending_satang'] ?? 0))
            ->values();
    }
}
