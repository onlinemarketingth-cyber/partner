<?php

namespace App\Services\Commission;

use App\Models\AuditLog;
use App\Models\CommissionSplitSetting;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TASK-174 — THE ONE SERVER-SIDE ANSWER to "is TASK-026's co-agent
 * commission split enabled for this company?" (BR-7 config, human decision
 * D2, 2026-08-12).
 *
 * isEnabledForCompany() is the single predicate the spec (§4) demands, and
 * every one of these consults it — no second copy of the rule exists:
 *
 *   - CommissionService::recordDirectSale()          (the calculation, D1)
 *   - SetCoAgentRequest::authorize()                 (write endpoint)
 *   - StoreReferralRequest::rules()                  (write endpoint)
 *   - ReferralController::coAgentOptions()           (write-support endpoint)
 *   - ReferralResource::toArray()                    (read)
 *   - CommissionSplitSettingController               (the admin CRUD itself)
 *
 * TeamClientResource does NOT call it: it builds its rows FROM
 * ReferralResource, so the fields are already absent by then — it only had
 * to stop putting a `co_agent` key back (see its narrowedReferral()).
 *
 * Shape copied from TeamVisibilitySettingService / AnnouncementSettingService
 * (forCompany + upsert, always a value, never null) rather than invented.
 */
class CommissionSplitSettingService
{
    /**
     * Per-instance memo, keyed by company id. Matters because
     * ReferralResource asks once PER ROW: a Kanban board of 200 referrals
     * would otherwise be 200 identical queries. Safe because
     * RequestScopedService scopes the instance to one HTTP request (see its
     * docblock for why not a container singleton), and upsert() clears the
     * entry it writes.
     *
     * @var array<string, bool>
     */
    private array $memo = [];

    /**
     * THE PREDICATE. Everything that could split money asks exactly this.
     *
     * Fails CLOSED: an unknown/absent company resolves to false. "Off" is
     * the safe side to be wrong on here — a sale that should have been split
     * and was not is a visible, fixable underpayment to one agent, whereas a
     * split nobody could see in the UI is precisely the silent, unauditable
     * money movement TASK-174 exists to stop.
     */
    public function isEnabledForCompany(?int $companyId): bool
    {
        $key = $companyId === null ? 'null' : (string) $companyId;

        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $this->forCompany($companyId)['is_enabled'];
        }

        return $this->memo[$key];
    }

    /**
     * Always returns a value, never null — same contract as
     * TeamVisibilitySettingService::forCompany(): a caller must not have to
     * tell "not configured" from "configured", because getting that
     * distinction wrong is how a tenant ends up failing open.
     *
     * @return array{is_enabled: bool}
     */
    public function forCompany(?int $companyId): array
    {
        if ($companyId !== null) {
            $override = CommissionSplitSetting::withoutGlobalScopes()->where('company_id', $companyId)->first();

            if ($override) {
                return ['is_enabled' => (bool) $override->is_enabled];
            }
        }

        return ['is_enabled' => false];
    }

    /**
     * @param  array{is_enabled?: bool}  $data
     */
    public function upsert(int $companyId, array $data, ?User $actor = null): CommissionSplitSetting
    {
        // BR-6/§5 — $data comes from $request->validated() and may still
        // carry a client-supplied company_id (the Super Admin path in
        // UpdateCommissionSplitSettingRequest validates one).
        // updateOrCreate() would otherwise overwrite the match-key
        // company_id with that value via fill(), redirecting the write into
        // another tenant. Always use the server-resolved $companyId. Same
        // IDOR fix already applied to TeamVisibilitySettingService /
        // VideoProcessingSettingService / AffiliateAttributionSettingService.
        unset($data['company_id']);

        $before = $this->forCompany($companyId)['is_enabled'];

        unset($this->memo[(string) $companyId]);

        $setting = CommissionSplitSetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $companyId],
            $data,
        );

        // Section 6 — "record every action that affects money [or]
        // commission." This flag decides whether one sale pays one agent or
        // two, so it is squarely that; shape copied from
        // CommissionRuleService::create()'s AuditLog::create() call. The
        // other per-company settings services do not audit because none of
        // them moves money.
        if ($actor && $before !== (bool) $setting->is_enabled) {
            AuditLog::create([
                'company_id' => $companyId,
                'actor_user_id' => $actor->id,
                'action' => 'commission_split_setting.updated',
                'auditable_type' => CommissionSplitSetting::class,
                'auditable_id' => $setting->id,
                'old_values' => ['is_enabled' => $before],
                'new_values' => ['is_enabled' => (bool) $setting->is_enabled],
                'ip_address' => request()?->ip(),
            ]);
        }

        return $setting;
    }

    /**
     * Spec §6 — "a consequence to design for, not to discover later".
     *
     * Switching the split back ON makes every referral that still carries a
     * stored co_agent_id, and whose BR-4 ledger row has not been written
     * yet, resume splitting — money behaviour changing on deals nobody
     * touched. That is correct (the data was deliberately preserved, §3),
     * but it must not be a surprise, so the endpoint volunteers the count
     * and Admin shows it before the flip.
     *
     * "Not yet paid out" is asked as "has no commission_ledger row",
     * deliberately NOT as a pipeline stage: since ADR-026 every referral
     * can have a different journey, and the ledger row's existence is the
     * one fact that actually decides whether this referral's split can
     * still change anything.
     */
    public function pendingReferralsWithStoredSplitCount(int $companyId): int
    {
        return Referral::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('co_agent_id')
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('commission_ledger')
                ->whereColumn('commission_ledger.referral_id', 'referrals.id'))
            ->count();
    }
}
