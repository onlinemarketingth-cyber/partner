<?php

namespace App\Http\Requests\Profile;

use App\Enums\IdDocumentType;
use App\Rules\IdDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 2026-08-27 — the identity document, filled in AFTER sign-up.
 *
 * It was removed from the registration form (asking a stranger for a
 * national ID number before they have an account was costing sign-ups), so
 * this is now the only place an agent supplies it themselves.
 *
 * REQUIRED here, unlike the bank fields next door which are `nullable`.
 * That difference is deliberate: a half-filled bank record is a nuisance an
 * admin can chase, whereas submitting this form at all is an explicit "here
 * is my document" — an empty submission would only ever be a mistake, and
 * silently accepting it would leave the person believing they had completed
 * a step they had not.
 *
 * Self-scoped like every other Profile request: the Controller only ever
 * passes $request->user(), never a route-bound {user}, so there is no IDOR
 * surface and no Policy to consult.
 */
class UpdateIdDocumentRequest extends FormRequest
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
        return [
            // The TYPE is validated first because App\Rules\IdDocument reads
            // it to decide which check to run (13-digit mod-11 for a Thai
            // card, a 6-12 alphanumeric shape for a passport), and passes
            // silently when it is missing — precisely so this field owns
            // that error rather than the number inheriting it.
            'id_document_type' => ['required', Rule::enum(IdDocumentType::class)],
            'national_id' => [
                'required',
                'string',
                'max:255',
                new IdDocument($this->input('id_document_type')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id_document_type.required' => 'กรุณาเลือกประเภทเอกสารยืนยันตัวตน (บัตรประชาชน หรือ หนังสือเดินทาง)',
            'national_id.required' => 'กรุณากรอกเลขที่บัตรประชาชน หรือเลขที่หนังสือเดินทาง',
        ];
    }
}
