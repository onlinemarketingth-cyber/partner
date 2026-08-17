<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// health_notes is included as plaintext here — the 'encrypted' model
// cast only protects it at rest (DB column); anyone who can view this
// Resource at all (ClientPolicy::view already gated it to the
// referring Agent / Company Admin / Super Admin) is allowed to read it.
class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'referring_agent_id' => $this->referring_agent_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            // TASK-049 — national ID (PDPA §6). Always expose the masked
            // form (last 4 digits) for display; expose the FULL decrypted
            // value only to a privileged viewer: Super Admin, the client's
            // own Company Admin, or the referring agent who captured it.
            // Any other agent (e.g. a second agent competing on the same
            // client) sees only the mask.
            'national_id_masked' => $this->maskedNationalId(),
            'national_id' => $this->when($this->viewerMaySeeFullNationalId($request), fn () => $this->national_id),
            'consent_given_at' => $this->consent_given_at,
            'health_notes' => $this->health_notes,
            // Client-level status + lead source (human request,
            // 2026-07-13, following a CRM-standards comparison) —
            // independent of any Referral, so a client with zero
            // referrals still has a status to show. Same {key,label}
            // shape as Referral's current_stage for UI consistency.
            'status' => [
                'key' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'lead_source' => $this->lead_source,
            // TASK-056 Sprint P2 — client segmentation (BR-7 config).
            'client_category_id' => $this->client_category_id,
            'client_category_name' => $this->whenLoaded('category', fn () => $this->category?->name),
            // TASK-014 demographic fields — general personal data
            // (Section 6), all optional.
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'address' => $this->address,
            'province' => $this->province,
            'occupation' => $this->occupation,
            // Human-requested: Client Management should show "customer
            // status" + "products the customer is interested in" — both
            // already exist as Referral.current_stage / Referral.product
            // (BR-4.3 pipeline), so this reuses ReferralResource rather
            // than inventing a new field on Client itself (a client with
            // no referral yet, or several referrals for different
            // products, is represented exactly as-is — never collapsed
            // to one fake "status").
            'referrals' => $this->whenLoaded('referrals', fn () => ReferralResource::collection($this->referrals)),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * TASK-049 — full (decrypted) national ID is exposed only to a
     * privileged viewer: Super Admin, the client's own Company Admin, or
     * the referring agent who captured it. Every other viewer (including a
     * second, competing agent on the same client) sees only the mask.
     * Mirrors the "encryption at rest is not enough — the Resource/JSON
     * layer needs its own gating" reasoning already documented on
     * users.bank_account_number.
     */
    private function viewerMaySeeFullNationalId(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }

        return $user->isSuperAdmin()
            || $user->isCompanyAdmin()
            || ($user->isAgent() && $this->referring_agent_id === $user->id);
    }
}
