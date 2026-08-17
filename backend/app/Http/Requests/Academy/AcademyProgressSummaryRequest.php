<?php

namespace App\Http\Requests\Academy;

use App\Models\Module;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TASK-152 — authorization + company scoping for GET /academy-progress-summary.
 *
 * This is other people's learning data, so it is gated the same way the other
 * cross-agent Academy readouts are (ADR-028 §4's lesson-progress list, ADR-029
 * §2.5's quiz-attempt list): Company Admin for their own company, Super Admin
 * with a company named explicitly. An Agent gets 403 — they have a legitimate
 * view of their OWN progress via /module-completions, and none at all of a
 * colleague's.
 *
 * Written as a Form Request rather than the inline `$request->validate()` some
 * report endpoints use, because unlike those this one takes a `company_id` that
 * decides WHICH TENANT'S DATA IS READ. That check belongs somewhere it is
 * obvious and testable, not folded into a controller line (CLAUDE.md §6 —
 * validate every input via Form Requests).
 */
class AcademyProgressSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user->can('viewProgressSummary', Module::class)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        /*
         * BR-6 / §5 rule 5 — IDOR.
         *
         * A Company Admin naming a company that is not their own is answered
         * 403, NOT silently coerced back to their own company_id. Several older
         * report endpoints coerce (`isSuperAdmin() && filled() ? ... : null`),
         * which is safe but mute: the caller gets their own numbers under
         * someone else's heading and nothing anywhere says the request was
         * refused. Here the attempt is a distinct, loggable failure.
         */
        return ! $this->filled('company_id')
            || $this->integer('company_id') === $user->company_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Required for a Super Admin, exactly as StoreModuleRequest requires
            // it: TenantScope does not constrain them, so an unnamed company
            // would mean "aggregate across every tenant on the platform", which
            // is not a number anyone has asked for and not one BR-6 wants
            // computed by accident. The Admin screen already has the company
            // picker this leans on.
            'company_id' => [
                Rule::requiredIf(fn () => $this->user()->isSuperAdmin()),
                'integer',
                'exists:companies,id',
            ],
            // Free text over name/phone/email. Same param name as
            // UserController::index() so the Admin search box keeps one spelling.
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * The company whose data this request may read. Never derived from the
     * client for anyone but a Super Admin, and for them only after
     * authorize()/rules() above have run.
     */
    public function effectiveCompanyId(): int
    {
        return $this->user()->isSuperAdmin()
            ? $this->integer('company_id')
            : (int) $this->user()->company_id;
    }
}
