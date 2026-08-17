<?php

namespace App\Services\Order;

use App\Enums\GamificationSourceType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PipelineStage;
use App\Models\Client;
use App\Models\Company;
use App\Models\Order;
use App\Models\PipelineStageLog;
use App\Models\Product;
use App\Models\ProductShareLink;
use App\Models\Referral;
use App\Models\Scopes\TenantScope;
use App\Services\Gamification\GamificationService;
use App\Services\Pipeline\PipelineTemplateResolver;
use Illuminate\Support\Facades\DB;

/**
 * TASK-136 (ADR-019 + ADR-017 + ADR-026) — turns a public product-share
 * link into a payable order for an anonymous visitor.
 *
 * This closes the gap between /p/{token} (view-only showcase, ADR-019)
 * and /pay/{token} (a working payment page that until now only an agent
 * could bring into existence, ADR-017).
 *
 * DELIBERATELY SHAPED LIKE AffiliateLeadCaptureService::capture()
 * ---------------------------------------------------------------
 * That Service is the only existing code that creates a Client + Referral
 * for a visitor with no session, and TASK-136's spec is explicit that this
 * must copy its shape rather than invent a second anonymous-write style.
 * Copied, in order: BR-1 gate on the LINK's agent (never on a visitor —
 * there isn't one, and gating on the wrong user is risk R3 of the sprint),
 * a single DB transaction, `consent_given_at` stamped for PDPA, a
 * PipelineStageLog attributed to the link's agent, the template snapshot
 * (ADR-026 §3.4), and the agent's referral-submitted XP (BR-5 source (b)).
 *
 * WHAT IT ADDS on top of capture(): the Order, via the EXISTING
 * OrderService::createForReferral() — the only method allowed to derive
 * an order's amount and FKs from a referral (§5).
 *
 * FAILURE MODEL — returns null, never throws, never explains
 * ---------------------------------------------------------
 * Every refusal (BR-1 lost, product gone or deactivated, journey unreadable,
 * journey requires a medical visit) returns null and the Controller renders ONE
 * indistinguishable generic message. A public endpoint must not be an
 * oracle for another company's internal configuration (§6), and a
 * customer cannot act on "the agent's certification lapsed" anyway.
 */
class ProductShareCheckoutService
{
    /**
     * // TODO: CONFIRM (business rule) — the duplicate-submit window.
     *
     * ADR-026 §5 "STILL OPEN" item 3 / TASK-132 §"Blocked on the human"
     * item 3 both name this as a BR-7 value the human has NOT yet given.
     * The BEHAVIOUR is specified ("same phone, same link, <N min → reuse
     * the existing pending order, do not create a second") and is
     * implemented below; only N is a guess, and it is parked here as a
     * single named constant so answering the question is a one-line
     * change with no logic to re-read.
     *
     * 30 is a placeholder chosen for one reason only — it is long enough
     * to cover a customer double-tapping, closing the tab and coming
     * back, or retrying after a failed transfer, and short enough that a
     * genuine second purchase later the same day is not swallowed. It is
     * NOT a rule anyone agreed to. Do not build reporting on it.
     */
    private const DUPLICATE_SUBMIT_WINDOW_MINUTES = 30;

    public function __construct(
        private GamificationService $gamificationService,
        private PipelineTemplateResolver $pipelineTemplateResolver,
        private OrderService $orderService,
    ) {}

    /**
     * Returns the Order the customer should pay — either a brand-new one
     * or, inside the duplicate-submit window, the pending one they
     * already have. Null means "refuse, generically".
     *
     * @param  array{name: string, phone: string, email?: string|null}  $data
     */
    public function checkout(ProductShareLink $link, array $data): ?Order
    {
        // BR-1 (Access Gate), enforced on the LINK's agent exactly as
        // AffiliateLeadCaptureService does. An agent who has lost/never
        // held Basic certification has no selling rights, and a link they
        // minted earlier must not keep selling on their behalf. Risk R3:
        // gating on the visitor instead would be no gate at all.
        if (! $link->agent || ! $link->agent->hasPassedCertTier('basic')) {
            return null;
        }

        // Resolved from the LINK's company (BR-6). This runs
        // unauthenticated, where TenantScope is a complete no-op, so the
        // company filter has to be explicit — same reasoning as
        // AffiliateLeadCaptureService and PipelineTemplateResolver.
        $product = Product::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $link->company_id)
            ->find($link->product_id);

