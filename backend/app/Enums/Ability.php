<?php

namespace App\Enums;

/**
 * ADR-032 §2.2 / TASK-185 §2 — the ability catalogue.
 *
 * ONE CASE PER DISTINCT QUESTION THE CURRENT CODE ASKS. Every case below is
 * derived from a real call site that exists today; the docblock on each names
 * the file and line it came from. Nothing here was invented from what a
 * permission system "ought" to have — that is the whole point of Phase 1, and
 * the provenance is what makes TASK-186's conversions reviewable.
 *
 * CLOSED SET, CODE-DEFINED (ADR-032 §2.2). Admins pick from this list; they
 * never type their own. Adding a case is a code change plus an ADR — the same
 * shape ADR-026 chose for PipelineStage, for the same reason.
 *
 * NOT MERGED ON A HUNCH. Many of the sites below compute the byte-identical
 * boolean `isCompanyAdmin() || isSuperAdmin()`. They are still separate
 * abilities, because ADR-032 §2.2/Phase 3 exist to let one company grant an
 * accountant `commission.*` without also granting `academy.*`. Collapsing 28
 * settings screens into one `admin.anything` case would make that impossible
 * and would be a one-way widening the moment any of them diverges. Where two
 * sites genuinely ask the same question they share a case; where they differ
 * in ANY condition they are two (TASK-185 §2).
 *
 * NOTHING CONSUMES THIS YET. TASK-185 converts no call site: the enum, the
 * resolver and the Gate wiring exist so TASK-186 onwards has somewhere to move
 * each site TO, one at a time, against the characterization suite in
 * tests/Feature/Authorization.
 */
enum Ability: string
{
    // ---------------------------------------------------------------
    // Reports (TASK-041) — all three are `abort_unless` role gates in
    // Controllers with no Policy behind them.
    // ---------------------------------------------------------------

    /** Cross-company platform report. From PlatformReportController.php:22 (`abort_unless(isSuperAdmin(), 403)` — the ONLY Super-Admin-only site of the 17). */
    case ReportPlatformView = 'report.platform.view';

    /** PDPA/compliance aggregate over client consent state. From ComplianceReportController.php:19. */
    case ReportComplianceView = 'report.compliance.view';

    /** BR-7 config-health tracker. From ConfigHealthReportController.php:19. */
    case ReportConfigHealthView = 'report.config_health.view';

    // ---------------------------------------------------------------
    // Sales oversight
    // ---------------------------------------------------------------

    /** Company-wide "ทีมขาย" leadership cockpit. From SalesTeamOverviewController.php:19. */
    case SalesTeamOverviewView = 'sales.team_overview.view';

    /** Chart-based agent dashboard metrics. From AgentDashboardMetricsController.php:17. */
    case SalesAgentDashboardMetricsView = 'sales.agent_dashboard_metrics.view';

    // ---------------------------------------------------------------
    // Commission
    // ---------------------------------------------------------------

    /** Per-agent commission summary on screen. From AgentCommissionSummaryController.php:53. */
    case CommissionAgentSummaryView = 'commission.agent_summary.view';

    /**
     * Per-agent commission summary as a payout CSV. From
     * AgentCommissionSummaryController.php:123.
     *
     * NOT merged with CommissionAgentSummaryView even though the boolean is
     * identical today: the export produces a pending-only payout FILE carrying
     * bank-account columns, which is a different thing to be permitted than
     * reading totals on a screen. Merging them would be a silent widening the
     * first time a company wants "may look, may not export".
     */
    case CommissionAgentSummaryExport = 'commission.agent_summary.export';

    // ---------------------------------------------------------------
    // Agent targets (TASK-053 / ADR-016)
    // ---------------------------------------------------------------

    /** Read one agent's targets to prefill the Admin editor. From AgentTargetController.php:46. */
    case AgentTargetView = 'agent.target.view';

    /** Set/update an agent's target for a period. From AgentTargetController.php:58. */
    case AgentTargetUpdate = 'agent.target.update';

    // ---------------------------------------------------------------
    // Company settings — READ. Each of these is an `abort_unless` in the
    // settings Controller's show(). Deliberately one case per settings
    // area, not one shared `settings.view`.
    // ---------------------------------------------------------------

    /** From TeamVisibilitySettingController.php:22. */
    case SettingsTeamVisibilityView = 'settings.team_visibility.view';

    /** From AcademyCompletionSettingController.php:22. */
    case SettingsAcademyCompletionView = 'settings.academy_completion.view';

    /** From CommissionBinarySettingController.php:21. */
    case SettingsCommissionBinaryView = 'settings.commission_binary.view';

    /** From CommissionMatrixSettingController.php:17. */
    case SettingsCommissionMatrixView = 'settings.commission_matrix.view';

    /** From CommissionGenerationSettingController.php:17. */
    case SettingsCommissionGenerationView = 'settings.commission_generation.view';

