<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('brand'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            // TASK-205 — see StoreBrandRequest for the mime/size reasoning.
            // Browsers cannot send multipart on PUT, so the frontend posts
            // with _method=PUT (same as the banner form).
            'logo' => ['sometimes', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            // Explicit "take the logo away" flag: an absent `logo` means
            // "leave it alone" (otherwise every rename would wipe the mark),
            // so clearing needs its own signal.
            'remove_logo' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
