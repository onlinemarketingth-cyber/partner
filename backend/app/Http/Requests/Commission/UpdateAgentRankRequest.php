<?php

namespace App\Http\Requests\Commission;

use App\Enums\CommissionRateType;
use App\Models\AgentRank;
use App\Services\Commission\AgentRankLadderInspector;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgentRankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('agent_rank'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'volume_threshold' => ['sometimes', 'required', 'integer', 'min:0'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
            'rate_type' => ['sometimes', 'required', Rule::enum(CommissionRateType::class)],
            'rate_value' => ['sometimes', 'required', 'integer', 'min:0'],
            'is_breakaway_rank' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The same ladder-level guard as StoreAgentRankRequest — see its
     * docblock for why it measures "worse than before" rather than "valid".
     *
     * The company comes from the ROUTE MODEL, never the request body: this
     * request has no company_id field at all, and taking one would let a
     * Company Admin validate their edit against somebody else's ladder
     * (BR-6). The rank being edited is passed as the replacement target so
     * its own old rung is not counted twice — an edit that fixes a
     * duplicate threshold must read as a fix, not as leaving one behind.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var AgentRank $agentRank */
                $agentRank = $this->route('agent_rank');

                $inspector = app(AgentRankLadderInspector::class);
                $regressions = $inspector->regressionsForCompany(
                    (int) $agentRank->company_id,
                    $agentRank,
                    $this->validated(),
                );

                foreach ($inspector->regressionMessages($regressions) as $message) {
                    $validator->errors()->add('rate_value', $message);
                }
            },
        ];
    }
}
