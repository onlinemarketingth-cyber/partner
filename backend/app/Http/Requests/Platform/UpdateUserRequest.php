<?php

namespace App\Http\Requests\Platform;

use App\Enums\IdDocumentType;
use App\Rules\IdDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// company_id is never editable here — moving a user between companies
// is a much bigger operation (re-parenting every referral/commission/
// xp row they own) than a simple team-management edit; not requested,
// not built. role is restricted the same way as StoreUserRequest —
// this can promote an agent to company_admin (human-confirmed scope)
// but can never touch super_admin in either direction.
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            /*
             * TASK-131 (human, 2026-08-08: "ทำไมเบอร์โทรแก้ไขไม่ได้").
             *
             * `phone` was an oversight, not a decision. The column exists,
             * User::$fillable has carried it since TASK-017, and
             * RegisterRequest collects it at self-registration — but no admin
             * Form Request ever listed it, so validated() dropped it and
             * UserService::update() never saw it. The value was therefore
             * write-once at signup and unfixable afterwards: an agent who
             * mistyped their own number, or one an admin created (no phone
             * field there either), could never be corrected.
             *
             * Same rule as RegisterRequest so the two entry points cannot
             * disagree about what a valid number is.
             */
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'role' => ['sometimes', Rule::in(['agent', 'company_admin'])],
            // TASK-112 / ADR-025 §1 — the "may mint recruit links and
            // approve their own recruits" flag. Company Admin / Super
            // Admin only, expressed with the same Rule::prohibitedIf()
            // shape StoreUserRequest already uses for company_id rather
            // than relying solely on authorize(): UserPolicy::update()
            // does already restrict this whole endpoint to those two
            // roles, so this is defence in depth — if that Policy is ever
            // widened (e.g. to let a leader edit their own recruits,
            // ADR-025 §7), granting yourself leadership must NOT quietly
            // come along for the ride.
            //
            // Deliberately NOT in StoreUserRequest: a brand-new user is
            // never created as a leader. Leadership is a designation an
            // Admin makes about someone they already have (ADR-025 §1),
            // and keeping creation free of it means there is exactly one
            // code path that can ever turn the flag on — one place to
            // audit, one place to test.
            'is_team_leader' => [
                'sometimes',
                Rule::prohibitedIf(fn () => ! ($this->user()->isSuperAdmin() || $this->user()->isCompanyAdmin())),
                'boolean',
            ],
            // TASK-025 — this only checks shape ("is it some existing
            // user id"); same-company + no-cycle checks need an actual
            // query walk and live in UserService::assertValidManager()
            // instead (Section 7 — business logic never in a Request).
            'manager_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            // TASK-044 Phase A — Company Admin editing an agent's bank
            // payout details (human-confirmed: both self-service AND
            // Admin may write these). All 3 optional/nullable, same as
            // the self-service UpdateBankAccountRequest.
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank_account_holder_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            // TASK-122 — see StoreUserRequest for why the type is
            // `required_with` and the number stays nullable here.
            //
            // `required_with` fires only when national_id arrives with a
            // NON-EMPTY value (that is what Laravel's required_* family
            // means by "present"), so sending national_id = null to CLEAR a
            // document does not demand a type alongside it.
            'id_document_type' => ['sometimes', 'nullable', 'required_with:national_id', Rule::enum(IdDocumentType::class)],
            // TASK-059/122 — the identity document number: Thai national ID
            // or passport, per the type above.
            'national_id' => ['sometimes', 'nullable', 'string', 'max:255', new IdDocument($this->input('id_document_type'))],
        ];
    }
}
