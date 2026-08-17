<?php

namespace App\Services\Academy;

use App\Models\CertTier;
use App\Models\Module;
use App\Models\ModuleLesson;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TASK-151 / ADR-031 §2.1 — bulk reordering.
 *
 * "A bulk endpoint per parent that takes the full ordered list of child ids
 * and rewrites `sort_order` in ONE TRANSACTION — never N separate PUTs,
 * which would leave the list half-reordered if the tab closed mid-way."
 *
 * Two parents, one shape:
 *   - Sections within a cert tier
 *   - Lessons within a Section
 *
 * The numeric `sort_order` field STAYS in the edit forms as the accessible
 * fallback (§2.1: "drag is added, not substituted"), so
 * Store/UpdateModuleRequest and Store/UpdateModuleLessonRequest are
 * untouched by this file.
 *
 * ===================================================================
 * WHY THE PAYLOAD MUST BE THE COMPLETE SIBLING SET
 * ===================================================================
 *
 * The ADR says "the FULL ordered list of child ids", and this class checks
 * it rather than hoping. A partial list is not a smaller reorder — it is a
 * corrupt one: the omitted siblings keep their old `sort_order`, so a
 * three-of-five payload writes 0,1,2 over rows whose absent neighbours are
 * still sitting on 0..4. The result is duplicate positions and an order
 * nobody asked for. A 422 that says "reload the page" is the honest answer
 * to a stale tab.
 *
 * ===================================================================
 * CONCURRENCY
 * ===================================================================
 *
 * No row lock, per ADR-031 §3: "two admins reordering the same Section
 * concurrently will have last-write-wins. Acceptable (it is a display
 * order, not money), and not worth a lock." The completeness check above
 * turns the common case — one of them had a stale list — into a 422
 * anyway, which is the part that would actually have hurt.
 */
