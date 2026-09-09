<?php

namespace App\Models;

use App\Enums\PipelineStage;
use App\Models\Scopes\SharedOrTenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-026 (TASK-132) — a named, ordered SUBSET of the PipelineStage
 * vocabulary. BR-7 config: the sequence of stages a customer walks
 * through is a business value, so an admin owns it, not the code.
 *
 * CLAUDE.md §4.3 (as amended 2026-08-08): every template must contain
 * complete_registered (entry) and complete_payment — the latter because
 * BR-4 fires commission there and nowhere else, so a template without it
 * would be a silent commission outage. Enforced in
 * PipelineTemplateResolver::assertValidStageSequence(), not here.
 */
class PipelineTemplate extends Model
{
    use HasFactory;

    /**
     * The two seeded, is_system templates (ADR-026 §3.1). Constants
     * rather than literals per §7 "no magic strings" — the resolver's
     * final fail-safe, the seeder, and TASK-134's data backfill all have
     * to agree on these exact keys.
     */
    public const KEY_MEDICAL_PACKAGE_DEFAULT = 'medical_package_default';

    public const KEY_DIRECT_SALE_DEFAULT = 'direct_sale_default';

    /*
     * 2026-09-09 — SharedOrTenantScope, not TenantScope.
     *
     * `company_id` is nullable here now: a journey with no company is owned
     * by the PLATFORM and used by every company, exactly like a platform
     * brand or category (ADR-040). Plain TenantScope (`where company_id =
     * :own`) silently excludes NULL, so a Company Admin would have been
     * offered none of them and a shared product's own journey would have
     * been invisible to the very screens that must show it. Company A still
     * cannot see company B's — that guarantee is unchanged.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new SharedOrTenantScope);
    }

    protected $fillable = [
        'company_id',
        // Stable machine handle (resolver/seeder look up by this); `name`
        // is the renameable human label.
        'key',
        'name',
        // Seeded platform template. TASK-134 makes these copy-only in
        // the admin UI; nothing in TASK-132 keys off it yet.
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The template's stages, always in journey order. Ordering lives on
     * the relation (same idiom as Product::specs()/media()) so no caller
     * can accidentally read an unordered stage list and derive a wrong
     * "next stage" from it.
     *
     * @return HasMany<PipelineTemplateStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(PipelineTemplateStage::class)->orderBy('position');
    }

    /**
     * The ordered stage sequence as plain enum cases — what
     * PipelineService (TASK-133) actually needs to answer "what comes
     * after X on THIS referral's journey".
     *
     * @return list<PipelineStage>
     */
    public function stageSequence(): array
    {
        return $this->stages->map(fn (PipelineTemplateStage $stage) => $stage->stage)->values()->all();
    }
}
