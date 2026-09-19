<?php

namespace App\Console\Commands;

use App\Enums\TrackedLinkGroup;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * uat:reset — wipe the data a test run produced, so a real one can start.
 *
 * Requested 2026-08-03: "ช่วยเคลียร์ DATA นอกจาก user ผมจะทดสอบ UAT ด้วยตัวเอง".
 * Rewritten 2026-09-19, when the owner asked whether production could be
 * cleared after testing and the honest answer was "not with this command".
 *
 * ═══ WHAT SURVIVES, AND WHY ═══
 *
 * "Everything except users" cannot be taken literally — `users.company_id` is
 * a non-nullable FK, so companies must survive too, and an Agent with no
 * products, no cert tiers and no commission rules cannot perform a single
 * step. So the line is drawn at DATA THE TEST RUN ITSELF PRODUCED.
 *
 *   KEPT  — identity (companies, users, abilities, sessions), the catalogue
 *           (products and everything hanging off them), Academy CONTENT
 *           (modules, lessons, quizzes, exams), suppliers, and every BR-7
 *           config table (commission rules and all five plan mechanics, XP
 *           and badge rules, cert tiers, pipeline templates, themes).
 *   WIPED — clients, referrals, pipeline history, orders and vouchers, the
 *           commission ledger and its per-plan working tables, BOTH payout
 *           flows (agent and supplier), XP/badge/point awards, Academy
 *           PROGRESS, share/affiliate/invite links and their click logs,
 *           notifications, agent targets, audit logs, and queued jobs.
 *
 * Owner's ruling, 2026-09-19: "ผมให้เก็บสินค้าไว้ กับ Academy Company ไว้" —
 * which is the default above. `--content` is the dev-only escape hatch that
 * also removes the catalogue and Academy content.
 *
 * ═══ WHY THE LISTS ARE EXHAUSTIVE, AND WHAT HAPPENS IF THEY ARE NOT ═══
 *
 * The first version of this command listed the tables to DELETE and said
 * nothing about the rest. Six weeks later fifteen new tables existed that it
 * had never heard of, five of them carrying money:
 * commission_withdrawal_requests, its items, and the three supplier
 * settlement/payout tables. Running it in that state would have emptied
 * `commission_ledger` and left the withdrawal queue full of requests pointing
 * at rows that no longer existed — an admin looking at a real name and a real
 * amount that settle nothing. WORSE THAN NOT CLEARING AT ALL, and silent.
 *
 * So the classification is now total: every table in the live database must
 * appear in exactly one list, and a table in none of them makes this command
 * REFUSE TO RUN and name it. Adding a migration is therefore a decision — the
 * same shape as CommissionLedger::MUTABLE_AFTER_CREATION, which is an
 * allowlist for the same reason: a blocklist protects a new column only if
 * somebody remembers, and nobody remembers.
 *
 * The check reads the LIVE database rather than the migration files, so a
 * table created by hand is caught too, and the SQLite-only `*_rebuild_tmp`
 * scratch tables — which are renamed away during migration and never exist
 * afterwards — need no entry.
 *
 * ═══ PRODUCTION ═══
 *
 * This used to refuse outright, on the reasoning that "there is no legitimate
 * production use". That premise expired: going live after a period of testing
 * IN production is exactly such a use, and it is the owner's situation.
 *
 * It is still not a normal command there. Production requires, together:
 *   · --production-cutover, a flag nobody types by accident;
 *   · MAINTENANCE MODE already on (`php artisan down`). Deleting the ledger
 *     while a referral is advancing through Complete Payment would tear a
 *     sale in half, and BR-4 means the rows written on the way down cannot be
 *     corrected afterwards;
 *   · typing the DATABASE NAME back, after the row counts are printed. It
 *     names the exact thing about to be emptied and cannot be muscle memory.
 *
 * What this command will NOT do, and what nobody should let it pretend to:
 * uploaded FILES stay on disk (client documents, payment slips, product
 * media), emails already sent stay sent, records on the payment provider's
 * side stay there, and every link or QR handed out during testing stops
 * working. Take a full backup and RESTORE IT SOMEWHERE FIRST — a backup
 * nobody has restored is a hope, not a backup.
 */
