<?php

namespace App\Http\Requests\Public;

use App\Models\AffiliateLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-011 Section 4 (TASK-032) — the FIRST unauthenticated Form Request
 * in this codebase. Section 6 ("never trust the client") applies with
 * MORE force here, not less, since there is no login at all to fall
 * back on. authorize() is always true — access control for this route
 * is "does {token} resolve to a real, non-revoked AffiliateLink". That
 * check is duplicated here (aborting 404 at the very top of rules(),
 * before any field is validated) as well as in the Controller — Laravel
 * runs FormRequest validation BEFORE the controller body ever executes,
 * so without this, an invalid token combined with an incomplete payload
 * would surface as 422 ("phone is required") instead of 404 ("this
 * link doesn't exist"), which is both the wrong status code and a
 * confusing error for a genuinely bad/expired link. The Controller's
 * own lookup+abort_if(404) still runs too (defense-in-depth, same
 * "belt and braces" precedent as every other public token lookup in
 * this app — SalesMaterialShareLinkController::show()).
 *
 * Mirrors StoreClientRequest (name/phone/consent) + StoreReferralRequest
 * (branch/preferred_time/product_id) field-for-field, since a lead
 * capture creates exactly one Client + one Referral, same shape as the
 * existing internal SWS Referral flow — just reached via a public link
 * instead of an authenticated agent typing it in.
 *
 * `hp_field` — the honeypot (human-approved bot mitigation, ADR-011/
 * TASK-032 design question): a field real visitors never see or fill
 * (hidden via CSS by the TASK-033 frontend form), so a non-empty value
 * signals an automated submission. Deliberately accepted here as
 * 'nullable' (never rejected at the VALIDATION layer, which would
 * return a 422 and tip off a bot that it was caught) — the actual
 * honeypot check happens in AffiliateLeadCaptureController::store(),
 * which returns the SAME success response either way and silently
 * skips calling the Service instead.
 */
class StoreAffiliateLeadRequest extends FormRequest
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
        $link = AffiliateLink::withoutGlobalScopes()->where('token', $this->route('token'))->first();

        abort_if(! $link, 404);

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'branch' => ['required', 'string', 'max:255'],
            'preferred_time' => ['nullable', 'date'],
            // Required only when the link itself isn't already scoped
            // to one product (AffiliateLink.product_id nullable — see
            // that model's own comment).
            'product_id' => [
                $link && $link->product_id ? 'nullable' : 'required',
                'integer',
                $link ? Rule::exists('products', 'id')->where('company_id', $link->company_id) : 'integer',
            ],
            // PDPA (Section 6) — an anonymous public visitor must
            // explicitly consent before their (potentially health-
            // adjacent) data is collected, stronger than the internal
            // StoreClientRequest's nullable consent_given_at (an
            // internal agent form has an accountable human already
            // typing it in; this one doesn't).
            'consent' => ['required', 'accepted'],
            'hp_field' => ['nullable', 'string'],
        ];
    }
}
