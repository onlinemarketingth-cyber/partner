<?php

namespace App\Http\Requests\Share;

use App\Enums\ShareLinkType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TASK-212 — validates the share-by-email call.
 *
 * authorize() is `true` on purpose: "may this actor email THIS target" is
 * not answerable here, because the target is a polymorphic (type, id) pair
 * that has to be resolved first. ShareLinkEmailService does the resolve and
 * then asks the target's own Policy — one check, in the place that knows
 * what it is checking, rather than a duplicate here that could drift.
 */
class SendShareLinkEmailRequest extends FormRequest
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
            'type' => ['required', Rule::enum(ShareLinkType::class)],
            'id' => ['required', 'integer', 'min:1'],
            // Optional ONLY where the server can supply a default — see
            // withValidator(). `email:rfc` and not `email:rfc,dns`: a DNS
            // lookup inside a request an agent is watching adds a network
            // round trip and rejects perfectly deliverable addresses behind
            // slow resolvers. SMTP will reject an undeliverable address, and
            // that rejection is surfaced to the agent as a 422 anyway.
            'email' => ['nullable', 'email:rfc', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Product-share and team-invite links are broadcast links: the
            // server has no idea who should receive them, so the agent must
            // say. Only an order carries its own client.
            $type = ShareLinkType::from($this->string('type')->toString());

            if ($type !== ShareLinkType::Order && ! filled($this->input('email'))) {
                $validator->errors()->add('email', 'กรุณากรอกอีเมลผู้รับ');
            }
        });
    }
}