class UatResetCommand extends Command
{
    protected $signature = 'uat:reset
        {--force : Skip the confirmation prompt (never accepted in production)}
        {--keep-certs : Keep Academy progress (certifications, exam attempts, lesson progress) so agents can sell immediately without re-taking Basic}
        {--content : ALSO wipe the catalogue and Academy content — products and their media/specs/materials, product-scoped commission rules, modules, lessons, quizzes and exams}
        {--production-cutover : Required in production. See the class docblock before using it.}
        {--dry-run : Show the row counts that WOULD be deleted, then stop}';

    protected $description = 'Wipe the data a test run produced. Keeps users, companies, the catalogue, Academy content and all BR-7 config.';

    /**
     * Tables that survive, always.
     *
     * Listed rather than implied: this half is what makes the classification
     * total, and a table nobody thought about has to fail loudly rather than
     * default into either behaviour. See the class docblock.
     *
     * @var list<string>
     */
    private const KEEP = [
        // Laravel's own.
        'migrations',

        // Identity. Sessions and tokens stay so the people already signed up
        // keep their accounts — they are not test data, they are the users
        // this system exists for.
        'users',
        'user_abilities',
        'sessions',
        'personal_access_tokens',
        'password_reset_tokens',
        'social_accounts',
        'companies',
        'company_invite_codes',

        // Platform catalogue (the products a company adopts from).
        'catalog_brands',
        'catalog_categories',
        'product_catalog_items',
        'product_catalog_media',
        'product_catalog_specs',

        // Company catalogue taxonomy. Kept even under --content so a product
        // can be created immediately afterwards instead of rebuilding the
        // tree first.
        'brands',
        'product_categories',
        'company_product_settings',

        // Trading partners — master data an admin entered, not a test artefact.
        'suppliers',

        // BR-7 config: commission.
        'commission_rules',
        'commission_override_rules',
        'commission_split_settings',
        'commission_binary_settings',
        'commission_matrix_settings',
        'commission_matrix_level_rates',
        'commission_generation_settings',
        'commission_generation_rules',
        'agent_ranks',
        'agent_rank_settings',
        'affiliate_attribution_settings',
        'platform_commission_settings',

        // BR-7 config: everything else.
        'cert_tiers',
        'academy_completion_settings',
        'gamification_rules',
        'badges',
        'level_thresholds',
        'reward_items',
        'agent_promotions',
        'announcements',
        'announcement_settings',
        'client_categories',
        'pipeline_templates',
        'pipeline_template_stages',
        'team_visibility_settings',
        'company_theme_settings',
        'theme_presets',
        'company_payment_gateway_settings',
        'platform_mail_settings',
        'video_processing_settings',
    ];

