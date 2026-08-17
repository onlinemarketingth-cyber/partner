<?php

namespace App\Http\Requests\Public;

use App\Models\ProductShareLink;
use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-136 — validation for POST /public/product-shares/{token}/checkout,
 * the SECOND unauthenticated write endpoint in this codebase.
 *
 * Modelled field-for-field on StoreAffiliateLeadRequest (§Input of the
 * TASK-136 spec: "copy the shape, do not invent a second anonymous-write
 * style"), including that Request's reasoning:
 *
 *  - authorize() is always true. Access control for this route is "does
 *    {token} resolve to a real, non-revoked ProductShareLink", and that
 *    check is duplicated at the top of rules() as well as in the
 *    Controller — Laravel validates BEFORE the controller body runs, so
 *    without it a bad token plus an incomplete payload would surface as
 *    422 "phone is required" instead of 404 "this link doesn't exist".
 *  - `hp_field` is the honeypot and is accepted as 'nullable' here on
 *    purpose. Rejecting it at the validation layer would return a 422 and
 *    tell a bot it was caught; the actual check lives in the Controller.
 *
 * TWO DELIBERATE DIFFERENCES from its affiliate twin:
 *
 * 1. NO `branch`. Ruled by human + ag-lead on 2026-08-08 (TASK-132
 *    §"Decision — referrals.branch"): the column is now nullable and a
 *    self-serve referral leaves it NULL, because a customer cannot know
 *    which branch they are buying through and must not be asked to invent
 *    one. A placeholder like 'ONLINE' was rejected outright — it would be
 *    indistinguishable from a real branch name the day branches become a
 *    real entity. So this Request does not accept the field at all; a
 *    client that posts one is simply ignored (§6 — never trust the
 *    client), rather than silently having it written through.
 *
 * 2. NO `product_id`. A ProductShareLink's product_id is NOT nullable
 *    (ADR-019 §Data model), so the product is a property of the link, not
 *    a customer choice. Accepting one would let a visitor swap the
 *    product they are being charged for.
 */
class StoreProductShareCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $link = ProductShareLink::withoutGlobalScopes()->where('token', $this->route('token'))->first();

        abort_if(! $link || ! $link->isUsable(), 404);

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            // PDPA (§6) — an anonymous visitor must explicitly consent
            // before their data is collected, stronger than the internal
            // StoreClientRequest's nullable consent_given_at (an internal
            // agent form already has an accountable human typing it in;
            // this one does not).
            'consent' => ['required', 'accepted'],
            'hp_field' => ['nullable', 'string'],
        ];
    }
}
