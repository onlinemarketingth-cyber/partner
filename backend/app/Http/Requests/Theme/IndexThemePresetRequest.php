<?php

namespace App\Http\Requests\Theme;

use App\Http\Requests\Theme\Concerns\ResolvesPresetCompany;
use App\Models\ThemePreset;
use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-161 §5.2 — GET /theme-presets, scoped to ONE company.
 *
 * A Company Admin never sends `company_id` (it is stripped) and gets their
 * own; TenantScope would already have done that, and this makes it true
 * whether or not the scope is there.
 *
 * A Super Admin MUST name one. Without this request they were exempt from
 * TenantScope and the list returned every tenant's presets mixed together
 * — unreadable, and dangerous to click "ใช้ชุดนี้" in.
 */
class IndexThemePresetRequest extends FormRequest
{
    use ResolvesPresetCompany;

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', ThemePreset::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->companyRules();
    }
}