    /**
     * Child-before-parent order. MySQL FK checks are disabled during the run
     * anyway, but keeping the order correct means this also works on a driver
     * that does not allow disabling them.
     *
     * @var list<string>
     */
    private const WIPE = [
        // Gamification awards (BR-5) — the rules that generate them are
        // config and stay.
        'user_badges',
        'xp_ledger',
        'reward_point_ledger',
        'reward_redemptions',
        'agent_promotion_credits',
        'agent_promotion_agent',

        /*
         * MONEY. The agent payout flow, then the supplier one, then the
         * ledger they both draw on — in that order, because an allocation row
         * pointing at a deleted ledger row is the exact failure this rewrite
         * exists to prevent. All five of these were missing before 2026-09-19.
         */
        'commission_withdrawal_items',
        'commission_withdrawal_requests',
        'supplier_withdrawal_items',
        'supplier_withdrawal_requests',
        'supplier_settlement_ledger',
        'binary_leg_volumes',
        'binary_matching_cycles',
        'matrix_placements',
        'commission_ledger',

        // Sales pipeline (§4.3), orders and what an order produced.
        'voucher_redemptions',
        'order_vouchers',
        'payment_webhook_events',
        'orders',
        'pipeline_stage_logs',
        'referrals',

        // CRM. client_categories is NOT here — it is admin-managed config.
        'client_activities',
        'client_documents',
        'clients',

        /*
         * Sharing and attribution. Every link handed out during testing stops
         * working here, which is the point: a QR on a test poster must not
         * keep recruiting people into the live system.
         *
         * `tracked_links` is deliberately absent — it is partially wiped, see
         * wipeTrackedLinks().
         */
        'tracked_link_visits',
        'affiliate_link_clicks',
        'affiliate_links',
        'product_share_links',
        'sales_material_share_links',
        'agent_invite_links',

        // Ops.
        'notifications',
        'agent_targets',
        'audit_logs',

        /*
         * Runtime state, and this one is not hygiene. A queued job holding a
         * referral id fires AFTER the reset and throws on a row that is gone;
         * a cached settings payload describes a database that no longer looks
         * like that.
         */
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache',
        'cache_locks',
        'chunked_uploads',
    ];

    /**
     * Academy PROGRESS — separated so --keep-certs can spare it. Academy
     * CONTENT is never touched except under --content.
     *
     * `module_lesson_progress` and `module_lesson_quiz_attempts` joined this
     * list on 2026-09-19. Without them the old command wiped completions and
     * exam attempts but left per-lesson progress behind, so an agent's course
     * showed lessons ticked inside a module that reported 0%.
     *
     * @var list<string>
     */
    private const ACADEMY_PROGRESS = [
        'module_lesson_quiz_attempts',
        'module_lesson_progress',
        'exam_attempts',
        'module_completions',
        'user_certifications',
    ];

    /**
     * --content only. Kept by DEFAULT — the owner's ruling of 2026-09-19 is
     * that the catalogue and Academy content survive a production cutover.
     *
     * Children strictly before parents: `commission_rules.product_id`,
     * `modules.product_id` and `products.brand_id` are all restrictOnDelete,
     * and with FK checks disabled a wrong order does not error — it silently
     * leaves dangling rows the Admin UI then renders as broken records.
     *
     * @var list<string>
     */
    private const CONTENT = [
        'module_lesson_quiz_options',
        'module_lesson_quiz_questions',
        'module_lessons',
        'quizzes',
        'modules',
        'exam_question_options',
        'exam_questions',
        'exams',

        'product_recommendation_pins',
        'storefront_banners',
        'product_price_promotions',
        'product_spec_attachments',
        'product_specs',
        'product_media',
        'product_sales_materials',
        'products',
    ];

    /**
     * Tracked-link groups whose target row this command deletes.
     *
     * `company_signup` and `company_login` are NOT here: the first resolves to
     * a company_invite_codes row and the second to `companies.slug`, both of
     * which survive. Wiping them would break the live signup and login links
     * of a tenant that is about to go into service — the opposite of what a
     * cutover is for.
     *
     * @return list<string>
     */
    private function deadLinkGroups(): array
    {
        return [
            TrackedLinkGroup::TeamSignup->value,
            TrackedLinkGroup::ProductShare->value,
            TrackedLinkGroup::Payment->value,
            TrackedLinkGroup::Affiliate->value,
            TrackedLinkGroup::SalesMaterial->value,
        ];
    }

