<?php

namespace App\Http\Resources;

use App\Services\Academy\LessonAccessGate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// ADR-009 — Module is now a "Section": pure grouping/ordering under a
// cert tier. Per-item content fields moved to ModuleLessonResource.
//
// ADR-031 (TASK-151) — the Section now carries the two release controls
// (§2.2 sequential, §2.3 drip) AND, new here, the SERVER-SIDE LESSON
// COUNTS both frontends should be using as their denominator (§2.4).
class ModuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'cert_tier' => $this->whenLoaded('certTier', fn () => [
                'id' => $this->certTier->id,
                'key' => $this->certTier->key,
                'name' => $this->certTier->name,
            ]),
            'product' => new ProductResource($this->whenLoaded('product')),
            'title' => $this->title,
            'sort_order' => $this->sort_order,
            'is_published' => $this->is_published,

            // ---- ADR-031 §2.2 / §2.3, the Section's release controls ----
            //
            // Both are exposed to LEARNERS as well as admins, deliberately.
            // They are course STRUCTURE ("this course is meant to be taken
            // in order", "this section opens later"), not the completion
            // THRESHOLDS ADR-028 §4 withheld — a learner who cannot see the
            // rule can only experience it as the app being broken.
            'enforce_sequential' => (bool) $this->enforce_sequential,
            'drip_days' => $this->drip_days,
            /*
             * ADR-031 §2.3/§3 — WHEN this Section opens for the CURRENT
             * learner, so the UI can say "เปิดในอีก 3 วัน" instead of an
             * unexplained padlock. Null when no drip is configured.
             *
             * Computed per requesting user; see LessonAccessGate::unlocksAt()
             * for the anchor and its `TODO: CONFIRM (business rule)`.
             */
            'unlocks_at' => $this->drip_days === null
                ? null
                : app(LessonAccessGate::class)->unlocksAt($this->resource, $user)?->toIso8601String(),

            /*
             * ---- ADR-031 §2.4, THE DENOMINATORS ----
             *
             * Until now the backend shipped no lesson total at all, so every
             * "X/Y บท" on both frontends was `lessons.length` computed
             * client-side (frontend/src/views/AcademyView.vue and
             * frontend-admin/src/views/AcademyManagementView.vue). That is
             * exactly the shape §2.4 breaks: an optional lesson counted in
             * the denominator leaves a learner stuck at "4/5" forever.
             *
             * Three counts rather than one, because the two audiences want
             * genuinely different numbers:
             *
             *   lesson_count           — everything shipped in `lessons`,
             *                            drafts included. The ADMIN's "this
             *                            Section has 12 lessons".
             *   required_lesson_count  — PUBLISHED and NOT optional. THE
             *                            LEARNER'S DENOMINATOR. ag-ui: this
             *                            is the one to divide by.
             *   optional_lesson_count  — PUBLISHED and optional. Lets the UI
             *                            say "+2 บทเสริม" beside the fraction
             *                            rather than hiding that they exist.
             *
             * `required_lesson_count` also excludes UNPUBLISHED lessons,
             * which fixes a second, pre-existing bug in the same stroke:
             * ModuleResource ships drafts (`is_published` is a field, not a
             * filter), so today's client-side `lessons.length` already
             * counts them.
             */
            'lesson_count' => $this->whenLoaded('lessons', fn () => $this->lessons->count()),
            'required_lesson_count' => $this->whenLoaded(
                'lessons',
                fn () => $this->lessons->filter(fn ($l) => $l->is_published && ! $l->is_optional)->count(),
            ),
            'optional_lesson_count' => $this->whenLoaded(
                'lessons',
                fn () => $this->lessons->filter(fn ($l) => $l->is_published && $l->is_optional)->count(),
            ),

            'lessons' => ModuleLessonResource::collection($this->whenLoaded('lessons')),
        ];
    }
}
