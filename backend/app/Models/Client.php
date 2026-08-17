<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Config;

/**
 * Customer — ERD-001 §"Customer" (rev. 3), CLAUDE.md §2 "Client". Peer
 * domain to Agent and Product catalog under Tenancy. PDPA-sensitive
 * (Section 6): health_notes and national_id are encrypted at rest.
 */
class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        // TASK-049 — keep the blind index in lockstep with national_id.
        // Because national_id is 'encrypted' (unsearchable), every write
        // that touches it re-derives the deterministic HMAC hash used by
        // the /clients exact-match search. Done here (not in a Service)
        // so it's impossible to persist a national_id whose hash is stale
        // — no matter which call site (Agent Portal create, Admin edit,
        // seeder, test) sets the value.
        static::saving(function (Client $client) {
            if ($client->isDirty('national_id')) {
                $client->national_id_hash = self::hashNationalId($client->national_id);
            }
        });
    }

    protected $fillable = [
        'company_id',
        'referring_agent_id',
        'name',
        'phone',
        'email',
        'national_id',
        'consent_given_at',
        'health_notes',
        'status',
        'lead_source',
        'client_category_id',
        'date_of_birth',
        'address',
        'province',
        'occupation',
    ];

    // national_id_hash is derived, never mass-assigned — kept out of
    // $fillable so a client can't spoof the blind index independently of
    // the encrypted value it's supposed to mirror.
    protected function casts(): array
    {
        return [
            'consent_given_at' => 'datetime',
            'health_notes' => 'encrypted', // PDPA — Section 6
            'national_id' => 'encrypted', // PDPA — Section 6 (TASK-049)
            'status' => ClientStatus::class,
            'date_of_birth' => 'date',
        ];
    }

    /**
     * TASK-049 — deterministic blind index for exact-match search over the
     * encrypted national_id. Digits-only normalization first (so a value
     * typed with spaces/dashes still matches), then HMAC-SHA256 keyed by
     * APP_KEY. Returns null for an empty value so a client with no
     * national ID has a null hash (and never collides with another).
     */
    public static function hashNationalId(?string $nationalId): ?string
    {
        $digits = $nationalId === null ? '' : preg_replace('/\D/', '', $nationalId);
        if ($digits === '') {
            return null;
        }

        return hash_hmac('sha256', $digits, (string) Config::get('app.key'));
    }

    /**
     * TASK-049 — national_id masked to only the last 4 digits (e.g.
     * "*********1234"), the single source of truth for both ClientResource
     * (JSON masking for non-privileged viewers) and any audit-log write.
     */
    public function maskedNationalId(): ?string
    {
        return self::maskNationalId($this->national_id);
    }

    public static function maskNationalId(?string $nationalId): ?string
    {
        if ($nationalId === null || $nationalId === '') {
            return null;
        }

        $len = mb_strlen($nationalId, 'UTF-8');
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4).mb_substr($nationalId, -4, null, 'UTF-8');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function referringAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referring_agent_id');
    }

    /** @return HasMany<ClientDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ClientDocument::class);
    }

    /** @return HasMany<Referral, $this> */
    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class);
    }

    /** @return HasMany<ClientActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(ClientActivity::class);
    }

    /** @return BelongsTo<ClientCategory, $this> TASK-056 Sprint P2 — segmentation (BR-7). */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ClientCategory::class, 'client_category_id');
    }
}
