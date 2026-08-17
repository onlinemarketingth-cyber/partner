<?php

namespace App\Services\Theme;

use App\Models\Company;
use App\Models\CompanyThemeSetting;
use App\Models\Scopes\TenantScope;
use App\Models\ThemePreset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TASK-161 §3.2 / §5 — business logic for company colour presets.
 *
 * BR-7: this class holds NO colour values. `COLOR_FIELDS` is a list of
 * COLUMN NAMES, not a palette — every actual hex comes from the company's
 * own `company_theme_settings` row (or, where that row has no opinion,
 * from ThemeService::defaults(), which is the platform's existing neutral
 * fallback and not a new invention). Designed starter palettes
 * ("โทนทอง", "โทนน้ำเงิน", …) remain BR-7 values that must come from the
 * human — §5.1 was approved precisely BECAUSE it copies what already
 * exists rather than choosing anything.
 *
 * §5/BR-6: `company_id` is always keyed from the passed Company / the
 * preset's own row, never from request input.
 *
 * TASK-164 amends the BR-7 note above in ONE respect: designed starter
 * palettes now exist, because the human supplied them (via ag-lead,
 * 2026-08-11) — which is exactly the condition TASK-161 §5.1 set. They
 * still are not held here. They live in `config/theme_presets.php`, which
 * is seed data a non-developer can be pointed at; this class reads that
 * file and writes rows, and continues to hold no colour of its own.
 */
class ThemePresetService
{
    /**
     * The name of the preset every company is provisioned with (§5.1,
     * human decision 2026-08-11).
     *
     * NO LONGER THE IDEMPOTENCY KEY — TASK-164 §1 moved that to
     * DEFAULT_PRESET_KEY below. The name remains matched against only to
     * recognise rows created before `key` existed (see provisionDefault).
     *
     * A LITERAL, not a translation lookup, because it is also matched
     * against on re-run — a name that changed with the request locale
     * would make the backfill create a second preset per language.
     */
    public const DEFAULT_PRESET_NAME = 'ค่าเริ่มต้น';

    /**
     * TASK-164 §2 — the idempotency handle of the auto-provisioned
     * snapshot. It is the company's restore point, so it is a system
     * preset: applicable, never renameable or deletable.
     *
     * A key rather than the name because a name is a label: it can be
     * translated, and (before this task) could be edited. Keying seeding
     * on it is how you get either a duplicate or an unrecognisable row.
     */
    public const DEFAULT_PRESET_KEY = 'company_default';

    /**
     * The refusal an admin sees when they try to change a system preset.
     * 422, not 403 (TASK-164 §1): the row exists and they may see and
     * apply it — they simply may not change it. A 403 would suggest they
     * are looking at something that is not theirs.
     */
    public const SYSTEM_PRESET_READ_ONLY_MESSAGE = 'ชุดสีนี้เป็นชุดมาตรฐานของระบบ จึงเปลี่ยนชื่อหรือลบไม่ได้ — กด "ใช้ชุดนี้" เพื่อนำไปใช้ได้ตามปกติ';

    public function __construct(private ThemeService $themeService) {}

    /**
     * The colour surface a preset carries — and nothing else.
     *
     * Explicitly ABSENT (TASK-161 §3.2): logos, favicon, fonts, labels,
     * nav_icon_overrides, recommended_slot_count, background_image_path.
     * Those are a company's identity or its configuration, not a "look".
     *
     * `background_type` IS included while `background_image_path` is not:
     * presets are company-scoped (human decision 2026-08-11), so a preset
     * that says `image` can only ever be re-applied to the same company —
     * i.e. onto the very image path it was captured beside, which is left
     * untouched by apply().
     *
     * @var list<string>
     */
    public const COLOR_FIELDS = [
        'primary_hex',
        'accent_hex',
        'nav_bg_hex',
        'nav_bg_type',
        'nav_bg_config',
        'nav_text_hex',
        'nav_active_hex',
        'card_bg_hex',
        'card_text_hex',
        'card_border_hex',
        'card_shadow',
        'background_type',
        'background_config',
    ];

