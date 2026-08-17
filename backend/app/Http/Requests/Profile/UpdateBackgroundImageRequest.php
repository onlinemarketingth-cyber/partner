<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBackgroundImageRequest extends FormRequest
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
            'background_image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'], // 8MB
        ];
    }
}
