<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

// Section 7: API Resources on every JSON response — never return raw
// models (prevents field leakage, e.g. password/remember_token). Used
// by both AuthController (/me, /login) and, as of Phase 7, the "Manage
// Agents" UserController — the extra fields below are additive and
// harmless for the /me case (never break that existing usage).
class UserResource extends JsonResource
{
    /**
     * TASK-044 Phase A — defaults to masked (last-4-digits only) bank
     * account number for every existing call site (list/summary JSON
     * responses must never leak the full number — task spec). Set true
     * only via UserResource::forOwner() at the few call sites that are
     * unambiguously the owning agent reading/updating their OWN row
     * (AuthController::login/me, UserProfileController's self-service
     * endpoints — all of which operate on $request->user() only, never
     * a route-bound {user}, so there is no IDOR surface here).
     *
     * TASK-047 — human-confirmed reversal: Company Admin/Super Admin must
     * ALSO see the FULL number wherever they manage an agent within their
     * own company ("แสดงเลยครับ เพราะต้องใช้งาน" — show it directly, it's
     * needed for actual use; a hide/show toggle is explicitly deferred to
     * a future system-settings task, not built now). Rather than adding a
     * second static factory that every UserController call site would
     * need to remember to use, toArray() below checks
     * $request->user()->can('view', $this->resource) directly —
     * $request is auto-injected into every Resource's toArray() by
     * Laravel's resource pipeline regardless of whether the controller
     * explicitly passes it, so this covers index()/show()/update()/etc.
     * with zero controller changes. UserPolicy::view() already encodes
     * exactly the right rule (Super Admin -> true always except another
     * Super Admin target; Company Admin -> true only if
     * same company_id) — reused here rather than re-deriving the same
     * check a second time.
     */
    private bool $revealBankAccountNumber = false;

