<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// `config` (the question/answer content — effectively an answer key) is
// only ever included for Company Admin/Super Admin, never Agent, even
// though ExamPolicy::view allows Agent to see the exam's metadata.
class ExamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canSeeConfig = $user && ($user->isSuperAdmin() || $user->isCompanyAdmin());

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'cert_tier' => $this->whenLoaded('certTier', fn () => [
                'id' => $this->certTier->id,
                'key' => $this->certTier->key,
                'name' => $this->certTier->name,
            ]),
            'title' => $this->title,
            'passing_score' => $this->passing_score,
            'config' => $canSeeConfig ? $this->config : null,
            // Academy Sprint 1 — question bank. Agents need question_text
            // + option_text to actually take the exam, so (unlike
            // `config` above) this is NOT admin-only; only is_correct
            // within each option is masked for anyone but Company
            // Admin/Super Admin (the actual answer key).
            'questions' => $this->whenLoaded('questions', fn () => $this->questions->map(fn ($q) => [
                'id' => $q->id,
                'question_text' => $q->question_text,
                'sort_order' => $q->sort_order,
                'options' => $q->options->map(fn ($o) => [
                    'id' => $o->id,
                    'option_text' => $o->option_text,
                    'sort_order' => $o->sort_order,
                    'is_correct' => $canSeeConfig ? $o->is_correct : null,
                ]),
            ])),
        ];
    }
}
