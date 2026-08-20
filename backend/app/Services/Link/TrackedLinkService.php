<?php

namespace App\Services\Link;

use App\Enums\TrackedLinkGroup;
use App\Models\TrackedLink;
use App\Models\TrackedLinkVisit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TASK-232 — mints short codes, resolves them, and records what happened.
 *
 * The ONE place that knows how a code is made and how a visit is counted.
 * Six token tables already exist, each having invented its own answer to
 * the first half; this file is the reason there will not be a seventh.
 */
class TrackedLinkService
{
    /**
     * The code alphabet — 56 characters.
     *
     * Base62 MINUS `0 O o 1 l I`. Those six are the pairs a human cannot
     * tell apart in a sans-serif font, and codes get read off paper: a
     * flyer, a business card, the QR that would not scan so the customer
     * squints at the caption underneath. Every one of those six is a
     * support ticket that reads "the link doesn't work" from someone who
     * typed the link correctly as far as they could tell.
     *
     * The loss is small — 56^10 is still ~3.0e17 — and it buys a code that
     * survives being spoken over the phone.
     */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    /**
     * Codes a human may not claim, whatever the group.
     *
     * The agent portal serves real pages at the top level, and the two
     * groups that allow custom codes sit one segment deep (`/c/thailife`).
     * That prefix already makes a collision with `/login` impossible — but
     * `admin`, `api` and `assets` are reserved anyway because they are
     * paths on the SAME host that a future deploy could start serving, and
     * a link that dies because somebody added a folder is the kind of
     * failure nobody thinks to look for. TASK-231 is a fresh reminder of
     * how invisible a routing failure is from the inside.
     */
    private const RESERVED_CODES = [
        'admin', 'api', 'assets', 'login', 'logout', 'register', 'app',
        'www', 'mail', 'static', 'public', 'storage', 'health', 'up',
    ];

    /**
     * Mint (or reuse) the short link for one target.
     *
     * IDEMPOTENT PER TARGET, deliberately. Tapping "แชร์" on the same
     * product twice must produce the same short link, not two — otherwise
     * the counts for one product split across however many times the agent
     * happened to press the button, and the number they are shown is
     * meaningless. `ProductShareLinkService` already reuses its own row for
     * exactly this reason; this mirrors it.
     */
    public function mintFor(
        TrackedLinkGroup $group,
        Model $target,
        ?User $actor = null,
        ?string $label = null,
        ?string $customCode = null,
    ): TrackedLink {
        $expected = $group->targetClass();
        if (! $target instanceof $expected) {
            throw ValidationException::withMessages([
                'group' => 'ลิงก์กลุ่มนี้ไม่รองรับข้อมูลที่ส่งมา',
            ]);
        }

        $existing = TrackedLink::withoutGlobalScopes()
            ->where('target_type', $target->getMorphClass())
            ->where('target_id', $target->getKey())
            ->whereNull('revoked_at')
            ->first();

        if ($existing) {
            // A label supplied later is an edit, not a reason to mint a
            // second link — the agent naming a campaign after the fact is
            // the normal case, not an exception.
            if ($label !== null && $label !== $existing->label) {
                $existing->update(['label' => $label]);
            }

            return $existing;
        }

        return TrackedLink::withoutGlobalScopes()->create([
            // A Company is its own tenant — it has no `company_id` column,
            // and its primary key is the answer. Written as a fallback
            // rather than a match on the group so that any future
            // self-tenanted target works without editing this line.
            'company_id' => $target->getAttribute('company_id') ?? $target->getKey(),
            'code' => $this->resolveCode($group, $customCode),
            'group' => $group,
            'target_type' => $target->getMorphClass(),
            'target_id' => $target->getKey(),
            'label' => $label,
            'created_by_user_id' => $actor?->id,
        ]);
    }

    /**
     * Find a link by its public code.
     *
     * `withoutGlobalScopes()` because the caller is a stranger with no
     * company of their own — the same escape SalesMaterialShareLink and
     * ProductShareLink already make, for the same reason, in the same one
     * place each.
     *
     * Returns null for unknown, revoked AND expired alike. The caller must
     * answer all three with an identical 404: telling an attacker apart
     * "no such code" from "that code exists but expired" hands them a
     * working oracle for enumerating codes.
     */
    public function resolve(string $code): ?TrackedLink
    {
        $link = TrackedLink::withoutGlobalScopes()->where('code', $code)->first();

        return $link && $link->isUsable() ? $link : null;
    }

    /**
     * Resolve a short code straight to its target model.
     *
     * The convenience twin of `resolve()`, for callers that only want the
     * thing behind the link. Returns null for every failure — unknown,
     * revoked, expired, wrong group, or a target that has since been
     * deleted — so the caller answers all of them with one identical
     * response and a stranger cannot tell them apart.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $expected
     * @return TModel|null
     */
    public function resolveTarget(string $code, TrackedLinkGroup $group, string $expected): ?Model
    {
        $link = $this->resolve($code);

        if (! $link || $link->group !== $group) {
            return null;
        }

        $target = $link->target()->withoutGlobalScopes()->first();

        return $target instanceof $expected ? $target : null;
    }

