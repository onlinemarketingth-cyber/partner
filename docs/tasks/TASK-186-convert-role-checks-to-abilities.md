# TASK-186 — convert the 29 role checks onto the resolver

- **Owner:** ag-lead (spec) → ag-dev → ag-qa
- **Date:** 2026-08-13
- **Related:** ADR-032 §2.1, TASK-185 (its output is your input and your safety net)
- **Depends on:** TASK-185 — done. `App\Enums\Ability` (32 cases),
  `App\Services\Authorization\PermissionResolver`, and a 120-test characterization suite exist.

---

## 1. Goal

Replace the 29 hand-written role checks TASK-185 catalogued with one question asked one way.

**Not one user's access may change.** This is a refactor. The characterization suite is the proof,
and it was written against untouched code precisely so it can hold you to that.

## 2. Scope

**In — exactly the 29 sites TASK-185 derived its catalogue from:**

- the **17 `abort_unless(...role...)`** sites in `backend/app/Http/Controllers/Api/V1/`
- the **12 Form Requests** whose `authorize()` is a raw `isCompanyAdmin() || isSuperAdmin()`

Each becomes the corresponding `Ability` check, via the Gate wiring TASK-185 installed.

**Out — do not touch, in this task:**

- the 41 Policies (a later task)
- the ~35 `Rule::prohibitedIf` validation-as-authorization sites (a later task)
- the ~30 Services re-deriving scope and ~25 Controllers narrowing index queries (a later task)
- the frontend (a later task)
- **every defect TASK-185 recorded** — see §4

## 3. The rule that makes this safe

**You may not edit `tests/Feature/Authorization/RoleGateCharacterizationTest.php`.**

It is the safety net. A net you are allowed to move is not a net. If a characterization test goes
red, the correct response is to fix your conversion — or, if you genuinely believe the test is
wrong, **stop and report to ag-lead**. Do not adjust an assertion to match your new code. This is
the single rule that decides whether this phase is trustworthy.

Adding *new* tests is fine and encouraged.

## 4. Behaviours that must survive unchanged — including the ugly ones

TASK-185 recorded six oddities. **All six must still behave identically after your change.**
Specifically:

1. **`GET /video-processing-settings` as Super Admin with no `?company_id` must still 500.** The
   TypeError happens *after* the role check, so converting the role check must not accidentally
   turn it into a 403 or a 200. It is a real defect with its own task; fixing it here would make
   this diff unreviewable.
2. **`POST /user-certifications` keeps having no Policy.** Convert the Form Request's role check
   to the ability; do not add a Policy, do not add `$this->authorize()` in the controller.
3. **The silent cross-tenant coercion stays silent.** A Company Admin naming another company's
   `company_id` still gets their own tenant's answer with no refusal and no log. Making that a 403
   is a real improvement and a real behaviour change — it is **not** this task.
4. **`GET /agent-targets` cross-tenant still returns 200 with an empty list**, not 403/404.
5. Status codes are preserved exactly: 403 stays 403, 404 stays 404, 422 stays 422, 204 stays 204.

If you find yourself wanting to improve any of these, that instinct is right and the timing is
wrong. Note it in your report.

## 5. How to convert

- **Controllers:** the `abort_unless(role, 403)` becomes an ability check that produces the **same
  403**. If you use `Gate::authorize()` / `$this->authorize()`, confirm the resulting status code
  is 403 and not 401/404 for each actor in the matrix — Laravel's default for a denied gate is 403,
  but verify rather than assume.
- **Form Requests:** `authorize()` returns the ability check. Laravel turns `false` into 403; the
  characterization suite already pins that.
- **One question, one way.** After this task there must be **zero** `isCompanyAdmin()` /
  `isSuperAdmin()` calls left in those 29 sites. Grep and show it.
- Do not "tidy" anything else in the files you touch. A small diff is the point.

## 6. Verification

- `tests/Feature/Authorization/RoleGateCharacterizationTest.php` — **green, unedited.** Show
  `git diff --stat` or an equivalent proof that the file is byte-identical.
- Every other backend suite green.
- Grep proving no raw role check remains among the 29 sites.
- **Mutation check:** point one converted site at the *wrong* ability, observe the characterization
  suite go red, restore. Report the observed failure count. If a wrong ability does **not** turn
  the suite red, that is a hole in the net and a finding — report it rather than moving on.
- `pint` on every file touched.

Run the real command and report its real output. `php artisan test` may not run in your
environment; previous agents used `@php-wasm/cli@3.1.49` + PHPUnit directly against a **copy** of
`backend/` in `/tmp`, patching `RefreshDatabase::migrateDatabases()` to `Artisan::call(...)` **in
the copy only**. Delete the copy; never patch the real repo. **Never report a result you did not
observe** (CLAUDE.md §8 rule 4).

## 7. Definition of Done

CLAUDE.md §9, plus: 29 sites converted, the characterization file untouched and green, all six
recorded oddities still behaving exactly as recorded, and a mutation proving the net actually
catches a wrong ability.
