<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-161 §3.2 — a named snapshot of a company's colour surface.
 *
 * §5 rules 1–2 / BR-6: business table → `company_id` + the shared
 * TenantScope, no exception for "it's only colours". Combined with
 * ThemePresetPolicy this is what makes company A's preset unreachable
 * (list AND apply AND rename AND delete) from company B — the scope turns
 * a guessed id into a 404 at route-model-binding time, the Policy turns a
 * Super-Admin-visible one into a 403 for the wrong role.
 *
 * `colors` is a whitelisted map written ONLY by ThemePresetService from
 * the company's own already-validated company_theme_settings row — never
 * from a client-supplied payload (§3.2).
 */
class ThemePreset extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * TASK-164 §1 — `is_system` and `key` are fillable because the only
     * writer of either is ThemePresetService's provisioning path, which
     * builds the array itself. No Form Request accepts them, so no client
     * can mass-assign a preset into being undeletable (or claim a seeded
     * key and block the platform's own palette).
     */
    protected $fillable = [
        'company_id',
        'name',
        'is_system',
        'key',
        'colors',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'colors' => 'array',
            // Without this the SQLite/MySQL 0|1 arrives as an int and the
            // read-only guard (`! $preset->is_system`) would still work by
            // accident — but ThemePresetResource would ship 0/1 to a
            // frontend that checks `preset.is_system === true`.
            'is_system' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
