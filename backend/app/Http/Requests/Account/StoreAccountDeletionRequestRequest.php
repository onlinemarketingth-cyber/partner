<?php

namespace App\Http\Requests\Account;

use App\Models\AccountDeletionRequest;
use App\Services\Account\AccountDeletionService;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * MOB-12 (2026-10-02) — "delete my account", from the agent portal / mobile app.
 *
 * Self-scoped like every /me endpoint: the controller only ever acts on
 * $request->user(), so there is no {user} to tamper with and no IDOR surface.
 *
 * `password` is the re-authentication. Requesting deletion signs the person
 * out everywhere and locks the account, so a stolen session alone must not be
 * able to do it — the same reasoning UpdateEmailRequest gives for requiring
 * the current password. It is only SHAPE-checked here; the comparison against
 * the stored hash, and the login-style throttle around it, live in
 * AccountDeletionService::assertPassword() so the throttle counts every wrong
 * guess, which a validation rule could not do.
 */
class StoreAccountDeletionRequestRequest extends FormRequest
{
    /**
     * Returns the Policy's Response rather than a bool so a refusal carries
     * the Policy's own Thai sentence (only agents may ask) instead of the
     * framework's "This action is unauthorized." — FormRequest calls
     * ->authorize() on a Response, which throws with that message.
     */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('create', AccountDeletionRequest::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
            /*
             * MOB-12 follow-up (owner decision 2026-10-03) — what to do with
             * unpaid commission. Only SHAPE-checked here: whether it is
             * REQUIRED depends on whether anything is unpaid, which only the
             * Service can know at the moment it locks the ledger rows (the
             * client's preview may already be stale). Ignored when nothing is
             * unpaid.
             */
            'commission_choice' => ['nullable', 'string', Rule::in([
                AccountDeletionService::CHOICE_WAIVE,
                AccountDeletionService::CHOICE_KEEP,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'กรุณากรอกรหัสผ่านเพื่อยืนยันตัวตน',
            'reason.max' => 'เหตุผลยาวได้ไม่เกิน 500 ตัวอักษร',
            'commission_choice.in' => 'ตัวเลือกค่าแนะนำค้างจ่ายไม่ถูกต้อง',
        ];
    }
}