class ModuleOrderService
{
    /**
     * Sections within a cert tier.
     *
     * @param  array<int, int>  $moduleIds  full ordered list
     * @return Collection<int, Module>
     */
    public function reorderSections(CertTier $certTier, array $moduleIds, User $actor): Collection
    {
        $ids = array_map('intval', array_values($moduleIds));

        /*
         * TenantScope applies here, and is the FIRST line of BR-6 defence:
         * for a Company Admin, another company's module id simply is not
         * returned, so it falls out at the count check below as "not found"
         * — the same 404-shaped answer §5 rule 5 asks for, expressed as a
         * 422 because the id arrived in a body rather than a URL.
         */
        $modules = Module::query()->whereIn('id', $ids)->get()->keyBy('id');

        if ($modules->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'module_ids' => 'พบรายการที่ไม่มีอยู่จริงหรือไม่ได้อยู่ในสิทธิ์ของคุณ',
            ]);
        }

        // FOREIGN PARENT (the smuggling test): every id must be a child of
        // the cert tier named in the URL. Without this, a valid id from
        // another cert tier would be silently renumbered against a list it
        // does not belong to.
        foreach ($modules as $module) {
            if ($module->cert_tier_id !== $certTier->id) {
                throw ValidationException::withMessages([
                    'module_ids' => 'มีรายการที่ไม่ได้อยู่ในระดับใบรับรองนี้',
                ]);
            }
        }

        /*
         * BR-6. A SUPER ADMIN is exempt from TenantScope, so for them the
         * count check above proves nothing about tenancy — this is the
         * check that does. Reordering across two companies in one payload
         * is not a permission question, it is a nonsense question: the two
         * lists are different lists.
         */
        $companyIds = $modules->pluck('company_id')->unique();

        if ($companyIds->count() !== 1) {
            throw ValidationException::withMessages([
                'module_ids' => 'ไม่สามารถจัดลำดับข้ามบริษัทในคำขอเดียวได้',
            ]);
        }

        $this->assertMayUpdateAll($modules, $actor);

        $siblingIds = Module::withoutGlobalScopes()
            ->where('cert_tier_id', $certTier->id)
            ->where('company_id', (int) $companyIds->first())
            ->pluck('id')
            ->all();

        $this->assertCompleteSet($ids, $siblingIds, 'module_ids');

        return $this->write($modules, $ids);
    }

    /**
     * Lessons within a Section.
     *
     * @param  array<int, int>  $lessonIds  full ordered list
     * @return Collection<int, ModuleLesson>
     */
    public function reorderLessons(Module $module, array $lessonIds, User $actor): Collection
    {
        if (! $actor->can('update', $module)) {
            throw new AuthorizationException;
        }

        $ids = array_map('intval', array_values($lessonIds));

        $lessons = ModuleLesson::query()->whereIn('id', $ids)->get()->keyBy('id');

        if ($lessons->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'lesson_ids' => 'พบบทเรียนที่ไม่มีอยู่จริงหรือไม่ได้อยู่ในสิทธิ์ของคุณ',
            ]);
        }

        // FOREIGN PARENT. The lesson routes are flat
        // (/module-lessons/{id}), so a lesson id from ANOTHER Section — of
        // the same company, therefore visible through TenantScope — is the
        // realistic smuggling case here, and only this check catches it.
        foreach ($lessons as $lesson) {
            if ($lesson->module_id !== $module->id) {
                throw ValidationException::withMessages([
                    'lesson_ids' => 'มีบทเรียนที่ไม่ได้อยู่ใน Section นี้',
                ]);
            }
        }

        $siblingIds = ModuleLesson::withoutGlobalScopes()
            ->where('module_id', $module->id)
            ->pluck('id')
            ->all();

        $this->assertCompleteSet($ids, $siblingIds, 'lesson_ids');

        return $this->write($lessons, $ids);
    }

    /**
     * @param  Collection<int, Module>  $modules
     */
    private function assertMayUpdateAll(Collection $modules, User $actor): void
    {
        foreach ($modules as $module) {
            // Policy per row (CLAUDE.md §5 rule 3). The Form Request already
            // established that this actor may author Academy content at all;
            // ModulePolicy::update is what ties that to THIS company.
            if (! $actor->can('update', $module)) {
                throw new AuthorizationException;
            }
        }
    }

    /**
     * @param  array<int, int>  $given
     * @param  array<int, int>  $siblings
     */
    private function assertCompleteSet(array $given, array $siblings, string $field): void
    {
        $givenSorted = $given;
        $siblingsSorted = array_map('intval', $siblings);
        sort($givenSorted);
        sort($siblingsSorted);

        if ($givenSorted !== $siblingsSorted) {
            throw ValidationException::withMessages([
                // Actionable: the only sane recovery from a stale list is to
                // reload it.
                $field => 'รายการที่ส่งมาไม่ครบทุกรายการในกลุ่มนี้ กรุณารีเฟรชหน้าแล้วลองใหม่',
            ]);
        }
    }

    /**
     * ADR-031 §2.1 — ONE transaction. Either the whole sibling set is
     * renumbered or none of it is; a half-applied order is the failure the
     * bulk endpoint exists to prevent.
     *
     * Positions are 0-based to match the existing `sort_order` default of 0
     * and the `orderBy('sort_order')` every read path already uses.
     *
     * @template TModel of Module|ModuleLesson
     *
     * @param  Collection<int, TModel>  $records  keyed by id
     * @param  array<int, int>  $orderedIds
     * @return Collection<int, TModel>
     */
    private function write(Collection $records, array $orderedIds): Collection
    {
        return DB::transaction(function () use ($records, $orderedIds) {
            foreach ($orderedIds as $position => $id) {
                $record = $records[$id];

                // Skip the write when nothing moved — keeps updated_at (and
                // therefore any "last edited" readout) honest about what an
                // admin actually changed.
                if ((int) $record->sort_order !== $position) {
                    $record->update(['sort_order' => $position]);
                }
            }

            return $records->sortBy('sort_order')->values();
        });
    }
}
