<?php

namespace App\Services\Registration;

use App\Enums\TrackedLinkGroup;
use App\Models\CompanyInviteCode;
use App\Models\User;
use App\Services\Link\TrackedLinkService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * TASK-233 — creates and manages a company's own signup link.
 *
 * ── WHY THIS FILE DID NOT EXIST UNTIL NOW ──
 *
 * `company_invite_codes` has been in the schema since ADR-005 and the
 * application has only ever read it. TASK-018 resolves a code during
 * registration; TASK-022 was supposed to build the management side and
 * never did. The gap survived because it is invisible from inside the
 * code: everything that touches the table works, there is simply nothing
 * that puts a row in it. Setting a company up meant somebody opening the
 * database and typing an INSERT.
 *
 * So this is not "add a link to an existing feature". The feature is the
 * new part; the link is what it is for.
 */
class CompanyInviteCodeService
{
    public function __construct(private readonly TrackedLinkService $trackedLinks) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): CompanyInviteCode
    {
        $companyId = $this->resolveCompanyId($data, $actor);

        $code = CompanyInviteCode::create([
            'company_id' => $companyId,
            'code' => $this->normalizeCode($data['code'] ?? null),
            'label' => $data['label'] ?? null,
            // Both nullable, both meaning UNLIMITED, and neither defaulted
            // here. BR-7: "how long should a company's signup link live"
            // and "how many people may use it" are business decisions, and
            // the request is where somebody states them. A default invented
            // in this file would be a business rule nobody agreed to,
            // hiding in a service.
            'expires_at' => $data['expires_at'] ?? null,
            'max_uses' => $data['max_uses'] ?? null,
            'used_count' => 0,
            'created_by_user_id' => $actor->id,
        ]);

        // The short link IS the deliverable here — unlike the other groups,
        // where it is a nicer version of a URL that already worked. Minted
        // in the same call so a code can never exist without the link it
        // was created to be.
        $this->trackedLinks->mintFor(
            TrackedLinkGroup::CompanySignup,
            $code,
            $actor,
            $data['label'] ?? null,
            $code->code,
        );

        return $code;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CompanyInviteCode $code, array $data): CompanyInviteCode
    {
        // `code` is absent on purpose. It is the printed part of the URL:
        // once a link is on a flyer, changing the code does not edit that
        // flyer, it kills it. Somebody who wants a different code wants a
        // different link, and revoking this one and minting another is the
        // honest way to say so.
        $code->update([
            'label' => array_key_exists('label', $data) ? $data['label'] : $code->label,
            'expires_at' => array_key_exists('expires_at', $data) ? $data['expires_at'] : $code->expires_at,
            'max_uses' => array_key_exists('max_uses', $data) ? $data['max_uses'] : $code->max_uses,
        ]);

        return $code->fresh() ?? $code;
    }

    /**
     * Soft revoke, never delete.
     *
     * Four of the six older link tables already revoke this way and one —
     * affiliate links — hard-deletes, taking its own click history with it
     * (TASK-236 fixes that). Deleting a signup code would also orphan every
     * `users.registered_via_invite_code_id` that points at it, erasing the
     * answer to "where did this agent come from" for people who are still
     * working here.
     */
    public function revoke(CompanyInviteCode $code): CompanyInviteCode
    {
        $code->update(['revoked_at' => now()]);

        $link = $code->trackedLink()->withoutGlobalScopes()->first();
        $link?->update(['revoked_at' => now()]);

        return $code->fresh() ?? $code;
    }

    /**
     * Count one successful registration against the code.
     *
     * Called from inside RegistrationService's transaction, under the same
     * row lock `agent_invite_links.used_count` already uses — two people
     * submitting the last seat of a 50-use link at the same moment must not
     * both get in.
     */
    public function recordUse(CompanyInviteCode $code): void
    {
        $code->increment('used_count');
    }

    private function resolveCompanyId(array $data, User $actor): int
    {
        // BR-6 — a Company Admin's company is taken from their session and
        // any company_id they send is ignored outright, not rejected.
        // Trusting it would let one tenant mint a signup link into another
        // company, which is the single worst thing this endpoint could do.
        if (! $actor->isSuperAdmin()) {
            return (int) $actor->company_id;
        }

        if (! isset($data['company_id'])) {
            throw ValidationException::withMessages([
                'company_id' => 'กรุณาเลือกบริษัทก่อนสร้างลิงก์สมัคร',
            ]);
        }

        return (int) $data['company_id'];
    }

    /**
     * A chosen code is lowercased; an omitted one is generated.
     *
     * Lowercasing rather than rejecting mixed case: somebody typing
     * "ThaiLife" into the form means the same link as "thailife", and
     * refusing them over the shift key would be pedantry. URLs are matched
     * case-sensitively, so normalising at the door is what makes the two
     * actually be one link rather than two that look identical in print.
     */
    private function normalizeCode(?string $code): string
    {
        if ($code !== null && trim($code) !== '') {
            return strtolower(trim($code));
        }

        // No code chosen — fall back to a random one so the form can be
        // submitted without inventing a name. Lowercase alphanumeric to
        // match the shape a human would have typed.
        do {
            $candidate = strtolower(Str::random(10));
        } while (CompanyInviteCode::where('code', $candidate)->exists());

        return $candidate;
    }
}
