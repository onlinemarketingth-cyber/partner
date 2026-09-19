<?php

namespace App\Console\Commands;

use App\Enums\CommissionBasis;
use App\Enums\CommissionPlanType;
use App\Models\Company;
use App\Services\Commission\OverrideDeductionGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * commission:audit-config — what is actually configured, right now, in this
 * database. Reads only. Writes nothing, prompts for nothing.
 *
 * ═══ WHY IT EXISTS ═══
 *
 * Owner, 2026-09-19, weighing up a set of changes to the commission engines:
 * "หากแก้จะกระทบอะไรบ้างในงานที่ทำไปแล้วปัจจุบัน". The honest answer from
 * source alone is "it depends what you have configured", and the source
 * cannot say: no seeder creates a CommissionOverrideRule, a binary setting,
 * an AgentRank or a generation rule, so every one of those was either typed
 * into the admin screens on a live database or does not exist at all. The
 * blast radius of closing the per-product plan override, or of locking the
 * basis once sales exist, is a different size in each case.
 *
 * So this command answers it from the data instead of from the repository,
 * and does so without touching anything — the whole point of running it
 * BEFORE the changes is that it cannot be the thing that breaks them.
 *
 * ═══ WHY A CONSOLE COMMAND ═══
 *
 * Same reason CollapseOverrideTiersCommand is one: `tinker` is unavailable
 * on the production plan, so a command run over the existing SSH session is
 * the only way to read production without handing anybody a query console.
 *
 * ═══ WHAT IT LOOKS FOR, AND WHY EACH ONE ═══
 *
 *  1. Plan + basis per company, beside the ledger count. A company with rows
 *     already written is one where changing either would mean two eras of
 *     commission under one promise — and BR-4 says the old era can never be
 *     restated. Today nothing prevents that change; this says who it would
 *     hurt.
 *  2. Products whose own commission_plan_type disagrees with their company's.
 *     Product::effectivePlanType() lets the product win, silently, so these
 *     are sales already being calculated under a plan the company did not
 *     choose. If any exist, closing that override is not just a guard — it is
 *     a correction, and the rows involved need a human decision.
 *  3. Products with no PV on a company that runs on PV.
 *     CommissionBasisResolver falls back to the sale price and the readiness
 *     banner shouts, but the fallback still pays — so this counts how much of
 *     the catalogue is paying on the wrong number.
 *  4. Deepest manager chain. Unilevel walks it with no cap and pays the same
 *     rate at every hop, so this number is what OverrideDeductionGuard divides
 *     by: it is the live measure of how badly the missing per-level rate table
 *     is squeezing the rates an admin is allowed to set.
 *  5. Whether the chosen plan has the settings rows it needs at all. A company
 *     on Generation with no AgentRank ladder pays nobody, forever, silently —
 *     that is not an error state anywhere in the code, so nothing reports it
 *     but this.
 *  6. Agents with no binary_leg on a Binary company. Nothing in the app can
 *     set that column, so the answer is expected to be "all of them"; it is
 *     here so the report says it out loud rather than leaving it inferred.
 *  7. Audit-log entries where the basis or plan changed AFTER the first ledger
 *     row. That is the damage the lock would have prevented, already done.
 *
 * Global scopes are dropped on purpose throughout: there is no authenticated
 * user on the console, so TenantScope's fail-closed branch would filter every
 * row away and the command would cheerfully report that nothing is wrong.
 */
class AuditCommissionConfigCommand extends Command
{
    protected $signature = 'commission:audit-config
                            {--company= : Only this company id}';

    protected $description = 'Read-only report of how commission is configured per company (writes nothing)';

