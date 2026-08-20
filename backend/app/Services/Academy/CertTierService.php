<?php

namespace App\Services\Academy;

use App\Models\CertTier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * TASK-221 — cert tiers become editable through the Admin UI.
 *
 * Until now `cert_tiers` was read-only config with no create path at all:
 * `GET /cert-tiers` was the only route, and the rows were written by
 * CatalogSeeder, a DEV-ONLY seeder that also inserts placeholder brands,
 * products and commission rules. Production therefore ran with ZERO tiers
 * (verified 2026-08-20: `{"data":[]}`), which silently made the Academy
 * Section form unsavable — its Cert tier <select> is required and had
 * nothing to select.
 *
 * BR-7: this class holds NO tier values. Names, keys, order and the
 * mandatory flag all come from the admin. CLAUDE.md §2's
 * "Basic (mandatory) -> Intermediate -> High" stays documentation, not a
 * default this code writes on anyone's behalf.
 *
 * GLOBAL, NOT PER-COMPANY. `cert_tiers` has no company_id (see the table's
 * own migration), so every company shares one list — which is exactly why
 * writes are Super-Admin-only in CertTierPolicy: a Company Admin renaming
 * a tier would rename it for every tenant on the platform.
 */
class CertTierService
{
    /**
     * Every FK pointing at cert_tiers, as [table, column, human label].
     *
     * All of them are `restrictOnDelete`, so the database already refuses a
     * delete that would orphan a row. Left to itself that surfaces as a raw
     * QueryException — a 500 with an SQLSTATE in it. This list turns the
     * same refusal into a sentence naming WHAT is still using the tier,
     * which is the only version an admin can act on.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const REFERENCES = [
        ['user_certifications', 'cert_tier_id', 'ตัวแทนที่สอบผ่านระดับนี้'],
        ['commission_ledger', 'cert_tier_id_at_time', 'รายการค่าคอมมิชชั่นที่บันทึกไว้'],
        ['commission_rules', 'cert_tier_id', 'อัตราค่าคอมมิชชั่น'],
        ['commission_override_rules', 'manager_cert_tier_id', 'อัตราค่าคอมหัวหน้าทีม'],
        ['modules', 'cert_tier_id', 'โมดูลใน Academy'],
        ['exams', 'cert_tier_id', 'แบบทดสอบ'],
        ['agent_promotions', 'target_cert_tier_id', 'Promotion ที่เจาะระดับนี้'],
        ['announcements', 'target_cert_tier_id', 'ข่าวสารที่เจาะระดับนี้'],
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CertTier
    {
        return CertTier::create([
            'key' => $data['key'],
            'name' => $data['name'],
            // Not defaulted to 0: two tiers sharing sort_order 0 makes the
            // "highest passed tier" queries (User::highestPassedCertTierId,
            // AgentPromotion's and-above targeting) order arbitrarily, and
            // the symptom is a wrong commission tier, not a wrong list.
            'sort_order' => $data['sort_order'] ?? $this->nextSortOrder(),
            'is_mandatory' => $data['is_mandatory'] ?? false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CertTier $certTier, array $data): CertTier
    {
        /*
         * `key` IS EDITABLE, deliberately, but only while nothing depends
         * on the tier — the same test as delete. It is a stable handle that
         * seeders and support queries match on ('basic'), so changing it
         * under live data would silently break `CertTier::where('key', ...)`
         * call sites that cannot be found by looking at this table.
         *
         * Renaming (`name`) is always allowed: it is a label.
         */
        if (array_key_exists('key', $data) && $data['key'] !== $certTier->key) {
            $inUse = $this->usageSummary($certTier);

            if ($inUse !== []) {
                throw ValidationException::withMessages([
                    'key' => 'เปลี่ยนรหัส (key) ไม่ได้ เพราะมีข้อมูลผูกอยู่แล้ว — '
                        .implode(' · ', $inUse).' · เปลี่ยนได้เฉพาะชื่อที่แสดงผล',
                ]);
            }
        }

        $certTier->update(array_intersect_key($data, array_flip([
            'key', 'name', 'sort_order', 'is_mandatory',
        ])));

        return $certTier->fresh();
    }

    /**
     * @throws ValidationException when anything still points at this tier
     */
    public function delete(CertTier $certTier): void
    {
        $inUse = $this->usageSummary($certTier);

        if ($inUse !== []) {
            throw ValidationException::withMessages([
                'cert_tier' => 'ลบระดับนี้ไม่ได้ เพราะยังมีข้อมูลผูกอยู่: '
                    .implode(' · ', $inUse)
                    .' — ย้ายหรือลบข้อมูลเหล่านั้นก่อน แล้วจึงลบระดับนี้ได้',
            ]);
        }

        $certTier->delete();
    }

    /**
     * Human-readable list of what is still using this tier — empty when
     * nothing is.
     *
     * Uses the query builder, not relations: most of these tables have no
     * relation declared on CertTier, and adding eight of them just to count
     * rows would put the delete guard's correctness at the mercy of a model
     * file nobody reads when adding the ninth FK.
     *
     * @return list<string>
     */
    public function usageSummary(CertTier $certTier): array
    {
        $found = [];

        foreach (self::REFERENCES as [$table, $column, $label]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $count = DB::table($table)->where($column, $certTier->id)->count();

            if ($count > 0) {
                $found[] = "{$label} {$count} รายการ";
            }
        }

        return $found;
    }

    private function nextSortOrder(): int
    {
        return (int) CertTier::max('sort_order') + 1;
    }
}
