<?php

namespace App\Http\Requests\Referral;

use App\Models\Client;
use App\Services\Commission\CommissionSplitSettingService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// CLAUDE.md §2 "SWS Referral" fields: Client Name, Preferred Time,
// Branch, Package/Price. Client Name/Package-Price aren't free-text
// here — they resolve via client_id/product_id FKs (ERD-001 §"Referral
// & Pipeline"), never duplicated as loose strings that could drift
// from the Client/Product records.
class StoreReferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Referral::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => [
                'required',
                'integer',
                Rule::exists('clients', 'id')->where('company_id', $this->user()->company_id),
            ],
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('company_id', $this->user()->company_id),
            ],
            // STAYS REQUIRED, on purpose. TASK-134a widened
            // referrals.branch to nullable (ag-lead ruling 2026-08-08,
            // TASK-132 spec §"Decision — referrals.branch") ONLY so
            // TASK-136's anonymous public checkout — which has no agent
            // to type a branch and no customer who could know one — can
            // leave it NULL. This is the AUTHENTICATED agent path:
            // agents still know their branch, so nothing about their UX
            // changes. The column being nullable is not an invitation to
            // relax the rule here; the public endpoint gets its own Form
            // Request rather than this one growing a conditional.
            'branch' => ['required', 'string', 'max:255'],
            // No longer required — human request (2026-07-13):
            // "เวลาที่สะดวกนัดไม่ต้อง validate".
            'preferred_time' => ['nullable', 'date'],
            // agent_id: same pattern as StoreClientRequest's
            // referring_agent_id — an Agent never sends this (always
            // forced to self in ReferralService), only Company
            // Admin/Super Admin assign a different agent explicitly.
            'agent_id' => [
                Rule::prohibitedIf(fn () => $this->user()->isAgent()),
                Rule::requiredIf(fn () => ! $this->user()->isAgent()),
                'integer',
                Rule::exists('users', 'id')->where('company_id', $this->user()->company_id)->where('role', 'agent'),
            ],
            // TASK-026 — optional at creation time; both-or-neither and
            // "not the same agent as agent_id" are checked in
            // withValidator() below / ReferralService (the resolved
            // referring agent isn't known here yet for a Company Admin
            // submission on behalf of someone else).
            //
            // TASK-174 §4 — when the company's split is switched off, the
            // pair is REJECTED (422), not quietly dropped. The spec allows
            // "rejects (or ignores)"; rejecting is the honest one — a
            // silently-ignored co_agent_id would leave the submitter
            // believing a split exists that the ledger will never honour.
            // Rule::prohibitedIf is evaluated per request, so flipping the
            // setting back on restores the field with no deploy (D2).
            'co_agent_id' => [
                Rule::prohibitedIf(fn () => ! $this->splitIsEnabled()),
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('company_id', $this->user()->company_id)->where('role', 'agent'),
            ],
            'split_percentage' => [
                Rule::prohibitedIf(fn () => ! $this->splitIsEnabled()),
                'nullable',
                'integer',
                'min:1',
                'max:99',
            ],
        ];
    }

    /**
     * TASK-174 — the ONE predicate (see CommissionSplitSettingService), asked
     * of the company this referral will belong to: ReferralService::create()
     * stamps company_id from the actor, so that is the actor's own company.
     */
    private function splitIsEnabled(): bool
    {
        return app(CommissionSplitSettingService::class)->isEnabledForCompany($this->user()->company_id);
    }

    /**
     * Section 5 rule 4: an Agent may only submit a referral for a
     * client THEY referred in — not a colleague's client, even one in
     * the same company (client_id's tenant-scoped `exists` rule above
     * only proves "same company", not "same agent"). Rejected here at
     * validation (422), not left to the Service to silently correct —
     * unlike agent_id there's no safe "force to self" fallback for
     * client_id, since the client itself belongs to someone else.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->user()->isAgent() || ! $this->filled('client_id')) {
                return;
            }

            $client = Client::find($this->input('client_id'));

            if ($client && $client->referring_agent_id !== $this->user()->id) {
                $validator->errors()->add('client_id', 'You may only submit referrals for clients you referred in yourself.');
            }
        });

        // TASK-026 — both-or-neither: co_agent_id and split_percentage
        // are a pair, never one without the other.
        $validator->after(function (Validator $validator) {
            $hasCoAgent = $this->filled('co_agent_id');
            $hasSplit = $this->filled('split_percentage');

            // TASK-170 — same two messages as SetCoAgentRequest, and Thai
            // for the same reason: the referral create form renders the
            // 422's field messages verbatim too.
            if ($hasCoAgent && ! $hasSplit) {
                $validator->errors()->add('split_percentage', 'กรุณาระบุเปอร์เซ็นต์ที่จะแบ่งให้ผู้ร่วมทีม');
            }
            if ($hasSplit && ! $hasCoAgent) {
                $validator->errors()->add('co_agent_id', 'กรุณาเลือกตัวแทนที่จะแบ่งคอมมิชชั่นด้วย');
            }
        });
    }
}