        if (! $product) {
            return null;
        }

        // TASK-156 §3 — "ปิดการใช้งาน ซ่อนทุกที่" (human, 2026-08-10): an
        // inactive product cannot be BOUGHT. A share link outlives the
        // catalogue decision that produced it — the link is a token an agent
        // handed out weeks ago and a customer may open at any time — so
        // hiding the product from browse/search is not enough on its own; the
        // one route that turns a link into a payable order has to refuse too,
        // or "deactivated" would still take the customer's money.
        //
        // Same shape as the BR-1 gate above: a null return, rendered by the
        // Controller as the single generic refusal message. A public endpoint
        // must not become an oracle for another company's catalogue state
        // (§6), and "this product was discontinued" is not something a
        // customer can act on any more than "the agent's certification
        // lapsed" is.
        if (! $product->is_active) {
            return null;
        }

        $template = $this->pipelineTemplateResolver->resolveForProduct($product);

        // ADR-026 §3.7 — the one rule that decides whether this product is
        // self-serve at all: can a referral created now reach
        // complete_payment on its very first move? Computed from the
        // RESOLVED TEMPLATE (see paymentReachableFromEntry()), never from a
        // hardcoded stage name or a requires_medical_journey boolean.
        //
        // A product whose journey still routes through an appointment and a
        // doctor's visit would otherwise let a customer pay for an order
        // that OrderService::confirmPayment() then refuses — money in, order
        // stuck, nobody able to close it. Those products keep the existing
        // view-only share page plus the "สนใจ ให้ติดต่อกลับ" lead form
        // (TASK-137).
        if (! $this->pipelineTemplateResolver->paymentReachableFromEntry($template)) {
            return null;
        }

        // Duplicate-submit reuse BEFORE the transaction: if this customer
        // already has an open order from this link, hand back that one
        // rather than minting a second Client, Referral, XP award and
        // order for one purchase.
        $existing = $this->findReusableOrder($link, $data['phone']);
        if ($existing) {
            return $existing;
        }

        $paymentMethod = $this->defaultPaymentMethod($link->company_id);

