<?php

namespace App\Services\Commission;

use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Catalog\CommissionRuleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 2026-09-13 — copy one company's commission rates onto another.
 *
 * ── THE QUESTION THIS ANSWERS ──
 *
 * Owner, 2026-09-13: "ทำไมระบบเราไม่ดึงค่าคอมจากค่าเริ่มต้นมาตั้งเป็นค่าคอม
 * มาตรฐาน ทำไม thailife ถึงมีค่าเริ่มต้น" — asked while looking at a brand-new
 * company with no rates beside an established one that had them.
 *
 * The system does NOT invent a rate, and that part is deliberate: BR-7 forbids
 * hardcoding a business value, and a guessed 3% is indistinguishable on screen
 * from a 3% somebody decided. It would flow into a commission_ledger row that
 * BR-4 forbids anybody from correcting, and the first sign that nobody ever
 * approved it would be a payout.
 *
 * But the friction underneath the question is real: a platform running several
 * companies makes an operator re-type a rate table that is usually identical.
 * So the answer is to make COPYING one click, not to make GUESSING automatic.
 * A copied rate is still a rate a human chose — they chose it by pressing a
 * button that named exactly what it was about to create.
 *
 * ── WHY preview() AND apply() SHARE ONE PRIVATE METHOD ──
 *
 * The owner asked for a confirmation step. A preview that is computed
 * separately from the write is a preview that can lie — and this one describes
 * money. entries() is the single answer; preview() formats it and apply()
 * executes it.
 *
 * ── WHAT IS DELIBERATELY NOT COPIED ──
 *
 *  · EXPIRED AND NOT-YET-EFFECTIVE ROWS. Those are the source company's
 *    history and its plans. Copying them would hand a new company a past it
 *    never had, and the dates would collide with anything it sets later.
 *
 *  · THE DATES THEMSELVES. Every copy starts TODAY and is open-ended. A rate
 *    whose effective_from is six months old claims this company was paying it
 *    six months ago, which is false, and would make assertNoOverlap() reserve
 *    a window nobody asked for.
 *
 *  · ANY SCOPE THE TARGET ALREADY HAS A LIVE RATE FOR. An existing rate is a
 *    decision somebody made; a "copy" that silently replaced it would be an
 *    overwrite wearing a friendlier word. Those are reported as skipped, with
 *    the reason, so the operator can see what was left alone.
 *
 *  · RULES POINTING AT A PRODUCT OR CATEGORY THE TARGET CANNOT SEE. A rule
 *    scoped to the source company's own product would be a cross-tenant
 *    reference (BR-6) that could never match anything anyway. Shared/platform
 *    rows are fine — both companies genuinely sell those.
 */
class CommissionRateCopyService
{
    public function __construct(
        private readonly CommissionRuleService $commissionRuleService,
        private readonly CommissionOverrideRuleService $commissionOverrideRuleService,
    ) {}

    /**
     * What a copy WOULD do. Writes nothing.
     *
     * @return array<string, mixed>
     */
    public function preview(int $fromCompanyId, int $toCompanyId): array
    {
        return $this->describe($fromCompanyId, $toCompanyId, $this->entries($fromCompanyId, $toCompanyId));
    }

    /**
     * Does it, and returns the same shape preview() returns — recomputed
     * AFTER the writes, so `copied` becomes empty and everything shows as
     * already present. That is not a wasted query: it is how the screen
     * repaints into the finished state without inventing one.
     *
     * @return array<string, mixed>
     */
    public function apply(int $fromCompanyId, int $toCompanyId, User $actor): array
    {
        $entries = $this->entries($fromCompanyId, $toCompanyId);

        /*
         * ONE TRANSACTION FOR THE WHOLE COPY.
         *
         * A half-copied rate table is worse than none: some products pay at
         * the new rates and some at nothing, and the operator has no way to
         * tell which half succeeded. All or nothing, and they press the button
         * again.
         */
        DB::transaction(function () use ($entries, $actor, $toCompanyId) {
            foreach ($entries['agent']['copied'] as $entry) {
                // Through the Service, not a bare create(): overlap checking,
                // the audit row, and TASK-197's "first rule stamps the
                // product's rate format" all live there, and a copy is not a
                // reason to skip any of them.
                $this->commissionRuleService->create($this->payload($entry, $toCompanyId), $actor);
            }

            foreach ($entries['leader']['copied'] as $entry) {
                $this->commissionOverrideRuleService->create($this->payload($entry, $toCompanyId), $actor);
            }
        });

        return $this->describe($fromCompanyId, $toCompanyId, $this->entries($fromCompanyId, $toCompanyId));
    }

    /**
     * The single answer both public methods are built on.
     *
     * @return array{agent: array{copied: list<array<string, mixed>>, skipped: list<array<string, mixed>>}, leader: array{copied: list<array<string, mixed>>, skipped: list<array<string, mixed>>}}
     */
    private function entries(int $fromCompanyId, int $toCompanyId): array
    {
        return [
            'agent' => $this->split(
                CommissionRule::withoutGlobalScope(TenantScope::class)
                    ->where('company_id', $fromCompanyId)
                    ->get(),
                CommissionRule::withoutGlobalScope(TenantScope::class)
                    ->where('company_id', $toCompanyId)
                    ->get(),
                $toCompanyId,
            ),
            'leader' => $this->split(
                CommissionOverrideRule::withoutGlobalScope(TenantScope::class)
                    ->where('company_id', $fromCompanyId)
                    ->get(),
                CommissionOverrideRule::withoutGlobalScope(TenantScope::class)
                    ->where('company_id', $toCompanyId)
                    ->get(),
                $toCompanyId,
            ),
        ];
    }

