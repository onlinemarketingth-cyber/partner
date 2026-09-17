<?php

namespace App\Models;

use App\Enums\SupplierGpMode;
use App\Enums\SupplierReleaseTrigger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 2026-09-17 — a company that supplies goods we sell. NOT one of our tenants.
 *
 * ── HOW THIS DIFFERS FROM Company, WHICH IS THE WHOLE POINT ──
 *
 *   Company    a TENANT. Sells through us. We pay COMMISSION to their agents.
 *              Everything it owns is fenced by BR-6.
 *
 *   Supplier   a COUNTERPARTY. Supplies goods. We pay them the sale price
 *              less commission less our GP. Reads orders across MANY tenants,
 *              because their products are sold by more than one of them.
 *
 * The first cut of this feature made a supplier a flag on Company. The owner
 * rejected it, correctly: not one field means the same thing in both, and a
 * screen that edits commission plans is not a screen that edits a supply
 * contract.
 *
 * ── NO TenantScope, AND NO company_id ──
 *
 * This is platform data. A Super Admin creates and edits it; nobody else sees
 * the table at all. The supplier's own people reach their SALES through
 * /supplier/*, which filters on `products.supplier_id`, never on a tenant key.
 *
 * ── SOFT DELETES ──
 *
 * `is_active = false` is how a deal normally ends: the history has to stay
 * readable and any balance still has to be payable. Deleting is for a row
 * created by mistake, and even then the settlement rows hold their own
 * snapshots, so nothing that ever mattered is stored only here.
 */
class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'legal_name',
        'tax_id',
        'contact_name',
        'contact_phone',
        'contact_email',
        'address',
        'is_active',
        'gp_mode',
        'gp_value',
        'release_trigger',
        'min_withdrawal_satang',
        'wht_rate',
        'payout_bank_name',
        'payout_bank_account_number',
        'payout_bank_account_name',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'gp_mode' => SupplierGpMode::class,
            // BR-3 — basis points or satang depending on the mode, integer
            // either way, never divided outside the display layer.
            'gp_value' => 'integer',
            'release_trigger' => SupplierReleaseTrigger::class,
            'min_withdrawal_satang' => 'integer',
            'wht_rate' => 'integer',
        ];
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * The logins that belong to this supplier.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<SupplierSettlementLedger, $this> */
    public function settlements(): HasMany
    {
        return $this->hasMany(SupplierSettlementLedger::class);
    }

    /** @return HasMany<SupplierWithdrawalRequest, $this> */
    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(SupplierWithdrawalRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Can this supplier be paid at all?
     *
     * Surfaced rather than hidden behind a disabled button, because "GP not
     * configured" is something a person can go and fix and an unexplained
     * disabled button is a support ticket. The payout screen shows the reason.
     */
    public function hasCompleteTerms(): bool
    {
        return $this->gp_mode !== null
            && $this->gp_value !== null
            && $this->release_trigger !== null;
    }

    /**
     * Which parts of the deal are still blank.
     *
     * @return list<string>
     */
    public function missingTerms(): array
    {
        $missing = [];

        if ($this->gp_mode === null || $this->gp_value === null) {
            $missing[] = 'gp';
        }

        if ($this->release_trigger === null) {
            $missing[] = 'release_trigger';
        }

        /*
         * The bank account is NOT part of hasCompleteTerms() — a balance is
         * correct and worth showing without one. It IS reported here, because
         * accounting cannot transfer without it and would rather know before
         * raising the payout than after.
         */
        if (blank($this->payout_bank_account_number) || blank($this->payout_bank_account_name)) {
            $missing[] = 'bank';
        }

        return $missing;
    }
}
