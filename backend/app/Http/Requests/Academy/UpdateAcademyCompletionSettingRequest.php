<?php

namespace App\Http\Requests\Academy;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-028 §4 / BR-7 — Company Admin (own company) / Super Admin only,
// same visibility shape as UpdateVideoProcessingSettingRequest.
class UpdateAcademyCompletionSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Ability::SettingsAcademyCompletionUpdate);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin()), 'integer', 'exists:companies,id'],
            /*
             * 1..100. The lower bound is 1, not 0: a 0% threshold would
             * mean "opening the lesson is not required", which is the
             * pre-ADR-028 behaviour the whole sprint exists to remove —
             * and it would remove it silently, per company, with no audit
             * trail of the gate having been turned off. An admin who wants
             * a specific learner exempted uses the override (§2.3 guard 2),
             * which IS audit-logged.
             *
             * These are sanity bounds around an admin-editable value, not
             * business values in themselves (BR-7's target is the DEFAULT,
             * which lives in config/academy.php and the table default).
             */
            'video_watch_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'pdf_read_percent' => ['required', 'integer', 'min:1', 'max:100'],
            /*
             * ADR-029 §2.4 — the company-level quiz pass mark.
             *
             * `sometimes` rather than `required`, unlike its two siblings:
             * this column arrived after the endpoint did, and an admin
             * screen built against the ADR-028 shape must keep working
             * rather than start 422-ing on a field it has never heard of.
             * Omitted simply leaves the stored value alone.
             *
             * Same 1..100 sanity bounds and the same reason for the lower
             * bound of 1: a 0% pass mark means "answering nothing passes",
             * which silently disables the gate for a whole company with no
             * audit trail. The per-learner escape hatch is the audit-logged
             * completion override (ADR-028 §2.3 guard 2).
             */
            'quiz_pass_percent' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