        return DB::transaction(function () use ($link, $data, $product, $template, $paymentMethod) {
            $client = Client::create([
                'company_id' => $link->company_id,
                'referring_agent_id' => $link->agent_id,
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                // PDPA (§6) — the visitor ticked the consent box; the
                // Request made it 'required|accepted'.
                'consent_given_at' => now(),
                // ADR-019 — distinguishes a self-serve purchase from
                // 'Affiliate Link' lead capture and from an agent-typed
                // client, so reporting can tell the three apart.
                'lead_source' => 'Product Share',
            ]);

            $referral = Referral::create([
                'company_id' => $link->company_id,
                'client_id' => $client->id,
                'agent_id' => $link->agent_id,
                'product_id' => $product->id,
                // NULL, not a placeholder — human + ag-lead ruling
                // 2026-08-08 (TASK-132 §"Decision — referrals.branch").
                // NULL means "this sale did not happen at a branch",
                // which is simply true. Every UI renders it as
                // "ผ่านลิงก์ออนไลน์"; that label is a presentation
                // decision and stays in Vue (§7).
                'branch' => null,
                // No agent asked the customer when they'd like to be
                // seen, and on a self-serve journey nobody will — this is
                // a purchase, not an appointment request.
                'preferred_time' => null,
                'current_stage' => PipelineStage::CompleteRegistered,
                'meeting_number' => null,
                'submitted_at' => now(),
                // ADR-026 §3.4 — snapshotted once, never re-resolved, so
                // editing the template later cannot reroute or strand this
                // customer mid-journey.
                'pipeline_template_id' => $template->id,
            ]);

            // §4.3 audit trail — the initial-entry row, same shape
            // ReferralService::create() and AffiliateLeadCaptureService
            // write. changed_by_user_id has no nullable column and there
            // is no authenticated actor on a public route; the link's own
            // agent is the closest thing to "who this happened on behalf
            // of", the same reasoning that credits them the XP below.
            PipelineStageLog::create([
                'company_id' => $referral->company_id,
                'referral_id' => $referral->id,
                'from_stage' => null,
                'to_stage' => $referral->current_stage,
                'changed_by_user_id' => $link->agent_id,
                'changed_at' => $referral->submitted_at,
            ]);

            // BR-5 source (b). Mirrors AffiliateLeadCaptureService exactly
            // — a referral arriving through an agent's link earns that
            // agent the ordinary ReferralSubmitted XP, whoever typed the
            // form. Not a new rule: the identical situation is already
            // decided one file over, and treating the two channels
            // differently would be the inconsistency. The xp_value itself
            // still comes entirely from gamification_rules (BR-7).
            $this->gamificationService->awardXp($link->agent, GamificationSourceType::ReferralSubmitted, $referral->id);

            // The ONLY way an order is created here. Not a direct
            // Order::create(): createForReferral() is the single place
            // allowed to derive amount_satang and the tenant/ownership
            // FKs from the referral (§5), and since TASK-136 it is also
            // where the promotion-aware price lives (risk R1) — so the
            // customer is charged exactly the number the share page
            // advertised.
            return $this->orderService->createForReferral($referral, $paymentMethod);
        });
    }

    /**
     * The open order this same customer already has from this same link,
     * inside the duplicate-submit window — or null.
     *
     * Matched on (company, agent, product, client phone, still open,
     * recent) rather than on the share-link id: a ProductShareLink is
     * unique-in-practice per (agent, product) (ADR-019 §Data model), and
     * matching the tuple also catches the case where the agent revoked
     * and re-minted a link between the customer's two taps — which is the
     * same purchase from the customer's point of view.
     *
     * `phone` is the identity key because it is the one field this form
     * requires and a customer reliably retypes the same way; `name` is
     * not (spacing/nickname), and `email` is optional.
     */
    private function findReusableOrder(ProductShareLink $link, string $phone): ?Order
    {
        // withoutGlobalScope(TenantScope::class), not withoutGlobalScopes():
        // Client soft-deletes, and stripping ALL scopes would resurrect a
        // deleted client into a live order.
        $clientIds = Client::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $link->company_id)
            ->where('phone', $phone)
            ->pluck('id');

        if ($clientIds->isEmpty()) {
            return null;
        }

        return Order::withoutGlobalScopes()
            ->where('company_id', $link->company_id)
            ->where('agent_id', $link->agent_id)
            ->where('product_id', $link->product_id)
            ->whereIn('client_id', $clientIds)
            // Only an order the customer can still pay. A cancelled or
            // already-paid one must never be handed back — the first
            // would be unpayable, the second would tell them to pay
            // twice.
            ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::AwaitingVerification->value])
            ->where('created_at', '>=', now()->subMinutes(self::DUPLICATE_SUBMIT_WINDOW_MINUTES))
            ->latest('id')
            ->first();
    }

    /**
     * // TODO: CONFIRM (business rule) — which payment method a self-serve
     * order defaults to.
     *
     * An order REQUIRES a payment_method (the column is not nullable and
     * PublicOrderResource renders per-method instructions), but the
     * TASK-136 request body deliberately does not ask the customer, so the
     * backend must choose one. Rather than hardcode a preference, this
     * derives it from data the company already configured:
     *
     *   promptpay_id present -> PromptPay (the pay page can then render a
     *                           real QR payload — PromptPayService)
     *   otherwise            -> BankTransfer (the pay page shows the bank
     *                           account details instead)
     *
     * That is a mechanical consequence of existing config, not an invented
     * business preference — but the human may want an explicit per-company
     * default, or to let the customer pick, and neither is decided. Both
     * available methods are slip-verified today (PaymentMethod's own
     * comment), so this choice changes the instructions shown, never
     * whether the money can arrive.
     */
    private function defaultPaymentMethod(int $companyId): PaymentMethod
    {
        // Company carries no TenantScope of its own (it IS the tenant
        // boundary), so a plain find() is correct even unauthenticated.
        $company = Company::find($companyId);

        return $company?->payment_promptpay_id
            ? PaymentMethod::PromptPay
            : PaymentMethod::BankTransfer;
    }
}
