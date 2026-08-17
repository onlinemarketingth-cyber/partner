<?php

namespace App\Http\Requests\Academy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// An Agent marks their OWN module as complete — user_id is never
// accepted from the client (ModuleCompletionService forces it to
// auth()->id()), so there is deliberately no user_id rule here.
class StoreModuleCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\ModuleCompletion::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'module_lesson_id' => ['required', 'integer', Rule::exists('module_lessons', 'id')->where('company_id', $this->user()->company_id)],
            'score' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
