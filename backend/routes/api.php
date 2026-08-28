<?php

use App\Http\Controllers\Api\V1\AcademyCompletionSettingController;
use App\Http\Controllers\Api\V1\AcademyProgressSummaryController;
use App\Http\Controllers\Api\V1\AffiliateAttributionSettingController;
use App\Http\Controllers\Api\V1\AffiliateLeadCaptureController;
use App\Http\Controllers\Api\V1\AffiliateLinkController;
use App\Http\Controllers\Api\V1\AffiliateLinkRedirectController;
use App\Http\Controllers\Api\V1\AgentApprovalController;
use App\Http\Controllers\Api\V1\AgentCommissionSummaryController;
use App\Http\Controllers\Api\V1\AgentDashboardMetricsController;
use App\Http\Controllers\Api\V1\AgentInviteLinkController;
use App\Http\Controllers\Api\V1\AgentPromotionController;
use App\Http\Controllers\Api\V1\AgentRankController;
use App\Http\Controllers\Api\V1\AgentRankSettingController;
use App\Http\Controllers\Api\V1\AgentTargetController;
use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AnnouncementSettingController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BadgeController;
use App\Http\Controllers\Api\V1\BinaryMatchingCycleController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CatalogBrandController;
use App\Http\Controllers\Api\V1\CatalogCategoryController;
use App\Http\Controllers\Api\V1\CertTierController;
use App\Http\Controllers\Api\V1\ChunkedUploadController;
use App\Http\Controllers\Api\V1\ClientActivityController;
use App\Http\Controllers\Api\V1\ClientCategoryController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ClientDocumentController;
use App\Http\Controllers\Api\V1\CommissionBinarySettingController;
use App\Http\Controllers\Api\V1\CommissionGenerationRuleController;
use App\Http\Controllers\Api\V1\CommissionGenerationSettingController;
use App\Http\Controllers\Api\V1\CommissionLedgerController;
use App\Http\Controllers\Api\V1\CommissionMatrixLevelRateController;
use App\Http\Controllers\Api\V1\CommissionMatrixSettingController;
use App\Http\Controllers\Api\V1\CommissionOverrideRuleController;
use App\Http\Controllers\Api\V1\CommissionRuleController;
use App\Http\Controllers\Api\V1\CommissionSplitSettingController;
use App\Http\Controllers\Api\V1\CommissionWithdrawalRequestController;
use App\Http\Controllers\Api\V1\CommissionWithdrawalSettingController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CompanyInviteCodeController;
use App\Http\Controllers\Api\V1\CompanyPaymentGatewayController;
use App\Http\Controllers\Api\V1\CompanyThemeController;
use App\Http\Controllers\Api\V1\ComplianceReportController;
use App\Http\Controllers\Api\V1\ConfigHealthReportController;
use App\Http\Controllers\Api\V1\ExamAttemptController;
use App\Http\Controllers\Api\V1\ExamController;
use App\Http\Controllers\Api\V1\ExamQuestionController;
use App\Http\Controllers\Api\V1\ExamQuestionOptionController;
use App\Http\Controllers\Api\V1\GamificationRuleController;
use App\Http\Controllers\Api\V1\LeaderboardController;
use App\Http\Controllers\Api\V1\LevelThresholdController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MeTeamController;
use App\Http\Controllers\Api\V1\ModuleCompletionController;
use App\Http\Controllers\Api\V1\ModuleController;
use App\Http\Controllers\Api\V1\ModuleLessonController;
use App\Http\Controllers\Api\V1\ModuleLessonProgressController;
use App\Http\Controllers\Api\V1\ModuleLessonQuizAttemptController;
use App\Http\Controllers\Api\V1\ModuleLessonQuizController;
use App\Http\Controllers\Api\V1\ModuleLessonQuizOptionController;
use App\Http\Controllers\Api\V1\ModuleLessonQuizQuestionController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\PipelineTemplateController;
use App\Http\Controllers\Api\V1\PlatformCommissionSettingController;
use App\Http\Controllers\Api\V1\PlatformMailSettingController;
use App\Http\Controllers\Api\V1\PlatformReportController;
use App\Http\Controllers\Api\V1\ProductCatalogItemController;
use App\Http\Controllers\Api\V1\ProductCatalogLinkController;
use App\Http\Controllers\Api\V1\ProductCategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductMediaController;
use App\Http\Controllers\Api\V1\ProductPricePromotionController;
use App\Http\Controllers\Api\V1\ProductRecommendationPinController;
use App\Http\Controllers\Api\V1\ProductSalesMaterialController;
use App\Http\Controllers\Api\V1\ProductShareLinkController;
use App\Http\Controllers\Api\V1\ProductSpecAttachmentController;
use App\Http\Controllers\Api\V1\ProductSpecController;
use App\Http\Controllers\Api\V1\PublicPaymentController;
use App\Http\Controllers\Api\V1\PublicProductShareController;
use App\Http\Controllers\Api\V1\PublicThemeController;
use App\Http\Controllers\Api\V1\QuizController;
use App\Http\Controllers\Api\V1\ReferralController;
use App\Http\Controllers\Api\V1\RegisterController;
use App\Http\Controllers\Api\V1\RewardItemController;
use App\Http\Controllers\Api\V1\RewardRedemptionController;
use App\Http\Controllers\Api\V1\SalesMaterialShareLinkController;
use App\Http\Controllers\Api\V1\SalesTeamOverviewController;
use App\Http\Controllers\Api\V1\ShareLinkEmailController;
use App\Http\Controllers\Api\V1\StorefrontBannerController;
use App\Http\Controllers\Api\V1\TeamVisibilitySettingController;
use App\Http\Controllers\Api\V1\ThemePresetController;
use App\Http\Controllers\Api\V1\TrackedLinkController;
use App\Http\Controllers\Api\V1\UserBadgeController;
use App\Http\Controllers\Api\V1\UserCertificationController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserProfileController;
use App\Http\Controllers\Api\V1\VideoProcessingSettingController;
use App\Http\Controllers\Api\V1\VoucherController;
use App\Http\Controllers\Api\V1\XpLedgerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All endpoints are versioned under /api/v1/... per CLAUDE.md Section 3
| (API versioning). Add feature route files as they are built, e.g.:
|
|   require __DIR__.'/api/v1/sws-referrals.php';
|
*/

