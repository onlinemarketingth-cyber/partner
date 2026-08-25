<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One company's stored credentials for one payment provider (ADR-027 §3).
 *
 * A row is STORED CREDENTIALS, not an active configuration. Which provider a
 * company is actually using is `companies.payment_provider` — a single
 * column, in which two simultaneously-active gateways cannot be written down
 * at all (the human's rule, 2026-08-22).
 *
 * Keeping a disabled provider's row is deliberate: switching to Omise and
 * back must not mean re-typing secrets that are printed nowhere.
 *
 * `credentials` carries the `encrypted` cast, the same treatment
 * users.bank_account_number (TASK-044) and platform_mail_settings.password
 * already get. It must NEVER reach a response body — see
 * CompanyPaymentGatewayService, which is the only thing allowed to read it
 * and never returns the secret half.
 */
class CompanyPaymentGatewaySetting extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'provider',
        'credentials',
        'is_live',
        'verified_at',
        'verified_note',
    ];

    /**
     * `credentials` is deliberately absent from the array/JSON form of this
     * model. Every other protection here is a rule somebody has to follow;
     * this one holds even when a future controller returns the model directly.
     *
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            // 'encrypted:array' — encrypted at rest, an array in PHP. The
            // shape inside is per provider and is validated against that
            // driver's credentialFields() declaration, not by the database.
            'credentials' => 'encrypted:array',
            'is_live' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Have these credentials ever been proven against the provider's API?
     *
     * The gate on activation: a company may not switch to a provider that has
     * never passed. Wrong keys otherwise fail at the customer, silently, one
     * payment at a time — on somebody else's screen, so nobody here finds out.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
