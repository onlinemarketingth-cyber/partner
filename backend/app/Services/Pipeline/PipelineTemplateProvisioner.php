<?php

namespace App\Services\Pipeline;

use App\Enums\PipelineStage;
use App\Models\Company;
use App\Models\PipelineTemplate;
use App\Models\PipelineTemplateStage;
use Illuminate\Support\Facades\DB;

/**
 * ADR-026 §3.1 — provisions the two SYSTEM pipeline templates for a company.
 *
 * WHY THIS EXISTS AS A SERVICE (ag-lead, 2026-08-08, TASK-134a review)
 * ---------------------------------------------------------------------
 * This logic started life inside PipelineTemplateSeeder, which meant the
 * templates only ever existed for companies that were present when someone
 * last ran `php artisan db:seed`. `CompanyService::create()` writes a bare
 * `companies` row and nothing else, so **every company created through the
 * Admin UI after the TASK-132 deploy would have had zero templates**.
 *
 * That is not a cosmetic gap. PipelineTemplateResolver fails closed by
 * design (ADR-026 §3.3) — no template resolves, so no referral can advance
 * and no order can be confirmed. A brand-new tenant would have looked
 * perfectly healthy right up until their first sale, and then silently
 * refused to close it. Seeders are a development convenience; a tenant's
 * ability to make money is not.
 *
 * So: one definition of the two journeys, one write path, called from BOTH
 * the seeder (existing companies, re-runnable) and company creation (every
 * future company). Adding a third caller is fine; forking the definitions
 * is not.
 *
 * These sequences are STRUCTURE, not guessed business values — both are
 * written down verbatim in CLAUDE.md §4.3 and ADR-026 §3.1, so defining
 * them in code is not a BR-7 violation. Which template a given product
 * actually uses remains entirely the admin's call.
 */
class PipelineTemplateProvisioner
{
    public function __construct(private PipelineTemplateResolver $resolver) {}

    /**
     * The system templates, as data. Order within each list IS the journey.
     *
     * @return array<string, array{name: string, stages: list<PipelineStage>}>
     */
    public static function systemTemplates(): array
    {
        return [
            // CLAUDE.md §4.3's original five stages, verbatim and in order.
            // Still the correct journey for every product that genuinely
            // involves a doctor meeting — it merely stops being the only
            // one. Also the resolver's final fail-safe (ADR-026 §3.3),
            // which is why EVERY company must have it.
            PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT => [
                'name' => 'Medical Package (default)',
                'stages' => [
                    PipelineStage::CompleteRegistered,
                    PipelineStage::WaitingAppointment,
                    PipelineStage::Finish1stDoctorMeeting,
                    PipelineStage::CompletePayment,
                    PipelineStage::OngoingNextMeeting,
                ],
            ],
            // The "pay from a shared link, no medical component" journey.
            PipelineTemplate::KEY_DIRECT_SALE_DEFAULT => [
                'name' => 'Direct Sale (default)',
                'stages' => [
                    PipelineStage::CompleteRegistered,
                    PipelineStage::CompletePayment,
                ],
            ],
        ];
    }

    /**
     * Idempotent — safe to call on a company that already has them.
     */
    public function provision(Company $company): void
    {
        foreach (self::systemTemplates() as $key => $definition) {
            $this->provisionTemplate($company->id, $key, $definition['name'], $definition['stages'], true);
        }
    }

    /**
     * 2026-09-09 — the same two journeys, owned by the PLATFORM.
     *
     * A journey with no company is usable by every company, exactly like a
     * platform brand or category (ADR-040). These are what a สินค้ากลาง
     * points at: one journey that travels with the product, rather than a
     * different one resolved per company from whatever each had configured.
     *
     * Idempotent, and independent of any company — safe to call from the
     * seeder on every run.
     */
    public function provisionPlatform(): void
    {
        foreach (self::systemTemplates() as $key => $definition) {
            $this->provisionTemplate(null, $key, $definition['name'], $definition['stages'], true);
        }
    }

    /**
     * The platform journey that matches a company's one, creating it if
     * this is the first time anyone has asked.
     *
     * Used when a product is promoted to the platform: its journey has to
     * come with it, and a company-owned template cannot (a shared product
     * pointing at one company's row would be that company deciding the
     * journey for everybody else — BR-6 in spirit if not in letter).
     *
     * MATCHED BY STAGES, NOT BY NAME. Two journeys called "Direct Sale" that
     * differ by a step are two journeys; two called different things with the
     * same ordered stages are one. The `key` is only how the row is found
     * again cheaply, which is why a clash on it falls back to a suffixed key
     * rather than quietly reusing a journey that is not the same journey.
     */
    public function platformEquivalentOf(PipelineTemplate $template): PipelineTemplate
    {
        $stages = $template->stageSequence();
        $existing = PipelineTemplate::withoutGlobalScopes()
            ->with('stages')
            ->whereNull('company_id')
            ->where('key', $template->key)
            ->first();

        if ($existing && $existing->stageSequence() == $stages) {
            return $existing;
        }

        return $this->provisionTemplate(
            null,
            // A different journey already holds this key on the platform, so
            // this one takes a key of its own rather than overwriting it.
            $existing ? $template->key.'_'.$template->id : $template->key,
            $template->name,
            $stages,
            // Only the two seeded journeys are `is_system`; a company's own
            // journey does not become one by being promoted.
            false,
        );
    }

    /**
     * @param  int|null  $companyId  null = owned by the PLATFORM, usable by every company
     * @param  list<PipelineStage>  $stages
     */
    private function provisionTemplate(?int $companyId, string $key, string $name, array $stages, bool $isSystem): PipelineTemplate
    {
        // §6 "never trust the client" applies to a seeder and to an internal
        // service call too — these are write paths. The invariants (must
        // contain complete_registered first + complete_payment, post-sale
        // stages only after payment, ongoing_next_meeting only last, each
        // stage at most once) live in the Resolver so EVERY write path is
        // checked, not just the ones that happen to go through a Form
        // Request. If someone edits the sequences above into something
        // invalid, this throws here rather than shipping a broken tenant.
        $this->resolver->assertValidStageSequence($stages);

        return DB::transaction(function () use ($companyId, $key, $name, $stages, $isSystem) {
            // TenantScope is bypassed EXPLICITLY rather than relied upon:
            // this runs both unauthenticated (seeder, where the scope
            // no-ops) and as a Super Admin creating a company they are not
            // a member of (where it also no-ops, but for a different
            // reason). Depending on two different accidents of context
            // producing the same result is not a guarantee — company_id is
            // always stated outright instead. BR-6.
            $template = PipelineTemplate::withoutGlobalScopes()
                ->firstOrCreate(
                    ['company_id' => $companyId, 'key' => $key],
                    ['name' => $name, 'is_system' => $isSystem],
                );

            foreach ($stages as $position => $stage) {
                PipelineTemplateStage::withoutGlobalScopes()
                    ->updateOrCreate(
                        ['pipeline_template_id' => $template->id, 'stage' => $stage->value],
                        ['company_id' => $companyId, 'position' => $position],
                    );
            }

            // Prune anything a previous revision of these definitions left
            // behind, so re-running never yields a template that is the
            // UNION of two versions of the same journey.
            PipelineTemplateStage::withoutGlobalScopes()
                ->where('pipeline_template_id', $template->id)
                ->whereNotIn('stage', array_map(fn (PipelineStage $stage) => $stage->value, $stages))
                ->delete();

            return $template->load('stages');
        });
    }
}
