<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * uat:reset — wipe transactional data so a human can start a clean UAT run.
 *
 * Requested 2026-08-03: "ช่วยเคลียร์ DATA นอกจาก user ผมจะทดสอบ UAT ด้วยตัวเอง".
 *
 * WHAT SURVIVES, AND WHY
 * "Everything except users" cannot be taken literally — `users.company_id`
 * is a non-nullable FK, so companies must survive too, and an Agent with
 * no products, no cert tiers and no commission rules cannot perform a
 * single UAT step. So the line is drawn at DATA THE UAT RUN ITSELF WILL
 * PRODUCE:
 *
 *   KEPT  — identity (companies, users), catalogue (brands, categories,
 *           products + their media/specs/materials, storefront banners),
 *           Academy content (modules, lessons, exams and their questions),
 *           announcements / rewards / promotions, and every BR-7 config
 *           table (commission rules and all 5 plan mechanics, XP and badge
 *           rules, cert tiers, level thresholds, theme settings).
 *   WIPED — clients, referrals, pipeline history, orders, the commission
 *           ledger and its per-plan working tables, XP/badge/point awards,
 *           Academy PROGRESS (completions, exam attempts, certifications),
 *           share/affiliate links and their click logs, notifications,
 *           agent targets, audit logs.
 *
 * Definitions of the two lists live in the constants below rather than in
 * prose, so a table added later is a one-line decision and not an
 * archaeology exercise.
 *
 * SAFETY
 *  - Refuses to run when the app environment is `production`, with no
 *    override. This command deletes money records (BR-4 ledger); there is
 *    no legitimate production use.
 *  - Requires interactive confirmation unless --force is passed.
 *  - Prints a per-table row count first, so the human approves a number
 *    rather than a promise.
 *
 * Uploaded FILES (client documents, payment slips) are deliberately left
 * on disk. They become orphaned rows-less blobs, which is harmless in a
 * dev environment, and deleting user files is not something this command
 * should be doing silently.
 */
class UatResetCommand extends Command
{
    protected $signature = 'uat:reset
        {--force : Skip the confirmation prompt}
        {--keep-certs : Keep Academy progress (completions, exam attempts, certifications) so agents can sell immediately without re-taking Basic}
        {--content : ALSO wipe the leftover QA catalogue and Academy content — products and their media/specs/materials, product-scoped commission rules, modules, lessons and exams}
        {--dry-run : Show the row counts that WOULD be deleted, then stop}';

    protected $description = 'Wipe transactional/UAT data. Keeps users, companies, catalogue, Academy content and all BR-7 config.';

    /**
     * Child-before-parent order. MySQL FK checks are disabled during the
     * run anyway, but keeping the order correct means the command also
     * works on a driver that does not allow disabling them.
     *
     * @var list<string>
     */
    private const TRANSACTIONAL_TABLES = [
        // Gamification awards (BR-5) — the rules that generate them are config and stay.
        'user_badges',
        'xp_ledger',
        'reward_point_ledger',
        'reward_redemptions',
        'agent_promotion_credits',
        'agent_promotion_agent',

        // Commission (BR-4). The ledger is immutable in normal operation;
        // this command is the one sanctioned way to clear a dev run.
        'binary_leg_volumes',
        'binary_matching_cycles',
        'matrix_placements',
        'commission_ledger',

        // Sales pipeline (BR-4.3) + orders.
        'orders',
        'pipeline_stage_logs',
        'referrals',

        // CRM. client_categories is NOT here — it is admin-managed config.
        'client_activities',
        'client_documents',
        'clients',

        // Sharing / attribution.
        'affiliate_link_clicks',
        'affiliate_links',
        'product_share_links',
        'sales_material_share_links',

        // Ops.
        'notifications',
        'agent_targets',
        'audit_logs',
    ];

    /**
     * Academy PROGRESS — separated so --keep-certs can spare it. The
     * Academy CONTENT (modules, lessons, exams, questions) is never
     * touched by this command.
     *
     * @var list<string>
     */
    private const ACADEMY_PROGRESS_TABLES = [
        'exam_attempts',
        'module_completions',
        'user_certifications',
    ];

