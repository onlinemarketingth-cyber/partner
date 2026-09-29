<?php

namespace App\Services\Payment;

use App\Enums\PaymentAccountScope;
use App\Enums\PaymentProvider;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Order;
use App\Models\PlatformPaymentSetting;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * ADR-050 — the ONE answer to "which accounts does this order pay into".
 *
 * Owner, 2026-09-29: "ระบบชำระเงินใช้ค่าเดียวทุกบริษัท หรือแยกบริษัท" is a
 * platform switch the Super Admin sets. In "ใช้ค่าเดียวทุกบริษัท" every
 * channel — bank transfer, PromptPay and the card gateway — is the
 * platform's, for every company, with no per-company exception.
 *
 * ── THE ORDER DECIDES, NOT THE SWITCH ──
 *
 * The switch only decides which accounts a NEW order is stamped with
 * (orders.payment_account). Everything that shows a customer where to pay,
 * starts a card payment or verifies a webhook reads the ORDER's stamp. A
 * customer holding a pay link from before the switch keeps the bank account
 * that link showed them — they may already have transferred to it.
 *
 * Every reader of a destination goes through here. Reading companies.payment_*
 * or CompanyPaymentGatewayService::activeConfig() directly for an order would
 * quietly send a platform-mode customer to the company's account.
 */
class PaymentAccountService
{
    public function __construct(private readonly CompanyPaymentGatewayService $gateways) {}

    public function mode(): PaymentAccountScope
    {
        return PlatformPaymentSetting::current()->mode ?? PaymentAccountScope::default();
    }

    /** What OrderService stamps on an order it is creating right now. */
    public function scopeForNewOrder(): PaymentAccountScope
    {
        return $this->mode();
    }

    /**
     * Where a transfer for this order goes. Keys match the public pay page's
     * `company_payment` block, which it has read since ADR-017.
     *
     * @return array{bank_name: ?string, bank_account_number: ?string, bank_account_name: ?string, promptpay_id: ?string}
     */
    public function transferDestination(Order $order): array
    {
        return $this->destinationFor($this->scopeOf($order), $order->company);
    }

    /**
     * Where a transfer for a NOT-YET-CREATED order of this company would go.
     * For choosing the order's default payment method before it exists.
     *
     * @return array{bank_name: ?string, bank_account_number: ?string, bank_account_name: ?string, promptpay_id: ?string}
     */
    public function transferDestinationForNewOrder(?Company $company): array
    {
        return $this->destinationFor($this->scopeForNewOrder(), $company);
    }

    /**
     * The online gateway this order may be charged through, or null.
     *
     * @return array{provider: PaymentProvider, credentials: array<string, string>, is_live: bool}|null
     */
    public function onlineConfig(Order $order): ?array
    {
        if ($this->scopeOf($order) === PaymentAccountScope::Platform) {
            return $this->gateways->platformActiveConfig();
        }

        return $order->company === null ? null : $this->gateways->activeConfig($order->company);
    }

    /**
     * The credentials of ONE named provider in this order's scope — for the
     * webhook simulator, which must sign with the key the real webhook uses.
     *
     * @return array{provider: PaymentProvider, credentials: array<string, string>, is_live: bool}|null
     */
    public function configFor(Order $order, PaymentProvider $provider): ?array
    {
        if ($this->scopeOf($order) === PaymentAccountScope::Platform) {
            return $this->gateways->platformConfigFor($provider);
        }

        return $order->company === null ? null : $this->gateways->configFor($order->company, $provider);
    }

    /**
     * Switch every company's NEW orders between their own accounts and the
     * platform's.
     *
     * Refuses to switch to the platform until the platform can actually take
     * money both ways: a transfer destination AND a verified online gateway
     * switched on. Switching early would leave every company in the system
     * unable to take a card payment at the same moment.
     *
     * @throws ValidationException
     */
    public function setMode(PaymentAccountScope $mode, ?User $actor = null): PlatformPaymentSetting
    {
        $settings = PlatformPaymentSetting::current();
        $before = $settings->mode ?? PaymentAccountScope::default();

        if ($mode === PaymentAccountScope::Platform) {
            $missing = $this->platformReadinessProblems($settings);

            if ($missing !== []) {
                throw ValidationException::withMessages(['mode' => implode(' · ', $missing)]);
            }
        }

        if ($before === $mode && $settings->exists) {
            return $settings;
        }

        $settings->forceFill(['mode' => $mode->value])->save();

        // §6 — this moves where every company's money goes.
        AuditLog::create([
            'company_id' => null,
            'actor_user_id' => $actor?->id,
            'action' => 'platform_payment.mode_changed',
            'auditable_type' => PlatformPaymentSetting::class,
            'auditable_id' => $settings->id,
            'old_values' => ['mode' => $before->value],
            'new_values' => ['mode' => $mode->value],
            'ip_address' => request()?->ip(),
        ]);

        return $settings->refresh();
    }

