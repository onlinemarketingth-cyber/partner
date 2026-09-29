<?php

namespace App\Services\Payment;

use App\Enums\PaymentProvider;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\PaymentWebhookDeliveryStat;
use App\Models\User;
use App\Notifications\WebhookSignatureRejectedNotification;
use App\Services\Payment\Gateways\GatewayException;
use App\Services\Payment\Gateways\InspectsWebhookEndpoints;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * 2026-09-29 — "is this payment account's webhook actually working?"
 *
 * Owner: "การตั้งค่า stripe ในบริษัทแยกกันมีปัญหาเรื่อง web hook ไม่ตรงกัน
 * สามารถขึ้นแจ้งเตือนมีปุ่มทดสอบ webhook ได้ไหม ถ้าได้ทำทั้ง stripe กับ omise"
 * — and, asked where the warning should appear: on the payment screen AND to
 * the Super Admins.
 *
 * Two kinds of evidence, because no provider lets us read the signing secret
 * back:
 *
 *   what ARRIVED   every account, every provider: webhooks accepted, and
 *                  webhooks refused for a bad signature (counted per day in
 *                  payment_webhook_delivery_stats; a refused body is never
 *                  stored, it is unverified).
 *   what is SET UP only where the provider's API can say (Stripe: URL,
 *                  enabled, events). Omise has no such API.
 *
 * An "account" is a company's own (owner 'company:<id>') or the platform's
 * (owner 'platform', ADR-050). NULL company below means the platform.
 */
class WebhookHealthService
{
    /** How far back "recently" reaches for refusals and deliveries. */
    private const WINDOW_DAYS = 7;

    /** A verified gateway that has heard nothing for this long is worth a look. */
    private const SILENCE_WARNING_HOURS = 24;

    public function __construct(private readonly PaymentGatewayRegistry $registry) {}

    // ── Recording (called by PaymentWebhookController) ───────────────────

    public function recordAccepted(?Company $company, PaymentProvider $provider): void
    {
        $this->bump($company, $provider, 'accepted_count', 'last_accepted_at');
    }

    /**
     * A webhook that failed its signature check.
     *
     * The FIRST refusal of the day for an account also emails the Super
     * Admins — once a day, so a stream of forged requests cannot become a
     * stream of emails.
     */
    public function recordRejected(?Company $company, PaymentProvider $provider): void
    {
        $row = $this->bump($company, $provider, 'rejected_count', 'last_rejected_at');

        if ($row !== null && $row->rejected_count === 1) {
            $this->notifySuperAdmins($company, $provider);
        }
    }

    // ── Reading ──────────────────────────────────────────────────────────

    /**
     * What arrived recently. Cheap: one query, no network — so the settings
     * screen can show it on every load.
     *
     * @return array{accepted_recent: int, rejected_recent: int, last_accepted_at: ?string, last_rejected_at: ?string, window_days: int}
     */
    public function deliveries(?Company $company, PaymentProvider $provider): array
    {
        $rows = PaymentWebhookDeliveryStat::query()
            ->where('owner_key', $this->ownerKey($company))
            ->where('provider', $provider->value)
            ->get();

        $recent = $rows->filter(fn (PaymentWebhookDeliveryStat $r) => $r->day->gte(today()->subDays(self::WINDOW_DAYS - 1)));

        return [
            'accepted_recent' => (int) $recent->sum('accepted_count'),
            'rejected_recent' => (int) $recent->sum('rejected_count'),
            // Ever, not only recently: "never received one" is the finding.
            'last_accepted_at' => $rows->max('last_accepted_at')?->toIso8601String(),
            'last_rejected_at' => $rows->max('last_rejected_at')?->toIso8601String(),
            'window_days' => self::WINDOW_DAYS,
        ];
    }

