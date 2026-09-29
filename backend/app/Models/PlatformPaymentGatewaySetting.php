<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-050 — the platform's stored credentials for one payment provider.
 *
 * CompanyPaymentGatewaySetting without the company: same encrypted cast,
 * same hidden `credentials`, same "a row is stored credentials, not an
 * active choice" rule (the choice is platform_payment_settings.payment_provider).
 * Read and written only by CompanyPaymentGatewayService.
 */
class PlatformPaymentGatewaySetting extends Model
{
    protected $fillable = [
        'provider',
        'credentials',
        'is_live',
        'verified_at',
        'verified_note',
    ];

    /** @var list<string> */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'credentials' => 'encrypted:array',
            'is_live' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
