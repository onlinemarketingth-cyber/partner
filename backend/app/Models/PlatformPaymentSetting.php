<?php

namespace App\Models;

use App\Enums\PaymentAccountScope;
use App\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-050 — the platform's payment switch and its own transfer destination.
 *
 * One row, read through PaymentAccountService only. No TenantScope: it
 * belongs to no company, and only a Super Admin can reach the screen that
 * edits it (Ability::SettingsPaymentGatewayUpdate).
 *
 * `payment_provider` is deliberately NOT fillable, for the same reason
 * companies.payment_provider is not: which gateway takes the money moves
 * only through the one method that checks it was verified first.
 */
class PlatformPaymentSetting extends Model
{
    protected $fillable = [
        'promptpay_id',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
    ];

    protected $attributes = [
        'mode' => 'company',
    ];

    protected function casts(): array
    {
        return [
            'mode' => PaymentAccountScope::class,
        ];
    }

    /**
     * The one row, or an unsaved default (mode 'company') when none exists.
     *
     * Never cached: this decides where money goes, and a stale answer after
     * a Super Admin switches is money sent to the old account.
     */
    public static function current(): self
    {
        return self::query()->first() ?? new self;
    }

    public function activeProvider(): ?PaymentProvider
    {
        $provider = PaymentProvider::tryFrom((string) $this->payment_provider);

        return $provider === null || $provider->requiresHumanVerification() ? null : $provider;
    }

    /** Has somewhere for a transfer to land: a bank account number or a PromptPay id. */
    public function hasTransferDestination(): bool
    {
        return filled($this->bank_account_number) || filled($this->promptpay_id);
    }
}
