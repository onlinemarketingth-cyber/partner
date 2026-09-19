<?php

namespace App\Models;

use App\Enums\WithdrawalSource;
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
        // 2026-09-15 — who raised it. The ONLY thing that differs between an
        // agent's own request and a payout an admin raised for them; see
        // WithdrawalSource for why they share this table at all.
        'source',
        // GROSS. Not reduced by withholding — see the wht_* migration for
        // why, and never change this without reading it.
        'amount_satang',
        // 2026-09-19 — ภาษีหัก ณ ที่จ่าย, the same three columns
        // supplier_withdrawal_requests carries, with amount_satang standing
        // in for that table's gross_satang.
        'wht_rate_at_time',
        'wht_satang',
        'net_satang',
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
            'wht_rate_at_time' => 'integer',
            'wht_satang' => 'integer',
            'net_satang' => 'integer',
            'status' => WithdrawalStatus::class,
            'source' => WithdrawalSource::class,
            'decided_at' => 'datetime',
            'transferred_at' => 'datetime',
            // §6/PDPA — same treatment the live column on users gets. A
            // payout snapshot is not a reason to store an account number in
            // plaintext.
            'bank_account_number' => 'encrypted',
        ];
    }

    /**
     * What actually leaves the bank account — the ONLY figure an admin
     * should ever be asked to transfer, and the only one an agent should be
     * told to expect.
     *
     * Never read `net_satang` directly. It is NULL on every row written
     * before withholding existed, and on such a row the truth is that
     * nothing was withheld, so the net IS the gross. Returning null there
     * would make a payout screen render an empty amount for historical rows,
     * and a `?? 0` at a call site would render zero, which is worse.
     */
    public function netTransferSatang(): int
    {
        return $this->net_satang ?? (int) $this->amount_satang;
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
