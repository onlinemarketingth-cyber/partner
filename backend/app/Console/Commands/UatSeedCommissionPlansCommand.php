<?php

namespace App\Console\Commands;

use App\Enums\BinaryCycleFrequency;
use App\Enums\BinaryLeg;
use App\Enums\CommissionBasis;
use App\Enums\CommissionRateType;
use App\Enums\IdDocumentType;
use App\Enums\MatrixSpilloverRule;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PipelineStage;
use App\Enums\UserRole;
use App\Models\AgentRank;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionBinarySetting;
use App\Models\CommissionGenerationRule;
use App\Models\CommissionGenerationSetting;
use App\Models\CommissionLedger;
use App\Models\CommissionMatrixLevelRate;
use App\Models\CommissionMatrixSetting;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\PipelineTemplate;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Commission\MatrixCommissionService;
use App\Services\Order\OrderService;
use App\Services\Pipeline\PipelineTemplateProvisioner;
use App\Services\Referral\ReferralService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Six throwaway tenants, one per compensation plan, each carrying a real
 * closed sale — so QA can check what the system actually pays against what
 * the plan says it should.
 *
 * ── WHY THIS EXISTS ──
 *
 * Owner, 2026-09-21: "คุณออก UAT ตัวอย่าง บริษัทใหม่ ให้ผมทดสอบค่าการตั้งค่า
 * และการแบ่งค่าคอมในแต่ละ Type เพื่อ QA ทดสอบความถูกต้อง".
 *
 * Reading the six plans off the settings screen tells you what was
 * CONFIGURED. It does not tell you what gets PAID, and those are different
 * questions: five of the six plans pay through their own service, on their
 * own trigger, and two of them (Binary, Generation) pay nobody at all unless
 * a second piece of state exists that the settings screen never mentions —
 * a binary leg, a breakaway rank. The gap between "configured" and "paid" is
 * exactly where this platform has hidden its bugs, and the owner had already
 * found one by hand: fourteen paid orders with an empty commission ledger.
 *
 * So each company here is driven all the way through a real sale — referral,
 * order, slip, confirmed payment — using the SAME services the application
 * uses. Nothing is written straight into commission_ledger. If a plan pays
 * nothing, this command will show it paying nothing, which is the finding.
 *
 * ── IT IS SAFE TO RUN ON PRODUCTION, AND THAT IS DELIBERATE ──
 *
 * The owner runs UAT against the live deployment. Every row this command
 * writes hangs off a company whose slug starts with `uat-`, it never reads
 * or edits a company it did not create, and `uat:purge-commission-plans`
 * removes exactly what it made. It asks before writing unless --force.
 *
 * The one thing it does NOT do on production is run the Binary matching
 * cycle: `commissions:run-binary-cycles` sweeps EVERY company that has
 * binary settings, so triggering it from here would reach into tenants that
 * have nothing to do with UAT. The command ends by naming that step and any
 * non-UAT company it would touch, and leaves the decision to a human.
 *
 * ── THE NUMBERS ARE FIXTURES, NOT A RECOMMENDATION (BR-7) ──
 *
 * Every rate and threshold below was chosen so the arithmetic can be checked
 * in your head: a ฿10,000 product, a 10% seller, 5/3/1% leaders. They are
 * seed data for throwaway tenants and say nothing about what any real
 * company should pay. The owner's real values are still outstanding.
 */
class UatSeedCommissionPlansCommand extends Command
{
    protected $signature = 'uat:seed-commission-plans
        {--force : Skip the confirmation prompt}
        {--plan=* : Seed only these plan types (default: all six)}';

    protected $description = 'Create six disposable UAT tenants, one per commission plan type, each with a real closed sale, so QA can verify what each plan actually pays';

    /**
     * Every company this command owns carries this slug prefix, and nothing
     * else on the platform may. It is the whole safety model: the purge
     * command, and every "did I touch something real" question, reduce to
     * this string.
     */
    public const SLUG_PREFIX = 'uat-plan-';

    /* ── Fixtures (BR-7: seed data for throwaway tenants, not business values) ── */

    /** ฿10,000.00 — round, so 10% is visibly ฿1,000.00. */
    public const PRICE_SATANG = 1_000_000;

    /** ฿7,000.00 of point value, for the one company that pays on PV. */
    public const PV_SATANG = 700_000;

    /** Rates are basis points everywhere they are stored: 1000 = 10.00%. */
    public const SELLER_RATE_BP = 1_000;

