<?php

namespace App\Http\Requests\Theme;

use App\Http\Requests\Theme\Concerns\ResolvesPresetCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-161 §5.2 — POST /theme-presets/{preset}/apply.
 *
 * "`apply` must write the theme of the SAME resolved company the preset
 * belongs to. Reading a preset from company A and writing settings to
 * company B must not be expressible."
 *
 * So the Super Admin states which company they believe they are working
 * in, and ThemePresetService::apply() refuses if the preset disagrees.
 * The Service check is the load-bearing one (it guards the method, not
 * just this route); this request is what gives it something to compare
 * against and what turns a nonexistent company id into a 422 before any
 * write is attempted.
 *
 * Note this makes `company_id` REQUIRED for a Super Admin on apply, even
 * though the preset already pins a company. That is the point: the two
 * have to be able to disagree for the mismatch to be catchable. Silently
 * letting the preset's own company win would be safe but mute — the admin
 * would walk away certain they had themed a different tenant.
 */
class ApplyThemePresetRequest extends FormRequest
{
    use ResolvesPresetCompany;

    /**
     * TASK-164 §1 — `apply`, not `update`. A SYSTEM preset is read-only but
     * still applicable, and ThemePresetPolicy::update() now refuses one;
     * asking it here would have made the five designed palettes
     * un-appliable, i.e. useless.
     */
    public function authorize(): bool
    {
        return $this->user()->can('apply', $this->route('theme_preset'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->companyRules();
    }
}
