<?php

namespace App\Http\Requests\Platform;

use App\Enums\IdDocumentType;
use App\Models\User;
use App\Rules\IdDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

// "Manage Agents" — human-confirmed scope this phase: the Admin types a
// temporary password directly into the create form (no email/invite
// infrastructure exists anywhere in this codebase yet, see TASK-009
// design notes) and communicates it to the new agent out of band. role
// is restricted to agent/company_admin — creating a Super Admin via
// this endpoint is never allowed, at any actor level.
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [
                Rule::prohibitedIf(fn () => ! $this->user()->isSuperAdmin()),
                Rule::requiredIf(fn () => $this->user()->isSuperAdmin()),
                'integer',
                'exists:companies,id',
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            // SECURITY AUDIT 2026-08-21 (V18) — one policy, registered in
            // AppServiceProvider.
            'password' => ['required', 'string', Password::defaults()],
            'role' => ['required', Rule::in(['agent', 'company_admin'])],
            // TASK-122 — WHICH identity document `national_id` below is.
            // `required_with`, not `required`: the document itself stays
            // optional here (see below), so demanding a type for an absent
            // document would be nonsense — but a number with no type is
            // unusable, because the type decides both the validation rule
            // and the blind-index normalization (User::hashNationalId).
            'id_document_type' => ['required_with:national_id', Rule::enum(IdDocumentType::class)],
            // TASK-059/122 — the identity document number: a Thai national
            // ID or a passport, per the type above.
            //
            // STAYS NULLABLE ON THE ADMIN PATH, unlike self-registration
            // (RegisterRequest), and that asymmetry is deliberate: an Admin
            // creating an agent on someone's behalf often does not have the
            // document in front of them, and making it mandatory here would
            // block a legitimate existing workflow to solve a problem that
            // only exists on the public form. The two paths differ in who is
            // vouching for the identity — an Admin creating an account IS
            // the vetting (the same reasoning ADR-005 uses to default that
            // path straight to `approved`).
            'national_id' => ['nullable', 'string', 'max:255', new IdDocument($this->input('id_document_type'))],
        ];
    }
}