    /** Unilevel leader ladder, level 1 first. */
    public const LEVEL_RATES_BP = [1 => 500, 2 => 300, 3 => 100];

    public const MATRIX_LEVEL_RATES_BP = [1 => 500, 2 => 300];

    public const GENERATION_RATES_BP = [1 => 500, 2 => 300];

    public const BINARY_MATCHED_RATE_BP = 1_000;

    public const AFFILIATE_RATE_BP = 300;

    /**
     * Withholding tax, basis points, on the one tenant that withholds.
     * 3% is the rate Thai law applies to service fees; it is still a fixture
     * here, and the owner's real figure is outstanding (BR-7).
     */
    public const WHT_RATE_BP = 300;

    /**
     * The stairstep ladder. `rate_value` is the rank's OWN rate; a manager is
     * paid the difference between their rank and their downline's.
     */
    public const RANKS = [
        ['key' => 'starter', 'name' => 'UAT ขั้นเริ่มต้น', 'sort' => 1, 'threshold' => 0, 'rate_bp' => 500, 'breakaway' => false],
        ['key' => 'leader', 'name' => 'UAT ขั้นผู้นำ', 'sort' => 2, 'threshold' => 5_000_000, 'rate_bp' => 1_000, 'breakaway' => false],
        ['key' => 'manager', 'name' => 'UAT ขั้นผู้จัดการ', 'sort' => 3, 'threshold' => 20_000_000, 'rate_bp' => 1_500, 'breakaway' => true],
    ];

    /** @var array<int, array{plan: string, name: string}> */
    private const PLANS = [
        ['plan' => 'unilevel', 'name' => 'UAT · Unilevel'],
        ['plan' => 'binary', 'name' => 'UAT · Binary'],
        ['plan' => 'matrix', 'name' => 'UAT · Matrix'],
        ['plan' => 'stairstep_breakaway', 'name' => 'UAT · Stairstep'],
        ['plan' => 'generation', 'name' => 'UAT · Generation'],
        ['plan' => 'affiliate', 'name' => 'UAT · Affiliate'],
    ];

    private CertTier $basicTier;

    public function handle(
        PipelineTemplateProvisioner $templates,
        ReferralService $referrals,
        OrderService $orders,
        MatrixCommissionService $matrix,
    ): int {
        $wanted = $this->option('plan') ?: array_column(self::PLANS, 'plan');
        $plans = array_values(array_filter(self::PLANS, fn (array $p): bool => in_array($p['plan'], $wanted, true)));

        if ($plans === []) {
            $this->error('No plan type matched --plan. Valid values: '.implode(', ', array_column(self::PLANS, 'plan')));

            return self::FAILURE;
        }

        $this->line('This will CREATE '.count($plans).' disposable companies:');
        foreach ($plans as $p) {
            $this->line("  · {$p['name']}  (slug ".self::SLUG_PREFIX.$this->slugFor($p['plan']).')');
        }
        $this->newLine();
        $this->line('Nothing outside these companies is read or written. Remove them again with:');
        $this->line('  php artisan uat:purge-commission-plans');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Create them now?', false)) {
            $this->warn('Nothing was written.');

            return self::SUCCESS;
        }

