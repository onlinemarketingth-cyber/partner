<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\CsvCell;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

// TASK-041 (4.1) — Policy & Report IA item 4, Audit Log Viewer. Section 6
// ("record every action that affects money, commission, status,
// certification, or permissions"). AuditLog is NOT TenantScope'd (see
// its own docblock) so — unlike every other index() in this codebase —
// the company_id narrowing for Company Admin is done explicitly here,
// by hand, rather than relying on a global scope (Section 5 rule 2 is
// about business tables; this is deliberately the one exception, called
// out in AuditLog's own docblock as "a Policy/Service concern").
class AuditLogController extends Controller
{
    /**
     * TASK-242 — the widest window one request may ask for, in days.
     *
     * Not a performance limit (the composite indexes added in TASK-240 make
     * the query cheap either way). It is about what leaves the building: an
     * unbounded export is "every recorded action since the system was
     * installed, including personal data, as a file", produced by one click
     * and forwardable to anyone. A year is long enough for the real reasons
     * people export — an audit, a dispute, a quarter's review — and short
     * enough that the whole history is never one accident away.
     *
     * 366, not 365: "the last year" typed as 2027-01-01..2028-01-01 by a
     * human must not be refused for containing a leap day.
     */
    private const MAX_EXPORT_DAYS = 366;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        $logs = $this->filtered($request, $request->user())
            ->with('actor')
            ->orderByDesc('created_at')
            ->paginate();