    public static function forOwner(User $user): self
    {
        $resource = new self($user);
        $resource->revealBankAccountNumber = true;

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // TASK-047 — see $revealBankAccountNumber's docblock. $this->resource
        // is the underlying User model; can('view', ...) resolves
        // UserPolicy::view($request->user(), $this->resource). Guarded
        // with a null-check on $request->user() defensively (every route
        // this Resource is reachable from requires auth already, so this
        // should never actually be null) and wrapped so a viewer who is
        // neither the owner nor authorized to manage this target simply
        // falls through to the masked default, never an exception.
        $revealBankAccountNumber = $this->revealBankAccountNumber
            || (bool) $request->user()?->can('view', $this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            // first_name/last_name — added for the self-service "edit
            // name" form (ProfileSettingsView) and Manage Agents edit
            // form to prefill split fields; `name` above stays the
            // derived combined value every other read site already uses.
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role?->value,
            // TASK-112 / ADR-025 §1 — a FLAG, not a role: `role` above is
            // still 'agent' for a team leader. Surfaced unconditionally
            // (not gated behind can('view')) because it is a capability
            // designation, not personal data — TASK-116's Agent Portal
            // reads it from /me to decide whether to show "ชวนเข้าทีม",
            // and TASK-117's Admin UI binds the toggle to it.
            // Cast to bool explicitly: MySQL hands back tinyint 1/0 and
            // the JSON contract for both frontends must be a real boolean.
            'is_team_leader' => (bool) $this->is_team_leader,
            // 2026-08-22 — the agent's own notification-email preference,
            // so ProfileSettingsView can render the toggle in its true
            // position on load instead of guessing. Same explicit bool cast
            // as is_team_leader above, for the same tinyint reason.
            'email_notifications_enabled' => (bool) $this->email_notifications_enabled,
            // ADR-005/TASK-017..020 — additive fields for the
            // self-registration approval queue (never populated/relevant
            // for agents created directly by an Admin via the "+
            // เพิ่มตัวแทน" form above, which defaults to Approved/'email'
            // per TASK-017's migration backfill).
            'agent_approval_status' => $this->agent_approval_status?->value,
            'approval_rejection_reason' => $this->approval_rejection_reason,
            'registered_via' => $this->registered_via?->value,
            // A BOOLEAN, NOT THE TIMESTAMP — the same line
            // PendingRecruitResource already draws, for the same reason. An
            // admin deciding on this row has to know that approving an
            // UNVERIFIED person will not let them in yet: LoginGateService
            // raises EmailUnverified BEFORE ApprovalPending, so the approval
            // lands, the login still refuses, and it reads as the approval
            // having silently failed. The exact minute somebody clicked a
            // link in their inbox is nobody else's business.
            'email_verified' => $this->email_verified_at !== null,
            // WHO recruited them, and through which link (ADR-025 §6).
            //
            // Deliberately NOT manager_id. `manager` is the CURRENT upline
            // and an admin may re-point it at any time;
            // recruited_via_agent_link_id is immutable attribution — it
            // survives the leader losing the flag, the link being revoked,
            // and any later re-parenting. "Who did this person sign up
            // under" has one honest answer and this is it.
            //
            // whenLoaded, so /users (which does not eager-load the link)
            // omits the key rather than firing queries per row. Null INSIDE
            // means they came through a company-wide invite code — a real
            // state, not a gap: nobody recruited them personally, and the UI
            // must say so rather than leave a blank where a name goes.
            'recruited_via' => $this->whenLoaded('recruitedViaAgentLink', fn () => $this->recruitedViaAgentLink ? [
                'link_label' => $this->recruitedViaAgentLink->label,
                'agent' => $this->recruitedViaAgentLink->relationLoaded('agent') && $this->recruitedViaAgentLink->agent
                    ? [
                        'id' => (int) $this->recruitedViaAgentLink->agent->id,
                        'name' => $this->recruitedViaAgentLink->agent->name,
                    ]
                    : null,
            ] : null),
            // TASK-115 / ADR-025 §7 — the "residual risk" mitigation made
            // visible: WHICH path admitted this agent, WHEN, and BY WHOM.
            // TASK-117's queue renders "อนุมัติโดยหัวหน้าทีม <name>" from
            // these three. All null for every row approved before
            // 2026_08_19_090000 and for every Admin-created agent (which was
            // never "approved" as an event) — the frontend must render that
            // as unknown, never invent an approver.
            'approval_source' => $this->approval_source?->value,
            'approved_at' => $this->approved_at,
            // whenLoaded, so an endpoint that does not eager-load approvedBy
            // omits the key entirely rather than firing one query per row.
            // AgentApprovalController::index()/approve() do load it.
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy ? [
                'id' => (int) $this->approvedBy->id,
                'name' => $this->approvedBy->name,
                // Not "is this person currently a leader" as a substitute for
                // approval_source — the two can legitimately disagree once an
                // approver's flag or role changes. Exposed only so the UI can
                // badge the approver row; approval_source above is the
                // authoritative answer to "how was this approved".
                'is_team_leader' => (bool) $this->approvedBy->is_team_leader,
            ] : null),
            // Profile customization (avatar + background) — human-
            // requested personal preference, not tied to any BR. Files
            // live on the public disk (Section 5 rule 6 doesn't apply —
            // that rule is specifically about client documents/PDPA
            // data, see UserProfileService's own comment), so a plain
            // Storage::url() is safe to expose here.
            'avatar_url' => $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null,
            'background' => [
                'type' => $this->background_type,
                'config' => $this->background_type === 'gradient' ? $this->background_config : null,
                'image_url' => $this->background_type === 'image' && $this->background_image_path
                    ? Storage::disk('public')->url($this->background_image_path)
                    : null,
            ],
            'company' => $this->whenLoaded('company', fn () => $this->company ? [
                'id' => $this->company->id,
                'name' => $this->company->name,
            ] : null),
            // TASK-025 — this agent's upline. manager_id alone is enough
            // for the frontend's <select> to preselect the current value;
            // 'manager' (name) is included whenever eager-loaded so a
            // list screen doesn't need an extra lookup per row.
            'manager_id' => $this->manager_id,
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? [
                'id' => $this->manager->id,
                'name' => $this->manager->name,
            ] : null),
            // TASK-044 Phase A — bank payout details. bank_account_number
            // is masked to last-4 by default (see $revealBankAccountNumber
            // docblock above); bank_name/bank_account_holder_name are not
            // sensitive on their own so are always shown in full. The
            // underlying attribute is decrypted transparently by User's
            // 'encrypted' cast before it ever reaches this array — masking
            // here is a SEPARATE, deliberate layer on top of that (Section
            // 6: encryption-at-rest alone would not stop the full number
            // leaking into a JSON list response once decrypted).
            'bank_name' => $this->bank_name,
            'bank_account_number' => $revealBankAccountNumber
                ? $this->bank_account_number
                : User::maskBankAccountNumber($this->bank_account_number),
            'bank_account_holder_name' => $this->bank_account_holder_name,
            // 2026-08-27 — the SAME answer User::hasCompletePayoutDetails()
            // gives the (not yet built) payout gate, so the prompt an agent
            // sees and the check that would refuse their withdrawal can
            // never drift apart. Not gated behind the reveal check above: it
            // is a yes/no about completeness, and leaks no digit of anything.
            'payout_details_complete' => $this->resource->hasCompletePayoutDetails(),
            // TASK-059 — Thai national ID (PDPA §6), same reveal gate as
            // bank_account_number above ($revealBankAccountNumber): full
            // value only to the owner or a viewer who can('view') this
            // agent (Company Admin same company / Super Admin); every
            // other viewer sees only the mask.
            'national_id_masked' => $this->maskedNationalId(),
            'national_id' => $revealBankAccountNumber ? $this->national_id : null,
            // TASK-122 — WHICH document the two fields above describe
            // (thai_national_id | passport), or null for every row created
            // before this task, which never recorded one. Deliberately NOT
            // behind the reveal gate: the type is not the number, it leaks
            // no digits, and both the Admin edit form and the agent's own
            // profile need it to label/prefill the field correctly. The
            // frontend must render null as "not recorded" — never guess.
            'id_document_type' => $this->id_document_type?->value,
            // BR-1 gate status — same canonical check used everywhere
            // else (User::hasPassedCertTier()), surfaced for the
            // "Manage Agents" team view. Only meaningful for agent role.
            'has_passed_basic_cert' => $this->role?->value === 'agent' ? $this->hasPassedCertTier('basic') : null,
            // TASK-047 point 4/5 — the agent's actual HIGHEST passed cert
            // tier (BR-2's own ranking, User::highestPassedCertTier()),
            // not just the has_passed_basic_cert boolean above. Used by
            // the Commission Summary drill-down's agent-detail header and
            // to color the initial-circle avatar fallback by tier. Null
            // when the agent hasn't passed anything yet — the frontend
            // must render a neutral/default state, never fabricate a tier.
            'cert_tier' => $this->role?->value === 'agent' && ($tier = $this->highestPassedCertTier())
                ? ['id' => $tier->id, 'key' => $tier->key, 'name' => $tier->name]
                : null,
            'is_active' => $this->deleted_at === null,
            'created_at' => $this->created_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