    /**
     * Save the company's CURRENT colours under a name.
     *
     * The values are read from `company_theme_settings` SERVER-SIDE — the
     * endpoint accepts a name and nothing else (TASK-161 §3.2). A
     * client-supplied colour blob would be a way to write values that
     * never passed UpdateThemeRequest's field validation and then have
     * apply() paste them straight into the theme row.
     *
     * A company with no theme row yet snapshots all-nulls, which is a
     * legitimate preset: "back to the platform defaults".
     */
    public function snapshot(Company $company, string $name, ?User $actor = null): ThemePreset
    {
        /*
         * RESOLVED colours, the same as provisionDefault() — ag-lead, closing
         * the inconsistency ag-dev flagged in their §5 report.
         *
         * This used to store `extractColors($company->themeSetting()->first())`,
         * i.e. the RAW nullable columns. For a company that had never touched
         * the theme screen that is a preset of nulls: its swatches render
         * blank in the picker and it reads as a broken row rather than a
         * saved look.
         *
         * Two write paths storing different things under the same word is the
         * drift this file exists to avoid. The button says "บันทึกสีปัจจุบัน"
         * — save the colours currently on screen — and what is on screen for
         * an untouched company is the resolved defaults, not null.
         *
         * The cost, stated: a preset saved today freezes today's platform
         * defaults rather than tracking future changes to them. That is the
         * correct reading of "save what I am looking at", and it is what the
         * auto-provisioned preset already does.
         */
        return ThemePreset::create([
            'company_id' => $company->id,
            'name' => $name,
            'colors' => $this->resolvedColors($company),
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * §5.1 — give a company the one preset it is entitled to on day one:
     * a named snapshot of the theme it ALREADY has.
     *
     * Called from CompanyService::create() (inside that method's existing
     * transaction, alongside PipelineTemplateProvisioner — same reason: a
     * company that exists but is missing a piece of its own scaffolding
     * looks perfectly healthy right up until someone needs it) and from
     * the one-time backfill migration for companies that predate this.
     *
     * IDEMPOTENT: a company that already has a preset by
     * DEFAULT_PRESET_NAME is left exactly as it is — the existing row is
     * returned, never duplicated and never overwritten. An admin who
     * renamed or re-saved that preset owns it now; re-running the backfill
     * must not quietly stamp their colours back to whatever they were on
     * migration day.
     */
    public function provisionDefault(Company $company): ThemePreset
    {
        /*
         * TenantScope is bypassed EXPLICITLY, exactly as
         * PipelineTemplateProvisioner does and for the same reason: this
         * runs both unauthenticated (the backfill migration, where the
         * scope no-ops) and as a Super Admin creating a company they are
         * not a member of (where it also no-ops, but for a different
         * reason). Two different accidents of context producing the same
         * answer is not a guarantee — company_id is stated outright
         * instead. BR-6.
         *
         * TASK-164 §1: the match is on `key` FIRST — the idempotency
         * handle — and falls back to the name only to recognise rows
         * created before that column existed. Both are needed: the key is
         * the durable identifier going forward, the name is the only thing
         * an un-migrated row has.
         */
        $existing = ThemePreset::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $company->id)
            ->where(fn ($q) => $q
                ->where('key', self::DEFAULT_PRESET_KEY)
                ->orWhere('name', self::DEFAULT_PRESET_NAME))
            ->first();

        if ($existing) {
            // Converge a legacy row onto the key without touching its
            // COLOURS — those belong to whoever last saved them (§5.1's
            // "an admin who renamed or re-saved that preset owns it now").
            // Only the identity handle and the read-only flag are stamped.
            if ($existing->key === null) {
                $existing->forceFill([
                    'key' => self::DEFAULT_PRESET_KEY,
                    'is_system' => true,
                ])->save();
            }

            return $existing;
        }

        return ThemePreset::create([
            'company_id' => $company->id,
            'name' => self::DEFAULT_PRESET_NAME,
            'key' => self::DEFAULT_PRESET_KEY,
            'is_system' => true,
            'colors' => $this->resolvedColors($company),
            // No actor: this is provisioning, not somebody's saved look.
            'created_by' => null,
        ]);
    }

    /**
     * TASK-164 §3 — every company also gets the five DESIGNED palettes.
     *
     * The hex values are the human's, held in `config/theme_presets.php`
     * (BR-7: seed data, not logic — see this class's docblock). Nothing is
     * invented, substituted or normalised on the way through; a palette is
     * copied verbatim so what an admin applies is what was specified.
     *
     * IDEMPOTENT ON `key`, not on the name: re-running this can only ever
     * leave one row per key per company, and a company whose admin has
     * applied and re-saved a palette elsewhere is unaffected. Existing
     * rows are never updated — a palette whose colours were changed after
     * seeding is not a case that can arise (they are read-only), but
     * "never overwrite" is the rule this whole task rests on, so the code
     * says it rather than relying on that.
     */
    public function provisionDesignedPalettes(Company $company): void
    {
        foreach ($this->designedPalettes() as $palette) {
            $exists = ThemePreset::withoutGlobalScope(TenantScope::class)
                ->where('company_id', $company->id)
                ->where('key', $palette['key'])
                ->exists();

            if ($exists) {
                continue;
            }

            ThemePreset::create([
                'company_id' => $company->id,
                'name' => $palette['name'],
                'key' => $palette['key'],
                'is_system' => true,
                'colors' => $this->sanitize($palette['colors']),
                'created_by' => null,
            ]);
        }
    }

    /**
     * Everything the platform owes a company on day one: its restore point
     * plus the designed palettes. One entry point so CompanyService::create()
     * and the backfill migration cannot drift into provisioning different
     * sets (a company created through the Admin UI must not end up with
     * fewer presets than one that predates this task).
     */
    public function provisionSystemPresets(Company $company): void
    {
        $this->provisionDefault($company);
        $this->provisionDesignedPalettes($company);
    }

    /**
     * The five palettes as configured. Returned typed and validated-shaped
     * so a typo in the config file surfaces here rather than as a preset
     * that silently carries half a look.
     *
     * @return list<array{key: string, name: string, colors: array<string, mixed>}>
     */
    public function designedPalettes(): array
    {
        /** @var list<array{key: string, name: string, colors: array<string, mixed>}> $palettes */
        $palettes = config('theme_presets.designed', []);

        return $palettes;
    }

    /**
     * Copy a preset back into the company's theme row.
     *
     * ONE transaction (TASK-161 acceptance criteria): a half-applied theme
     * — new nav colour, old card colour — is worse than one that was never
     * applied, because the admin has no way to tell it happened. The
     * find-or-create and the write both sit inside it, so an apply racing
     * an ordinary theme save cannot interleave into a mixed row.
     *
     * The target company is the PRESET's `company_id`, never request
     * input: a preset can only ever be applied to the company that owns
     * it (§5/BR-6).
     *
     * @param  int  $companyId  the company the CALLER believes it is acting on
     *                          (ApplyThemePresetRequest::effectiveCompanyId()).
     *                          §5.2: "reading a preset from company A and
     *                          writing settings to company B must not be
     *                          expressible" — so the two are compared here
     *                          rather than one of them silently winning.
     *
     * @throws ValidationException when the preset does not belong to $companyId
     */
    public function apply(ThemePreset $preset, int $companyId): CompanyThemeSetting
    {
        /*
         * A SUPER ADMIN is exempt from TenantScope (§5 rule 4), so route
         * model binding hands them any preset in the platform and the
         * Policy waves them through. If the id they typed into the company
         * picker and the preset they clicked disagree, the write would land
         * on the wrong tenant SILENTLY. This check is the only thing
         * standing in the way — the same reasoning as ModuleOrderService's
         * second check (TASK-151).
         *
         * Deliberately in the SERVICE and not only in the Form Request: a
         * Request guards one HTTP route, this guards the method. It is also
         * why the mismatch is an error rather than "the preset's company
         * wins" — quietly ignoring the caller's stated target is how you
         * get an admin who is certain they edited company B.
         */
        if ($preset->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'company_id' => 'ชุดสีนี้ไม่ได้เป็นของบริษัทที่เลือกอยู่',
            ]);
        }

        return DB::transaction(fn () => CompanyThemeSetting::updateOrCreate(
            ['company_id' => $preset->company_id],
            $this->sanitize($preset->colors),
        ));
    }

