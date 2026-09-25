<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IdDocumentType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\MoveUserCompanyRequest;
use App\Http\Requests\Platform\ResetUserPasswordRequest;
use App\Http\Requests\Platform\StoreUserRequest;
use App\Http\Requests\Platform\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Platform\AccountActivityProbe;
use App\Services\Platform\UserService;
use App\Support\CompanyScopeFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// "Manage Agents" — Company Admin's own team (agent + company_admin
// roles only, TenantScope already narrows Company Admin to their own
// company_id; Super Admin's queries are unscoped by TenantScope so they
// see every company by default — see TenantScope's own docblock).
//
// 2026-09-18 — Super Admin rows are no longer "always excluded from this
// list (UserPolicy::view() backs this up too) — they aren't 'team
// members' to browse/manage here". They are excluded from EVERYBODY
// ELSE'S list and shown to a Super Admin, who can now create, promote
// and demote them from this screen (UserPolicy's docblock says why the
// rule changed). Two consequences are handled in index(): the header's
// company scope must not hide them, and they sort to the top.
class UserController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(User::class, 'user');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        // `currentRank` since 2026-09-25 — the roster's "ขั้นปัจจุบัน". One
        // eager load for the page rather than a query per row; UserResource
        // only emits it for a company whose plan runs on ranks.
        $query = User::query()->with(['company', 'manager', 'currentRank']);

        $viewerIsSuperAdmin = (bool) $request->user()?->isSuperAdmin();

        /*
         * 2026-09-18 — was an unconditional
         * `where('role', '!=', 'super_admin')`.
         *
         * A Company Admin's list is unchanged: still no platform owners in
         * it, for the reason UserPolicy::view() gives. What changed is that
         * a Super Admin now sees them, because the screen that creates and
         * demotes them has to list them.
         *
         * This is the LIST half only, and it is a convenience, not the
         * control: UserPolicy::view() refuses a Super Admin row to anyone
         * else one at a time, so a Company Admin who guessed an id still
         * gets a 403 from /users/{id} whatever this query returns.
         */
        if (! $viewerIsSuperAdmin) {
            $query->where('role', '!=', UserRole::SuperAdmin->value);
        }

        if ($viewerIsSuperAdmin && $request->filled('company_id')) {
            /*
             * 2026-09-18 — the header's company scope, WITH the platform
             * owners kept in it.
             *
             * A Super Admin has no company_id, so the plain filter below
             * would drop every one of them the moment a company is picked
             * in the header — and "จัดการผู้ใช้ระบบ shows no Super Admins"
             * is indistinguishable from "there are none", on the one screen
             * that is supposed to account for them.
             *
             * Not CompanyScopeFilter's `includePlatformWide` flag, which
             * means something else here: on `users` a NULL company_id is
             * also what a `company_partner` carries, and a supplier's login
             * is not "platform-wide" — it has nothing to do with the company
             * being looked at, and should keep disappearing under a company
             * scope exactly as it does today.
             */
            $companyId = $request->integer('company_id');

            $query->where(fn (Builder $q) => $q
                ->where('company_id', $companyId)
                ->orWhere('role', UserRole::SuperAdmin->value));
        } else {
            // TASK-209 — Super Admin's header company scope, applied in SQL.
            CompanyScopeFilter::apply($query, $request);
        }

        if ($request->boolean('include_inactive')) {
            $query->withTrashed();
        }

        /*
         * TASK-259 — "จัดการผู้ใช้ระบบ" needs to ask two questions this
         * endpoint could not answer.
         *
         * ?role= — "who are the admins?" was previously answerable only by
         * loading every agent and filtering in the browser, which this
         * endpoint's own pagination makes a lie (page 1 of everybody is not
         * page 1 of the admins).
         */
        if ($request->filled('role')) {
            // 2026-09-18 — `super_admin` is now a value this filter can
            // meaningfully take, and only for a Super Admin: the branch above
            // has already narrowed anybody else's query to exclude those
            // rows, so asking for them still returns nothing rather than
            // quietly widening the list. The old note said the same thing
            // about a stricter base query; the guarantee is unchanged.
            $query->where('role', $request->string('role')->toString());
        }

        /*
         * 2026-09-18 — ?admins_only=1 (human: "ระบบตอนนี้เอา User ทุกคนทั้ง
         * Agent และมารวมกัน แต่ในการตั้งค่า ความต้องการจริงคือ Admin เท่านั้น").
         *
         * จัดการผู้ใช้ระบบ is about who administers the system. Agents are
         * managed on จัดการตัวแทน, which is about selling — and mixing the
         * two made a settings screen where a company's two admins were
         * buried under two hundred salespeople.
         *
         * IN SQL, not in the browser, and for the same reason `?role=`
         * exists (TASK-259): this endpoint paginates at 15, so "load
         * everybody and filter" is a lie — page 1 of everybody is not page 1
         * of the admins.
         *
         * Why not `?role=` four times: it takes ONE value, and the answer
         * this screen needs is "everyone who is not an agent" — which stays
         * correct when a new administrative role is added, where a list of
         * four literals in the query string would quietly omit it.
         */
        if ($request->boolean('admins_only')) {
            $query->where('role', '!=', UserRole::Agent->value);
        }

        /*
         * ?with_last_login=1 — "is this account still in use?" The answer has
         * been in audit_logs since 2026-08-21 and nothing read it back.
         *
         * A SUBSELECT, not a join or a second query per row: a join against a
         * table with many rows per user would multiply the result set and
         * break the pagination this endpoint depends on, and N+1 per row is
         * what makes an admin screen feel broken at 200 users. TASK-240's
         * (actor_user_id, created_at) index is exactly what this reads.
         *
         * Opt-in, because every other caller of /users (four admin screens
         * and a dropdown) has no use for it and should not pay for it.
         */
        if ($request->boolean('with_last_login')) {
            $query->select('users.*')->addSelect(['last_login_at' => AuditLog::query()
                ->select('created_at')
                ->whereColumn('actor_user_id', 'users.id')
                ->where('action', 'auth.login')
                ->orderByDesc('created_at')
                ->limit(1),
            ]);
        }

        /*
         * 2026-09-18 — ?with_activity=1 (human: "หากไม่มีกิจกรรม การซื้อขาย
         * อะไร ให้สามารถลบ รายชื่อสมาชิก แบบ Soft Delete ได้").
         *
         * The roster needs "has this person ever done anything?" per row,
         * to decide whether ลบสมาชิก is offered and — when it is not — to
         * say WHY in a sentence with numbers in it.
         *
         * ONE query for the page, not seven per row: `withCount` compiles
         * to correlated subselects on the row the list is already
         * fetching, the same shape `?with_last_login=1` above uses and for
         * the same reason. The relation list comes from the probe rather
         * than being spelled out here, so a check added there cannot
         * quietly become an N+1 nobody notices until the roster has two
         * hundred people on it.
         *
         * Opt-in, because the other callers of /users render no delete
         * button and should not pay seven subselects for it.
         */
        if ($request->boolean('with_activity')) {
            $query->withCount(AccountActivityProbe::countableRelations());
        }

        /*
         * ?with_abilities=1 — 2026-09-10. "Who in this company may redeem a
         * voucher?" is the question the grant screen exists to answer, and it
         * has to be answerable at a GLANCE, not one row at a time: a right
         * that can only be inspected by opening each person in turn is one
         * nobody audits.
         *
         * An eager load, so it is ONE extra query for the page rather than one
         * per row — and opt-in, because the four other callers of /users have
         * no use for it (see UserResource's `granted_abilities`).
         */
        if ($request->boolean('with_abilities')) {
            $query->with('abilityGrants');
        }

        // TASK-060 — search, same pattern as ClientController::index
        // (TASK-049). `q` is a free-text LIKE across name/phone/email
        // (partial match). `national_id` is EXACT-only: the column is
        // encrypted and therefore unsearchable directly, so we match on
        // the deterministic blind index (User::hashNationalId) — a
        // caller must supply the full number to get a hit. (TASK-122:
        // "national_id" now means "identity document number", which may be
        // a passport — see the block below.)
        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }
        if (($nationalId = trim((string) $request->query('national_id', ''))) !== '') {
            // TASK-122 — the column may now hold a Thai national ID OR a
            // passport, and the blind index normalizes the two differently
            // (User::hashNationalId), so ONE hash is no longer enough.
            //
            // HOW THE CALLER SIGNALS THE TYPE: optionally, with
            // `?id_document_type=thai_national_id|passport`. When it is
            // supplied we match that hash only. When it is ABSENT — which is
            // the case for every existing caller, including the Admin search
            // box that just wants "find whoever this number belongs to" — we
            // canonicalise DEFENSIVELY and try both. That is safe rather than
            // sloppy: the two candidates are HMACs of two different strings,
            // so a row can only match if its own stored document really does
            // canonicalise to one of them. It is not a fuzzy match.
            $type = IdDocumentType::tryFrom((string) $request->query('id_document_type', ''));

            $candidates = $type !== null
                ? [User::hashNationalId($nationalId, $type)]
                : [
                    User::hashNationalId($nationalId, IdDocumentType::ThaiNationalId),
                    User::hashNationalId($nationalId, IdDocumentType::Passport),
                ];

            // array_unique because a digits-only search term canonicalises
            // identically under both rules and would otherwise be asked for
            // twice. array_filter because a term that normalizes to nothing
            // hashes to null — and a null in this list would let Eloquent
            // rewrite the comparison into an IS NULL and wrongly return every
            // agent with NO document on file. If everything filters out, the
            // sentinel below can never equal a real 64-hex-char HMAC, so the
            // result is zero rows (never "all rows").
            $candidates = array_values(array_unique(array_filter($candidates)));

            $query->whereIn('national_id_hash', $candidates ?: ['no-match']);
        }

        /*
         * 2026-09-18 — platform owners first, then by name as before.
         *
         * This list paginates at 15. A handful of Super Admins sorted purely
         * by name would scatter through the pages of a 200-person company and
         * could easily be on none of the first few — which on this screen
         * reads as "there aren't any", the exact misreading the company-scope
         * branch above also exists to prevent. Bound rather than
         * interpolated so the enum stays the single source of the literal.
         */
        return UserResource::collection(
            $query
                ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [UserRole::SuperAdmin->value])
                ->orderBy('name')
                ->paginate()
        );
    }

    public function store(StoreUserRequest $request, UserService $service): UserResource
    {
        return new UserResource($service->create($request->validated(), $request->user())->load('company'));
    }

    public function show(User $user): UserResource
    {
        // 2026-09-10 — grants are loaded HERE and not on index(): the detail
        // screen edits them, a list of a hundred users would issue a query
        // each to render a toggle nobody is looking at.
        return new UserResource($user->load(['company', 'abilityGrants']));
    }

    public function update(UpdateUserRequest $request, User $user, UserService $service): UserResource
    {
        return new UserResource($service->update($user, $request->validated(), $request->user())->load('company'));
    }

    /**
     * Soft-delete (deactivate) — see UserPolicy::delete() for the self-lockout guard.
     *
     * TASK-183 §4.1 — $request->user() is passed through as the ACTOR for the
     * audit row. Same for restore() and resetPassword() below: the Service
     * cannot infer who did this, and "who" is most of the point of the row.
     */
    public function destroy(Request $request, User $user, UserService $service): Response
    {
        /*
         * 2026-09-18 — `release_email` says WHICH ACT this was: removal from
         * the roster (ลบสมาชิก / ลบผู้สมัคร) rather than a switch-off from
         * the edit modal. Both soft-delete; only removal gives the address
         * back, and only when the account is provably untouched — the
         * service re-checks that and refuses, so this flag can widen
         * nothing by itself.
         */
        $service->deactivate($user, $request->user(), $request->boolean('release_email'));

        return response()->noContent();
    }

    /** POST /users/{user}/restore — reactivate a deactivated agent. Route uses withTrashed binding. */
    public function restore(Request $request, User $user, UserService $service): UserResource
    {
        $this->authorize('restore', $user);

        return new UserResource($service->restore($user, $request->user())->load('company'));
    }

    /** POST /users/{user}/reset-password — the "no email system" companion to StoreUserRequest's temp password. */
    public function resetPassword(ResetUserPasswordRequest $request, User $user, UserService $service): UserResource
    {
        return new UserResource(
            $service->resetPassword($user, $request->validated('password'), $request->user())->load('company')
        );
    }

    /** POST /users/{user}/move-company — Phase 11, Super-Admin-only (UserPolicy::move()). */
    public function moveToCompany(MoveUserCompanyRequest $request, User $user, UserService $service): UserResource
    {
        return new UserResource($service->moveToCompany($user, $request->validated('company_id'), $request->user())->load('company'));
    }
}
