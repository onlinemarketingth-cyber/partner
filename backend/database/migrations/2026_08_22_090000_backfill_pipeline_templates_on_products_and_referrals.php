<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// TASK-134a — ADR-026 §3.8. The data half of the configurable-pipeline
// sprint: TASK-132 added the columns, this migration fills them for rows
// that already existed.
//
// ============================================================
// READ THIS BEFORE CHANGING ANYTHING HERE: the two defaults are
// DIFFERENT ON PURPOSE. This is the single most surprising line in
// the sprint (ADR-026 §3.8 says so in as many words).
// ============================================================
//
//   every existing product   -> direct_sale_default
//       Human decision, KreangYot 2026-08-08: "สินค้าเดิมไม่ต้องพบแพทย์"
//       — the existing catalogue (including the 8,900 / 9,900 packages)
//       does not require a doctor meeting. This is a business decision
//       recorded in ADR-026 §3.8, not an inference; without it every
//       existing product would keep resolving to medical_package_default
//       and TASK-136's public checkout would 422 on all of them.
//
//   every existing referral  -> medical_package_default
//       The journey it ACTUALLY started under (ADR-026 §3.4). A referral
//       parked at `waiting_appointment` must keep a template that CONTAINS
//       waiting_appointment, or it has no legal next stage and no legal
//       previous one — it is stranded forever. direct_sale_default does
//       not contain that stage, so applying the product's new template to
//       in-flight referrals would break exactly the customers who are
//       mid-journey today.
//
// The split is the only combination that honours the human's decision
// without stranding live referrals. Products look FORWARD (what the next
// sale should do); referrals look BACKWARD (what this sale already
// agreed to do). They are not the same question, so they do not get the
// same answer.
//
// BR-6 (tenant isolation, highest priority): templates are per-company
// rows. Every lookup below is keyed on (company_id, key) and no company
// is EVER handed another company's template id — a company whose
// templates are missing is skipped with a logged warning instead (see
// up()). Hardcoding an id would be both a BR-6 hole and a BR-7 smell.
//
// Convention: raw DB::table() queries throughout, never Eloquent — same
// reason as 2026_07_22_090300's docblock (a migration must keep working
// against the schema as it existed when it ran, independent of how the
// app's models change later). It also sidesteps TenantScope, which would
// silently filter these queries to nothing/everything depending on
// whether an artisan command happens to have an authenticated user.
//
// OPS NOTE (flagged to ag-lead): on an EXISTING database this migration
// can only find templates the PipelineTemplateSeeder has already
// created. `php artisan migrate` normally runs before `db:seed`, so for
// this deploy the order must be:
//     php artisan migrate            (runs TASK-132's schema migrations)
//     php artisan db:seed --class=PipelineTemplateSeeder
//     php artisan migrate            (this migration, now able to resolve)
// Deliberately NOT solved by having this migration create the templates
// itself: that would be a second write path for the §3.5 stage
// invariants, which CLAUDE.md §6 says must live in one place
// (PipelineTemplateResolver::assertValidStageSequence).
return new class extends Migration
{
    /**
     * Must match App\Models\PipelineTemplate::KEY_* exactly. Duplicated
     * as literals rather than referenced, per the raw-SQL migration
     * convention above: this migration must keep meaning what it meant
     * on the day it ran even if the model's constants are later renamed.
     */
    private const KEY_MEDICAL_PACKAGE_DEFAULT = 'medical_package_default';

    private const KEY_DIRECT_SALE_DEFAULT = 'direct_sale_default';

    /**
     * Rows updated per statement. The referrals table is the one that
     * grows without bound, so neither table is ever loaded whole — only
     * a page of ids at a time.
     */
    private const CHUNK_SIZE = 500;

    public function up(): void
    {
        // One query for every template of interest across every company,
        // then indexed in memory as [company_id][key] => id. Two seeded
        // rows per company, so this stays small however many tenants
        // exist — and it avoids N queries inside the company loop.
        $seededTemplates = DB::table('pipeline_templates')
            ->whereIn('key', [self::KEY_MEDICAL_PACKAGE_DEFAULT, self::KEY_DIRECT_SALE_DEFAULT])
            ->get(['id', 'company_id', 'key']);

        $templatesByCompany = [];

        foreach ($seededTemplates as $template) {
            $templatesByCompany[$template->company_id][$template->key] = $template->id;
        }

        // Safe on an empty database (fresh install: migrate runs before
        // seed, so there are no companies, no products and no referrals
        // yet) — this loop simply does not execute.
        foreach (DB::table('companies')->orderBy('id')->pluck('id') as $companyId) {
            $directSaleId = $templatesByCompany[$companyId][self::KEY_DIRECT_SALE_DEFAULT] ?? null;
            $medicalId = $templatesByCompany[$companyId][self::KEY_MEDICAL_PACKAGE_DEFAULT] ?? null;

            // BR-6: skip, do NOT substitute. Borrowing another company's
            // template id would be a cross-tenant data write — the exact
            // class of bug Section 5 exists to prevent — and it would be
            // invisible afterwards because a template id looks like any
            // other integer. Crashing is no better: it would abort the
            // migration for every OTHER company too. So: warn loudly,
            // leave the columns NULL (which the resolver already handles
            // as "fall through to the next scope"), and move on.
            if (! $directSaleId || ! $medicalId) {
                Log::warning(sprintf(
                    'TASK-134a backfill: company %d has no seeded pipeline templates (looked for "%s" and "%s" scoped to this company). Its products and referrals are left with pipeline_template_id = NULL and are SKIPPED — never given another company\'s template (BR-6). Run `php artisan db:seed --class=PipelineTemplateSeeder`, then re-run this migration (rollback + migrate) to backfill this company.',
                    $companyId,
                    self::KEY_DIRECT_SALE_DEFAULT,
                    self::KEY_MEDICAL_PACKAGE_DEFAULT,
                ));

                continue;
            }

            // Products: forward-looking — "สินค้าเดิมไม่ต้องพบแพทย์".
            // Raw DB::table() means soft-deleted products are backfilled
            // too, which is what we want: a restored product must not
            // come back pointing at nothing.
            $products = $this->backfillInChunks('products', $companyId, $directSaleId);

            // Referrals: backward-looking — the journey already in
            // progress (ADR-026 §3.4). whereNull leaves alone any
            // referral already stamped at creation by ReferralService
            // (TASK-132) — that snapshot is authoritative and must never
            // be overwritten, for the same reason BR-4's ledger is
            // immutable.
            $referrals = $this->backfillInChunks('referrals', $companyId, $medicalId);

            Log::info(sprintf(
                'TASK-134a backfill: company %d — %d product(s) -> %s (#%d), %d referral(s) -> %s (#%d).',
                $companyId,
                $products,
                self::KEY_DIRECT_SALE_DEFAULT,
                $directSaleId,
                $referrals,
                self::KEY_MEDICAL_PACKAGE_DEFAULT,
                $medicalId,
            ));
        }
    }

    public function down(): void
    {
        // Reverses to NULL — the pre-migration state, in which the
        // resolver falls through product -> category -> company ->
        // medical_package_default (ADR-026 §3.3) and a NULL referral
        // snapshot means "legacy, use the enum's default edges"
        // (§3.6). Nothing is stranded by rolling back.
        //
        // Scoped to the two SYSTEM template ids rather than a blanket
        // `update(['pipeline_template_id' => null])`: a blanket null
        // would also wipe an admin-authored template assignment
        // (TASK-134b) that this migration never wrote, which is data
        // loss dressed up as a rollback.
        //
        // Known imprecision, accepted: a product or referral created
        // AFTER this migration that legitimately points at one of the
        // two seeded system templates is indistinguishable from a
        // backfilled row and will also be nulled. There is no marker
        // column to tell them apart and adding one to carry a rollback
        // is not worth the schema. Rolling this migration back on a
        // live database is a deliberate act; this is its documented
        // cost.
        $systemTemplateIds = DB::table('pipeline_templates')
            ->whereIn('key', [self::KEY_MEDICAL_PACKAGE_DEFAULT, self::KEY_DIRECT_SALE_DEFAULT])
            ->pluck('id')
            ->all();

        if ($systemTemplateIds === []) {
            return;
        }

        foreach (['products', 'referrals'] as $table) {
            $this->nullInChunks($table, $systemTemplateIds);
        }
    }

    /**
     * Sets pipeline_template_id on every row of $table belonging to
     * $companyId that does not have one yet, CHUNK_SIZE rows at a time.
     *
     * Deliberately NOT chunkById(): the filter (`pipeline_template_id IS
     * NULL`) is the very column being written, so a cursor that re-runs
     * the filter per page would skip rows as earlier pages stop matching.
     * Paging on `id > $lastId` instead is stable no matter what the
     * update does to the filtered column.
     *
     * @return int rows updated
     */
    private function backfillInChunks(string $table, int $companyId, int $templateId): int
    {
        $lastId = 0;
        $updated = 0;

        while (true) {
            $ids = DB::table($table)
                ->where('company_id', $companyId)
                ->whereNull('pipeline_template_id')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return $updated;
            }

            $lastId = (int) end($ids);

            // updated_at is deliberately NOT touched: this is a schema
            // backfill, not a business edit by a person. Bumping it on
            // every product and referral in the database would corrupt
            // "recently updated" ordering and any report that keys off
            // it (§6 Audit Log covers real edits; this is not one).
            $updated += DB::table($table)
                ->whereIn('id', $ids)
                ->update(['pipeline_template_id' => $templateId]);
        }
    }

    /**
     * The down() counterpart — same id-paging reasoning as
     * backfillInChunks(), inverted filter.
     *
     * @param  list<int>  $templateIds
     */
    private function nullInChunks(string $table, array $templateIds): void
    {
        $lastId = 0;

        while (true) {
            $ids = DB::table($table)
                ->whereIn('pipeline_template_id', $templateIds)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return;
            }

            $lastId = (int) end($ids);

            DB::table($table)
                ->whereIn('id', $ids)
                ->update(['pipeline_template_id' => null]);
        }
    }
};