    public function rename(ThemePreset $preset, string $name): ThemePreset
    {
        $this->guardNotSystem($preset);

        $preset->update(['name' => $name]);

        return $preset;
    }

    /**
     * TASK-164 §1 — deleting a preset, refusing if it is one of the
     * platform's.
     *
     * Exists as a Service method AT ALL for that guard: the controller
     * used to call `$themePreset->delete()` directly, which is the shape
     * that leaves a Policy as the only line of defence.
     */
    public function delete(ThemePreset $preset): void
    {
        $this->guardNotSystem($preset);

        $preset->delete();
    }

    /**
     * TASK-164 §1 — the SERVICE half of "read-only", re-checking what
     * ThemePresetPolicy already refused at the HTTP edge.
     *
     * Not redundant, and the spec says so outright: a Policy alone has
     * been insufficient in this codebase before (ModuleOrderService,
     * TASK-151). A Policy guards a route; this guards the method, so a
     * future console command, job or seeder that renames a preset without
     * going through Gate cannot quietly wipe out a company's restore
     * point.
     *
     * ValidationException, so both halves answer 422 with the same
     * message — the row exists and the admin may see and apply it, they
     * just may not change it.
     *
     * @throws ValidationException
     */
    private function guardNotSystem(ThemePreset $preset): void
    {
        if (! $preset->is_system) {
            return;
        }

        throw ValidationException::withMessages([
            'preset' => self::SYSTEM_PRESET_READ_ONLY_MESSAGE,
        ]);
    }

