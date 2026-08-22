<?php

namespace App\Services\Platform;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Media\StoredFileName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Personal profile customization — avatar + background (human-requested
 * feature, not tied to any BR). Every method takes the User being edited
 * as an explicit argument, but the Controller only ever passes
 * $request->user() (never a route-model-bound {user}) — self-service by
 * construction, no IDOR surface, no Policy needed.
 *
 * Files live on the PUBLIC disk (unlike ClientDocumentService's private
 * disk for PDPA client documents — see that Service's comment) since
 * avatars/backgrounds are non-sensitive, decorative images. Requires
 * `php artisan storage:link` to have been run once (see SETUP.md).
 */
class UserProfileService
{
    private const DISK = 'public';

    public function updateAvatar(User $user, UploadedFile $file): User
    {
        $this->deleteFileIfSet($user->avatar_path);

        $path = $file->storeAs(
            "avatars/{$user->company_id}",
            StoredFileName::random($file, $user->id.'-'),
            self::DISK,
        );

        $user->update(['avatar_path' => $path]);

        return $user->fresh();
    }

    public function deleteAvatar(User $user): User
    {
        $this->deleteFileIfSet($user->avatar_path);
        $user->update(['avatar_path' => null]);

        return $user->fresh();
    }

    /**
     * @param  array{color1: string, color2: string, angle?: int|null}  $config
     */
    public function updateBackgroundGradient(User $user, array $config): User
    {
        // Switching to gradient drops any previously-uploaded background
        // image — a user has exactly one active background (type), never
        // both a stale image file AND gradient config at once.
        $this->deleteFileIfSet($user->background_image_path);

        $user->update([
            'background_type' => 'gradient',
            'background_config' => [
                'color1' => $config['color1'],
                'color2' => $config['color2'],
                'angle' => $config['angle'] ?? 135,
            ],
            'background_image_path' => null,
        ]);

        return $user->fresh();
    }

    public function updateBackgroundImage(User $user, UploadedFile $file): User
    {
        $this->deleteFileIfSet($user->background_image_path);

        $path = $file->storeAs(
            "backgrounds/{$user->company_id}",
            StoredFileName::random($file, $user->id.'-'),
            self::DISK,
        );

        $user->update([
            'background_type' => 'image',
            'background_config' => null,
            'background_image_path' => $path,
        ]);

        return $user->fresh();
    }

    public function deleteBackground(User $user): User
    {
        $this->deleteFileIfSet($user->background_image_path);

        $user->update([
            'background_type' => null,
            'background_config' => null,
            'background_image_path' => null,
        ]);

        return $user->fresh();
    }

    /**
     * @param  array{first_name: string, last_name: string}  $data
     */
    /**
     * The agent's own notification-email preference.
     *
     * forceFill rather than update(): the column is deliberately absent
     * from User::$fillable so that no Admin-facing Request (which accepts a
     * broad field set) can ever silence somebody else's approval and
     * payment mail as a side effect of an unrelated edit. This endpoint is
     * the only writer.
     */
    public function updateNotificationPreferences(User $user, bool $enabled): User
    {
        $user->forceFill(['email_notifications_enabled' => $enabled])->save();

        return $user->fresh();
    }

    public function updateName(User $user, array $data): User
    {
        // `name` itself is never passed here — User::booted()'s saving()
        // hook derives it from first_name/last_name automatically (see
        // migration 2026_07_12_090000 docblock).
        $user->update([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
        ]);

        return $user->fresh();
    }

    /**
     * Password hashing is handled by User's 'password' => 'hashed' cast
     * (Section 6: bcrypt/argon2, never done manually here) — same as
     * UserService::resetPassword. UpdatePasswordRequest has already
     * verified $newPassword's owner knows the CURRENT password via the
     * `current_password` validation rule before this is ever called.
     *
     * ── TASK-183 §4.4 — DECISION: YES, A SELF-SERVICE PASSWORD CHANGE IS
     *    AUDITED. The spec asked for the call to be made explicitly rather
     *    than skipped silently, so here is the reasoning.
     *
     * FOR (why it gets a row):
     *   1. §6's list is "money, commission, status, certification, or
     *      permissions". A password is the credential the whole permission
     *      system rests on — auditing an Admin reset (which we now do) but not
     *      a self-service change would leave the trail able to answer "who
     *      changed this account's password?" only when the answer is an Admin.
     *   2. THE FORENSIC CASE IS THE SELF-SERVICE ONE. The classic session-
     *      hijack sequence is: attacker rides a stolen cookie, changes the
     *      password, locks the owner out. `current_password` raises the bar but
     *      does not close it — the attacker who has the session usually has
     *      the password too (phishing), and the point of the row is to answer
     *      "when did the takeover happen, and from which IP" AFTERWARDS. The
     *      ip_address column is what makes this worth writing at all.
     *   3. The precedent and the shape already exist one method down:
     *      updateBankAccount() writes an audit row where actor == target, on
     *      this same self-service path. Nothing new is being invented.
     *
     * AGAINST (weighed, and why it does not win): volume. This fires on every
     * routine password change, and a trail that logs everything hides the rows
     * that matter. But the realistic rate is a handful per user per YEAR —
     * nothing next to `team_client_file.view`, which this codebase already
     * writes on every drill-down — so the noise argument does not survive
     * contact with the numbers.
     *
     * ACTION NAME: `user.password_changed`, deliberately DIFFERENT from
     * UserService's `user.password_reset_by_admin`. "The owner changed their
     * own password" and "an Admin overwrote someone's password" are different
     * events with different follow-up questions, and a single shared name
     * would make them unfilterable — the actor_user_id alone does not
     * distinguish them, because a Company Admin changing their OWN password
     * would also have actor == target.
     *
     * §4.2 — NO PASSWORD MATERIAL, not the plaintext and not the hash. Both
     * value columns are null; see UserService::resetPassword()'s docblock for
     * the full argument (audit_logs is read by a wider audience than the users
     * row, so putting a crackable hash there would undo the point of hashing).
     */
    public function updatePassword(User $user, string $newPassword): User
    {
        // Transaction for the same reason updateBankAccount() below has one:
        // if the audit write throws after the password already committed, the
        // user sees a 500 while their credential has silently changed with no
        // trail — the worst of the three possible outcomes.
        return DB::transaction(function () use ($user, $newPassword) {
            $user->update(['password' => $newPassword]);

            AuditLog::create([
                'company_id' => $user->company_id,
                'actor_user_id' => $user->id,
                'action' => 'user.password_changed',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'old_values' => null,
                'new_values' => null,
                'ip_address' => request()?->ip(),
            ]);

            return $user->fresh();
        });
    }

    /**
     * TASK-044 Phase A — self-service bank payout details. Always
     * operates on $request->user() (see this Service's own class
     * docblock / UserProfileController's comment) — the "actor" of the
     * audit log entry is therefore always the same row being edited,
     * unlike e.g. UserService::moveToCompany() where actor != target.
     *
     * @param  array{bank_name?: string|null, bank_account_number?: string|null, bank_account_holder_name?: string|null}  $data
     */
    public function updateBankAccount(User $user, array $data): User
    {
        // BUG FIX (2026-07-23) — $user->save() and AuditLog::create() used
        // to run un-wrapped, same issue as UserService::update() (see that
        // method's own comment): if the audit-log write throws AFTER
        // $user->save() already committed, the request 500s but the bank
        // fields had already changed with no audit trail. Wrapped in
        // DB::transaction() so the two are atomic — either both persist or
        // neither does.
        return DB::transaction(function () use ($user, $data) {
            $oldValues = $this->maskedBankFields($user);

            // array_intersect_key so only the keys the FormRequest actually
            // received (`sometimes` rule) are touched — omitted fields keep
            // their existing value rather than being nulled out.
            $user->fill(array_intersect_key($data, array_flip([
                'bank_name', 'bank_account_number', 'bank_account_holder_name',
            ])));

            if ($user->isDirty(['bank_name', 'bank_account_number', 'bank_account_holder_name'])) {
                $user->save();

                // Section 6 Audit Log rule — bank fields are money-adjacent
                // (payout destination). bank_account_number is masked in
                // BOTH old_values and new_values via User::maskBankAccountNumber()
                // — the audit trail must never hold the full plaintext number
                // either, same rule as UserResource's JSON masking.
                AuditLog::create([
                    'company_id' => $user->company_id,
                    'actor_user_id' => $user->id,
                    'action' => 'user.bank_account_updated',
                    'auditable_type' => User::class,
                    'auditable_id' => $user->id,
                    'old_values' => $oldValues,
                    'new_values' => $this->maskedBankFields($user->fresh()),
                    'ip_address' => request()?->ip(),
                ]);
            }

            return $user->fresh();
        });
    }

    /**
     * @return array{bank_name: ?string, bank_account_number: ?string, bank_account_holder_name: ?string}
     */
    private function maskedBankFields(User $user): array
    {
        return [
            'bank_name' => $user->bank_name,
            'bank_account_number' => $user->maskedBankAccountNumber(),
            'bank_account_holder_name' => $user->bank_account_holder_name,
        ];
    }

    private function deleteFileIfSet(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