    public function handle(): int
    {
        $unclassified = $this->unclassifiedTables();

        if ($unclassified !== []) {
            $this->error('uat:reset does not know what to do with '.count($unclassified).' table(s):');
            foreach ($unclassified as $table) {
                $this->line("  · {$table}");
            }
            $this->newLine();
            $this->line('Every table must be listed in KEEP, WIPE, ACADEMY_PROGRESS or CONTENT.');
            $this->line('Guessing is what left five money tables behind the last time this ran.');

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->productionGateOpen()) {
            return self::FAILURE;
        }

        $tables = self::WIPE;

        if (! $this->option('keep-certs')) {
            $tables = [...self::ACADEMY_PROGRESS, ...$tables];
        }

        if ($this->option('content')) {
            $tables = [...$tables, ...self::CONTENT];
        }

        // Only touch tables that actually exist — a fresh clone may be missing
        // the newest ones, and a hard failure mid-way would leave a half-wiped
        // database.
        $tables = array_values(array_filter($tables, fn (string $t) => Schema::hasTable($t)));

        [$counts, $total] = $this->rowCounts($tables);

        $this->newLine();
        $this->table(['table', 'rows to delete'], $counts);
        $this->line("  TOTAL: {$total} rows");
        $this->newLine();
        $this->printKeptSummary();

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing was deleted.');

            return self::SUCCESS;
        }

        if (! $this->confirmDeletion($total)) {
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

            $this->wipeTrackedLinks();
        } finally {
            // Re-enabled even if a delete throws, or the connection is left in
            // a state where later work silently skips FK checks.
            Schema::enableForeignKeyConstraints();
        }

        $this->newLine();
        $this->info("Done — {$total} rows deleted.");
        $this->warn('Uploaded files are still on disk, and emails already sent are still sent.');
        $this->warn('Every share / invite link handed out during testing has stopped working.');

