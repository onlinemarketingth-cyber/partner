<?php

namespace App\Http\Requests\Academy;

use App\Models\Quiz;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TASK-150 / ADR-030 §2.1 — create a quiz in the library.
 *
 * Same company-resolution shape as StoreModuleRequest: a Super Admin must
 * name the company, everyone else's is taken from their own account and the
 * field is ignored (BR-6 — a company_id from the client is never trusted for
 * a non-super-admin; QuizService resolves it again server-side).
 */
class StoreQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Quiz::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin()), 'integer', 'exists:companies,id'],
            'title' => ['required', 'string', 'max:255'],
        ];
    }
}
