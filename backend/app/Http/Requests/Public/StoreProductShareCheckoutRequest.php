<?php

namespace App\Http\Requests\Public;

use App\Enums\TrackedLinkGroup;
use App\Models\ProductShareLink;
use App\Services\Link\TrackedLinkService;
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
        /*
         * ── THIS LOOKUP MUST UNDERSTAND BOTH FORMS OF THE URL ──
         *
         * Human-reported 2026-08-21: a customer filled in the checkout
         * sheet on a page that had loaded perfectly and got
         * "ไม่พบข้อมูลที่ต้องการ อาจถูกลบไปแล้ว" — the SPA's generic 404
         * copy — on a link that was alive.
         *
         * TASK-232 gave every public share URL a SHORT CODE (/p/R4TB8WM2XK)
         * alongside the original 64-character token, and taught
         * PublicProductShareController::resolveUsableLink() to accept
         * either. It did not teach THIS class, which had its own copy of
         * the lookup and matched the `token` column alone.
         *
         * So a short link read fine and refused to sell: the GET went
         * through the controller's resolver and answered 200, and the POST
         * never reached that resolver at all — Laravel runs a FormRequest
         * BEFORE the controller body, so this abort fired first. Every
         * short link in existence could show a product and not take an
         * order, and the message told the customer the product was gone.
         *
         * The docblock above already explains why this check is duplicated
         * here (a bad token with an incomplete payload must 404, not 422
         * "phone is required"). That reason still holds — what it did not
         * survive was a second way of naming the same link.
         *
         * resolveTarget() and NOT the controller's resolveViaTrackedLink():
         * same resolution, no side effect. That one RECORDS A VISIT, and a
         * submitted order is not a page view — counting it would inflate
         * every short link's open count by one per purchase.
         *
         * Order mirrors the controller's deliberately, so the two read the
         * same way rather than merely agreeing.
         */
        $token = (string) $this->route('token');

        $link = app(TrackedLinkService::class)
            ->resolveTarget($token, TrackedLinkGroup::ProductShare, ProductShareLink::class)
            ?? ProductShareLink::withoutGlobalScopes()->where('token', $token)->first();

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