    /**
     * The colour subset of a theme row, with every field present (null when
     * unset) so a preset is a COMPLETE look — applying it must be able to
     * clear a colour the company set after the snapshot was taken, not
     * silently leave it behind.
     *
     * @return array<string, mixed>
     */
    private function extractColors(?CompanyThemeSetting $theme): array
    {
        $colors = [];
        foreach (self::COLOR_FIELDS as $field) {
            $colors[$field] = $theme?->{$field};
        }

        return $colors;
    }

    /**
     * Defence in depth on the way OUT of the json column: only known
     * colour columns are ever written back to `company_theme_settings`, so
     * even a row tampered with directly in the database cannot use apply()
     * to set `background_image_path`, `company_id` or anything else.
     *
     * @param  array<string, mixed>|null  $colors
     * @return array<string, mixed>
     */
    private function sanitize(?array $colors): array
    {
        return array_intersect_key(
            $colors ?? [],
            array_flip(self::COLOR_FIELDS),
        );
    }

    /**
     * §5.1 — the company's colours as the CLIENT would actually see them:
     * the stored row with the platform defaults filled in where it is
     * silent.
     *
     * Why not extractColors() (raw nullable columns)? A company with no
     * `company_theme_settings` row would store a preset of pure nulls: its
     * swatches would render blank in the Admin list and "ใช้ชุดนี้" would
     * be a no-op that looks broken. The provisioned preset is the one
     * every tenant sees first, so it has to be self-describing.
     *
     * The defaults come from ThemeService — the SAME source ThemeResource
     * reads (`$this->primary_hex ?? $defaults['primary_hex']`), so
     * "resolved" here means exactly what the API means by it. Writing a
     * second defaulting path is how the two drift apart, and inventing a
     * hex for a field ThemeService has no default for would be a BR-7
     * violation. Fields with no platform default (nav/card colours, which
     * the client resolves itself from its own neutral chrome) therefore
     * stay null on purpose: null is their resolved value.
     *
     * @return array<string, mixed>
     */
    private function resolvedColors(Company $company): array
    {
        // forCompanyPublic() rather than forCompany(): it is the variant
        // that reads the theme row WITHOUT the global scope (TASK-159), and
        // an explicit bypass is what provisioning needs — see the note in
        // provisionDefault(). Both call the same hydrate() underneath, so
        // a company with no row still yields an object rather than null.
        $colors = $this->extractColors($this->themeService->forCompanyPublic($company));

        foreach ($this->themeService->defaults() as $field => $value) {
            // Only fields that are part of the colour surface — defaults()
            // also carries font_family, which a preset must never hold
            // (§3.2: a font is identity, not a "look").
            if (array_key_exists($field, $colors) && $colors[$field] === null) {
                $colors[$field] = $value;
            }
        }

        return $colors;
    }
}