Route::prefix('v1')->group(function () {
    Route::get('/ping', function () {
        return response()->json(['status' => 'ok']);
    });

    // Sanctum SPA session auth. Login is public (rate-limited inside
    // LoginRequest); logout/me require an authenticated session cookie.
    // Stateful-domain cookie auth is enabled via $middleware->statefulApi()
    // in bootstrap/app.php, so 'auth:sanctum' here checks the web session.
    Route::post('/login', [AuthController::class, 'login']);

    // ADR-005 — public, unauthenticated self-registration (Agent
    // Portal only, frontend-admin never links here). Every action is
    // rate-limited (Section 6). verify-email additionally requires a
    // valid `signed` URL — see VerifyRegistrationEmailNotification for
    // why the email link points at the frontend rather than here
    // directly, and RegisterController::verifyEmail()'s own comment.
    Route::post('/register/resolve-invite-code', [RegisterController::class, 'resolveInviteCode'])->middleware('throttle:10,1');
    // TASK-114 / ADR-025 §5 — the recruit-link equivalent of the line
    // above: a visitor landing on /register?ref=<token> exchanges the
    // token for "whose team, which company" before filling the form. Same
    // throttle:10,1 as its invite-code twin, and for a stronger reason —
    // it is the only endpoint that will confirm a recruit token exists, so
    // it is the one an enumerator would point at.
    Route::post('/register/resolve-ref-token', [RegisterController::class, 'resolveRefToken'])->middleware('throttle:10,1');
    // The signup form asks whether an address is already an account BEFORE
    // the recruit fills in a national ID and a password, because the email
    // IS the login identity here — a taken one makes the rest of the form
    // pointless, and the usual cause is that they already signed up and
    // want the login page.
    //
    // THIS IS AN ACCOUNT-EXISTENCE ORACLE and is treated as one: the caller
    // must present the same live invite code or recruit token that
    // POST /register demands (CheckEmailRequest enforces it through the same
    // RegistrationService lookups), so it cannot be harvested by anyone who
    // does not already hold a working link — and links are quota-bounded,
    // expiring and revocable, which is the lever a company needs if abuse
    // shows up. The full reasoning, including why /register's 422 always
    // leaked the same fact and why the COST of extracting it is what
    // actually changed, is in that Request's docblock.
    //
    // throttle:20,1 rather than its siblings' 10: this one is called while
    // somebody is typing (debounced, but a corrected address is a second
    // call, and an office or campus shares one IP between several recruits),
    // so 10 would start refusing genuine signups. It remains a hard bound on
    // volume for an attacker who does hold a link, which is what the number
    // is there for.
    Route::post('/register/check-email', [RegisterController::class, 'checkEmail'])->middleware('throttle:20,1');
    Route::post('/register', [RegisterController::class, 'register'])->middleware('throttle:10,1');
    // TASK-115 (TASK-021 item 3) — the resend affordance the login gate's
    // 403 advertises via `can_resend_verification`. Tighter throttle than its
    // siblings above (5/min, not 10) because this one SENDS AN EMAIL on every
    // accepted call: an unthrottled version is a free mail cannon aimed at
    // any address an attacker names. It always answers 200 with the same
    // message, so it is never an existence oracle — see the Controller.
    Route::post('/register/resend-verification-email', [RegisterController::class, 'resendVerificationEmail'])
        ->middleware('throttle:5,1');
    Route::get('/register/verify-email/{id}/{hash}', [RegisterController::class, 'verifyEmail'])
        ->middleware(['throttle:10,1', 'signed'])
        ->name('registration.verify-email');

    // ADR-007 — the ONE public, unauthenticated download route in this
    // app deliberately built for external sharing (a prospect with no
    // account clicking a link an agent sent them). Looked up by an
    // opaque random `token`, not a database id — never guessable/
    // enumerable (Section 5 rule 5's IDOR concern) — and checked for
    // expiry/revocation inside the Controller. Rate-limited like every
    // other public endpoint (Section 6).
    Route::get('/share/sales-materials/{token}', [SalesMaterialShareLinkController::class, 'show'])
        ->middleware('throttle:30,1')
        ->name('sales-material-share-links.show');

    // ADR-011 Section 4 (TASK-032) — Affiliate trackable links. First
    // UNAUTHENTICATED WRITE surface in this app (flagged explicitly in
    // ADR-011/CLAUDE.md Section 3 as a deliberate departure, not a
    // silent one). Both routes rate-limited per Section 6.
    //
    // ag-lead note on the URL shape: every route in this file — this
    // one included, since it lives inside the same
    // Route::prefix('v1')->group() as everything else — is served under
    // /api/v1/... (bootstrap/app.php's withRouting(api: routes/api.php)
    // plus this file's own prefix('v1') wrapper). The task spec's own
    // wording ("GET /l/{token}") reads as a bare top-level short link,
    // but achieving that literally would need a NEW routes/web.php
    // entry point outside this prefix group — a bigger architectural
    // call (reopens Section 3's "strictly API, Blade forbidden"
    // framing) than ag-lead should make unilaterally. Shipping this
    // task with the link at /api/v1/l/{token} for now; flagged to the
    // human in the TASK-032 completion report as a follow-up decision
    // if a truly short marketing-facing URL is wanted later.
    Route::get('/l/{token}', [AffiliateLinkRedirectController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('affiliate-links.redirect');
    Route::post('/public/affiliate-leads/{token}', [AffiliateLeadCaptureController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('affiliate-leads.store');
    // TASK-033 gap-fill — the landing page needs to render *something*
    // (product name/price, company/agent name) before the prospect ever
    // submits the form above; TASK-032 never built a read counterpart.
    // Same throttle tier as the redirect (60/min) since this is a GET a
    // page-load fires once, not a form submission.
    Route::get('/public/affiliate-leads/{token}', [AffiliateLeadCaptureController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('affiliate-leads.show');

    // ADR-017 (TASK-054) — PUBLIC, UNAUTHENTICATED payment page, same
    // opaque-token + throttled treatment as the affiliate routes above.
    // GET returns the amount + company bank/PromptPay details (via
    // PublicOrderResource — never agent/commission/PDPA data, §6); POST
    // accepts the customer's payment slip. Resolved by public_token only,
    // outside TenantScope (there is no authenticated user to scope by).
    Route::get('/pay/{token}', [PublicPaymentController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('public-payment.show');
    Route::post('/pay/{token}/slip', [PublicPaymentController::class, 'submitSlip'])
        ->middleware('throttle:10,1')
        ->name('public-payment.slip');

    /*
     * ADR-027 / TASK-139 — the CARD half of the same public pay page.
     *
     * Throttled to 5/min rather than the slip's 10: each request here is a
     * real charge attempt against the company's gateway account, and a
     * stream of them from one address is either card testing or an attack on
     * the company's provider-side fraud reputation. A person paying for one
     * order does not need six attempts a minute.
     *
     * The body carries a one-time provider token, never a card number — see
     * ChargeOrderRequest.
     */
    Route::post('/pay/{token}/charge', [PublicPaymentController::class, 'charge'])
        ->middleware('throttle:5,1')
        ->name('public-payment.charge');

    /*
     * ADR-027 / TASK-139 — WHERE A PAYMENT PROVIDER TELLS US MONEY ARRIVED.
     *
     * The highest-consequence unauthenticated route in this application: it
     * can mark an order paid, which writes an immutable BR-4 commission
     * ledger row. Flagged here as ADR-011 flags every unauthenticated
     * endpoint, so it is never mistaken for an accident.
     *
     * Its ONLY protection is the provider's signature, verified in the
     * controller against THAT COMPANY's own webhook secret. There is no
     * permissive path and no environment in which the check is skipped.
     *
     * `{company}` is in the URL because the secret to verify against must be
     * chosen before the payload can be trusted at all — reading it from the
     * body would let a forger nominate the key their forgery is checked
     * with.
     *
     * Throttled generously (120/min): a legitimate provider retrying a batch
     * of events must not be throttled into looking like a failure, and the
     * signature — not the rate limit — is what stops abuse.
     */
    Route::post('/webhooks/payments/{provider}/{company}', PaymentWebhookController::class)
        ->middleware('throttle:120,1')
        ->name('payment-webhooks.handle');

    // TASK-055 / ADR-018 — PUBLIC per-company theme by slug, for pre-login
    // white-label branding. Unauthenticated + throttled, resolved outside
    // TenantScope; ThemeResource exposes ONLY presentational fields (§6).
    // TASK-235 (UAT) — /in/<code> needs the slug to become the branded
    // login page it is short for. Slug only; it is already in the URL every
    // company shares. Same throttle tier as the other public resolvers.
    Route::get('/public/login-links/{code}', [RegisterController::class, 'resolveLoginLink'])
        ->middleware('throttle:30,1');
    Route::get('/public/theme/{slug}', [PublicThemeController::class, 'showBySlug'])
        ->middleware('throttle:60,1')
        ->name('public-theme.show');

    // TASK-056 Sprint P1 — PUBLIC Product Share landing page + the file
    // routes it needs. Same opaque-token + throttled treatment as every
    // other public route above; never a raw storage path (§5 rule 6).
    Route::get('/public/product-shares/{token}', [PublicProductShareController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('public-product-shares.show');
    Route::get('/public/product-shares/{token}/media/{productMedia}/stream', [PublicProductShareController::class, 'mediaStream'])
        ->middleware('throttle:60,1')
        ->name('public-product-shares.media-stream');
    Route::get('/public/product-shares/{token}/media/{productMedia}/thumbnail', [PublicProductShareController::class, 'mediaThumbnail'])
        ->middleware('throttle:60,1')
        ->name('public-product-shares.media-thumbnail');
    Route::get('/public/product-shares/{token}/materials/{salesMaterial}/stream', [PublicProductShareController::class, 'materialStream'])
        ->middleware('throttle:30,1')
        ->name('public-product-shares.material-stream');
    // TASK-136 — the WRITE half of the public product share: an anonymous
    // visitor turns the link into a payable order. Same opaque-token
    // resolution as its read siblings above, but throttled far harder
    // (10/min, matching the affiliate lead-capture POST) because this one
    // creates a Client + Referral + Order rather than reading a page.
    // Registered OUTSIDE auth:sanctum on purpose — flagged here, as
    // ADR-011 flagged the first such endpoint, so it is never mistaken for
    // an accident (§3, §6).
    Route::post('/public/product-shares/{token}/checkout', [PublicProductShareController::class, 'checkout'])
        ->middleware('throttle:10,1')
        ->name('public-product-shares.checkout');

    /*
     * TASK-183 §3.3 — `company.operational` is stacked on the WHOLE
     * authenticated group, not sprinkled per-endpoint, so that "a deactivated
     * or soft-deleted company can do nothing" is true for every route below
     * including the ones added after this comment. See
     * App\Http\Middleware\EnsureCompanyIsOperational for the reasoning and for
     * why a Super Admin (company_id = null) is never refused by it.
     */
    Route::middleware(['auth:sanctum', 'company.operational'])->group(function () {
        /*
         * THE ONE DELIBERATE EXCLUSION (TASK-183 §3.3).
         *
         * Logout is the only authenticated action that GRANTS nothing: it
         * destroys the session and returns 204. Refusing it would leave a user
         * of a closed company holding a server-side session they are unable to
         * end, and would break the SPA's only recovery path — every other call
         * answers 403 `company_inactive`, so the UI's remaining affordance is
         * "log out", and a 403 there would trap the user on a screen with no
         * working button. Excluded, not merely tolerated: pinned by
         * CompanyDeactivationTest so nobody has to guess whether it was
         * intentional.
         */
        Route::post('/logout', [AuthController::class, 'logout'])
            ->withoutMiddleware('company.operational');
        Route::get('/me', [AuthController::class, 'me']);

        // Personal profile customization (avatar + background) — always
        // self-scoped (see UserProfileController's own comment), no {user}
        // route parameter exists anywhere here.
        Route::post('/me/avatar', [UserProfileController::class, 'updateAvatar']);
        Route::delete('/me/avatar', [UserProfileController::class, 'destroyAvatar']);
        Route::put('/me/background', [UserProfileController::class, 'updateBackgroundGradient']);
        Route::post('/me/background/image', [UserProfileController::class, 'updateBackgroundImage']);
        Route::delete('/me/background', [UserProfileController::class, 'destroyBackground']);
        Route::put('/me/name', [UserProfileController::class, 'updateName']);
        Route::put('/me/password', [UserProfileController::class, 'updatePassword']);
        // 2026-08-22 — the agent's own notification-email off switch.
        Route::put('/me/notification-preferences', [UserProfileController::class, 'updateNotificationPreferences']);

        // TASK-053 / ADR-016 Phase 1 — personal notifications (the real
        // bell, replacing the stub) + per-agent targets (goal ring).
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::get('/me/targets', [AgentTargetController::class, 'me']);
        // TASK-053 / ADR-016 Phase 2 — personal home hub aggregation.
        Route::get('/me/home', [MeController::class, 'home']);
        Route::get('/me/tasks', [MeController::class, 'tasks']);

        // TASK-107 / ADR-024 — team leader monitor. READ ONLY: these are the
        // only two routes under /me/team and both are GET, so every write
        // verb against them is a 405 by construction, not by a guard someone
        // could forget (ADR-024 §7). The downline query shape lives ONLY
        // here — ClientController / ReferralController /
        // CommissionLedgerController keep their single `agent_id = self`
        // shape untouched, which is the entire security argument for
        // ADR-024's option C.
        //
        // Self-scoped like every /me/* route above: the CALLER is always
        // $request->user(). {user} on the drill-down identifies who is being
        // looked at and is checked against the caller's own subtree
        // (DownlineService::isInSubtree) before any data is read — a miss is
        // a 404, never a 403 (see MeTeamController).
        Route::get('/me/team', [MeTeamController::class, 'index']);
        Route::get('/me/team/{user}/clients', [MeTeamController::class, 'clients']);
        // Admin-set + admin-read (role gate inside the controller).
        Route::get('/agent-targets', [AgentTargetController::class, 'index']);
        Route::post('/agent-targets', [AgentTargetController::class, 'upsert']);
        // TASK-044 Phase A — self-service bank payout details, same
        // self-scoped ($request->user() only) construction as every
        // other /me/* route above.
        Route::put('/me/bank-account', [UserProfileController::class, 'updateBankAccount']);

        // 2026-08-27 — the identity document, filled in after sign-up (it is
        // no longer asked for on the registration form). Sits next to
        // /me/bank-account on purpose: the two together are the "can this
        // person be paid" record, and a payout flow will check both.
        Route::put('/me/id-document', [UserProfileController::class, 'updateIdDocument']);

        // TASK-055 / ADR-018 — per-company white-label theme. /me/theme is
        // readable by ANY authenticated user (agents render the branded
        // portal too); the write + asset-upload endpoints are gated to
        // Company Admin (own company) / Super Admin inside the
        // Request/controller, which force the target company_id server-side.
        Route::get('/me/theme', [CompanyThemeController::class, 'me']);
        Route::put('/company-theme', [CompanyThemeController::class, 'update']);
        Route::post('/company-theme/asset', [CompanyThemeController::class, 'uploadAsset']);

        // TASK-161 §3.2 — named colour presets for the company's theme.
        // ADMIN ONLY: unlike /me/theme above, an Agent gets 403 on every
        // route here (ThemePresetPolicy) — nothing in the Agent Portal
        // reads presets, they are admin config. TenantScope on the model
        // makes another company's id a 404 at binding time (§5/BR-6).
        // `apply` is POST (it mutates the company's theme) and lives
        // before the apiResource so it is never shadowed by `show`.
        Route::post('/theme-presets/{theme_preset}/apply', [ThemePresetController::class, 'apply']);
        Route::apiResource('theme-presets', ThemePresetController::class)
            ->parameters(['theme-presets' => 'theme_preset'])
            ->except(['show']);

        // Product Catalog — ERD-001 §"Product Catalog". Every action is
        // gated by its Policy inside the controller (authorizeResource);
        // TenantScope handles the query-level tenant filter (BR-6).
        Route::apiResource('brands', BrandController::class);
        Route::apiResource('product-categories', ProductCategoryController::class)
            ->parameters(['product-categories' => 'product_category']);
        // TASK-068 / ADR-020 row 4 — registered BEFORE apiResource('products')
        // so the literal 'recommended' segment matches ahead of the
        // {product} show route's wildcard (same collision this codebase
        // already avoided once via the flat /products-abc-grades path
        // below — here the task spec calls for the nested
        // GET /products/recommended shape instead, so route ORDER is what
        // prevents the collision this time).
        Route::get('/products/recommended', [ProductController::class, 'recommended']);
        Route::apiResource('products', ProductController::class);
        // Product-view IA item 2.2 — flat path (not nested under
        // /products/{id}) so it isn't swallowed by the apiResource
        // show route, same "flat custom path" choice as
        // /reward-redemptions-my-balance above.
        Route::get('/products-abc-grades', [ProductController::class, 'abcGrades']);

        // ADR-036 (TASK-211..213) — shared cross-company product catalog.
        // catalog-brands/catalog-categories/product-catalog-items are
        // global (no company_id) — Policy is the only gate
        // (viewAny/view true for anyone, create/update/delete
        // Super-Admin-only). The link/unlink action is deliberately its
        // own controller, not a ProductController action — see
        // ProductCatalogLinkController's docblock.
        Route::apiResource('catalog-brands', CatalogBrandController::class)
            ->parameters(['catalog-brands' => 'catalog_brand']);
        Route::apiResource('catalog-categories', CatalogCategoryController::class)
            ->parameters(['catalog-categories' => 'catalog_category']);
        Route::apiResource('product-catalog-items', ProductCatalogItemController::class)
            ->parameters(['product-catalog-items' => 'product_catalog_item']);
        Route::post('/products/{product}/catalog-link', [ProductCatalogLinkController::class, 'store']);
        Route::delete('/products/{product}/catalog-link', [ProductCatalogLinkController::class, 'destroy']);

        // Product-view IA item 2.3b — customer-facing price promotions.
        Route::apiResource('product-price-promotions', ProductPricePromotionController::class)
            ->parameters(['product-price-promotions' => 'product_price_promotion']);
        // TASK-068 / ADR-020 rows 2 & 4 — storefront banners (full CRUD,
        // agent-readable) + recommendation pins (Admin CRUD, feeds
        // /products/recommended's manual half).
        Route::apiResource('storefront-banners', StorefrontBannerController::class)
            ->parameters(['storefront-banners' => 'storefront_banner']);
        Route::apiResource('product-recommendation-pins', ProductRecommendationPinController::class)
            ->parameters(['product-recommendation-pins' => 'product_recommendation_pin']);
        Route::apiResource('commission-rules', CommissionRuleController::class)
            ->parameters(['commission-rules' => 'commission_rule']);

        // ADR-026 (TASK-136) — pipeline templates, READ-ONLY.
        //
        // ->only(['index']) is a deliberate, load-bearing restriction, not
        // a stub: authoring is TASK-134b and must not exist as an endpoint
        // until the ADR-026 §3.5 invariants (must contain
        // complete_registered first + complete_payment; post-sale stages
        // only after payment; ongoing_next_meeting only last) are enforced
        // in a Form Request as well as in
        // PipelineTemplateResolver::assertValidStageSequence(). A template
        // saved without complete_payment silently stops paying BR-4
        // commission for every product that resolves to it, so a
        // half-validated write route is worse than no write route.
        //
        // Company Admin / Super Admin only (PipelineTemplatePolicy) — an
        // Agent gets their referral's own journey on ReferralResource
        // instead, not the company's whole config catalogue.
        Route::apiResource('pipeline-templates', PipelineTemplateController::class)
            ->parameters(['pipeline-templates' => 'pipeline_template'])
            ->only(['index']);

        // TASK-025 / ADR-006 — Unilevel manager override rates, keyed
        // by the manager's own cert tier (not product-scoped, unlike
        // commission-rules above).
        Route::apiResource('commission-override-rules', CommissionOverrideRuleController::class)
            ->parameters(['commission-override-rules' => 'commission_override_rule']);

        // ADR-011/TASK-029 — Binary matched-volume config (one row per
        // company, show/update only — same singleton shape as
        // video-processing-settings below) + read-only cycle history.
        Route::get('/commission-binary-settings', [CommissionBinarySettingController::class, 'show']);
        Route::put('/commission-binary-settings', [CommissionBinarySettingController::class, 'update']);
        Route::get('/binary-matching-cycles', [BinaryMatchingCycleController::class, 'index']);

        // ADR-011/TASK-030 — Matrix plan type: width/depth/spillover_rule
        // config (singleton, same shape as commission-binary-settings
        // above) + per-level override rates (keyed by level, same shape
        // as commission-override-rules). Placement itself has no direct
        // API — it's a side effect of PUT /users/{user} setting
        // manager_id (see UserService::update()'s own comment).
        Route::get('/commission-matrix-settings', [CommissionMatrixSettingController::class, 'show']);
        Route::put('/commission-matrix-settings', [CommissionMatrixSettingController::class, 'update']);
        Route::apiResource('commission-matrix-level-rates', CommissionMatrixLevelRateController::class)
            ->parameters(['commission-matrix-level-rates' => 'commission_matrix_level_rate']);

        // ADR-011/TASK-031 — Stairstep/Breakaway + Generation plan
        // types. agent_ranks (full CRUD, shared rank ladder) +
        // agent_rank_settings (singleton, trailing-window/cadence for
        // RecalculateAgentRanks) + commission_generation_rules
        // (per-generation-number rate, same shape as
        // commission-matrix-level-rates) + commission_generation_settings
        // (singleton, max_generation_depth cap). Stairstep/Breakaway
        // itself has no separate rate table — a rank's own
        // rate_type/rate_value (on agent_ranks) IS its rate, see
        // AgentRank's own docblock.
        Route::apiResource('agent-ranks', AgentRankController::class)
            ->parameters(['agent-ranks' => 'agent_rank']);
        Route::get('/agent-rank-settings', [AgentRankSettingController::class, 'show']);
        Route::put('/agent-rank-settings', [AgentRankSettingController::class, 'update']);
        Route::apiResource('commission-generation-rules', CommissionGenerationRuleController::class)
            ->parameters(['commission-generation-rules' => 'commission_generation_rule']);
        Route::get('/commission-generation-settings', [CommissionGenerationSettingController::class, 'show']);
        Route::put('/commission-generation-settings', [CommissionGenerationSettingController::class, 'update']);

        // ADR-011/TASK-032 — Affiliate trackable links: minting/
        // listing/revoking (Agent manages own; Company Admin/Super
        // Admin manage any within their scope — AffiliateLinkPolicy) +
        // attribution-window config (singleton, same shape as
        // agent-rank-settings above). No update() on the link itself —
        // see AffiliateLinkController's own comment.
        Route::apiResource('affiliate-links', AffiliateLinkController::class)
            ->parameters(['affiliate-links' => 'affiliate_link'])
            ->only(['index', 'store', 'show', 'destroy']);
        Route::get('/affiliate-attribution-settings', [AffiliateAttributionSettingController::class, 'show']);
        Route::put('/affiliate-attribution-settings', [AffiliateAttributionSettingController::class, 'update']);

        // TASK-212 — send a shareable link by email THROUGH THE PLATFORM
        // (human, 2026-08-19: "ระบบ อีเมล์ให้ส่งผ่านระบบ"), replacing
        // <ShareLinkModal>'s `mailto:` handoff.
        //
        // The body is {type, id, email} — never a URL. See ShareLinkType's
        // docblock: an endpoint that mailed a caller-supplied URL from this
        // application's From: address would be an authenticated open relay.
        //
        // throttle:10,1 — the same tier as /register/resolve-invite-code
        // and stricter than the default, for the reason
        // /register/resend-verification-email's comment already states: an
        // unthrottled mail-sending endpoint is a free mail cannon, and this
        // one takes an arbitrary recipient address.
        Route::post('/share-emails', [ShareLinkEmailController::class, 'store'])
            ->middleware('throttle:10,1');

        // TASK-056 Sprint P1 — Product Share links: minting/listing/
        // revoking (Agent manages own; Company Admin/Super Admin manage
        // any within their scope — ProductShareLinkPolicy), same shape as
        // affiliate-links above. No update() — see
        // ProductShareLinkController's own comment (destroy() revokes).
        Route::apiResource('product-shares', ProductShareLinkController::class)
            ->parameters(['product-shares' => 'product_share_link'])
            ->only(['index', 'store', 'show', 'destroy']);

        // TASK-113 / ADR-025 §3 — Team-leader recruit links: minting/
        // listing/revoking, same shape as product-shares above (an Agent
        // manages their own, a Company Admin their company's —
        // AgentInviteLinkPolicy). destroy() is a SOFT revoke: hard-deleting
        // would null every recruit's users.recruited_via_agent_link_id and
        // destroy attribution (ADR-025 §6).
        //
        // No show() here, unlike product-shares: TASK-113's spec lists only
        // index/store/destroy, and nothing needs a single-link read yet —
        // add it in TASK-117 if the Admin oversight screen actually wants
        // one. The Policy's view() already exists and is what delete()
        // delegates to, so adding the route later is a one-line change.
        //
        // The PUBLIC side of these tokens (resolve-ref-token + registration)
        // is deliberately absent — that is TASK-114, and it goes OUTSIDE
        // this auth:sanctum group.
        Route::apiResource('agent-invite-links', AgentInviteLinkController::class)
            ->parameters(['agent-invite-links' => 'agent_invite_link'])
            ->only(['index', 'store', 'destroy']);

        // Product sales materials (human-requested — brochures/PDFs an
        // Agent can download for a product a client is interested in).
        // No dedicated Policy — reuses ProductPolicy (see
        // ProductSalesMaterialController's own comment). Same
        // nested-for-index/store, standalone-for-download/destroy
        // routing shape as client-documents above.
        // TASK-094 — chunked upload transport. Deliberately NOT tied to a
        // parent resource: the same two endpoints feed product media,
        // sales materials, Academy lessons and spec attachments, and none
        // of them creates a record. The create endpoints below stay the
        // single place where mime/size/policy are enforced; they simply
        // accept `upload_token` as an alternative to `file`, resolved by
        // the resolve.chunked-upload middleware.
        Route::post('/uploads/init', [ChunkedUploadController::class, 'init']);
        Route::post('/uploads/{token}/chunk', [ChunkedUploadController::class, 'chunk']);

        Route::get('/products/{product}/sales-materials', [ProductSalesMaterialController::class, 'index']);
        Route::post('/products/{product}/sales-materials', [ProductSalesMaterialController::class, 'store'])
            ->middleware('resolve.chunked-upload');
        Route::get('/sales-materials/{salesMaterial}/download', [ProductSalesMaterialController::class, 'download']);
        // Human-requested 2026-07-20: inline stream (thumbnails +
        // click-to-preview in the redesigned grid) — mirrors
        // product-media.stream / product-spec-attachments.stream.
        Route::get('/sales-materials/{salesMaterial}/stream', [ProductSalesMaterialController::class, 'stream'])->name('sales-materials.stream');
        // Move a material into a different group without deleting/re-uploading — material_group only.
        Route::patch('/sales-materials/{salesMaterial}', [ProductSalesMaterialController::class, 'update']);
        Route::delete('/sales-materials/{salesMaterial}', [ProductSalesMaterialController::class, 'destroy']);

        // ADR-007 — 1-to-many external sharing. Any same-company user
        // (ProductPolicy::view — Agents mint their own share links, not
        // just Company Admin) may generate/revoke a link for a material
        // they can already see; the resulting token is consumed via the
        // PUBLIC route registered above, outside this auth:sanctum group.
        Route::post('/sales-materials/{salesMaterial}/share-links', [SalesMaterialShareLinkController::class, 'store']);
        Route::get('/sales-materials/{salesMaterial}/share-links', [SalesMaterialShareLinkController::class, 'index']);
        Route::delete('/share-links/{shareLink}', [SalesMaterialShareLinkController::class, 'destroy']);

        // ADR-007 — Product image/video gallery (Amazon-style detail
        // page) + admin-editable key-value spec sheet. No dedicated
        // Policy — reuses ProductPolicy (see ProductMediaController's
        // own comment).
        Route::get('/products/{product}/media', [ProductMediaController::class, 'index']);
        Route::post('/products/{product}/media', [ProductMediaController::class, 'store'])
            ->middleware('resolve.chunked-upload');
        Route::put('/product-media/{productMedia}', [ProductMediaController::class, 'update']);
        Route::delete('/product-media/{productMedia}', [ProductMediaController::class, 'destroy']);
        Route::get('/product-media/{productMedia}/stream', [ProductMediaController::class, 'stream'])->name('product-media.stream');
        Route::get('/product-media/{productMedia}/thumbnail', [ProductMediaController::class, 'thumbnail'])->name('product-media.thumbnail');
        Route::get('/product-media/{productMedia}/download', [ProductMediaController::class, 'download']);

        Route::get('/products/{product}/specs', [ProductSpecController::class, 'index']);
        Route::post('/products/{product}/specs', [ProductSpecController::class, 'store']);
        Route::put('/product-specs/{productSpec}', [ProductSpecController::class, 'update']);
        Route::delete('/product-specs/{productSpec}', [ProductSpecController::class, 'destroy']);

        // ADR-008 — Product's spec image/PDF gallery, separate from the
        // hero/thumbnail gallery above (product_media). No dedicated
        // Policy — reuses ProductPolicy (see ProductSpecAttachmentController's
        // own comment).
        Route::get('/products/{product}/spec-attachments', [ProductSpecAttachmentController::class, 'index']);
        Route::post('/products/{product}/spec-attachments', [ProductSpecAttachmentController::class, 'store'])
            ->middleware('resolve.chunked-upload');
        Route::put('/product-spec-attachments/{productSpecAttachment}', [ProductSpecAttachmentController::class, 'update']);
        Route::delete('/product-spec-attachments/{productSpecAttachment}', [ProductSpecAttachmentController::class, 'destroy']);
        Route::get('/product-spec-attachments/{productSpecAttachment}/stream', [ProductSpecAttachmentController::class, 'stream'])->name('product-spec-attachments.stream');
        Route::get('/product-spec-attachments/{productSpecAttachment}/thumbnail', [ProductSpecAttachmentController::class, 'thumbnail'])->name('product-spec-attachments.thumbnail');
        Route::get('/product-spec-attachments/{productSpecAttachment}/download', [ProductSpecAttachmentController::class, 'download']);

        // ADR-007 — BR-7 admin-editable video compression limits.
        Route::get('/video-processing-settings', [VideoProcessingSettingController::class, 'show']);
        Route::put('/video-processing-settings', [VideoProcessingSettingController::class, 'update']);

        // TASK-050 / ADR-014 — "ทีมขาย" leadership cockpit (Manager rollup:
        // per-agent client count + deals-by-stage + conversion). Company
        // Admin / Super Admin only (enforced in the Controller).
        Route::get('/sales-team-overview', [SalesTeamOverviewController::class, 'index']);

        // TASK-106 / ADR-024 §5 — BR-7 admin-editable "how much of a
        // subordinate's client data may a team leader see" (counts_only /
        // names / full_file, plus a master on/off). Company Admin (own
        // company) / Super Admin only — enforced in the Controller (show)
        // and the Form Request (update). Deliberately NOT Agent-readable:
        // a team leader is still role = 'agent', so exposing this to an
        // Agent would be exposing the PDPA boundary to the party it binds.
        Route::get('/team-visibility-settings', [TeamVisibilitySettingController::class, 'show']);
        Route::put('/team-visibility-settings', [TeamVisibilitySettingController::class, 'update']);

        // TASK-174 (human decision D2, 2026-08-12) — BR-7 admin-editable
        // per-company switch for TASK-026's co-agent commission split,
        // OFF until a company turns it on. update() is Company Admin (own
        // company) / Super Admin only (Form Request); show() IS
        // Agent-readable — the Agent Portal needs the flag to know whether
        // to render the split controls, and the server refuses the writes
        // either way (spec §4: the client only REFLECTS the switch).
        Route::get('/commission-split-settings', [CommissionSplitSettingController::class, 'show']);
        Route::put('/commission-split-settings', [CommissionSplitSettingController::class, 'update']);

        // TASK-052 / ADR-015 — chart-based Agent Dashboard metrics (totals,
        // 6-month series, pipeline funnel, cert/lead-source distributions,
        // top agents). Company Admin / Super Admin only (enforced in Controller).
        Route::get('/agent-dashboard-metrics', [AgentDashboardMetricsController::class, 'index']);

        // Academy — ERD-001 §Academy, BR-1. cert-tiers is read-only
        // global config; module-completions/exam-attempts are
        // append-only logs (index+store only, no update/destroy route);
        // user-certifications is normally system-created only (a passing
        // exam attempt), except for the Company/Super Admin manual-grant
        // override below (TASK-058, no update/destroy route either way).
        // TASK-221 — index stays open to every authenticated role (an
        // Agent needs it for Academy progress); store/update/destroy are
        // Super-Admin-only via CertTierPolicy, because cert_tiers has no
        // company_id and is therefore shared by every tenant.
        Route::apiResource('cert-tiers', CertTierController::class)
            ->parameters(['cert-tiers' => 'cert_tier'])
            ->except('show');

        /*
         * TASK-233 — the company's own signup link.
         *
         * THIS IS THE FIRST WRITE PATH THIS TABLE HAS EVER HAD.
         * `company_invite_codes` was added by ADR-005 and only ever read;
         * until today the only way a code came into existence outside a
         * test factory was somebody typing an INSERT into the database.
         *
         * `destroy` REVOKES (see the controller) — deleting would orphan
         * users.registered_via_invite_code_id on agents who still work here.
         *
         * Super Admin and Company Admin, per CompanyInviteCodePolicy; no
         * `show` because the admin list already carries every field the one
         * screen needs, and a second shape is a second thing to keep true.
         */
        Route::apiResource('company-invite-codes', CompanyInviteCodeController::class)
            ->parameters(['company-invite-codes' => 'company_invite_code'])
            ->except('show');

        // TASK-235 — the short form of /login?company=<slug>. POST because
        // minting writes a row, and ThemeResource (where the long link
        // lives) is read anonymously on every themed page load.
        Route::post('/company-login-link', [CompanyInviteCodeController::class, 'loginLink']);

        /*
         * TASK-234 — every link in the system, read through one endpoint.
         *
         * OPEN TO EVERY AUTHENTICATED ROLE, narrowed in the controller: an
         * Agent sees only links they created, a Company Admin sees their
         * company, a Super Admin sees everything or one named company.
         * Before this, an agent had to visit four separate screens to find
         * their own links and nothing anywhere showed a company its links
         * as a whole — splitting this per group would rebuild that problem.
         *
         * NO `store`, NO `destroy`. Links are minted by whichever service
         * owns the thing being linked to, because each carries its own
         * rules (BR-1 certification, team-leader flag, order state) and a
         * generic create endpoint would have to duplicate all of them.
         * Deleting is not offered at all: a deleted link takes its visit
         * history with it and NULLs the attribution on the orders and
         * agents it produced. Revoking happens on the underlying thing.
         */
        Route::apiResource('tracked-links', TrackedLinkController::class)
            ->parameters(['tracked-links' => 'trackedLink'])
            ->only(['index', 'show', 'update']);
        Route::apiResource('modules', ModuleController::class);
        /*
         * TASK-151 / ADR-031 §2.1 — BULK REORDER, one endpoint per parent.
         *
         * Both take the FULL ordered list of child ids and rewrite
         * `sort_order` in ONE transaction. Never N separate PUTs: "which
         * would leave the list half-reordered if the tab closed mid-way".
         *
         * PUT rather than PATCH: the body IS the complete new order of the
         * whole sibling set, which is a replacement, not a partial edit —
         * and ModuleOrderService rejects an incomplete list precisely so
         * that stays true.
         *
         * The numeric `sort_order` field on the normal create/update
         * endpoints above is UNTOUCHED and stays as the accessible fallback
         * (§2.1: "drag is added, not substituted").
         *
         * Neither route conflicts with apiResource('modules'): that
         * registers /modules and /modules/{module} only.
         */
        Route::put('/cert-tiers/{certTier}/modules/reorder', [ModuleController::class, 'reorder']);
        Route::put('/modules/{module}/lessons/reorder', [ModuleLessonController::class, 'reorder']);
        // ADR-009 — Udemy-style hierarchy: modules are Sections (pure
        // grouping), lessons carry the actual content. Same nested-for-
        // store, flat-for-update/destroy shape as exam-questions below.
        Route::post('/modules/{module}/lessons', [ModuleLessonController::class, 'store'])
            ->middleware('resolve.chunked-upload');
        /*
         * TASK-167 §3 — the SINGLE-lesson read.
         *
         * The Agent Portal's lesson screen is its own route now, so a deep
         * link or a refresh lands with no /modules payload to read the lesson
         * out of. Without this the screen would simply be empty.
         *
         * Draft/locked handling is the Controller's; it is identical to what
         * the list already does, which is the point.
         */
        Route::get('/module-lessons/{moduleLesson}', [ModuleLessonController::class, 'show']);
        Route::put('/module-lessons/{moduleLesson}', [ModuleLessonController::class, 'update'])
            ->middleware('resolve.chunked-upload');
        /*
         * TASK-188 §6.D3(a) — READ-ONLY preview of what changing this
         * lesson's content_type will do: the number of learners whose
         * recorded progress is discarded, the number who keep a completion
         * (they do — ADR-028 §2.3 grandfathering), whether a stored file is
         * deleted, whether is_downloadable resets, whether the quiz survives.
         *
         * A separate GET rather than fields on ModuleLessonResource: the
         * counts are cross-learner management data behind
         * ModulePolicy::update, and the lesson resource is served to Agents.
         */
        Route::get('/module-lessons/{moduleLesson}/content-type-change-impact', [ModuleLessonController::class, 'contentTypeChangeImpact']);
        Route::delete('/module-lessons/{moduleLesson}', [ModuleLessonController::class, 'destroy']);
        // ADR-007/ADR-028 §2.1 — private-disk stream for ANY uploaded
        // (not embedded/external) lesson file: video, pdf or image.
        // Byte-range capable since TASK-143 (ADR-028 §2.5); authorization
        // still runs before any bytes. Never a public URL (§5 rule 6).
        Route::get('/module-lessons/{moduleLesson}/stream', [ModuleLessonController::class, 'stream'])->name('module-lessons.stream');
        /*
         * TASK-146 / ADR-028 §2.3 — verified learning progress.
         *
         * PUT is the LEARNER reporting raw positions; it answers 204 and
         * leaks nothing (ADR-028 §4). Throttled because TASK-147 reports on
         * a timer while a video plays — an unthrottled per-second write
         * endpoint is both a DoS surface (§6 "rate limiting on every
         * endpoint") and an accidental self-inflicted load problem.
         *
         * GET is the ADMIN readout for support (ADR-028 §4): same URL,
         * different verb, and a strictly narrower Policy check
         * (ModulePolicy::update, not ::view) inside the Controller.
         */
        Route::put('/module-lessons/{moduleLesson}/progress', [ModuleLessonProgressController::class, 'update'])
            ->middleware('throttle:60,1');
        Route::get('/module-lessons/{moduleLesson}/progress', [ModuleLessonProgressController::class, 'index']);
        /*
         * ADR-028 §4.1 — the LEARNER reads their OWN bookmark, so resume
         * survives closing the app (TASK-147), not just the browser
         * session. Returns last_position_seconds / last_page and NOTHING
         * else: "a bookmark is not the withheld number", but max-against-
         * threshold is (ADR-028 §4), and that stays on the Admin GET above.
         *
         * Self-scoped by CONSTRUCTION, like every other /me/* route: no
         * {user} parameter exists here, so another learner's progress is
         * not merely forbidden, it is unrequestable.
         */
        Route::get('/me/module-lessons/{moduleLesson}/progress', [ModuleLessonProgressController::class, 'me']);
        // ADR-009 — formative lesson-quiz authoring, mirrors exam-question
        // authoring shape exactly (index/store nested, update/destroy flat).
        Route::get('/module-lessons/{moduleLesson}/quiz-questions', [ModuleLessonQuizQuestionController::class, 'index']);
        Route::post('/module-lessons/{moduleLesson}/quiz-questions', [ModuleLessonQuizQuestionController::class, 'store']);
        Route::put('/module-lesson-quiz-questions/{moduleLessonQuizQuestion}', [ModuleLessonQuizQuestionController::class, 'update']);
        Route::delete('/module-lesson-quiz-questions/{moduleLessonQuizQuestion}', [ModuleLessonQuizQuestionController::class, 'destroy']);
        Route::post('/module-lesson-quiz-questions/{moduleLessonQuizQuestion}/options', [ModuleLessonQuizOptionController::class, 'store']);
        Route::put('/module-lesson-quiz-options/{moduleLessonQuizOption}', [ModuleLessonQuizOptionController::class, 'update']);
        Route::delete('/module-lesson-quiz-options/{moduleLessonQuizOption}', [ModuleLessonQuizOptionController::class, 'destroy']);
        /*
         * TASK-150 / ADR-030 — the quiz LIBRARY: author a quiz first, attach
         * it to a lesson later (possibly by a different person, possibly
         * before the lesson exists).
         *
         * Admin-only in every verb — QuizPolicy, which is deliberately
         * narrower than ModulePolicy because the library carries the answer
         * keys (see the Policy's docblock).
         *
         * DELETE /quizzes/{quiz} returns 422, not 204, while the quiz is
         * linked to a lesson (§2.4): deleting it under the lesson's feet
         * would silently remove a completion gate that, where
         * `quiz_blocks_completion` is on, sits on the BR-1 certification
         * path.
         */
        Route::apiResource('quizzes', QuizController::class);
        Route::get('/quizzes/{quiz}/questions', [ModuleLessonQuizQuestionController::class, 'indexForQuiz']);
        Route::post('/quizzes/{quiz}/questions', [ModuleLessonQuizQuestionController::class, 'storeForQuiz']);
        /*
         * ADR-030 §2.3/§2.5 — the LINK between a lesson and a library quiz.
         *
         * `available-quizzes` exists so the picker can only ever offer a
         * choice that will succeed: unattached quizzes in the same company,
         * plus the one currently attached (§2.5 — "the UI should not teach
         * the rule by rejecting the user").
         *
         * PUT attaches (or re-attaches, idempotently); DELETE unlinks and
         * returns the quiz to the library with its questions intact.
         * Recorded attempts are NOT touched by either — an attempt is a
         * record of a learner doing a LESSON (§2.3). Both are audit-logged,
         * because attaching/detaching can switch that lesson's completion
         * gate on or off (CLAUDE.md §6).
         */
        Route::get('/module-lessons/{moduleLesson}/available-quizzes', [ModuleLessonQuizController::class, 'available']);
        Route::put('/module-lessons/{moduleLesson}/quiz', [ModuleLessonQuizController::class, 'attach']);
        Route::delete('/module-lessons/{moduleLesson}/quiz', [ModuleLessonQuizController::class, 'detach']);
        /*
         * TASK-149 / ADR-029 — the graded end-of-lesson quiz. Append-only,
         * so index + store and no update/destroy route, exactly like
         * module-completions and exam-attempts above.
         *
         * POST is the LEARNER submitting {question_id: option_id}; grading
         * is server-side (§2.3) and the ADR-029 §2.2 unlock check runs in
         * the Service. GET is the ADMIN readout (§2.5) — same URL, different
         * verb, and a strictly narrower Policy check (ModulePolicy::update,
         * not ::view) inside the Controller.
         *
         * Throttled even though §2.5 grants unlimited retries: "no attempt
         * cap" is a teaching decision about a human clicking a button, not a
         * licence for an unbounded write loop against the attempts table
         * (CLAUDE.md §6 — rate limiting on every endpoint).
         */
        Route::post('/module-lessons/{moduleLesson}/quiz-attempts', [ModuleLessonQuizAttemptController::class, 'store'])
            ->middleware('throttle:30,1');
        Route::get('/module-lessons/{moduleLesson}/quiz-attempts', [ModuleLessonQuizAttemptController::class, 'index']);
        Route::apiResource('exams', ExamController::class);
        // Academy Sprint 1 (human-requested 2026-07-21) — question bank
        // authoring. Same nested-for-index/store, standalone-for-
        // update/destroy routing shape as product_specs above.
        Route::get('/exams/{exam}/questions', [ExamQuestionController::class, 'index']);
        Route::post('/exams/{exam}/questions', [ExamQuestionController::class, 'store']);
        Route::put('/exam-questions/{examQuestion}', [ExamQuestionController::class, 'update']);
        Route::delete('/exam-questions/{examQuestion}', [ExamQuestionController::class, 'destroy']);
        Route::post('/exam-questions/{examQuestion}/options', [ExamQuestionOptionController::class, 'store']);
        Route::put('/exam-question-options/{examQuestionOption}', [ExamQuestionOptionController::class, 'update']);
        Route::delete('/exam-question-options/{examQuestionOption}', [ExamQuestionOptionController::class, 'destroy']);
        Route::get('/module-completions', [ModuleCompletionController::class, 'index']);
        Route::post('/module-completions', [ModuleCompletionController::class, 'store']);
        // TASK-146 / ADR-028 §2.3 guard 2 — Company Admin / Super Admin
        // marks a lesson complete FOR an agent who could not satisfy the
        // verified-progress gate. Audit-logged (§6): a lesson completion
        // feeds the BR-1 Basic cert gate. Authorization + company scoping
        // live in StoreModuleCompletionOverrideRequest.
        Route::post('/module-lessons/{moduleLesson}/completions/override', [ModuleCompletionController::class, 'override']);
        // TASK-146 / ADR-028 §4 — BR-7 admin-editable completion
        // thresholds (video watch %, pdf read %). Deliberately NOT
        // Agent-readable: these are the numbers a blocked learner is not
        // told (see AcademyCompletionSettingController).
        Route::get('/academy-completion-settings', [AcademyCompletionSettingController::class, 'show']);
        Route::put('/academy-completion-settings', [AcademyCompletionSettingController::class, 'update']);
        /*
         * TASK-152 — the Admin progress dashboard, aggregated SERVER-SIDE.
         *
         * Replaces the ความคืบหน้าตัวแทน tab's client-side join of
         * /modules (15 Sections a page), /module-completions (15 rows a page)
         * and /user-certifications (15 rows a page): both halves of every
         * "X/Y บทเรียน" on that screen were computed from truncated data, and
         * those fractions are how a Company Admin judges progress toward the
         * Basic certification that unlocks selling rights (BR-1).
         *
         * Company Admin (own company) / Super Admin (company_id required) —
         * Agent gets 403 via ModulePolicy::viewProgressSummary. This is other
         * people's learning data, so it is gated exactly like the ADR-028 §4
         * lesson-progress and ADR-029 §2.5 quiz-attempt readouts.
         *
         * Throttled: it is several GROUP BY passes over module_completions, so
         * unlike the plain Academy reads around it, it is worth a ceiling.
         */
        Route::get('/academy-progress-summary', [AcademyProgressSummaryController::class, 'index'])
            ->middleware('throttle:60,1');
        Route::get('/exam-attempts', [ExamAttemptController::class, 'index']);
        Route::post('/exam-attempts', [ExamAttemptController::class, 'store']);
        Route::get('/user-certifications', [UserCertificationController::class, 'index']);
        // BR-1 admin override (human-requested 2026-07-30) — Company Admin/
        // Super Admin manually grants a cert tier without a real exam
        // attempt; authorization + company scoping live in
        // StoreUserCertificationRequest.
        Route::post('/user-certifications', [UserCertificationController::class, 'store']);
        // Academy Sprint 6 — on-demand certificate PDF, same tenant/ownership
        // rule as a single-record read (UserCertificationPolicy::view).
        Route::get('/user-certifications/{userCertification}/download', [UserCertificationController::class, 'download'])->name('user-certifications.download');

        // Customer — ERD-001 §Customer (rev. 3), CLAUDE.md §2 "Client",
        // PDPA (Section 6). Agent's index is narrowed to their own
        // referred clients inside ClientController — see its comment.
        // TASK-056 Sprint P2 — client segmentation (BR-7 admin-editable
        // config, seeded not hardcoded). Browsable by any same-company
        // user (an Agent needs the list to filter their own clients);
        // manage-only by Company Admin/Super Admin — same shape as brands.
        Route::apiResource('client-categories', ClientCategoryController::class)
            ->parameters(['client-categories' => 'client_category']);

        // Documents are nested under a Client for index/store, but
        // download/destroy address the document directly (its own ID
        // already carries enough context once Policy-checked).
        Route::apiResource('clients', ClientController::class);
        Route::get('/clients/{client}/documents', [ClientDocumentController::class, 'index']);
        Route::post('/clients/{client}/documents', [ClientDocumentController::class, 'store']);
        Route::get('/client-documents/{clientDocument}/download', [ClientDocumentController::class, 'download']);
        Route::delete('/client-documents/{clientDocument}', [ClientDocumentController::class, 'destroy']);

        // TASK-015 — Client Activity/Communication Log. Same nested
        // -for-index/store, standalone-for-update/destroy routing shape
        // as client-documents above, except activities also support
        // update (correcting your own entry) — see ClientActivityPolicy.
        Route::get('/clients/{client}/activities', [ClientActivityController::class, 'index']);
        Route::post('/clients/{client}/activities', [ClientActivityController::class, 'store']);
        Route::put('/client-activities/{clientActivity}', [ClientActivityController::class, 'update']);
        Route::delete('/client-activities/{clientActivity}', [ClientActivityController::class, 'destroy']);

        // Referral & Pipeline — CLAUDE.md §2 "SWS Referral", §4.3
        // (sequential-only pipeline state machine), BR-1 (Basic cert
        // gate, enforced in ReferralService against the resolved
        // referring agent). No update/destroy — see ReferralPolicy.
        // /advance takes no body (PipelineService always computes the
        // one allowed next stage itself); /stage-logs is the §4.3
        // audit trail.
        // TASK-026 — a minimal, name-only roster (id + name of this
        // company's OTHER agents) so the Agent Portal's referral form can
        // offer a co-agent picker. This is a deliberate, narrow exception
        // to Section 5 rule 4 ("Agent sees only own records"): an Agent
        // sees teammate NAMES ONLY (no other user fields, no numbers/
        // commission/clients), and only for this one purpose. Must be
        // registered BEFORE the apiResource below, or Laravel would try
        // to route-model-bind "co-agent-options" as a {referral} id.
        Route::get('/referrals/co-agent-options', [ReferralController::class, 'coAgentOptions']);
        Route::apiResource('referrals', ReferralController::class)->only(['index', 'store', 'show']);
        Route::post('/referrals/{referral}/advance', [ReferralController::class, 'advance']);
        Route::get('/referrals/{referral}/stage-logs', [ReferralController::class, 'stageLogs']);
        // TASK-026 — the one exception to "no update()" above: a narrow,
        // named-ability endpoint (same shape as /advance) that only ever
        // touches co_agent_id/split_percentage, gated to before Complete
        // Payment in ReferralService::setCoAgent().
        Route::patch('/referrals/{referral}/co-agent', [ReferralController::class, 'setCoAgent']);

        // ADR-017 (TASK-054) — Order & Payment Collection. Agent sees/acts
        // on their own orders; Company Admin their company's (OrderPolicy +
        // TenantScope). confirm() verifies a payment and advances the
        // referral to Complete Payment (BR-4, once — see OrderService).
        // slip download is the access-checked private-disk stream (§6),
        // same contract as client-documents above. The customer-facing
        // side lives on the PUBLIC /pay/{token} routes registered outside
        // this auth:sanctum group.
        Route::get('/orders', [OrderController::class, 'index']);
        // BEFORE /orders/{order} — a route parameter would swallow the word
        // "summary" and try to resolve an Order with that id, which is a 404
        // on a route that exists.
        Route::get('/orders/summary', [OrderController::class, 'summary']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm']);
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
        // SECURITY AUDIT 2026-08-21 (V15, human ruling D3) — Super Admin
        // only, gated in OrderPolicy::refund(). Reverses every commission
        // the order generated by writing negative ledger entries; the
        // originals are never edited (BR-4).
        Route::post('/orders/{order}/refund', [OrderController::class, 'refund']);
        // Follow-up to the 2026-08-21 audit — an admin uploading the slip
        // on the customer's behalf (cash at a branch, slip sent over LINE).
        // The PUBLIC counterpart at /pay/{token}/slip is unchanged and is
        // still how a customer does it themselves.
        Route::post('/orders/{order}/slip', [OrderController::class, 'uploadSlip']);
        Route::get('/orders/{order}/slip', [OrderController::class, 'slip']);

        // ADR-033 (TASK-189) §2.1/C4-C5 — voucher redemption, authenticated
        // (human decision 5 — no public redemption channel this phase),
        // gated by Ability::VoucherRedeem (CompanyAdmin/SuperAdmin only).
        // show() is a lookup-by-code so the redeem screen can display
        // order/product/customer before staff commit to the POST below.
        // No {voucher} route-model binding — VoucherRedemptionService
        // resolves by code, not id (OrderVoucher has no company_id column
        // of its own for implicit binding to scope against).
        Route::get('/vouchers/{code}', [VoucherController::class, 'show']);
        Route::post('/vouchers/redeem', [VoucherController::class, 'redeem']);

        // TASK-190 §3.4 — platform-wide SMTP settings (one global row, no
        // company_id — see PlatformMailSetting's own docblock). Gated by
        // Ability::SettingsMailUpdate, Super Admin only — both actions
        // enforce that gate themselves (show() via abort_unless, update()
        // via UpdatePlatformMailSettingRequest::authorize()), same
        // belt-and-suspenders shape as every other Settings* pair.
        /*
         * ADR-027 / TASK-139 — which gateway takes a company's money.
         *
         * Super Admin only, INCLUDING the read (see the controller): the list
         * is a map of where every tenant's revenue goes. `{company}` is
         * explicit because the only role allowed here has no company of its
         * own.
         */
        Route::get('/companies/{company}/payment-gateways', [CompanyPaymentGatewayController::class, 'index']);
        Route::put('/companies/{company}/payment-gateways/{provider}', [CompanyPaymentGatewayController::class, 'update']);
        Route::post('/companies/{company}/payment-gateways/activate', [CompanyPaymentGatewayController::class, 'activate']);

        Route::get('/platform/mail-settings', [PlatformMailSettingController::class, 'show']);
        Route::put('/platform/mail-settings', [PlatformMailSettingController::class, 'update']);
        // TASK-201 — "ทดสอบส่งอีเมล" button. Same auth group/gate as the
        // GET/PUT pair above (SendTestMailRequest::authorize() enforces
        // Ability::SettingsMailUpdate itself, belt-and-suspenders shape).
        Route::post('/platform/mail-settings/test', [PlatformMailSettingController::class, 'test']);

        // TASK-196 §2.2 — platform-wide commission-rate cap (one global
        // row, no company_id — see PlatformCommissionSetting's own
        // docblock). Unlike mail-settings above, show() has NO ability
        // gate (any authenticated user — §2.2's "read-everywhere", same
        // shape as /cert-tiers); update() enforces
        // Ability::CommissionRateCapUpdate (Super Admin only) via
        // UpdatePlatformCommissionSettingRequest::authorize().
        Route::get('/platform/commission-cap', [PlatformCommissionSettingController::class, 'show']);
        Route::put('/platform/commission-cap', [PlatformCommissionSettingController::class, 'update']);

        // Commission Ledger — BR-2 (rate depends on cert tier x
        // product), BR-4 (immutable ledger, system-created only —
        // CommissionService::recordForReferral(), triggered by
        // PipelineService at Complete Payment). Agent's index is
        // narrowed to their own earnings; markPaid is the one allowed
        // mutation (Company Admin/Super Admin only, see
        // CommissionLedgerPolicy).
        Route::apiResource('commission-ledger', CommissionLedgerController::class)
            ->only(['index', 'show'])
            ->parameters(['commission-ledger' => 'commission_ledger']);
        Route::post('/commission-ledger/{commission_ledger}/mark-paid', [CommissionLedgerController::class, 'markPaid']);

        /*
         * 2026-08-27 — agent-initiated commission withdrawal.
         *
         * Sits beside the ledger because it is the same money seen from the
         * other end: the ledger records what was earned, these record asking
         * to be paid it. Agent and admin share the routes; the Policy and
         * index()'s own scoping decide who sees and may act on what, rather
         * than a second set of /admin-prefixed endpoints that would have to
         * repeat the tenant rules.
         *
         * `available` is deliberately its own GET rather than a field on the
         * index: it is a computed balance, it is what the request form needs
         * before anything exists to list, and it must not be derived in the
         * browser (see the controller).
         */
        // The per-company minimum. Registered BEFORE the
        // /commission-withdrawals/{id} route below so "settings" is never
        // parsed as a request id — Laravel matches in declaration order, and
        // a settings page that 404s as a missing model is a confusing way to
        // learn that.
        Route::get('/commission-withdrawal-settings', [CommissionWithdrawalSettingController::class, 'show']);
        Route::put('/commission-withdrawal-settings', [CommissionWithdrawalSettingController::class, 'update']);

        Route::get('/commission-withdrawals/available', [CommissionWithdrawalRequestController::class, 'available']);
        Route::get('/commission-withdrawals', [CommissionWithdrawalRequestController::class, 'index']);
        Route::post('/commission-withdrawals', [CommissionWithdrawalRequestController::class, 'store'])
            // A payout request is a money action; the throttle is the same
            // shape used on the other write endpoints that move value.
            ->middleware('throttle:10,1');
        Route::get('/commission-withdrawals/{commissionWithdrawalRequest}', [CommissionWithdrawalRequestController::class, 'show']);
        Route::post('/commission-withdrawals/{commissionWithdrawalRequest}/cancel', [CommissionWithdrawalRequestController::class, 'cancel']);
        Route::post('/commission-withdrawals/{commissionWithdrawalRequest}/approve', [CommissionWithdrawalRequestController::class, 'approve']);
        Route::post('/commission-withdrawals/{commissionWithdrawalRequest}/reject', [CommissionWithdrawalRequestController::class, 'reject']);
        Route::post('/commission-withdrawals/{commissionWithdrawalRequest}/mark-transferred', [CommissionWithdrawalRequestController::class, 'markTransferred']);

        // TASK-043 §3 — per-agent commission summary (grouped-by-agent
        // aggregate CommissionLedgerController::index() never provided).
        // Company Admin/Super Admin only — see AgentCommissionSummaryController.
        Route::get('/agent-commission-summary', [AgentCommissionSummaryController::class, 'index']);

        // TASK-044 §3 — bank payout CSV export of the same summary above.
        // Registered as a static sub-path (not a resource route), so no
        // collision with the plain GET above. Same auth gate/filters,
        // reused from index() — see AgentCommissionSummaryController::export()
        // docblock for why this is the most sensitive endpoint in TASK-044
        // (returns real, unmasked bank_account_number values).
        Route::get('/agent-commission-summary/export', [AgentCommissionSummaryController::class, 'export']);

        // Gamification — BR-5 (XP sources: learning completion + sales
        // activity). XP itself is entirely system-awarded by
        // GamificationService, triggered from Academy/Referral/Pipeline
        // Services — never written directly via this API (xp-ledger is
        // index/show only). gamification-rules is the BR-5 config CRUD
        // (Admin only, company-override-or-platform-default). badges is
        // a read-only definition catalog (index only — no admin
        // authoring yet). user-badges is index (own earned badges) +
        // the one manual award action. /leaderboard is a standalone
        // aggregate, not a CRUD resource — see LeaderboardController.
        // "Level" (Phase 9): level_thresholds is platform-wide config (no
        // company_id — see LevelThresholdPolicy), Super-Admin-write /
        // anyone-read. /leaderboard now includes level_number per row.
        Route::apiResource('gamification-rules', GamificationRuleController::class)
            ->parameters(['gamification-rules' => 'gamification_rule']);
        Route::apiResource('level-thresholds', LevelThresholdController::class)
            ->parameters(['level-thresholds' => 'level_threshold']);
        Route::apiResource('xp-ledger', XpLedgerController::class)
            ->only(['index', 'show'])
            ->parameters(['xp-ledger' => 'xp_ledger']);
        Route::get('/leaderboard', [LeaderboardController::class, 'index']);
        Route::apiResource('badges', BadgeController::class);
        Route::get('/user-badges', [UserBadgeController::class, 'index']);
        Route::post('/user-badges', [UserBadgeController::class, 'store']);

        // Agent-view IA items 1.4/1.5/1.6 (prototype, human sign-off
        // pending on the BR-7 flags in each Service's own docblock —
        // see /docs/tasks/ agent-engagement task specs). Kept as a
        // separate "Engagement" group rather than folded into
        // Gamification/Commission since none of the 3 is either.
        Route::apiResource('agent-promotions', AgentPromotionController::class)
            ->parameters(['agent-promotions' => 'agent_promotion']);
        Route::apiResource('reward-items', RewardItemController::class)
            ->parameters(['reward-items' => 'reward_item']);
        Route::apiResource('reward-redemptions', RewardRedemptionController::class)
            ->only(['index', 'store', 'show'])
            ->parameters(['reward-redemptions' => 'reward_redemption']);
        Route::get('/reward-redemptions-my-balance', [RewardRedemptionController::class, 'myBalance']);
        Route::post('/reward-redemptions/{reward_redemption}/decide', [RewardRedemptionController::class, 'decide']);
        // TASK-042 §2: tracking_number is plain Admin-editable data, not a
        // status transition — kept as its own PATCH route rather than folded
        // into /decide (see RewardRedemptionService::updateTrackingNumber()).
        Route::patch('/reward-redemptions/{reward_redemption}/tracking-number', [RewardRedemptionController::class, 'updateTrackingNumber']);
        Route::apiResource('announcements', AnnouncementController::class);
        // TASK-076 — BR-7 admin-editable "how many times to auto-pop an
        // unseen announcement" config. show() is Agent-readable too (see
        // AnnouncementSettingController docblock).
        Route::get('/announcement-settings', [AnnouncementSettingController::class, 'show']);
        Route::put('/announcement-settings', [AnnouncementSettingController::class, 'update']);

        // Platform / Admin management (Phase 7). companies is Super
        // Admin only end to end (CompanyPolicy). users ("Manage Agents")
        // is Company Admin's own team (agent + company_admin roles,
        // TenantScope narrows it automatically) or Super Admin across
        // every company — see UserPolicy/UserController for the full
        // shape. restore uses withTrashed() binding since the row is
        // soft-deleted by the time this route needs to find it.
        Route::apiResource('companies', CompanyController::class);
        Route::apiResource('users', UserController::class)->parameters(['users' => 'user']);
        Route::post('/users/{user}/restore', [UserController::class, 'restore'])->withTrashed();
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
        // Phase 11 — Super-Admin-only, see UserPolicy::move(). Historical
        // ledger/audit rows keep their own independent company_id
        // (BR-4/BR-5) — moving a user does not rewrite the past.
        Route::post('/users/{user}/move-company', [UserController::class, 'moveToCompany']);

        // TASK-020 (ADR-005 decision 3) — Pending Agent Approvals queue.
        // Reuses UserPolicy (viewAny/update) rather than a new Policy
        // class — see AgentApprovalController's own comment for why.
        //
        // TASK-115 / ADR-025 §7 — approve() now also accepts a designated
        // team leader acting on their OWN recruit (UserPolicy::
        // approveRegistration). reject() deliberately does NOT — it still
        // authorizes against UserPolicy::update(), i.e. admins only; see
        // AgentApprovalService's class docblock for the reasoning.
        //
        // Route order: the literal /my-recruits segment is registered before
        // nothing that could shadow it (the {user} routes below all carry a
        // second segment), but it is kept adjacent to its siblings for
        // readability. It is a GET and self-scoped to $request->user() — no
        // {user} parameter exists, so there is no IDOR surface at all.
        Route::get('/agent-approvals', [AgentApprovalController::class, 'index']);
        Route::get('/agent-approvals/my-recruits', [AgentApprovalController::class, 'myRecruits']);
        Route::put('/agent-approvals/{user}/approve', [AgentApprovalController::class, 'approve']);
        Route::put('/agent-approvals/{user}/reject', [AgentApprovalController::class, 'reject']);
        // Admin-only reversal of an approval (including a leader's) — the
        // concrete form of ADR-025 §7's "Company Admins keep the full
        // approval queue and can reverse anything a leader did".
        Route::put('/agent-approvals/{user}/revoke', [AgentApprovalController::class, 'revoke']);

        // TASK-041 — Policy & Report IA item 4 ("มุมที่ 4"). Four
        // read-only reporting endpoints, each gated inside its own
        // Controller (AuditLogPolicy::viewAny for the first; explicit
        // abort_unless(isSuperAdmin()/isCompanyAdmin()) for the other
        // three curated-aggregate reports, same style as
        // /products-abc-grades above). Section 6 audit trail viewer +
        // BR-7 config-health/compliance/cross-company aggregate reports.
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/platform-report', [PlatformReportController::class, 'index']);
        Route::get('/compliance-report', [ComplianceReportController::class, 'index']);
        Route::get('/config-health-report', [ConfigHealthReportController::class, 'index']);
    });
});
