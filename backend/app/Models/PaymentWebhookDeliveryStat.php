<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One day of webhook deliveries for one payment account and provider.
 *
 * No TenantScope: rows are read and written only by WebhookHealthService,
 * always with an explicit owner_key, and the screen that shows them is Super
 * Admin only (Ability::SettingsPaymentGatewayUpdate).
 */
class PaymentWebhookDeliveryStat extends Model
{
    protected $fillable = [
        'owner_key',
        'company_id',
        'provider',
        'day',
        'accepted_count',
        'rejected_count',
        'last_accepted_at',
        'last_rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'accepted_count' => 'integer',
            'rejected_count' => 'integer',
            'last_accepted_at' => 'datetime',
            'last_rejected_at' => 'datetime',
        ];
    }
}