    /**
     * Problems visible from deliveries alone — shown on the card without
     * pressing anything.
     *
     * @param  array{accepted_recent: int, rejected_recent: int, last_accepted_at: ?string, last_rejected_at: ?string, window_days: int}  $deliveries
     * @return list<array{level: string, code: string, message: string}>
     */
    public function deliveryProblems(array $deliveries, ?Carbon $verifiedAt): array
    {
        $problems = [];
        $lastAccepted = $deliveries['last_accepted_at'] ? Carbon::parse($deliveries['last_accepted_at']) : null;
        $lastRejected = $deliveries['last_rejected_at'] ? Carbon::parse($deliveries['last_rejected_at']) : null;

        if ($deliveries['rejected_recent'] > 0) {
            // Refusals that are older than the last good delivery have most
            // likely been fixed by re-entering the secret; still worth a line.
            $fixedSince = $lastAccepted !== null && $lastRejected !== null && $lastAccepted->gt($lastRejected);

            $problems[] = [
                'level' => $fixedSince ? 'warning' : 'error',
                'code' => 'signature_rejected',
                'message' => "มี webhook ถูกปฏิเสธเพราะลายเซ็นไม่ตรง {$deliveries['rejected_recent']} ครั้งใน {$deliveries['window_days']} วัน"
                    .($fixedSince
                        ? ' — หลังจากนั้นได้รับ webhook ปกติแล้ว น่าจะแก้ไปแล้ว'
                        : ' — secret ในระบบน่าจะไม่ตรงกับใน Dashboard ให้คัดลอก webhook secret ใหม่มาบันทึก (หรืออาจมีคนส่ง webhook ปลอม)'),
            ];
        }

        if ($lastAccepted === null && $verifiedAt !== null && $verifiedAt->lt(now()->subHours(self::SILENCE_WARNING_HOURS))) {
            $problems[] = [
                'level' => 'warning',
                'code' => 'never_received',
                'message' => 'ตั้งค่าไว้เกิน 1 วันแล้ว แต่ยังไม่เคยได้รับ webhook เลย — ตรวจ URL ใน Dashboard หรือลองชำระเงินทดสอบ 1 รายการ',
            ];
        }

        return $problems;
    }