    /**
     * Record one opening and roll the counters forward.
     *
     * WHY THIS IS CALLED FROM THE API RESOLVER AND NOT A REDIRECT.
     * The short link lives on the agent portal's own domain, so the
     * sequence is: browser loads the SPA, SPA calls this endpoint. LINE,
     * Facebook and Messenger fetch a URL the instant it is pasted into a
     * chat, to build the preview card — but they only take the HTML, they
     * do not run the JavaScript, so they never reach this method.
     *
     * That is worth stating plainly because it is the difference between
     * this feature being trusted and being ignored. An agent who shares a
     * link and immediately sees "5 opens" before a single human has looked
     * at it stops believing the number, permanently. The redirect-service
     * design we did NOT build would have needed a hand-maintained list of
     * crawler user agents to get here; this one is simply out of their
     * reach.
     */
    public function recordVisit(TrackedLink $link, Request $request): TrackedLinkVisit
    {
        $ipHash = hash_hmac('sha256', (string) $request->ip(), config('app.key'));
        $now = now();

        // Same visitor, same link, same calendar day = not a new person.
        // Counting per-day rather than for all time is the honest middle:
        // someone returning a week later genuinely is worth counting again,
        // and re-reading a page four times in one evening is not four
        // people.
        $isUnique = ! TrackedLinkVisit::withoutGlobalScopes()
            ->where('tracked_link_id', $link->id)
            ->where('ip_hash', $ipHash)
            ->whereDate('visited_at', $now->toDateString())
            ->exists();

        return DB::transaction(function () use ($link, $request, $ipHash, $now, $isUnique) {
            $visit = TrackedLinkVisit::withoutGlobalScopes()->create([
                'company_id' => $link->company_id,
                'tracked_link_id' => $link->id,
                'visited_at' => $now,
                'ip_hash' => $ipHash,
                'user_agent' => $this->truncate($request->userAgent(), 512),
                'referrer_host' => $this->referrerHost($request),
                'device_type' => $this->deviceType($request->userAgent()),
                'is_unique' => $isUnique,
                'created_at' => $now,
            ]);

            $link->increment('click_count');
            if ($isUnique) {
                $link->increment('unique_click_count');
            }
            $link->forceFill([
                'first_clicked_at' => $link->first_clicked_at ?? $now,
                'last_clicked_at' => $now,
            ])->save();

            return $visit;
        });
    }

    /**
     * Attribute a conversion to a link.
     *
     * The counter here is a cache; the fact lives on the converted record
     * itself (`users.tracked_link_id`, `orders.tracked_link_id`,
     * `referrals.tracked_link_id`) exactly as `referrals.affiliate_link_id`
     * and `users.recruited_via_agent_link_id` already do. Keeping the truth
     * on the record means a conversion survives this counter being wrong,
     * and can be recounted.
     */
    public function recordConversion(TrackedLink $link): void
    {
        $link->increment('conversion_count');
    }

    /**
     * Host only — never the full referring URL.
     *
     * `line.me` is the entire useful content of a referrer for these
     * reports. The rest of the URL can carry a search query, an email
     * subject, or the address of a private group chat, none of which this
     * application has any business storing to answer "where do our
     * customers come from".
     */
    private function referrerHost(Request $request): ?string
    {
        $referer = $request->headers->get('referer');
        if (! $referer) {
            return null;
        }

        $host = parse_url($referer, PHP_URL_HOST);

        return is_string($host) ? $this->truncate($host, 255) : null;
    }

    /**
     * mobile / tablet / desktop, best effort.
     *
     * Substring matching on the user agent, not a UA-parsing library. The
     * only question the reports ask is "should this page be designed for a
     * phone", and for that a three-way split is enough. A dependency that
     * needs updating whenever a new device ships would cost more than the
     * precision is worth here — and `null` is a legitimate answer rather
     * than a guess.
     */
    private function deviceType(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        $ua = strtolower($userAgent);

        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')) {
            return 'tablet';
        }

        if (str_contains($ua, 'mobi') || str_contains($ua, 'iphone') || str_contains($ua, 'android')) {
            return 'mobile';
        }

        return 'desktop';
    }

    private function truncate(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }

    private function resolveCode(TrackedLinkGroup $group, ?string $customCode): string
    {
        if ($customCode === null) {
            return $this->generateUniqueCode($group->codeLength());
        }

        if (! $group->allowsCustomCode()) {
            throw ValidationException::withMessages([
                'code' => 'ลิงก์กลุ่มนี้ต้องใช้รหัสสุ่มเท่านั้น ตั้งเองไม่ได้',
            ]);
        }

        $normalized = strtolower(trim($customCode));

        if (in_array($normalized, self::RESERVED_CODES, true)) {
            throw ValidationException::withMessages([
                'code' => 'รหัสนี้เป็นคำสงวนของระบบ กรุณาใช้รหัสอื่น',
            ]);
        }

        if (TrackedLink::withoutGlobalScopes()->where('code', $normalized)->exists()) {
            throw ValidationException::withMessages([
                'code' => 'รหัสนี้ถูกใช้ไปแล้ว กรุณาใช้รหัสอื่น',
            ]);
        }

        return $normalized;
    }

    /**
     * Retry on collision rather than trusting the odds.
     *
     * At 56^10 a collision is not going to happen, but "not going to
     * happen" is the reasoning behind every unique-constraint violation
     * that ever reached production at 2am. `AgentInviteLinkService` already
     * loops; this does the same thing for the same reason. The bounded
     * attempt count is there so a genuinely exhausted namespace surfaces as
     * an error instead of an infinite loop.
     */
    private function generateUniqueCode(int $length): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $this->randomCode($length);

            if (! TrackedLink::withoutGlobalScopes()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw ValidationException::withMessages([
            'code' => 'สร้างรหัสลิงก์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง',
        ]);
    }

    private function randomCode(int $length): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            // random_int, not rand()/mt_rand(): these codes are the only
            // thing standing between a stranger and an order's contents.
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
