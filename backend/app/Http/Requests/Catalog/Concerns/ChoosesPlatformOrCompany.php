<?php

namespace App\Http\Requests\Catalog\Concerns;

use Illuminate\Validation\Rule;

/**
 * 2026-09-09 (human: "Super admin เพิ่มสินค้าแล้วแต่ไม่มี UI ตรงไหนแจ้งไว้ว่าจะ
 * บันทึกลงเฉพาะ company หรือ ใช้เป็น product กลาง ทำแบบเดียวกันกับทางแบรนด์
 * และหมวดหมู่").
 *
 * "Whose row is this?" — asked once, answered the same way for products,
 * brands and categories.
 *
 * ── WHY THE QUESTION HAD NO ANSWER ──
 *
 * ADR-040 made a row with `company_id NULL` mean "the platform owns this and
 * every company uses it", and the reads were updated everywhere. The WRITES
 * were not: all three Store requests still demanded a concrete company_id from
 * a Super Admin, so the only way a platform row could ever come into existence
 * was `catalog:promote-products` — a command. A Super Admin adding a product on
 * screen had no way to say what they were making, and no way to find out what
 * they had made.
 *
 * ── WHO MAY ──
 *
 * Super Admin only, and the flag is STRIPPED for everyone else rather than
 * rejected: a Company Admin is never shown the control, so a 422 about it
 * would answer a question they did not ask. Their row is always their own
 * company's, exactly as before.
 *
 * The Services re-check this. A Form Request guards one route; the platform
 * rows it creates are visible to every tenant on the system.
 */
trait ChoosesPlatformOrCompany
{
    /** Did this request ask for a platform-owned row, and may it? */
    protected function wantsPlatformRow(): bool
    {
        return $this->user()->isSuperAdmin() && $this->boolean('is_platform');
    }

    /**
     * Strip the flag for anybody who was never offered it. Call from
     * prepareForValidation().
     */
    protected function stripPlatformFlagUnlessSuperAdmin(): void
    {
        if ($this->user()?->isSuperAdmin()) {
            return;
        }

        $this->getInputSource()->remove('is_platform');
        $this->query->remove('is_platform');
    }

    /**
     * The two rules that go together: the flag itself, and a company_id that
     * a Super Admin must supply UNLESS they are making a platform row.
     *
     * @return array<string, mixed>
     */
    protected function ownershipRules(): array
    {
        return [
            'is_platform' => ['sometimes', 'boolean'],
            'company_id' => [
                Rule::requiredIf(fn () => $this->user()->isSuperAdmin() && ! $this->wantsPlatformRow()),
                'integer',
                'exists:companies,id',
            ],
        ];
    }

    /**
     * The company the new row belongs to. NULL means the platform owns it —
     * a real answer here, not a missing one.
     */
    protected function ownerCompanyId(): ?int
    {
        if ($this->wantsPlatformRow()) {
            return null;
        }

        return $this->user()->isSuperAdmin()
            ? ($this->integer('company_id') ?: null)
            : $this->user()->company_id;
    }
}