        return AuditLogResource::collection($logs);
    }

    /**
     * TASK-242 — the same question as index(), answered as a file.
     *
     * ── WHY IT SHARES filtered() RATHER THAN REPEATING THE FILTERS ──
     *
     * The export exists to be taken away and shown to somebody: an auditor, a
     * lawyer, an insurer. A file that quietly answers a slightly different
     * question than the screen it was exported from is worse than no export
     * at all, because the discrepancy is discovered by the person you handed
     * it to. One builder, both callers, and BR-6's company narrowing cannot
     * be forgotten on this path alone.
     *
     * ── WHY THE EXPORT IS ITSELF AUDITED ──
     *
     * Section 6 records actions that affect money, permissions and personal
     * data. Taking a copy of the whole trail out of the system is such an
     * action — arguably the most sensitive read this application offers —
     * and until now the one thing the audit log could not tell you was who
     * had read the audit log. The row is written BEFORE the stream starts,
     * deliberately: a download the client aborts halfway still happened, and
     * a trail that only records completed reads is a trail with a documented
     * way to read it unseen.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $user = $request->user();

        $request->validate([
            'actor_user_id' => ['sometimes', 'integer'],
            'action' => ['sometimes', 'string', 'max:255'],
            'company_id' => ['sometimes', 'integer'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
        ]);

        [$dateFrom, $dateTo] = $this->exportWindow($request);

        /*
         * The dates come from exportWindow(), not from filtered(): this path
         * always has a CLOSED window (an absent bound is filled in), and
         * applying both would put two conditions on the same column that
         * agree today and would disagree the day either side's parsing
         * changes.
         */
        $query = $this->filtered($request, $user, applyDateFilters: false)
            ->whereDate('created_at', '>=', $dateFrom->toDateString())
            ->whereDate('created_at', '<=', $dateTo->toDateString());

        /*
         * ── THE FILE IS A SNAPSHOT TAKEN BEFORE THE EXPORT IS RECORDED ──
         *
         * Two things go wrong without this line, both of them small and both
         * of them the kind an auditor notices:
         *
         *  1. recordExport() writes a row that MATCHES this very query (same
         *     company, same actor, inside the window), so the file would
         *     contain the record of its own creation — and the row_count
         *     recorded on that row would be one less than the rows in the
         *     file it describes.
         *  2. the stream is produced after the response is returned, so any
         *     row written in between would appear in a file dated earlier.
         *
         * A ceiling on `id` fixes both: an append-only table (BR-4) means
         * "id <= N" is exactly "as it stood at that moment". A NULL ceiling
         * means the filters matched nothing at all, which must stay nothing
         * rather than becoming "everything up to the export row".
         */
        $snapshot = (clone $query)->toBase()->selectRaw('COUNT(*) AS row_count, MAX(id) AS max_id')->first();
        $rowCount = (int) ($snapshot->row_count ?? 0);
        $maxId = $snapshot->max_id ?? null;

        $query->when(
            $maxId === null,
            fn (Builder $q) => $q->whereRaw('1 = 0'),
            fn (Builder $q) => $q->where('id', '<=', $maxId),
        );

        $this->recordExport($request, $user, $dateFrom, $dateTo, $rowCount);

        $filename = 'audit-log-'.$dateFrom->toDateString().'-to-'.$dateTo->toDateString().'.csv';

        return response()->streamDownload(function () use ($query) {
            // UTF-8 BOM — Thai column headers and Thai names in the data
            // render as mojibake in Excel without it (Excel does not assume
            // UTF-8 for CSV), which would make the file unreadable to the
            // person it was produced for.
            echo "\xEF\xBB\xBF";

            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'เวลา',
                'ผู้ทำรายการ',
                'รหัสผู้ทำรายการ',
                'การกระทำ',
                'ประเภทข้อมูล',
                'รหัสข้อมูล',
                'IP',
                'ค่าเดิม',
                'ค่าใหม่',
            ]);

            /*
             * lazyByIdDesc, not get() or cursor().
             *
             * get() loads the whole result into memory before a single byte
             * reaches the client — on shared hosting that is the export that
             * 500s on the year somebody actually needs. cursor() streams but
             * holds one long-lived result set open for the length of the
             * download, which is the connection most likely to be slow.
             *
             * Keyset pagination by id descending IS newest-first here: this
             * table is append-only (BR-4), so ids and created_at agree. It
             * also cannot skip or repeat a row when new rows arrive mid-
             * export, which offset chunking silently does.
             */
            foreach ($query->with('actor')->lazyByIdDesc(500) as $row) {
                fputcsv($out, [
                    // The reader is in Bangkok and so is every timestamp
                    // rendered on the screen this file mirrors.
                    $row->created_at?->timezone('Asia/Bangkok')->format('Y-m-d H:i:s'),
                    CsvCell::safe($row->actor?->name ?? ''),
                    $row->actor_user_id ?? '',
                    CsvCell::safe($row->action),
                    CsvCell::safe($row->auditable_type ?? ''),
                    $row->auditable_id ?? '',
                    CsvCell::safe($row->ip_address ?? ''),
                    CsvCell::safe(self::encodeValues($row->old_values)),
                    CsvCell::safe(self::encodeValues($row->new_values)),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * The filters, in one place, for both callers.
     *
     * @return Builder<AuditLog>
     */
    private function filtered(Request $request, User $user, bool $applyDateFilters = true): Builder
    {
        $query = AuditLog::query();

        if ($user->isSuperAdmin()) {
            // Super Admin sees across every company by default; ?company_id=
            // narrows to one, same optional-filter shape as ConfigHealthReportController.
            if ($request->filled('company_id')) {
                $query->where('company_id', $request->integer('company_id'));
            }
        } else {
            // Company Admin — hard-scoped to their own company (BR-6). Never
            // trust a client-supplied company_id for this role.
            $query->where('company_id', $user->company_id);
        }

        /*
         * TASK-240 — "WHICH USER DID WHAT", the question this table could
         * always answer and nothing ever asked.
         *
         * `actor_user_id` has been written on every row since the table was
         * created; no endpoint and no screen ever read it back, so the trail
         * could be browsed by action and by date but never by person.
         *
         * BR-6: a Company Admin may only ask about people in their own
         * company. The narrowing is done by intersecting with the company
         * scope already applied above rather than by rejecting the id —
         * an actor from another company simply matches nothing. A 403 here
         * would answer a question nobody should be able to ask: whether
         * that user id exists at all.
         */
        if ($request->filled('actor_user_id')) {
            $query->where('actor_user_id', $request->integer('actor_user_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', 'like', '%'.$request->input('action').'%');
        }

        if ($applyDateFilters) {
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->input('date_from'));
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->input('date_to'));
            }
        }

        return $query;
    }

    /**
     * The window this export covers — always a closed range, never open.
     *
     * A missing bound is filled in rather than left unbounded, and the filled
     * value is what the file is NAMED after and what the audit row records,
     * so the range is never something the caller has to remember. Asking for
     * more than a year is refused with the number of days asked for, because
     * "ช่วงเวลากว้างเกินไป" alone leaves somebody guessing by how much.
     *
     * @return array{Carbon, Carbon}
     */
    private function exportWindow(Request $request): array
    {
        $dateTo = $request->filled('date_to')
            ? Carbon::parse($request->input('date_to'))->startOfDay()
            : Carbon::now()->startOfDay();

        $dateFrom = $request->filled('date_from')
            ? Carbon::parse($request->input('date_from'))->startOfDay()
            : $dateTo->copy()->subDays(self::MAX_EXPORT_DAYS - 1);

        abort_if(
            $dateFrom->greaterThan($dateTo),
            422,
            'วันที่เริ่มต้นต้องไม่อยู่หลังวันที่สิ้นสุด',
        );

        $days = $dateFrom->diffInDays($dateTo) + 1;

        abort_if(
            $days > self::MAX_EXPORT_DAYS,
            422,
            'ช่วงเวลาที่เลือกกว้าง '.$days.' วัน — ส่งออกได้ครั้งละไม่เกิน '.self::MAX_EXPORT_DAYS.' วัน กรุณาแบ่งช่วงเวลา',
        );

        return [$dateFrom, $dateTo];
    }

    /**
     * Write the `audit_log.exported` row.
     *
     * The FILTERS are the payload, not just the fact of the export: "somebody
     * exported the audit log" is nearly useless a year later, while "somebody
     * exported every action by user #7 between these two dates, and got 412
     * rows" is an answer. `auditable_id` is the actor's own id — a single row
     * cannot point at the thousands of rows it copied, and the person who did
     * it is what anybody reading this back is looking for.
     */
    private function recordExport(Request $request, User $user, Carbon $dateFrom, Carbon $dateTo, int $rowCount): void
    {
        AuditLog::create([
            /*
             * The company the export was ABOUT. For a Company Admin that is
             * their own (they cannot ask for another); for a Super Admin it
             * is whichever they narrowed to, or null for "every company" —
             * and null there is meaningful, not missing: it is exactly the
             * export worth noticing.
             */
            'company_id' => $user->isSuperAdmin() ? $request->integer('company_id') ?: null : $user->company_id,
            'actor_user_id' => $user->id,
            'action' => 'audit_log.exported',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => null,
            'new_values' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'actor_user_id' => $request->filled('actor_user_id') ? $request->integer('actor_user_id') : null,
                'action_filter' => $request->filled('action') ? (string) $request->input('action') : null,
                'company_id' => $request->filled('company_id') ? $request->integer('company_id') : null,
                'row_count' => $rowCount,
            ],
            'ip_address' => $request->ip(),
        ]);
    }

    /**
     * old_values / new_values as one cell.
     *
     * JSON_UNESCAPED_UNICODE because the alternative is a column of \uXXXX
     * escapes where a Thai name should be — technically correct, unreadable
     * to the person who asked for the file.
     */
    private static function encodeValues(?array $values): string
    {
        if ($values === null || $values === []) {
            return '';
        }

        return (string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
