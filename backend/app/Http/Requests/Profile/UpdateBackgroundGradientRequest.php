<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBackgroundGradientRequest extends FormRequest
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
            'color1' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color2' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'angle' => ['nullable', 'integer', 'min:0', 'max:360'],
        ];
    }
}
