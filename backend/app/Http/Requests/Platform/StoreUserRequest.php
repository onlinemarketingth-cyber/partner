<?php

namespace App\Http\Requests\Platform;

use App\Enums\IdDocumentType;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Rules\IdDocument;
use App\Support\PasswordRuleMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

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
            /*
             * 2026-09-10 — `voucher_staff` joins the list (human: "ที่ได้
             * สิทธิ์ในการตัดได้เฉพาะหน้าการตัดสิทธิ์ เพราะทำงานคนละหน้าที่กัน").
             *
             * Front-desk staff: they redeem vouchers at a branch and reach
             * nothing else in the console (RestrictScopedRole). Creatable
             * here because that is where a company's people are made, and
             * making one any other way would mean a Super Admin editing a
             * database column.
             *
             * `super_admin` is still absent, and still deliberately: this
             * endpoint creates a company's own staff, and the platform owner
             * is not one of them.
             *
             * 2026-09-16 — `company_partner` joins, with a condition the other
             * four do not carry: the company must be flagged `is_supplier`.
             *
             * A partner login is a login into a SUPPLIER's side of the system —
             * it reads orders belonging to other tenants, filtered by
             * `products.supplier_company_id`. Minted against a company that
             * supplies nothing, it is an account that can see nothing and means
             * nothing, and the person holding it would have no way to tell that
             * from a bug. Refusing here is cheaper than explaining an empty
             * screen later.
             */
            'role' => ['required', Rule::in(['agent', 'company_admin', 'voucher_staff', 'company_partner'])],
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

    /**
     * A partner login only makes sense against a supplier company.
     *
     * Checked here rather than in `rules()` because the company being created
     * into is either the actor's own (Company Admin, inferred server-side) or
     * the `company_id` they named (Super Admin) — one rule cannot see both.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('role') !== UserRole::CompanyPartner->value) {
                return;
            }

            $companyId = $this->user()->isSuperAdmin()
                ? $this->integer('company_id')
                : $this->user()->company_id;

            $isSupplier = Company::withoutGlobalScopes()
                ->whereKey($companyId)
                ->value('is_supplier');

            if (! $isSupplier) {
                $validator->errors()->add(
                    'role',
                    'บริษัทนี้ยังไม่ได้ตั้งเป็นบริษัทคู่ค้า จึงสร้างบัญชีคู่ค้าไม่ได้',
                );
            }
        });
    }

    /**
     * Thai text for the shared password policy — see PasswordRuleMessages.
     * Without this the Password rule answers in English inside a Thai app.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return PasswordRuleMessages::all();
    }
}
