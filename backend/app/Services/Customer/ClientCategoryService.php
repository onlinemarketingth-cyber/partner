<?php

namespace App\Services\Customer;

use App\Models\ClientCategory;
use App\Models\Company;
use App\Models\User;

// TASK-056 Sprint P2 — BR-7: client segmentation is admin-editable config,
// never hardcoded in business logic. The 4 starter names below are a
// SEED DEFAULT the human confirmed 2026-07-29 ("ใช้ชุดเริ่มต้นทั่วไป
// (แนะนำ)") — every company can rename/add/delete freely via the Admin
// CRUD (ClientCategoryController); nothing in Client/Referral/Commission
// logic ever branches on these names.
class ClientCategoryService
{
    public const DEFAULT_NAMES = ['ลูกค้าใหม่', 'ลูกค้าประจำ', 'VIP', 'มีความเสี่ยงเลิกซื้อ'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): ClientCategory
    {
        return ClientCategory::create([
            'company_id' => $actor->isSuperAdmin() ? $data['company_id'] : $actor->company_id,
            'name' => $data['name'],
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ClientCategory $category, array $data): ClientCategory
    {
        $category->update($data);

        return $category;
    }

    /**
     * Self-healing default seed — called from
     * ClientCategoryController::index() so a company that predates this
     * feature (or a brand-new one) gets the starter set on first visit,
     * without needing a one-off artisan command. Gated on
     * `client_categories_seeded_at` (a one-way marker), NOT on "does the
     * company currently have zero categories" — a company can legitimately
     * have zero after an admin deletes every row on purpose, and that must
     * never be re-seeded out from under them. Fires at most once per company.
     */
    public function ensureDefaults(Company $company): void
    {
        if ($company->client_categories_seeded_at !== null) {
            return;
        }

        // Marker not set yet, but the company may already have category
        // rows from a path that doesn't go through here (factories in
        // tests, or a company that somehow predates both this feature AND
        // the migration's backfill). Mark it seeded WITHOUT adding the
        // starter set on top — otherwise a company with e.g. one row would
        // end up with that row plus 4 duplicate defaults.
        if (ClientCategory::withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            $company->forceFill(['client_categories_seeded_at' => now()])->save();

            return;
        }

        foreach (self::DEFAULT_NAMES as $index => $name) {
            ClientCategory::create([
                'company_id' => $company->id,
                'name' => $name,
                'sort_order' => $index,
            ]);
        }

        $company->forceFill(['client_categories_seeded_at' => now()])->save();
    }
}
