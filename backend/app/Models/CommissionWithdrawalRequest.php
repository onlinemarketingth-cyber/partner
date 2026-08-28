<?php

namespace App\Models;

use App\Enums\WithdrawalStatus;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An agent's request to be paid their earned commission (2026-08-27).
 *
 * The amount is authoritative on this row; WHICH commission it is drawn
 * from lives in commission_withdrawal_items, because an arbitrary amount
 * does not divide neatly across indivisible ledger rows (see that table's
 * migration for the full reasoning).
 *
 * bank_* are a SNAPSHOT taken when the request was made, never read live at
 * payout time — the account an admin approved must be the account that was
 * on screen when they approved it.
 */
class CommissionWithdrawalRequest extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'agent_id',
        'amount_satang',
        'status',
        'decided_by_user_id',
        'decided_at',
        'rejection_reason',
        'transferred_at',
        'transfer_reference',
        'bank_name',
        'bank_account_number',
        'bank_account_holder_name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_satang' => 'integer',
            'status' => WithdrawalStatus::class,
            'decided_at' => 'datetime',
            'transferred_at' => 'datetime',
            // §6/PDPA — same treatment the live column on users gets. A
            // payout snapshot is not a reason to store an account number in
            // plaintext.
            'bank_account_number' => 'encrypted',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CommissionWithdrawalItem::class);
    }

    /**
     * Last 4 digits only — the same masking rule UserResource applies to the
     * live bank account. An admin reviewing a queue needs to recognise the
     * account, not to be handed it.
     */
    public function maskedBankAccountNumber(): ?string
    {
        return User::maskBankAccountNumber($this->bank_account_number);
    }
}