    public function handle(OverrideDeductionGuard $guard): int
    {
        $companies = Company::query()
            ->withoutGlobalScopes()
            ->when($this->option('company'), fn ($q, $id) => $q->where('id', (int) $id))
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('ไม่พบบริษัทในฐานข้อมูลนี้');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>รายงานการตั้งค่าค่าคอมมิชชัน — อ่านอย่างเดียว ไม่มีการเขียนอะไรทั้งสิ้น</>');
        $this->line(sprintf('ฐานข้อมูล: %s · เวลา: %s', DB::connection()->getDatabaseName(), now()->toDateTimeString()));

        $totals = ['mismatched_products' => 0, 'missing_pv' => 0, 'late_changes' => 0, 'incomplete_plans' => 0];

        foreach ($companies as $company) {
            $this->newLine();
            $this->line(str_repeat('─', 72));
            $this->line(sprintf('<options=bold>บริษัท #%d · %s</>', $company->id, $company->name));

            $plan = $company->commission_plan_type;
            $basis = $company->commission_basis ?? CommissionBasis::Price;

            $ledger = DB::table('commission_ledger')->where('company_id', $company->id);
            $ledgerCount = (clone $ledger)->count();
            $firstLedgerAt = (clone $ledger)->min('created_at');

            $this->line(sprintf('  แผน: <options=bold>%s</>   ฐานคิด: <options=bold>%s</>',
                $plan?->value ?? '—',
                $basis instanceof CommissionBasis ? $basis->value : (string) $basis));
            $this->line(sprintf('  บัญชีค่าแนะนำ: %s แถว%s',
                number_format($ledgerCount),
                $firstLedgerAt ? '   แถวแรก '.$firstLedgerAt : ''));

            if ($ledgerCount > 0) {
                $this->line('  <fg=yellow>⚠ มียอดขายแล้ว แต่ระบบยังยอมให้เปลี่ยนแผนและฐานได้อยู่ (ไม่มีอะไรห้าม)</>');
            }

            /* 2 — products overriding the company's plan */
            $mismatched = DB::table('products')
                ->where('company_id', $company->id)
                ->whereNotNull('commission_plan_type')
                ->when($plan, fn ($q) => $q->where('commission_plan_type', '!=', $plan->value))
                ->get(['id', 'name', 'commission_plan_type', 'is_active']);

            if ($mismatched->isEmpty()) {
                $this->line('  สินค้าที่ตั้งแผนสวนบริษัท: <fg=green>ไม่มี</>');
            } else {
                $totals['mismatched_products'] += $mismatched->count();
                $this->line(sprintf('  <fg=red>สินค้าที่ตั้งแผนสวนบริษัท: %d รายการ</>', $mismatched->count()));
                foreach ($mismatched as $p) {
                    $this->line(sprintf('     - #%d %s → %s%s', $p->id, $p->name, $p->commission_plan_type,
                        $p->is_active ? '' : ' (ปิดขายอยู่)'));
                }
            }

            /* 3 — PV gaps, only meaningful on the PV basis */
            $basisValue = $basis instanceof CommissionBasis ? $basis->value : (string) $basis;
            if ($basisValue === CommissionBasis::PointValue->value) {
                $missingPv = DB::table('products')
                    ->where('company_id', $company->id)
                    ->where('is_active', true)
                    ->whereNull('pv_satang')
                    ->count();
                $totalProducts = DB::table('products')->where('company_id', $company->id)->where('is_active', true)->count();
                $totals['missing_pv'] += $missingPv;
                $this->line($missingPv === 0
                    ? sprintf('  สินค้าที่ยังไม่ได้ตั้ง PV: <fg=green>ไม่มี</> (จาก %d รายการ)', $totalProducts)
                    : sprintf('  <fg=red>สินค้าที่ยังไม่ได้ตั้ง PV: %d จาก %d รายการ — คิดจากราคาขายแทน</>', $missingPv, $totalProducts));
            }

            /* 4 — how deep the tree actually is */
            $depth = $guard->deepestChain($company);
            $this->line(sprintf('  สายงานลึกที่สุดตอนนี้: <options=bold>%d ชั้น</>%s',
                $depth,
                $depth >= 5 ? '  <fg=yellow>(ยิ่งลึก อัตราหัวหน้าทีมที่ระบบยอมให้ตั้งยิ่งถูกบีบ)</>' : ''));

            /* 5 — does the chosen plan have anything configured behind it */
            $missing = $this->missingPlanSetup($company, $plan);
            if ($missing === null) {
                $this->line('  ค่าตั้งต้นของแผนที่เลือก: <fg=green>ครบ</>');
            } else {
                $totals['incomplete_plans']++;
                $this->line(sprintf('  <fg=red>ค่าตั้งต้นของแผนที่เลือก: %s</>', $missing));
            }

            /* 6 — Binary agents with no leg */
            if ($plan === CommissionPlanType::Binary) {
                $agents = DB::table('users')->where('company_id', $company->id)
                    ->whereNull('deleted_at')->where('role', 'agent');
                $noLeg = (clone $agents)->whereNull('binary_leg')->count();
                $this->line($noLeg === 0
                    ? '  ตัวแทนที่ยังไม่ได้อยู่ขาไหน: <fg=green>ไม่มี</>'
                    : sprintf('  <fg=red>ตัวแทนที่ยังไม่ได้อยู่ขาไหน: %d จาก %d คน — ยอดไม่เข้าขาใดเลย</>',
                        $noLeg, (clone $agents)->count()));
            }

            /* 7 — was the basis or plan changed after money had already been paid */
            $late = DB::table('audit_logs')
                ->where('company_id', $company->id)
                ->whereIn('action', ['commission_basis.updated', 'commission_plan_type.updated'])
                ->when($firstLedgerAt, fn ($q) => $q->where('created_at', '>', $firstLedgerAt))
                ->orderBy('created_at')
                ->get(['action', 'old_values', 'new_values', 'created_at']);

            if ($late->isEmpty()) {
                $this->line('  เคยเปลี่ยนแผน/ฐานหลังมียอดขายแล้ว: <fg=green>ไม่เคย</>');
            } else {
                $totals['late_changes'] += $late->count();
                $this->line(sprintf('  <fg=red>เคยเปลี่ยนแผน/ฐานหลังมียอดขายแล้ว: %d ครั้ง</>', $late->count()));
                foreach ($late as $row) {
                    $this->line(sprintf('     - %s  %s  %s → %s',
                        $row->created_at, $row->action,
                        $this->firstValue($row->old_values), $this->firstValue($row->new_values)));
                }
            }
        }

        $this->newLine();
        $this->line(str_repeat('═', 72));
        $this->line('<fg=cyan>สรุปรวมทุกบริษัท</>');
        $this->line(sprintf('  สินค้าที่ตั้งแผนสวนบริษัท          : %d', $totals['mismatched_products']));
        $this->line(sprintf('  สินค้าขาด PV (เฉพาะบริษัทที่ใช้ PV)   : %d', $totals['missing_pv']));
        $this->line(sprintf('  บริษัทที่ค่าตั้งต้นของแผนยังไม่ครบ   : %d', $totals['incomplete_plans']));
        $this->line(sprintf('  การเปลี่ยนแผน/ฐานหลังมียอดขาย      : %d ครั้ง', $totals['late_changes']));
        $this->newLine();
        $this->line('<fg=gray>คำสั่งนี้ไม่ได้เขียนอะไรลงฐานข้อมูลเลย รันซ้ำได้เท่าที่ต้องการ</>');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * The settings rows a plan needs before it can pay anybody, or null when
     * the plan is self-sufficient. Generation is listed as needing the RANK
     * ladder because that is a real, non-obvious coupling: it pays only
     * ancestors holding a breakaway rank, and only StairstepCommissionService
     * ever writes users.current_rank_id.
     */
    private function missingPlanSetup(Company $company, ?CommissionPlanType $plan): ?string
    {
        $has = fn (string $table) => DB::table($table)->where('company_id', $company->id)->exists();

        return match ($plan) {
            CommissionPlanType::Binary => $has('commission_binary_settings') ? null : 'ยังไม่ได้ตั้งค่า Binary (อัตราจับคู่/รอบ) — ยังไม่มีรอบไหนถูกประมวลผล',
            CommissionPlanType::Matrix => match (true) {
                ! $has('commission_matrix_settings') => 'ยังไม่ได้ตั้งความกว้าง/ความลึกของผัง Matrix',
                ! $has('commission_matrix_level_rates') => 'ยังไม่ได้ตั้งอัตราต่อชั้นของ Matrix — ไม่มีใครได้อะไร',
                default => null,
            },
            CommissionPlanType::StairstepBreakaway => $has('agent_ranks') ? null : 'ยังไม่ได้ตั้งบันไดขั้น — ทุกคนไม่มีขั้น จึงไม่มีส่วนต่างให้จ่าย',
            CommissionPlanType::Generation => match (true) {
                ! $has('agent_ranks') => 'ยังไม่ได้ตั้งบันไดขั้นของแผนอันดับ ซึ่ง Generation ต้องใช้ — ตอนนี้จ่ายศูนย์เงียบ ๆ',
                ! $has('commission_generation_settings') => 'ยังไม่ได้ตั้งจำนวนรุ่นสูงสุด',
                ! $has('commission_generation_rules') => 'ยังไม่ได้ตั้งอัตราต่อรุ่น',
                default => null,
            },
            CommissionPlanType::Unilevel, CommissionPlanType::Affiliate => DB::table('commission_override_rules')
                ->where('company_id', $company->id)->exists()
                ? null
                : 'ยังไม่ได้ตั้งอัตราหัวหน้าทีม — คนขายได้ แต่ไม่มีหัวหน้าคนไหนได้',
            default => null,
        };
    }

    /** audit_logs stores one-key maps; print the value, not the JSON. */
    private function firstValue(?string $json): string
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) && $decoded !== [] ? (string) reset($decoded) : '—';
    }
}
