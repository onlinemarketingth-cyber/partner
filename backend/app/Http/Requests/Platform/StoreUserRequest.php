<?php

namespace App\Http\Requests\Platform;

use App\Enums\IdDocumentType;
use App\Enums\UserRole;
use App\Models\Supplier;
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
// design notes) and communicates it to the new agent out of band.
//
// 2026-09-18 — the sentence that used to end this comment ("role is
// restricted to agent/company_admin — creating a Super Admin via this
// endpoint is never allowed, at any actor level") no longer holds: a
// SUPER ADMIN may now create another Super Admin here, at the owner's
// request. Nobody else can, and the new account belongs to no company —
// see assignableRoles() and the `company_id` rule below.
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
            /*
             * 2026-09-17 — a partner login has NO company.
             *
             * `company_id` is the tenant this person belongs to, and a
             * supplier is not one of our tenants. The first cut set it to the
             * supplier's company row, which made one column mean two things
             * depending on the reader's role — see User::$fillable.
             */
            /*
             * 2026-09-18 — …and NEITHER does a Super Admin, for the same
             * reason spelled differently: they are not inside a tenant at
             * all. A Super Admin row carrying a company_id is a
             * contradiction the rest of the system does not expect —
             * TenantScope reads the ROLE and leaves them unscoped, so the
             * column would sit there naming a tenant no query honours, and
             * every "which company am I working in" answer would disagree
             * with the row underneath it (the same reasoning
             * CreateSuperAdminCommand::promote() already uses when it
             * clears the column).
             */
            'company_id' => [
                Rule::prohibitedIf(fn () => ! $this->user()->isSuperAdmin() || $this->isPartner() || $this->isSuperAdminRole()),
                Rule::requiredIf(fn () => $this->user()->isSuperAdmin() && ! $this->isPartner() && ! $this->isSuperAdminRole()),
                'integer',
                'exists:companies,id',
            ],
            /*
             * …and a login for any other role has no supplier. The two are
             * exclusive, enforced from both sides so neither can be smuggled
             * in by omitting the other.
             */
            'supplier_id' => [
                Rule::prohibitedIf(fn () => ! $this->isPartner()),
                Rule::requiredIf(fn () => $this->isPartner()),
                'integer',
                'exists:suppliers,id',
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
             * 2026-09-18 — `super_admin` joins, SUPER ADMIN ONLY, replacing
             * the note that used to stand here ("still absent, and still
             * deliberately: this endpoint creates a company's own staff, and
             * the platform owner is not one of them").
             *
             * The owner asked for it. What that note was protecting is worth
             * stating and is now stated in UserPolicy's docblock; what it
             * COST was that every platform-owner change needed SSH to the
             * production host, because `artisan admin:create-super` is the
             * only other way to make one.
             *
             * A Company Admin still cannot — the same rule and the same
             * reason as `company_partner` below, one step further: a tenant
             * must not be able to mint an account that outranks the tenant.
             *
             * 2026-09-16 — `company_partner` joins. 2026-09-17 — and it is
             * SUPER ADMIN ONLY, which the other three are not.
             *
             * A partner login reads orders belonging to tenants OTHER than the
             * creator's, filtered by `products.supplier_id`. Letting a Company
             * Admin mint one would let them hand somebody a window onto every
             * company that sells that supplier's products — the cross-tenant
             * read BR-6 exists to prevent, issued by the tenant itself.
             *
             * Suppliers are platform data with a platform screen, and the
             * accounts that read them are made there.
             */
            'role' => ['required', Rule::in($this->assignableRoles())],
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

    /** Is this request minting a supplier's login? */
    private function isPartner(): bool
    {
        return $this->input('role') === UserRole::CompanyPartner->value;
    }

    /** Is this request minting a platform owner? */
    private function isSuperAdminRole(): bool
    {
        return $this->input('role') === UserRole::SuperAdmin->value;
    }

    /**
     * @return list<string>
     */
    private function assignableRoles(): array
    {
        $roles = ['agent', 'company_admin', 'voucher_staff'];

        /*
         * 2026-09-18 — `super_admin` moved from "absent from both branches,
         * and still deliberately" into the Super-Admin-only branch, beside
         * `company_partner`. Both are accounts that reach outside the
         * creator's own tenant, and both are therefore issued only by
         * somebody who is already outside it.
         */
        if ($this->user()->isSuperAdmin()) {
            $roles[] = UserRole::CompanyPartner->value;
            $roles[] = UserRole::SuperAdmin->value;
        }

        return $roles;
    }

    /**
     * A partner login only makes sense against a supplier that is still
     * trading.
     *
     * Checked here rather than in `rules()` because `exists:suppliers,id` says
     * the row is there, not that the deal is live. Pointing a new login at an
     * ended deal produces an account that can sign in and see nothing, which
     * the person holding it cannot tell from a bug.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->isPartner() || $this->integer('supplier_id') === 0) {
                return;
            }

            $isActive = Supplier::whereKey($this->integer('supplier_id'))->value('is_active');

            if (! $isActive) {
                $validator->errors()->add(
                    'supplier_id',
                    'คู่ค้ารายนี้ถูกปิดการใช้งานอยู่ จึงสร้างบัญชีผู้ใช้ใหม่ไม่ได้',
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