    /**
     * --content: the leftover QA catalogue and Academy CONTENT (2026-08-03,
     * human sent screenshots of the Setup and Academy admin screens still
     * full of "ddd" / "QA Affiliate Package" / "QA Generation Package" test
     * rows).
     *
     * Runs after the transactional list, and children strictly before
     * parents, because `commission_rules.product_id`, `modules.product_id`
     * and `products.brand_id` are all `restrictOnDelete` — with FK checks
     * disabled a wrong order does not error, it silently leaves dangling
     * rows that the Admin UI then renders as broken records.
     *
     * `commission_rules` is handled separately (see wipeProductScopedCommissionRules)
     * so COMPANY-WIDE default rates survive; only the product- and
     * category-scoped rows, which cannot outlive their product, are removed.
     *
     * NOT included on purpose: brands and product_categories. They are not
     * on either screen the human pointed at, and keeping them means a new
     * product can be created immediately after the reset instead of
     * rebuilding the taxonomy first.
     *
     * @var list<string>
     */
    private const CONTENT_TABLES = [
        // Academy content. exam_attempts / module_completions are already
        // gone by this point (transactional list), so these are safe.
        'module_lesson_quiz_options',
        'module_lesson_quiz_questions',
        'module_lessons',
        // ADR-030 §2.1 (TASK-150) — `quizzes` now owns the questions, and
        // `module_lessons.quiz_id` points AT it, so it has to come after
        // both of those: children strictly before parents, exactly as this
        // list's docblock demands. A quiz left behind after its lesson and
        // questions were wiped would show up in the library as an empty
        // "QA ..." entry the admin cannot explain.
        'quizzes',
        'modules',
        'exam_question_options',
        'exam_questions',
        'exams',

        // Product children.
        'product_recommendation_pins',
        'storefront_banners',
        'product_price_promotions',
        'product_spec_attachments',
        'product_specs',
        'product_media',
        'product_sales_materials',

        // Parent last.
        'products',
    ];

    /**
     * Commission rules that cannot outlive their product.
     *
     * Company-wide defaults (both scope columns null) are BR-7 config the
     * human may have tuned deliberately — the Setup screen shows them as
     * "ค่าเริ่มต้นทั้งบริษัท" — so they are spared. Product- and
     * category-scoped rows are not: their FKs are `restrictOnDelete`, and
     * with foreign-key checks disabled they would survive as rows pointing
     * at a product id that no longer exists.
     */
    private function productScopedCommissionRules(): \Illuminate\Database\Query\Builder
    {
        return DB::table('commission_rules')
            ->whereNotNull('product_id')
            ->orWhereNotNull('product_category_id');
    }

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('uat:reset is blocked in production. This deletes commission ledger rows (BR-4).');

            return self::FAILURE;
        }

        $tables = self::TRANSACTIONAL_TABLES;

        if (! $this->option('keep-certs')) {
            $tables = [...self::ACADEMY_PROGRESS_TABLES, ...$tables];
        }

        if ($this->option('content')) {
            $tables = [...$tables, ...self::CONTENT_TABLES];
        }

        // Only touch tables that actually exist — a fresh clone may be
        // missing the newest ones, and a hard failure mid-way would leave
        // a half-wiped database.
        $tables = array_values(array_filter($tables, fn (string $t) => Schema::hasTable($t)));

        $counts = [];
        $total = 0;

        foreach ($tables as $table) {
            $count = DB::table($table)->count();
            $counts[] = [$table, number_format($count)];
            $total += $count;
        }

        $scopedRuleCount = $this->option('content') ? $this->productScopedCommissionRules()->count() : 0;

        if ($scopedRuleCount > 0) {
            $counts[] = ['commission_rules (product/category-scoped only)', number_format($scopedRuleCount)];
            $total += $scopedRuleCount;
        }

        $this->newLine();
        $this->table(['table', 'rows to delete'], $counts);
        $this->line("  TOTAL: {$total} rows across ".count($tables).' tables');
        $this->newLine();

        if ($this->option('content')) {
            $this->line('  KEPT: users, companies, brands, product categories, announcements, rewards, cert tiers,');
            $this->line('        company-wide commission rates, XP/badge rules, theme settings.');
            $this->line('  WIPED (--content): products + media/specs/materials/banners, product-scoped commission rates,');
            $this->line('        Academy modules, lessons and exams.');
        } else {
            $this->line('  KEPT: users, companies, products/catalogue, Academy content, announcements, rewards, and all BR-7 config.');
        }

        if ($this->option('keep-certs')) {
            $this->line('  KEPT (--keep-certs): certifications, exam attempts, module completions — agents can sell straight away.');
        } else {
            $this->line('  WIPED: Academy progress too — agents must pass Basic again before BR-1 lets them sell.');
        }

        $this->newLine();

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing was deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Delete the rows listed above? This cannot be undone.')) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        Schema::disableForeignKeyConstraints();

        try {
            // Before products, so the restrictOnDelete FK never dangles.
            if ($this->option('content')) {
                $this->productScopedCommissionRules()->delete();
            }

            foreach ($tables as $table) {
                DB::table($table)->delete();
            }
        } finally {
            // Re-enable even if a delete throws, or the connection is left
            // in a state where later work silently skips FK checks.
            Schema::enableForeignKeyConstraints();
        }

        $this->newLine();
        $this->info("Done — {$total} rows deleted. Users, companies, catalogue and config are untouched.");

        return self::SUCCESS;
    }
}