    /**
     * The full check behind the "ตรวจสอบ webhook" button: deliveries, plus —
     * where the provider can say — how its dashboard is actually set up.
     *
     * @param  array{provider: PaymentProvider, credentials: array<string, string>, is_live: bool}  $config
     * @return array<string, mixed>
     */
    public function check(?Company $company, array $config, ?Carbon $verifiedAt): array
    {
        $provider = $config['provider'];
        $deliveries = $this->deliveries($company, $provider);
        $problems = $this->deliveryProblems($deliveries, $verifiedAt);
        $expectedPath = $this->expectedPath($company, $provider);

        $driver = $this->registry->driver($provider);
        $setup = ['checked' => false, 'endpoint_url' => null, 'other_urls' => [], 'missing_events' => []];

        if ($driver instanceof InspectsWebhookEndpoints) {
            try {
                $setup = $this->inspect($driver, $config['credentials'], $expectedPath);
                array_push($problems, ...$this->setupProblems($setup, $provider));
            } catch (GatewayException $e) {
                $problems[] = ['level' => 'warning', 'code' => 'setup_unreadable', 'message' => 'ตรวจการตั้งค่าใน Dashboard ไม่ได้: '.$e->getMessage()];
            }
        }

        $hasError = collect($problems)->contains('level', 'error');

        return [
            'provider' => $provider->value,
            'expected_url' => $this->expectedUrl($company, $provider),
            'setup' => $setup,
            // Omise's dashboard cannot be read, so the screen says why only
            // half was checked rather than implying a full pass.
            'setup_checkable' => $driver instanceof InspectsWebhookEndpoints,
            'deliveries' => $deliveries,
            'problems' => $problems,
            'status' => $hasError ? 'error' : ($problems === [] ? 'ok' : 'warning'),
        ];
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $credentials
     * @return array{checked: bool, endpoint_url: ?string, enabled?: bool, other_urls: list<string>, missing_events: list<string>}
     */
    private function inspect(InspectsWebhookEndpoints $driver, array $credentials, string $expectedPath): array
    {
        $endpoints = $driver->webhookEndpoints($credentials);
        $ours = null;
        $others = [];

        foreach ($endpoints as $endpoint) {
            $path = rtrim((string) parse_url($endpoint['url'], PHP_URL_PATH), '/');

            // Matched on the PATH: the host this backend sees behind its
            // proxy may be spelled differently from the one pasted into the
            // dashboard, and the path is what names the account.
            if ($ours === null && str_ends_with($path, $expectedPath)) {
                $ours = $endpoint;
            } elseif (str_contains($path, '/webhooks/payments/')) {
                $others[] = $endpoint['url'];
            }
        }

        if ($ours === null) {
            return ['checked' => true, 'endpoint_url' => null, 'other_urls' => $others, 'missing_events' => []];
        }

        $missing = in_array('*', $ours['events'], true)
            ? []
            : array_values(array_diff($driver->requiredWebhookEvents(), $ours['events']));

        return [
            'checked' => true,
            'endpoint_url' => $ours['url'],
            'enabled' => $ours['enabled'],
            'other_urls' => $others,
            'missing_events' => $missing,
        ];
    }

    /**
     * @param  array<string, mixed>  $setup
     * @return list<array{level: string, code: string, message: string}>
     */
    private function setupProblems(array $setup, PaymentProvider $provider): array
    {
        $label = $provider->label();

        if ($setup['endpoint_url'] === null) {
            return [[
                'level' => 'error',
                'code' => 'endpoint_missing',
                'message' => "ใน {$label} ยังไม่มีปลายทาง webhook ที่ URL ตรงกับบัญชีนี้ — สร้างใหม่ด้วย Webhook URL ด้านบน"
                    .($setup['other_urls'] !== [] ? ' (ที่พบคือ '.implode(', ', $setup['other_urls']).' ซึ่งเป็นของบัญชีอื่น)' : ''),
            ]];
        }

        $problems = [];

        if (($setup['enabled'] ?? true) === false) {
            $problems[] = ['level' => 'error', 'code' => 'endpoint_disabled', 'message' => "ปลายทาง webhook ใน {$label} ถูกปิดใช้งานอยู่ — เปิดใช้งานใน Dashboard"];
        }

        if ($setup['missing_events'] !== []) {
            $problems[] = [
                'level' => 'error',
                'code' => 'events_missing',
                'message' => 'ปลายทาง webhook ยังไม่ได้เลือก event: '.implode(', ', $setup['missing_events']).' — เพิ่มใน Dashboard',
            ];
        }

        return $problems;
    }

    private function bump(?Company $company, PaymentProvider $provider, string $counter, string $stamp): ?PaymentWebhookDeliveryStat
    {
        try {
            $row = PaymentWebhookDeliveryStat::query()->firstOrCreate(
                // A Carbon, not a 'Y-m-d' string: the model's date cast writes
                // it the same way, so tomorrow's lookup finds today's row.
                ['owner_key' => $this->ownerKey($company), 'provider' => $provider->value, 'day' => today()],
                ['company_id' => $company?->id],
            );
            $row->increment($counter, 1, [$stamp => now()]);

            return $row->refresh();
        } catch (Throwable $e) {
            // Bookkeeping must never fail a webhook delivery.
            Log::warning('Could not record a webhook delivery', ['counter' => $counter, 'reason' => $e->getMessage()]);

            return null;
        }
    }

    private function notifySuperAdmins(?Company $company, PaymentProvider $provider): void
    {
        try {
            $admins = User::withoutGlobalScopes()->where('role', UserRole::SuperAdmin->value)->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new WebhookSignatureRejectedNotification(
                    $provider->label(),
                    $company?->name,
                    $company === null ? '/platform-payment-settings' : '/payment-gateways',
                ));
            }
        } catch (Throwable $e) {
            Log::error('Webhook refusal recorded but the Super Admin email failed', ['reason' => $e->getMessage()]);
        }
    }

    private function ownerKey(?Company $company): string
    {
        return $company === null ? 'platform' : 'company:'.$company->id;
    }

    private function expectedPath(?Company $company, PaymentProvider $provider): string
    {
        return '/api/v1/webhooks/payments/'.$provider->value.'/'.($company === null ? 'platform' : $company->id);
    }

    private function expectedUrl(?Company $company, PaymentProvider $provider): string
    {
        return $company === null
            ? route('payment-webhooks.platform', ['provider' => $provider->value])
            : route('payment-webhooks.handle', ['provider' => $provider->value, 'company' => $company->id]);
    }
}