    /** From AgentRankSettingController.php:18. */
    case SettingsAgentRankView = 'settings.agent_rank.view';

    /** From VideoProcessingSettingController.php:17. */
    case SettingsVideoProcessingView = 'settings.video_processing.view';

    /**
     * Upload a logo/background image for the company theme. From
     * CompanyThemeController.php:48.
     *
     * Separate from SettingsCompanyThemeUpdate: that one writes presentational
     * config values through UpdateThemeRequest, this one accepts a FILE onto
     * the disk. Same boolean today, two different things to be permitted.
     */
    case SettingsCompanyThemeUploadAsset = 'settings.company_theme.upload_asset';

    // ---------------------------------------------------------------
    // Company settings — WRITE. Each of these is the ENTIRE body of a
    // Form Request's authorize() (a raw `isSuperAdmin() || isCompanyAdmin()`),
    // with no Policy behind it.
    // ---------------------------------------------------------------

    /** From Theme/UpdateThemeRequest.php:18. */
    case SettingsCompanyThemeUpdate = 'settings.company_theme.update';

    /** From Sales/UpdateTeamVisibilitySettingRequest.php:19. */
    case SettingsTeamVisibilityUpdate = 'settings.team_visibility.update';

    /** From Academy/UpdateAcademyCompletionSettingRequest.php:14. */
    case SettingsAcademyCompletionUpdate = 'settings.academy_completion.update';

    /** From Commission/UpdateCommissionBinarySettingRequest.php:21. */
    case SettingsCommissionBinaryUpdate = 'settings.commission_binary.update';

    /** From Commission/UpdateCommissionMatrixSettingRequest.php:18. */
    case SettingsCommissionMatrixUpdate = 'settings.commission_matrix.update';

    /** From Commission/UpdateCommissionGenerationSettingRequest.php:15. */
    case SettingsCommissionGenerationUpdate = 'settings.commission_generation.update';

    /** From Commission/UpdateAgentRankSettingRequest.php:17. */
    case SettingsAgentRankUpdate = 'settings.agent_rank.update';

    /** From Commission/UpdateCommissionSplitSettingRequest.php:20. */
    case SettingsCommissionSplitUpdate = 'settings.commission_split.update';

    /** From Catalog/UpdateVideoProcessingSettingRequest.php:14. */
    case SettingsVideoProcessingUpdate = 'settings.video_processing.update';

    /** From Referral/UpdateAffiliateAttributionSettingRequest.php:15. */
    case SettingsAffiliateAttributionUpdate = 'settings.affiliate_attribution.update';

    /** From Engagement/UpdateAnnouncementSettingRequest.php:15. */
    case SettingsAnnouncementUpdate = 'settings.announcement.update';

    /**
     * Manually grant a cert tier to an agent without an exam. From
     * Academy/StoreUserCertificationRequest.php:20.
     *
     * TODO: CONFIRM (behaviour recorded, not endorsed) — this Form Request is
     * the ENTIRE authorization for a write that unlocks BR-1 selling rights.
     * There is no Policy anywhere in the path (UserCertificationController::store
     * calls no $this->authorize()). Recorded here exactly as it behaves today;
     * whether a bare role check is the right gate for a BR-1 unlock is a human
     * decision, not a Phase 1 refactor (TASK-185 §4).
     */
    case AcademyCertificationGrant = 'academy.certification.grant';

    // ---------------------------------------------------------------
    // Abilities Super Admin does NOT hold.
    //
    // These three do NOT come from the 17+12 sites and are NOT converted by
    // TASK-186 — the Policies that own them are untouched by Phase 1. They are
    // catalogued here because ADR-032/TASK-185 §3 forbid a `Gate::before`
    // blanket for Super Admin, and a rule you cannot state is a rule nobody
    // can test. Each is the counterexample that would silently flip to `true`
    // the day someone adds that blanket.
    // ---------------------------------------------------------------

    /** Sit an exam attempt. From ExamAttemptPolicy.php:24 (`return $user->isAgent()`) — agent-only; both admin tiers excluded. */
    case AcademyExamAttemptCreate = 'academy.exam_attempt.create';

    /** Request a reward redemption. From RewardRedemptionPolicy.php:34 (`return $user->isAgent()`) — agent-only; both admin tiers excluded. */
    case RewardRedemptionCreate = 'reward.redemption.create';

    /**
     * Read a user record whose own role is Super Admin, through the Manage
     * Agents resource. From UserPolicy.php:29-33.
     *
     * Granted to NO role, including Super Admin itself ("Never exposed via
     * this resource, even to another Super Admin"). update(), delete() and
     * restore() all delegate to view(), so they inherit the same refusal.
     *
     * TODO: CONFIRM (behaviour recorded, not endorsed) — recorded exactly as
     * the code behaves. Note the real guard in UserPolicy is a property of the
     * TARGET, not of the actor, so it is not expressible as a pure role rule;
     * this case exists to state the outcome and to be the fail-closed
     * counterexample, NOT to replace that guard. TASK-186 does not convert
     * Policies.
     */
    case UserViewSuperAdminRecord = 'user.view_super_admin_record';