    /**
     * @param  Collection<int, CommissionRule|CommissionOverrideRule>  $source
     * @param  Collection<int, CommissionRule|CommissionOverrideRule>  $existing
     * @return array{copied: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    private function split($source, $existing, int $toCompanyId): array
    {
        $takenScopes = $existing
            ->filter(fn ($rule) => $this->isLive($rule))
            ->map(fn ($rule) => $this->scopeKey($rule))
            ->all();

        $copied = [];
        $skipped = [];

        foreach ($source as $rule) {
            $entry = [
                'scope' => $this->scopeName($rule),
                'label' => $this->scopeLabel($rule),
                'rate_type' => $rule->rate_type->value,
                'rate_value' => (int) $rule->rate_value,
                'product_id' => $rule->product_id,
                'product_category_id' => $rule->product_category_id,
            ];

            if (! $this->isLive($rule)) {
                // Not reported as skipped at all. An expired row is not
                // something the operator declined to copy — it is not part of
                // the source company's current rate table, and listing it
                // would make the preview longer and less true.
                continue;
            }

            if (in_array($this->scopeKey($rule), $takenScopes, true)) {
                $skipped[] = $entry + ['reason' => 'มีอัตราของบริษัทนี้อยู่แล้ว — ไม่ทับของเดิม'];

                continue;
            }

            if (! $this->scopeIsVisibleTo($rule, $toCompanyId)) {
                $skipped[] = $entry + ['reason' => 'สินค้า/หมวดหมู่นี้เป็นของบริษัทต้นทาง บริษัทนี้ใช้ไม่ได้'];

                continue;
            }

            $copied[] = $entry;
        }

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /**
     * The dates are the copy's own, never the source's — see the class
     * docblock for why a borrowed effective_from is a false claim about the
     * past.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function payload(array $entry, int $toCompanyId): array
    {
        return [
            'company_id' => $toCompanyId,
            'product_id' => $entry['product_id'],
            'product_category_id' => $entry['product_category_id'],
            'rate_type' => $entry['rate_type'],
            'rate_value' => $entry['rate_value'],
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
        ];
    }

    private function isLive(CommissionRule|CommissionOverrideRule $rule): bool
    {
        if ($rule->effective_from > now()) {
            return false;
        }

        return $rule->effective_to === null || $rule->effective_to >= now();
    }

    /** NULL-safe, because a company-wide row is (null, null) and two of those are the same scope. */
    private function scopeKey(CommissionRule|CommissionOverrideRule $rule): string
    {
        return ($rule->product_id ?? 'null').':'.($rule->product_category_id ?? 'null');
    }

    private function scopeName(CommissionRule|CommissionOverrideRule $rule): string
    {
        if ($rule->product_id !== null) {
            return 'product';
        }

        return $rule->product_category_id !== null ? 'category' : 'company';
    }

    private function scopeLabel(CommissionRule|CommissionOverrideRule $rule): string
    {
        if ($rule->product_id !== null) {
            return Product::withoutGlobalScopes()->find($rule->product_id)?->effectiveName() ?? "สินค้า #{$rule->product_id}";
        }

        if ($rule->product_category_id !== null) {
            return ProductCategory::withoutGlobalScopes()->find($rule->product_category_id)?->name ?? "หมวดหมู่ #{$rule->product_category_id}";
        }

        return 'ค่าเริ่มต้นทั้งบริษัท';
    }

    /**
     * BR-6. A rule scoped to the SOURCE company's own product or category
     * cannot be copied: the target does not sell it, the rule could never
     * match, and the row would hold a foreign tenant's id. A PLATFORM-owned
     * row (company_id null) is shared by construction and copies fine.
     */
    private function scopeIsVisibleTo(CommissionRule|CommissionOverrideRule $rule, int $toCompanyId): bool
    {
        if ($rule->product_id !== null) {
            $owner = Product::withoutGlobalScopes()->find($rule->product_id)?->company_id;

            return $owner === null || (int) $owner === $toCompanyId;
        }

        if ($rule->product_category_id !== null) {
            $owner = ProductCategory::withoutGlobalScopes()->find($rule->product_category_id)?->company_id;

            return $owner === null || (int) $owner === $toCompanyId;
        }

        return true;
    }

    /**
     * @param  array{agent: array{copied: list<array<string, mixed>>, skipped: list<array<string, mixed>>}, leader: array{copied: list<array<string, mixed>>, skipped: list<array<string, mixed>>}}  $entries
     * @return array<string, mixed>
     */
    private function describe(int $fromCompanyId, int $toCompanyId, array $entries): array
    {
        return [
            'from_company' => ['id' => $fromCompanyId, 'name' => Company::find($fromCompanyId)?->name],
            'to_company' => ['id' => $toCompanyId, 'name' => Company::find($toCompanyId)?->name],
            'agent_rates' => $entries['agent'],
            'leader_rates' => $entries['leader'],
            'total_to_copy' => count($entries['agent']['copied']) + count($entries['leader']['copied']),
        ];
    }
}