    /**
     * Save the platform's own bank account / PromptPay.
     *
     * While every company uses the platform, the destination may not be
     * emptied: every open transfer instruction would point at nothing.
     *
     * @param  array{promptpay_id?: ?string, bank_name?: ?string, bank_account_number?: ?string, bank_account_name?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function saveTransferAccount(array $data, ?User $actor = null): PlatformPaymentSetting
    {
        $settings = PlatformPaymentSetting::current();
        $before = $this->auditableDestination($settings);

        $settings->fill(array_map(
            fn ($value) => filled($value) ? trim((string) $value) : null,
            array_intersect_key($data, array_flip(['promptpay_id', 'bank_name', 'bank_account_number', 'bank_account_name'])),
        ));

        if ($settings->mode === PaymentAccountScope::Platform && ! $settings->hasTransferDestination()) {
            throw ValidationException::withMessages([
                'bank_account_number' => 'ทุกบริษัทใช้บัญชีนี้อยู่ ต้องมีเลขบัญชีหรือพร้อมเพย์อย่างน้อยหนึ่งอย่าง',
            ]);
        }

        $settings->save();

        AuditLog::create([
            'company_id' => null,
            'actor_user_id' => $actor?->id,
            'action' => 'platform_payment.transfer_account_updated',
            'auditable_type' => PlatformPaymentSetting::class,
            'auditable_id' => $settings->id,
            'old_values' => $before,
            'new_values' => $this->auditableDestination($settings),
            'ip_address' => request()?->ip(),
        ]);

        return $settings->refresh();
    }

    /**
     * What is still missing before every company can be switched to the
     * platform. Empty = ready. Shown on the switch itself, so the Super Admin
     * sees why it is refused before pressing it.
     *
     * @return list<string>
     */
    public function platformReadinessProblems(?PlatformPaymentSetting $settings = null): array
    {
        $settings ??= PlatformPaymentSetting::current();
        $problems = [];

        if (! $settings->hasTransferDestination()) {
            $problems[] = 'ยังไม่ได้ตั้งบัญชีรับเงินกลาง (เลขบัญชีหรือพร้อมเพย์)';
        }

        if ($this->gateways->platformActiveConfig() === null) {
            $problems[] = 'ยังไม่ได้เลือกช่องทางออนไลน์กลางที่ตรวจสอบผ่านแล้ว (Omise หรือ Stripe)';
        }

        return $problems;
    }

    public function scopeOf(Order $order): PaymentAccountScope
    {
        return $order->payment_account ?? PaymentAccountScope::default();
    }

    /**
     * @return array{bank_name: ?string, bank_account_number: ?string, bank_account_name: ?string, promptpay_id: ?string}
     */
    private function destinationFor(PaymentAccountScope $scope, ?Company $company): array
    {
        if ($scope === PaymentAccountScope::Platform) {
            $settings = PlatformPaymentSetting::current();

            return [
                'bank_name' => $settings->bank_name,
                'bank_account_number' => $settings->bank_account_number,
                'bank_account_name' => $settings->bank_account_name,
                'promptpay_id' => $settings->promptpay_id,
            ];
        }

        return [
            'bank_name' => $company?->payment_bank_name,
            'bank_account_number' => $company?->payment_bank_account_number,
            'bank_account_name' => $company?->payment_bank_account_name,
            'promptpay_id' => $company?->payment_promptpay_id,
        ];
    }

    /**
     * The destination as the audit log keeps it. Where the platform's money
     * goes is exactly what an audit of this setting is for, so the account
     * number is recorded in full; it is a destination, not a credential.
     *
     * @return array<string, ?string>
     */
    private function auditableDestination(PlatformPaymentSetting $settings): array
    {
        return [
            'promptpay_id' => $settings->promptpay_id,
            'bank_name' => $settings->bank_name,
            'bank_account_number' => $settings->bank_account_number,
            'bank_account_name' => $settings->bank_account_name,
        ];
    }
}
