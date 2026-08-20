<?php

namespace App\Enums;

use App\Models\AffiliateLink;
use App\Models\AgentInviteLink;
use App\Models\Company;
use App\Models\CompanyInviteCode;
use App\Models\Order;
use App\Models\ProductShareLink;
use App\Models\SalesMaterialShareLink;

/**
 * TASK-232 — the kinds of public link this application hands out.
 *
 * Before this enum the app had six unrelated public-token tables that had
 * each solved "a stranger opens a URL and we look something up" in its own
 * way: different token lengths (64 / 40 / unbounded), different revoke
 * semantics (soft on four, HARD DELETE on affiliate links, none at all on
 * pay links), and counting that ranged from a full per-click table down to
 * nothing whatsoever. Nobody could answer "how many links does this
 * company have out in the world, and are any of them working?"
 *
 * This is the one place that says what a link CAN be. Everything else —
 * the short code, the visit rows, the dashboards — keys off these cases,
 * so adding a seventh kind of link is a change here and nowhere else.
 *
 * WHY A `case` AND NOT A FREE STRING COLUMN: `group` decides which model a
 * tracked link may point at, which public path it renders as, and what
 * counts as a conversion. All three of those are decisions, not data. A
 * string column would let a typo mint a link that resolves to nothing and
 * only fails when a customer opens it.
 */
enum TrackedLinkGroup: string
{
    /** สมัครตัวแทนบริษัท — the company's own open signup link (TASK-233). */
    case CompanySignup = 'company_signup';

    /** สมัครตัวแทนลูกทีม — a team leader recruiting into their own downline. */
    case TeamSignup = 'team_signup';

    /** แชร์สินค้า — an agent sharing one product with a prospect. */
    case ProductShare = 'product_share';

    /** แชร์ชำระเงิน — the pay page for one order. */
    case Payment = 'payment';

    /** ลิงก์พันธมิตร — the affiliate/trackable link (TASK-032). */
    case Affiliate = 'affiliate';

    /** แชร์สื่อการขาย — one sales material, time-limited. */
    case SalesMaterial = 'sales_material';

    /** ลิงก์ Login ของบริษัท — the branded login page. Points at the Company. */
    case CompanyLogin = 'company_login';

    /**
     * The model class a link in this group points at.
     *
     * COMPANYLOGIN POINTS AT THE COMPANY ITSELF, and that took a second
     * look to get right. The branded login link has never had a row — it
     * is built on the fly from `companies.slug` — so the first version of
     * this method returned null for it and special-cased it everywhere
     * downstream.
     *
     * That was backwards. The company IS the thing the link points at; a
     * missing row is not the same as a missing subject. Pointing at
     * Company removes the only asymmetry in this enum, and every caller
     * gets to stop asking "except this one".
     *
     * @return class-string
     */
    public function targetClass(): string
    {
        return match ($this) {
            self::CompanySignup => CompanyInviteCode::class,
            self::TeamSignup => AgentInviteLink::class,
            self::ProductShare => ProductShareLink::class,
            self::Payment => Order::class,
            self::Affiliate => AffiliateLink::class,
            self::SalesMaterial => SalesMaterialShareLink::class,
            self::CompanyLogin => Company::class,
        };
    }

    /**
     * The URL segment the short code sits under, e.g. `p` in `/p/R4TB8WM2XK`.
     *
     * DELIBERATELY NOT A FLAT `/{code}`. The agent portal already serves
     * real pages at the top level — /login, /register, /products, /profile,
     * /my-team and more. A flat short code shares that namespace, so the
     * day somebody adds a page whose path happens to equal a live code,
     * that customer's link dies and nothing in the codebase connects the
     * two events. A one- or two-letter prefix costs a couple of characters
     * and makes the collision impossible by construction.
     *
     * The prefixes for groups 1–4 are new; `p` and `pay` already exist and
     * are reused verbatim so a short code and a legacy token are handled by
     * the same route.
     */
    public function pathPrefix(): string
    {
        return match ($this) {
            self::CompanySignup => 'c',
            self::TeamSignup => 'j',
            self::ProductShare => 'p',
            self::Payment => 'pay',
            self::Affiliate => 'l',
            self::SalesMaterial => 'm',
            self::CompanyLogin => 'in',
        };
    }

    /**
     * Does this group's short URL open a page in the AGENT PORTAL?
     *
     * FOUND IN UAT, 2026-08-20. Every group was assumed to, and
     * `shortUrl()` built every URL against the portal's origin — but a
     * sales-material share has never had a page in the SPA at all. Its
     * long URL is an API endpoint that streams the file or redirects, so
     * the short one has to be the same endpoint. The link the admin was
     * handed pointed at a route that does not exist, and a missing route
     * in this SPA does not throw: it renders the app chrome with nothing
     * inside, which reads as "the site is broken".
     *
     * The 10-character code is still the win here even though the path is
     * long — it replaces 64 characters of token.
     */
    public function resolvesOnFrontend(): bool
    {
        return $this !== self::SalesMaterial;
    }

    /**
     * The full path for this group's short code, from whichever origin
     * `resolvesOnFrontend()` names.
     */
    public function publicPathFor(string $code): string
    {
        return $this === self::SalesMaterial
            ? '/api/v1/share/sales-materials/'.$code
            : '/'.$this->pathPrefix().'/'.$code;
    }

    /**
     * How many characters the random code gets.
     *
     * 10 from a 56-character alphabet is ~9.6e17 possibilities, which is
     * far past guessable given the rate limit on the resolve endpoint.
     *
     * PAYMENT GETS 14, on purpose. Its page shows an order's line items and
     * total, and today it is protected by a 40-character token; replacing
     * the front door with a 10-character one would be quietly REDUCING the
     * protection on the only link in this system that is about money. The
     * four extra characters cost nothing a customer will notice.
     */
    public function codeLength(): int
    {
        return $this === self::Payment ? 14 : 10;
    }

    /**
     * May a human choose this link's code instead of it being random?
     *
     * True only for the two links that outlive a campaign and get PRINTED —
     * on a flyer, a business card, a sign in the branch office. Somebody
     * has to read `partner.syncvision.io/c/thailife` off paper and type it
     * back in; nobody types `K7M3QP2X9A` correctly from a photo.
     *
     * False everywhere else, and that is a security property rather than a
     * preference: a product share, a pay page and a team invite are each
     * about one person, and being unguessable is the whole point.
     */
    public function allowsCustomCode(): bool
    {
        return $this === self::CompanySignup || $this === self::CompanyLogin;
    }

    /** Thai label for admin screens and exports. */
    public function label(): string
    {
        return match ($this) {
            self::CompanySignup => 'สมัครตัวแทนบริษัท',
            self::TeamSignup => 'สมัครตัวแทนลูกทีม',
            self::ProductShare => 'แชร์สินค้า',
            self::Payment => 'แชร์ชำระเงิน',
            self::Affiliate => 'ลิงก์พันธมิตร',
            self::SalesMaterial => 'แชร์สื่อการขาย',
            self::CompanyLogin => 'ลิงก์ Login บริษัท',
        };
    }
}
