<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 2026-09-11 — one signature-verified delivery from a payment gateway, kept
 * exactly as it arrived. See the migration for why.
 *
 * TenantScope like every other company-owned row (BR-6 / §5). It is written
 * from a webhook, where there is no authenticated user for the scope to key
 * on, so the recorder writes withoutGlobalScopes() and the CLI reads the same
 * way — the scope is here for the day something authenticated reads this
 * table, so that day does not start with a leak.
 */
class PaymentWebhookEvent extends Model
{
    use MassPrunable;

    /** Written when no order matched the event's token. */
    public const RESULT_UNMATCHED = 'unmatched';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'provider',
        'event_id',
        'event_type',
        'order_id',
        'order_token',
        'result',
        'charge_id',
        'amount_satang',
        'payload',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_satang' => 'integer',
            'received_at' => 'datetime',
            // Stored as JSON text and read back as an array, so a caller
            // never has to remember to decode it — and so a payload that is
            // not valid JSON (truncated, or a provider sending form data)
            // fails here rather than halfway through a report.
            'payload' => 'array',
        ];
    }

    /**
     * Thirty days.
     *
     * Long enough to investigate a payment a customer is disputing; short
     * enough that somebody ticking "send me everything" in the gateway's
     * dashboard costs disk rather than a database. Requires the scheduler
     * (`model:prune` in routes/console.php), which this deployment already
     * depends on for reminders and payouts.
     */
    public function prunable(): Builder
    {
        return static::withoutGlobalScopes()->where('received_at', '<', now()->subDays(30));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