    // ---------------------------------------------------------------
    // ADR-033 (TASK-189) §2.1 — voucher redemption.
    // ---------------------------------------------------------------

    /**
     * Redeem a post-payment service-access voucher (VoucherRedemptionService
     * ::redeem()/find()).
     *
     * UNLIKE every other case in this file, this one is NOT derived from a
     * pre-existing `abort_unless`/raw-role-check call site — TASK-185/186
     * converted 29 sites that already existed; this is the first case a task
     * ADDS as new functionality, wired straight onto the resolver from day
     * one instead of starting life as a bare role check and being converted
     * later (ADR-033 §2.1: "not a bespoke role check"). Recorded here so the
     * provenance convention stays honest — there is no line number to point
     * to, because there was no prior check.
     *
     * Interim grant: CompanyAdmin + SuperAdmin (the same tier that already
     * verifies bank-slip payments today), NOT Agent — ADR-033 §2.1. Human
     * decision 5 defers "who may redeem" to ADR-032 Phase 3 custom roles;
     * narrowing this grant later is a PermissionResolver table edit, not a
     * redesign of the endpoint.
     */
    case VoucherRedeem = 'voucher.redeem';

    // ---------------------------------------------------------------
    // TASK-190 §3.2 — platform-wide SMTP settings.
    // ---------------------------------------------------------------

    /**
     * Read/write the one platform-wide `platform_mail_settings` row
     * (PlatformMailSettingController::show()/update()).
     *
     * SAME PROVENANCE SHAPE AS Ability::VoucherRedeem ABOVE — not derived
     * from a pre-existing `abort_unless`/raw-role-check call site. This is a
     * new settings screen; there is no prior line number to point to.
     *
     * Grant is Super Admin ONLY, not Company Admin — unlike every
     * Settings* case above (which are all "Company Admin, own company /
     * Super Admin, all companies"). SMTP credentials are platform
     * infrastructure serving every tenant through one mailbox (see the
     * migration's own docblock for why there is no company_id column at
     * all), so there is no "own company" scope for a Company Admin to be
     * granted here — see PermissionResolver's ROLE_ABILITIES for the
     * one-role-only grant this implies.
     */
    case SettingsMailUpdate = 'settings.mail.update';

    // ---------------------------------------------------------------
    // TASK-196 §2.2 — platform-wide commission-rate cap.
    // ---------------------------------------------------------------

    /**
     * Write the one platform-wide `platform_commission_settings` row
     * (PlatformCommissionSettingController::update()).
     *
     * SAME PROVENANCE SHAPE AS Ability::SettingsMailUpdate ABOVE — not
     * derived from a pre-existing `abort_unless`/raw-role-check call site.
     * This is a new settings screen; there is no prior line number to
     * point to.
     *
     * Grant is Super Admin ONLY, not Company Admin — same reasoning as
     * SettingsMailUpdate: the cap is platform infrastructure (one ceiling
     * enforced against every company's commission rules, see the
     * migration's own docblock for why there is no company_id column at
     * all), so there is no "own company" scope for a Company Admin to be
     * granted here.
     *
     * READ has no matching Ability case at all — §2.2 calls for
     * "read-everywhere" (any authenticated Company Admin/Super Admin),
     * same shape as `/cert-tiers`' own "any authenticated user" read gate,
     * which is likewise not represented as an Ability (see
     * CertTierController's own docblock). Only the WRITE side needed a new
     * case.
     */
    case CommissionRateCapUpdate = 'commission.rate_cap.update';

    // ---------------------------------------------------------------
    // ADR-027 / TASK-139 — which payment gateway takes a company's money.
    // ---------------------------------------------------------------

    /**
     * Configure and activate a company's payment gateway.
     *
     * SUPER ADMIN ONLY, and here the reasoning is the opposite of
     * SettingsMailUpdate's rather than the same. That one is Super-Admin-only
     * because the setting is platform infrastructure with no per-company
     * scope to grant. This one is COMPLETELY per-company — and is
     * Super-Admin-only anyway, on the human's instruction (2026-08-22),
     * because of what it does: it names the bank account a company's customer
     * revenue lands in.
     *
     * A Company Admin who could edit this could redirect their own company's
     * income, and the change would look like an ordinary settings edit in
     * every screen the platform has. That is not a permission worth granting
     * for convenience.
     *
     * Covers both writing credentials and switching the active provider;
     * they are not separable in any way that would help, since either alone
     * can move where money goes.
     */
    case SettingsPaymentGatewayUpdate = 'settings.payment_gateway.update';
}