        /*
         * cert_tiers is a PLATFORM table, not a tenant one — it is the single
         * exception to "touches only UAT rows", and firstOrCreate is why that
         * is safe: on any real deployment the row already exists (CatalogSeeder
         * seeds it) and this reads it rather than writing anything.
         */
        $this->basicTier = CertTier::firstOrCreate(
            ['key' => 'basic'],
            ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true],
        );

        $rows = [];

        foreach ($plans as $p) {
            $rows[] = DB::transaction(fn (): array => $this->seedPlan($p['plan'], $p['name'], $templates, $referrals, $orders, $matrix));
        }

        $this->renderSummary($rows);
        $this->renderBinaryFootnote($plans);

        return self::SUCCESS;
    }

    private function slugFor(string $plan): string
    {
        // stairstep_breakaway → stairstep-breakaway; keeps the slug readable
        // and, more importantly, stable across runs so a re-run is idempotent.
        return str_replace('_', '-', $plan);
    }

    /**
     * @return array{plan: string, company: Company, expected: string, ledger: array<int, CommissionLedger>}
     */
    private function seedPlan(
        string $plan,
        string $name,
        PipelineTemplateProvisioner $templates,
        ReferralService $referrals,
        OrderService $orders,
        MatrixCommissionService $matrix,
    ): array {
        $slug = self::SLUG_PREFIX.$this->slugFor($plan);

        /*
         * PV for exactly one plan, and Matrix is the one that gets it.
         *
         * The basis is orthogonal to the plan — any plan can run on either —
         * so covering it means either twelve companies or one deliberate
         * pairing. One is enough to prove the basis reaches the engines,
         * because CommissionService hands every engine the same resolved
         * base (see its note above the plan switch); twelve would be QA
         * clicking through the same assertion six more times.
         */
        $basis = $plan === 'matrix' ? CommissionBasis::PointValue : CommissionBasis::Price;

        $company = Company::withTrashed()->firstWhere('slug', $slug);

        if ($company) {
            // Re-running is normal during UAT. Reuse rather than duplicate, so
            // the slug stays the stable handle QA and the purge command use.
            $company->restore();
            $company->update(['name' => $name, 'is_active' => true]);
        } else {
            $company = Company::create([
                'name' => $name,
                'slug' => $slug,
                'is_active' => true,
                'commission_plan_type' => $plan,
                'commission_basis' => $basis->value,
            ]);
        }

        /*
         * Written directly, not through CommissionSettingService::update().
         *
         * That service refuses to change the plan or the basis once the
         * company has sold anything (assertNotSettledByExistingSales), which
         * is correct and is precisely what makes it the wrong door here: a
         * re-run against an already-seeded company would be refused by the
         * guard it is trying to help QA test.
         */
        /*
         * Withholding tax on exactly ONE tenant, and Affiliate draws it.
         *
         * Same reasoning as the PV basis above: the deduction is orthogonal to
         * the plan, so covering it means either twelve companies or one
         * deliberate pairing. Five tenants withhold nothing and one withholds
         * 3%, which is what lets QA see the difference between gross and net
         * side by side instead of taking one screen's word for it.
         */
        $company->forceFill([
            'commission_plan_type' => $plan,
            'commission_basis' => $basis->value,
            'wht_rate' => $plan === 'affiliate' ? self::WHT_RATE_BP : null,
        ])->save();

        $templates->provision($company);

        /*
         * The two-stage journey, so a sale closes in one advance.
         *
         * The medical default would make QA walk a referral through a doctor
         * meeting to reach the only stage that pays (BR-4 fires at Complete
         * Payment and nowhere else). These tenants exist to exercise the
         * payout, not the journey.
         */
        $direct = PipelineTemplate::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('key', PipelineTemplate::KEY_DIRECT_SALE_DEFAULT)
            ->first();

        if ($direct) {
            $company->forceFill(['default_pipeline_template_id' => $direct->id])->save();
        }

        $product = $this->product($company, $plan);
        $this->sellerRateRule($company, $plan);

        $result = match ($plan) {
            'unilevel' => $this->seedUnilevel($company, $product, $referrals, $orders),
            'binary' => $this->seedBinary($company, $product, $referrals, $orders),
            'matrix' => $this->seedMatrix($company, $product, $referrals, $orders, $matrix),
            'stairstep_breakaway' => $this->seedStairstep($company, $product, $referrals, $orders),
            'generation' => $this->seedGeneration($company, $product, $referrals, $orders),
            'affiliate' => $this->seedAffiliate($company, $product, $referrals, $orders),
            default => ['expected' => '—'],
        };

        $ledger = CommissionLedger::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->with('agent')
            ->orderBy('id')
            ->get()
            ->all();

        return [
            'plan' => $plan,
            'company' => $company,
            'expected' => $result['expected'],
            'ledger' => $ledger,
        ];
    }

    /* ── shared building blocks ─────────────────────────────────────────── */

    private function product(Company $company, string $plan): Product
    {
        $product = Product::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('name', 'UAT Package')
            ->first();

        $attributes = [
            'company_id' => $company->id,
            'name' => 'UAT Package',
            'price_satang' => self::PRICE_SATANG,
            // Always set, even on the price-basis companies: a NULL PV is a
            // different state ("not priced yet") and would make the PV
            // company's fixture look accidental rather than chosen.
            'pv_satang' => self::PV_SATANG,
            'is_active' => true,
            // Null = inherit the company's plan. Left null on purpose: a
            // product-level override is a separate feature with its own
            // resolution ladder, and pinning it here would hide a company-level
            // mistake behind a product-level right answer.
            'commission_plan_type' => null,
            'requires_shipping' => false,
        ];

        if ($product) {
            $product->update($attributes);

            return $product;
        }

        return Product::create($attributes);
    }

    /**
     * What the person who closes the sale is paid, before any leader takes a
     * share. Company-wide and tier-agnostic — the narrower scopes exist and
     * are a different test.
     */
    private function sellerRateRule(Company $company, string $plan): void
    {
        /*
         * Stairstep is the exception, and it is worth knowing why.
         *
         * On every other plan the seller's own rate comes from
         * commission_rules. Stairstep ALSO gives the seller a rank with a rate
         * on it, and the two are not connected: the engine pays the seller
         * from commission_rules and pays each manager the DIFFERENCE between
         * their rank and their downline's. Seeding 10% here against a
         * 5%-rank seller would print two different "seller rates" on one
         * screen with nothing saying which one paid. So this company's
         * commission_rule is set to the starter rank's own rate.
         *
         * Whether that duplication should exist at all is a design question
         * for the owner, and it is listed on the QA sheet rather than decided
         * here.
         */
        $rateBp = $plan === 'stairstep_breakaway' ? self::RANKS[0]['rate_bp'] : self::SELLER_RATE_BP;

        $existing = CommissionRule::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('product_id')
            ->whereNull('product_category_id')
            ->whereNull('cert_tier_id')
            ->first();

        $attributes = [
            'company_id' => $company->id,
            'cert_tier_id' => null,
            'product_id' => null,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $rateBp,
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => null,
        ];

        $existing ? $existing->update($attributes) : CommissionRule::create($attributes);
    }

    /**
     * An agent who can legally sell and legally be paid.
     *
     * The certification row is not decoration: CommissionService refuses to
     * write anything at all for an uncertified seller, and silently walks
     * past an uncertified manager. A UAT tenant missing these rows would look
     * exactly like a broken payout engine.
     */
    private function agent(Company $company, string $handle, string $label, ?User $manager = null, ?BinaryLeg $leg = null): User
    {
        $email = 'uat+'.$company->slug.'-'.$handle.'@uat.invalid';

        $user = User::withoutGlobalScopes()->withTrashed()->firstWhere('email', $email);

        $attributes = [
            // `name` is derived from first_name + last_name by a model hook
            // that DOES fire here (this is a command, not a WithoutModelEvents
            // seeder), so the two parts have to add up to the label QA reads
            // on screen — setting `name` alone would be overwritten.
            'name' => $label,
            'first_name' => $label,
            'last_name' => null,
            'company_id' => $company->id,
            'role' => UserRole::Agent,
            'manager_id' => $manager?->id,
            'binary_leg' => $leg,
        ];

        if ($user) {
            $user->restore();
            $user->update($attributes);
        } else {
            $user = User::create($attributes + [
                'email' => $email,
                /*
                 * DELIBERATELY UNUSABLE, and never printed.
                 *
                 * QA verifies these tenants through the admin console with
                 * their own Super Admin account — nothing here needs an agent
                 * login. Minting a known password for six throwaway accounts
                 * on a production deployment would be creating six real ways
                 * in, to save a step nobody needs.
                 *
                 * A .invalid address (RFC 2606) cannot receive a reset mail
                 * either, so these accounts stay shut.
                 */
                'password' => Str::random(64),
            ]);
        }

        UserCertification::withoutGlobalScopes()->firstOrCreate(
            ['user_id' => $user->id, 'cert_tier_id' => $this->basicTier->id],
            ['company_id' => $company->id, 'passed_at' => now()],
        );

        $this->makePayoutReady($user);

        return $user->fresh();
    }

    /**
     * Fills in what `User::hasCompletePayoutDetails()` asks for, so the payout
     * queue has somebody to offer.
     *
     * ── WHY THIS WAS MISSING, AND WHY IT IS HERE NOW ──
     *
     * 2026-09-22. The first version of this command stopped at the commission
     * ledger, because that was the question: does each plan split correctly.
     * It does. But the owner opened the payout screen next and found all four
     * Unilevel agents sitting under "มีค่าแนะนำ แต่ตั้งจ่ายไม่ได้" — which is
     * the screen behaving CORRECTLY (it refuses to queue money for somebody
     * with no account to send it to and no verified identity) over a fixture
     * that had simply never been finished.
     *
     * A UAT tenant that cannot reach the payout queue leaves the second half
     * of the money path untested, so the fixture is finished here instead.
     *
     * ── WHY A PASSPORT AND NOT A THAI NATIONAL ID ──
     *
     * `national_id` is encrypted and mirrored into `national_id_hash`, a
     * DETERMINISTIC blind index the user search matches on. A plausible
     * 13-digit Thai ID invented for a test account could hash to the same
     * value as a real person's — on a production database. A UAT-prefixed
     * passport number cannot be mistaken for a Thai ID, cannot collide with
     * one, and is honest about being fabricated.
     */
    private function makePayoutReady(User $user): void
    {
        if (filled($user->bank_account_number) && filled($user->national_id)) {
            return; // already done on an earlier run
        }

        // Unique per user, and unmistakably not a real document or account.
        $tag = 'UAT'.str_pad((string) $user->id, 9, '0', STR_PAD_LEFT);

        $user->forceFill([
            'bank_name' => 'ธนาคารทดสอบ UAT',
            'bank_account_number' => $tag,
            'bank_account_holder_name' => $user->name,
            'national_id' => $tag,
            'id_document_type' => IdDocumentType::Passport,
        ])->save();
    }

    private function rank(Company $company, string $key): AgentRank
    {
        $spec = collect(self::RANKS)->firstWhere('key', $key);

        $existing = AgentRank::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('name', $spec['name'])
            ->first();

        $attributes = [
            'company_id' => $company->id,
            'name' => $spec['name'],
            'volume_threshold' => $spec['threshold'],
            'sort_order' => $spec['sort'],
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $spec['rate_bp'],
            'is_breakaway_rank' => $spec['breakaway'],
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return AgentRank::create($attributes);
    }

    /** `current_rank_id` is not fillable — it is the engine's to move, not a request's. */
    private function placeOnRank(User $user, AgentRank $rank): void
    {
        $user->forceFill(['current_rank_id' => $rank->id])->save();
    }

    /**
     * Referral → order → slip → confirmed payment, through the real services.
     *
     * The order is not optional scenery. A paid order is now half of what
     * locks a company's plan (owner's ruling, 2026-09-21), so a UAT tenant
     * without one would not exercise the lock QA is here to check — and
     * `confirmPayment` is the only path that both closes the sale and fires
     * BR-4, which is the other half.
     */
    private function sell(Company $company, Product $product, User $seller, ReferralService $referrals, OrderService $orders, string $clientName): void
    {
        $client = Client::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('name', $clientName)
            ->first()
            ?? Client::create([
                'company_id' => $company->id,
                'referring_agent_id' => $seller->id,
                'name' => $clientName,
                'phone' => '0800000000',
            ]);

        /*
         * ── THE IDEMPOTENCY CHECK, AND WHY IT IS HERE AND NOT BELOW ──
         *
         * 2026-09-22 — the owner re-ran the seeder and every figure doubled:
         * ฿1,900 became ฿3,800, four ledger rows became eight. The screen was
         * reporting the truth; the command had sold twice.
         *
         * The check that was here looked at the referral it had JUST created,
         * which is a new row at the entry stage every single time — it could
         * never be true. The question has to be asked of the client BEFORE
         * creating anything: has this UAT customer already bought this
         * product? ReferralService::create() has no "find or create" mode and
         * should not: a real customer buying the same package twice is a
         * second sale, and that is correct for everyone except a fixture.
         *
         * BR-4 makes the surplus rows permanent — they cannot be edited or
         * deleted — so a re-run is not a cosmetic mistake. Every figure on the
         * QA sheet would be wrong from the second run onward, and it would
         * read as a commission bug rather than a seeder one.
         */
        $alreadySold = Referral::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('client_id', $client->id)
            ->where('product_id', $product->id)
            ->where('current_stage', PipelineStage::CompletePayment->value)
            ->exists();

        if ($alreadySold) {
            return;
        }

        $referral = $referrals->create([
            'client_id' => $client->id,
            'product_id' => $product->id,
            'branch' => 'UAT',
            'preferred_time' => now()->addDay(),
        ], $seller);

        $order = $orders->createForReferral($referral, PaymentMethod::BankTransfer);

        /*
         * The 2026-08-21 audit made "there is proof on file" a precondition of
         * confirming payment, and expressed it as the AwaitingVerification
         * STATE rather than a non-null slip_path. So the state is what this
         * sets; the path is a placeholder because no file is uploaded.
         */
        $order->update([
            'status' => OrderStatus::AwaitingVerification,
            'slip_path' => 'orders/slips/'.$company->id.'/uat-placeholder.jpg',
        ]);

        $orders->confirmPayment($order->fresh(), $seller);
    }

    /* ── the six plans ──────────────────────────────────────────────────── */

    /** @return array{expected: string} */
    private function seedUnilevel(Company $company, Product $product, ReferralService $referrals, OrderService $orders): array
    {
        foreach (self::LEVEL_RATES_BP as $level => $bp) {
            $this->overrideRule($company, $bp, $level);
        }

        $l3 = $this->agent($company, 'l3', 'UAT หัวหน้าชั้น 3');
        $l2 = $this->agent($company, 'l2', 'UAT หัวหน้าชั้น 2', $l3);
        $l1 = $this->agent($company, 'l1', 'UAT หัวหน้าชั้น 1', $l2);
        $seller = $this->agent($company, 'seller', 'UAT ผู้ขาย', $l1);

        $this->sell($company, $product, $seller, $referrals, $orders, 'UAT ลูกค้า Unilevel');

        return ['expected' => 'ผู้ขาย 10% = ฿1,000 · ชั้น1 5% = ฿500 · ชั้น2 3% = ฿300 · ชั้น3 1% = ฿100 (รวม 4 แถว)'];
    }

    /** @return array{expected: string} */
    private function seedBinary(Company $company, Product $product, ReferralService $referrals, OrderService $orders): array
    {
        $this->upsert(CommissionBinarySetting::class, $company, [
            'matched_rate_type' => CommissionRateType::Percentage,
            'matched_rate_value' => self::BINARY_MATCHED_RATE_BP,
            'cycle_frequency' => BinaryCycleFrequency::Weekly,
            'payout_cap_satang' => null,
            'carry_over_unmatched' => true,
        ]);

        $sponsor = $this->agent($company, 'sponsor', 'UAT ผู้สนับสนุน');
        $left = $this->agent($company, 'left', 'UAT ขาซ้าย', $sponsor, BinaryLeg::Left);
        $right = $this->agent($company, 'right', 'UAT ขาขวา', $sponsor, BinaryLeg::Right);

        $this->sell($company, $product, $left, $referrals, $orders, 'UAT ลูกค้า ขาซ้าย');
        $this->sell($company, $product, $right, $referrals, $orders, 'UAT ลูกค้า ขาขวา');

        return ['expected' => 'ตอนขาย: ผู้ขายซ้าย/ขวา ได้คนละ 10% = ฿1,000 (2 แถว) · ผู้สนับสนุนยังไม่ได้อะไร — ต้องรันรอบจับคู่ก่อน แล้วจึงได้ 10% ของ ฿10,000 ที่จับคู่ได้ = ฿1,000'];
    }

    /** @return array{expected: string} */
    private function seedMatrix(Company $company, Product $product, ReferralService $referrals, OrderService $orders, MatrixCommissionService $matrix): array
    {
        $this->upsert(CommissionMatrixSetting::class, $company, [
            'width' => 3,
            'depth' => 2,
            'spillover_rule' => MatrixSpilloverRule::Breadth,
        ]);

        foreach (self::MATRIX_LEVEL_RATES_BP as $level => $bp) {
            $existing = CommissionMatrixLevelRate::withoutGlobalScopes()
                ->where('company_id', $company->id)->where('level', $level)->first();

            $attributes = [
                'company_id' => $company->id,
                'level' => $level,
                'rate_type' => CommissionRateType::Percentage,
                'rate_value' => $bp,
                'effective_from' => now()->subDay()->toDateString(),
                'effective_to' => null,
            ];

            $existing ? $existing->update($attributes) : CommissionMatrixLevelRate::create($attributes);
        }

        $top = $this->agent($company, 'top', 'UAT ชั้นบนสุด');
        $mid = $this->agent($company, 'mid', 'UAT ชั้นกลาง', $top);
        $seller = $this->agent($company, 'seller', 'UAT ผู้ขาย', $mid);

        /*
         * Matrix pays down a tree of its OWN — matrix_placements — not down
         * manager_id. They are seeded to agree here, and QA should know they
         * can disagree in production: an agent with a manager and no placement
         * is paid nothing by this plan, silently.
         */
        $matrix->place($mid, $top);
        $matrix->place($seller, $mid);

        $this->sell($company, $product, $seller, $referrals, $orders, 'UAT ลูกค้า Matrix');

        return ['expected' => 'บริษัทนี้คิดจาก PV ฿7,000 (ไม่ใช่ราคา ฿10,000) · ผู้ขาย 10% = ฿700 · ชั้นกลาง 5% = ฿350 · ชั้นบนสุด 3% = ฿210 (รวม 3 แถว)'];
    }

    /** @return array{expected: string} */
    private function seedStairstep(Company $company, Product $product, ReferralService $referrals, OrderService $orders): array
    {
        $starter = $this->rank($company, 'starter');
        $leader = $this->rank($company, 'leader');
        $manager = $this->rank($company, 'manager');

        $top = $this->agent($company, 'top', 'UAT ผู้จัดการ (ตัดสาย)');
        $mid = $this->agent($company, 'mid', 'UAT ผู้นำ', $top);
        $seller = $this->agent($company, 'seller', 'UAT ผู้ขาย', $mid);

        $this->placeOnRank($top, $manager);
        $this->placeOnRank($mid, $leader);
        $this->placeOnRank($seller, $starter);

        $this->sell($company, $product, $seller, $referrals, $orders, 'UAT ลูกค้า Stairstep');

        return ['expected' => 'ผู้ขาย (ขั้นเริ่มต้น 5%) = ฿500 · ผู้นำได้ส่วนต่าง 10%−5% = ฿500 · ผู้จัดการได้ส่วนต่าง 15%−10% = ฿500 (รวม 3 แถว)'];
    }

    /** @return array{expected: string} */
    private function seedGeneration(Company $company, Product $product, ReferralService $referrals, OrderService $orders): array
    {
        $this->upsert(CommissionGenerationSetting::class, $company, [
            'max_generation_depth' => count(self::GENERATION_RATES_BP),
        ]);

        foreach (self::GENERATION_RATES_BP as $generation => $bp) {
            $existing = CommissionGenerationRule::withoutGlobalScopes()
                ->where('company_id', $company->id)->where('generation_number', $generation)->first();

            $attributes = [
                'company_id' => $company->id,
                'generation_number' => $generation,
                'rate_type' => CommissionRateType::Percentage,
                'rate_value' => $bp,
                'effective_from' => now()->subDay()->toDateString(),
                'effective_to' => null,
            ];

            $existing ? $existing->update($attributes) : CommissionGenerationRule::create($attributes);
        }

        $starter = $this->rank($company, 'starter');
        $breakaway = $this->rank($company, 'manager');

        // Four managers above the seller, alternating: the two who have
        // reached the breakaway rank are the two generations that get paid,
        // and the two who have not are skipped WITHOUT consuming a
        // generation. That asymmetry is the whole plan, and it is invisible
        // on any settings screen.
        $g4 = $this->agent($company, 'up4', 'UAT หัวหน้า ง (ตัดสาย)');
        $g3 = $this->agent($company, 'up3', 'UAT หัวหน้า ค', $g4);
        $g2 = $this->agent($company, 'up2', 'UAT หัวหน้า ข (ตัดสาย)', $g3);
        $g1 = $this->agent($company, 'up1', 'UAT หัวหน้า ก', $g2);
        $seller = $this->agent($company, 'seller', 'UAT ผู้ขาย', $g1);

        $this->placeOnRank($g4, $breakaway);
        $this->placeOnRank($g3, $starter);
        $this->placeOnRank($g2, $breakaway);
        $this->placeOnRank($g1, $starter);
        $this->placeOnRank($seller, $starter);

        $this->sell($company, $product, $seller, $referrals, $orders, 'UAT ลูกค้า Generation');

        return ['expected' => 'ผู้ขาย 10% = ฿1,000 · หัวหน้า ข (รุ่นที่ 1) 5% = ฿500 · หัวหน้า ง (รุ่นที่ 2) 3% = ฿300 · หัวหน้า ก และ ค ถูกข้าม ได้ ฿0 (รวม 3 แถว)'];
    }

    /** @return array{expected: string} */
    private function seedAffiliate(Company $company, Product $product, ReferralService $referrals, OrderService $orders): array
    {
        // Company-wide, no level: Affiliate pays exactly one hop up and has no
        // ladder to price.
        $this->overrideRule($company, self::AFFILIATE_RATE_BP, null);

        $introducer = $this->agent($company, 'introducer', 'UAT ผู้แนะนำ');
        $seller = $this->agent($company, 'seller', 'UAT ผู้ขาย', $introducer);

        $this->sell($company, $product, $seller, $referrals, $orders, 'UAT ลูกค้า Affiliate');

        return ['expected' => 'ผู้ขาย 10% = ฿1,000 · ผู้แนะนำ 3% = ฿300 (บริษัทจ่ายเพิ่ม ไม่หักจากผู้ขาย) (รวม 2 แถว)'];
    }

    /* ── small helpers ──────────────────────────────────────────────────── */

    private function overrideRule(Company $company, int $rateBp, ?int $level): void
    {
        $query = CommissionOverrideRule::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('product_id')
            ->whereNull('product_category_id');

        $existing = ($level === null ? $query->whereNull('level') : $query->where('level', $level))->first();

        $attributes = [
            'company_id' => $company->id,
            'product_id' => null,
            'product_category_id' => null,
            'manager_cert_tier_id' => null,
            'level' => $level,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $rateBp,
            // Null = follow the company's own mode, which is what every real
            // company does. Pinning it here would test this row's override of
            // the setting rather than the setting.
            'override_mode' => null,
            'effective_from' => now()->subDay()->toDateString(),
            'effective_to' => null,
        ];

        $existing ? $existing->update($attributes) : CommissionOverrideRule::create($attributes);
    }

    /**
     * The one-row-per-company settings tables all carry a unique company_id,
     * so this is their shared shape.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(string $model, Company $company, array $attributes): void
    {
        $existing = $model::withoutGlobalScopes()->where('company_id', $company->id)->first();

        $existing
            ? $existing->update($attributes)
            : $model::create($attributes + ['company_id' => $company->id]);
    }

    /* ── output ─────────────────────────────────────────────────────────── */

    /**
     * @param  array<int, array{plan: string, company: Company, expected: string, ledger: array<int, CommissionLedger>}>  $rows
     */
    private function renderSummary(array $rows): void
    {
        foreach ($rows as $row) {
            $this->newLine();
            $this->line('── '.$row['company']->name.'  (id '.$row['company']->id.', slug '.$row['company']->slug.')');
            $this->line('   คาดว่าจะได้: '.$row['expected']);

            if ($row['ledger'] === []) {
                // Not an error condition to swallow. A plan that paid nobody
                // is the single most useful thing this command can report.
                $this->warn('   ค่าคอมที่ลงบัญชีจริง: ไม่มีเลย');

                continue;
            }

            $table = [];
            foreach ($row['ledger'] as $entry) {
                $table[] = [
                    $entry->agent?->name ?? '—',
                    $entry->earned_via->value,
                    number_format($entry->rate_applied / 100, 2).'%',
                    number_format($entry->amount_satang / 100, 2),
                ];
            }

            $this->table(['ใคร', 'ได้จาก', 'อัตรา', 'บาท'], $table);
            $this->line('   รวมจ่ายออก: '.number_format(array_sum(array_map(
                fn (CommissionLedger $e): int => $e->amount_satang,
                $row['ledger'],
            )) / 100, 2).' บาท');
        }
    }

    /**
     * @param  array<int, array{plan: string, name: string}>  $plans
     */
    private function renderBinaryFootnote(array $plans): void
    {
        if (! in_array('binary', array_column($plans, 'plan'), true)) {
            return;
        }

        $this->newLine();
        $this->line('── Binary จ่ายเป็นรอบ ไม่ใช่ต่อการขาย');
        $this->line('   ยอดสองขาถูกบันทึกแล้ว แต่ค่าคอมของผู้สนับสนุนจะเกิดเมื่อรันรอบจับคู่:');
        $this->line('     php artisan commissions:run-binary-cycles');

        /*
         * That command sweeps EVERY company with binary settings, so it is
         * not this command's to run on a shared deployment. Naming the other
         * tenants it would reach is the honest way to hand the decision over.
         */
        $others = Company::withoutGlobalScopes()
            ->whereHas('commissionBinarySetting')
            ->where('slug', 'not like', self::SLUG_PREFIX.'%')
            ->pluck('name')
            ->all();

        if ($others === []) {
            $this->line('   ไม่มีบริษัทอื่นที่ตั้ง Binary ไว้ — คำสั่งนี้จะแตะเฉพาะบริษัท UAT');

            return;
        }

        $this->warn('   ระวัง: คำสั่งนั้นจะประมวลผลบริษัทอื่นที่ตั้ง Binary ไว้ด้วย — '.implode(', ', $others));
    }
}