        return self::SUCCESS;
    }

    /**
     * Tables in the live database that no list mentions.
     *
     * Read from the DATABASE, not from the migration files: a table created by
     * hand counts, and the SQLite-only `*_rebuild_tmp` scratch tables — renamed
     * away during migration — never appear and need no entry.
     *
     * @return list<string>
     */
    private function unclassifiedTables(): array
    {
        $classified = array_flip([
            ...self::KEEP,
            ...self::WIPE,
            ...self::ACADEMY_PROGRESS,
            ...self::CONTENT,
            // Partially wiped, so it belongs to no single list.
            'tracked_links',
        ]);

        // getTables() hands back plain arrays on this Laravel version, keyed
        // 'name'. Read through a helper rather than assuming the shape, so an
        // upgrade that changes it fails here instead of quietly classifying
        // every table as unknown — or, worse, none of them.
        $live = array_map(
            fn (array $t): string => (string) ($t['name'] ?? ''),
            Schema::getTables(),
        );

        $live = array_values(array_filter($live, fn (string $t) => $t !== ''));

        sort($live);

        return array_values(array_filter($live, fn (string $t) => ! isset($classified[$t])));
    }

    /**
     * The three deliberate acts production asks for, in order.
     *
     * The DB name is typed AFTER the counts are printed, on purpose: the point
     * is that somebody has read them. --force is rejected here rather than
     * honoured, because a flag that skips the prompt is exactly what a script
     * inherits by accident.
     */
    private function productionGateOpen(): bool
    {
        if (! $this->option('production-cutover')) {
            $this->error('This is production. Re-run with --production-cutover once you have read the command docblock.');
            $this->line('It deletes commission ledger rows (BR-4) and both payout queues. There is no undo.');

            return false;
        }

        if (! app()->isDownForMaintenance()) {
            $this->error('Maintenance mode is not on. Run `php artisan down` first.');
            $this->line('A sale advancing into Complete Payment while the ledger is being deleted is torn in half,');
            $this->line('and BR-4 means the rows written on the way down cannot be corrected afterwards.');

            return false;
        }

        $this->warn('PRODUCTION CUTOVER');
        $this->line('  Take a full backup and restore it somewhere before continuing.');
        $this->line('  A backup nobody has restored is a hope, not a backup.');

        return true;
    }

    /** @param list<string> $tables @return array{0: list<array{0: string, 1: string}>, 1: int} */
    private function rowCounts(array $tables): array
    {
        $counts = [];
        $total = 0;

        foreach ($tables as $table) {
            $count = DB::table($table)->count();
            $counts[] = [$table, number_format($count)];
            $total += $count;
        }

        if ($this->option('content')) {
            $scoped = $this->productScopedCommissionRules()->count();

            if ($scoped > 0) {
                $counts[] = ['commission_rules (product/category-scoped only)', number_format($scoped)];
                $total += $scoped;
            }
        }

        if (Schema::hasTable('tracked_links')) {
            $links = $this->deadTrackedLinks()->count();

            if ($links > 0) {
                $counts[] = ['tracked_links (share / invite / payment codes only)', number_format($links)];
                $total += $links;
            }
        }

        return [$counts, $total];
    }

    private function printKeptSummary(): void
    {
        if ($this->option('content')) {
            $this->line('  KEPT: users, companies, brands, product categories, suppliers, announcements, rewards,');
            $this->line('        cert tiers, company-wide commission rates, XP/badge rules, theme settings.');
            $this->line('  WIPED (--content): products + media/specs/materials/banners, product-scoped rates,');
            $this->line('        Academy modules, lessons, quizzes and exams.');
        } else {
            $this->line('  KEPT: users, companies, the whole catalogue, Academy content, suppliers, and all BR-7 config.');
        }

        if ($this->option('keep-certs')) {
            $this->line('  KEPT (--keep-certs): certifications, exam attempts, lesson progress — agents can sell straight away.');
        } else {
            $this->line('  WIPED: Academy progress too — agents must pass Basic again before BR-1 lets them sell.');
        }

        $this->line('  KEPT: company signup and login links. Share / team-invite / payment links are wiped.');
        $this->newLine();
    }

    private function confirmDeletion(int $total): bool
    {
        if (app()->environment('production')) {
            $database = (string) DB::connection()->getDatabaseName();

            /*
             * The count is stated on its own line rather than inside the
             * prompt. It is already printed twice above, and a question whose
             * wording moves with the data is one no runbook can quote and no
             * test can pin — while the thing that actually has to be read and
             * retyped is the database name.
             */
            $this->warn("About to delete {$total} rows from {$database}.");
            $typed = (string) $this->ask('Type the database name to confirm');

            if ($typed !== $database) {
                $this->error('That is not the database name. Nothing was deleted.');

                return false;
            }

            return true;
        }

        return $this->option('force')
            || $this->confirm('Delete the rows listed above? This cannot be undone.');
    }

    /**
     * Commission rules that cannot outlive their product.
     *
     * Company-wide defaults (both scope columns null) are BR-7 config the
     * human may have tuned deliberately — the Setup screen shows them as
     * "ค่าเริ่มต้นทั้งบริษัท" — so they are spared. Product- and
     * category-scoped rows are not: their FKs are restrictOnDelete, and with
     * foreign-key checks disabled they would survive as rows pointing at a
     * product id that no longer exists.
     */
    private function productScopedCommissionRules(): Builder
    {
        return DB::table('commission_rules')
            ->whereNotNull('product_id')
            ->orWhereNotNull('product_category_id');
    }

    private function deadTrackedLinks(): Builder
    {
        return DB::table('tracked_links')->whereIn('group', $this->deadLinkGroups());
    }

    /**
     * Short codes whose target has just been deleted, and the click counters
     * on the ones that survive.
     *
     * Both halves matter. Leaving the dead codes behind leaves a public URL
     * that resolves to nothing; leaving the counters on the surviving codes
     * reports "47 clicks" over a visits table this command just emptied, which
     * is a number nobody can reconcile and nobody will think to doubt.
     */
    private function wipeTrackedLinks(): void
    {
        if (! Schema::hasTable('tracked_links')) {
            return;
        }

        $this->deadTrackedLinks()->delete();

        DB::table('tracked_links')->update([
            'click_count' => 0,
            'unique_click_count' => 0,
            'conversion_count' => 0,
            'first_clicked_at' => null,
            'last_clicked_at' => null,
        ]);
    }
}
