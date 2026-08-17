<?php

namespace App\Services\Catalog;

use App\Models\AuditLog;
use App\Models\CommissionRule;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// BR-2: "rate = cert tier x package sold... from commission_rules config
// — never hardcode." The one piece of real business logic here: two
// rules for the same (company, cert_tier, SCOPE) must never have
// overlapping effective date ranges, or CommissionService (BR-4)
// wouldn't know which rate to apply at ledger-creation time. ADR-011/
// TASK-028 widened "scope" from just product_id to the (product_id,
// product_category_id) pair — a product-specific rule, a category-wide
// rule, and the company-wide default are three independent scopes that
// may each separately have their own non-overlapping date ranges.
class CommissionRuleService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): CommissionRule
    {
        $companyId = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;

        if ($companyId === null) {
            // Defense-in-depth: the Form Request already requires company_id
            // for Super Admin, but the Service must never silently fall
            // through to a null tenant (BR-6) if that validation is ever
            // loosened.
            throw ValidationException::withMessages(['company_id' => 'company_id is required.']);
        }

        $data['company_id'] = $companyId;

        $this->assertNoOverlap(
            $data['company_id'],
            $data['cert_tier_id'],
            $data['product_id'] ?? null,
            $data['product_category_id'] ?? null,
            $data['effective_from'],
            $data['effective_to'] ?? null,
        );

        // TASK-197 §2.2 — "the first rule for this product decides the
        // format": if this is a product-scoped rule (product_id set) and
        // the product hasn't had its commission_rate_type configured yet
        // (still null), stamp the product with this rule's rate_type as a
        // side effect of creating it, in the SAME transaction as the rule
        // INSERT. Wrapped together so a crash between the two writes can
        // never leave the rule created but the product's format unset (or
        // vice versa). Category-scoped/company-wide rules (product_id
        // null) never touch a product row — same exemption as the
        // ValidatesCommissionRateTypeConsistency check that already
        // rejected any MISMATCHED product-scoped rate_type before this
        // point, so by the time we get here the incoming rate_type is
        // always safe to stamp.
        $commissionRule = DB::transaction(function () use ($data) {
            $rule = CommissionRule::create($data);

            $productId = $data['product_id'] ?? null;

            if ($productId !== null) {
                $product = Product::query()->find($productId);

                if ($product !== null && $product->commission_rate_type === null) {
                    $product->update(['commission_rate_type' => $data['rate_type']]);
                }
            }

            return $rule;
        });

        // TASK-041 (4.1) — Section 6: "record every action that affects
        // money [or] commission." BR-2 rates are exactly that, and this
        // was the first CommissionRule write path to gain audit coverage
        // (previously only UserService::moveToCompany() wrote to
        // audit_logs at all). Shape copied exactly from moveToCompany's
        // own AuditLog::create() call.
        AuditLog::create([
            'company_id' => $commissionRule->company_id,
            'actor_user_id' => $actor->id,
            'action' => 'commission_rule.created',
            'auditable_type' => CommissionRule::class,
            'auditable_id' => $commissionRule->id,
            'old_values' => null,
            'new_values' => $this->auditableFields($commissionRule),
            'ip_address' => request()?->ip(),
        ]);

        return $commissionRule;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CommissionRule $commissionRule, array $data, User $actor): CommissionRule
    {
        $effectiveFrom = $data['effective_from'] ?? $commissionRule->effective_from;
        $effectiveTo = array_key_exists('effective_to', $data) ? $data['effective_to'] : $commissionRule->effective_to;

        // Scope (product_id/product_category_id) is immutable after
        // creation — neither field is accepted by UpdateCommissionRuleRequest
        // (same established rule as product_id was before TASK-028), so
        // the overlap check always uses the row's OWN existing scope.
        $this->assertNoOverlap(
            $commissionRule->company_id,
            $commissionRule->cert_tier_id,
            $commissionRule->product_id,
            $commissionRule->product_category_id,
            $effectiveFrom,
            $effectiveTo,
            excludeId: $commissionRule->id,
        );

        $oldValues = $this->auditableFields($commissionRule);

        $commissionRule->update($data);

        // TASK-041 (4.1) — same Section 6 coverage as create() above.
        AuditLog::create([
            'company_id' => $commissionRule->company_id,
            'actor_user_id' => $actor->id,
            'action' => 'commission_rule.updated',
            'auditable_type' => CommissionRule::class,
            'auditable_id' => $commissionRule->id,
            'old_values' => $oldValues,
            'new_values' => $this->auditableFields($commissionRule),
            'ip_address' => request()?->ip(),
        ]);

        return $commissionRule;
    }

    /**
     * TASK-041 — the rate + scope fields worth capturing in an audit
     * diff; excludes timestamps/id (already carried by the AuditLog row
     * itself) and renewal fields (TASK-024, out of scope for this
     * task's audit coverage per its own instructions: "rate_type,
     * rate_value, and scope fields product_id/product_category_id/
     * cert_tier_id").
     *
     * @return array<string, mixed>
     */
    private function auditableFields(CommissionRule $commissionRule): array
    {
        return [
            'rate_type' => $commissionRule->rate_type?->value,
            'rate_value' => $commissionRule->rate_value,
            'product_id' => $commissionRule->product_id,
            'product_category_id' => $commissionRule->product_category_id,
            'cert_tier_id' => $commissionRule->cert_tier_id,
        ];
    }

    private function assertNoOverlap(
        int $companyId,
        int $certTierId,
        ?int $productId,
        ?int $productCategoryId,
        string $effectiveFrom,
        ?string $effectiveTo,
        ?int $excludeId = null,
    ): void {
        $overlaps = CommissionRule::query()
            ->where('company_id', $companyId)
            ->where('cert_tier_id', $certTierId)
            // Laravel translates where('col', null) into whereNull('col'),
            // so this correctly scopes to the SAME triple as the row being
            // created/updated — product-specific, category-specific, and
            // company-wide (both null) never collide with each other here.
            ->where('product_id', $productId)
            ->where('product_category_id', $productCategoryId)
            ->when($excludeId, fn ($query) => $query->where('id', '!=', $excludeId))
            ->where(function ($query) use ($effectiveFrom, $effectiveTo) {
                $query->where('effective_from', '<=', $effectiveTo ?? '9999-12-31')
                    ->where(function ($query) use ($effectiveFrom) {
                        $query->whereNull('effective_to')
                            ->orWhere('effective_to', '>=', $effectiveFrom);
                    });
            })
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'effective_from' => 'This cert tier + scope (product/category/company-default) already has a commission rule covering this date range.',
            ]);
        }
    }
}
