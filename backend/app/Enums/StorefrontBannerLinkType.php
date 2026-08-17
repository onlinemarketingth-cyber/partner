<?php

namespace App\Enums;

// TASK-073 — human-confirmed via AskUserQuestion (2026-08-02): a banner's
// click target is no longer always a Product (ADR-020 decision #2 is
// superseded by this — see StorefrontBanner model docblock). Exactly one
// of the 3 target fields on the model is populated per link_type:
//   Product  -> product_id   (existing behavior — mint/reuse a product
//                              share link, same as the product grid)
//   Url      -> external_url (opens in a new tab; admin-only input, not
//                              exposed to end users, so this is a
//                              conscious, human-confirmed relaxation of
//                              ADR-020's original "no free-text links"
//                              stance — trusted actors only)
//   Internal -> internal_path (in-app router.push — must be one of the
//                              Agent Portal's own authenticated route
//                              paths, whitelisted in
//                              StoreStorefrontBannerRequest, never
//                              free text)
enum StorefrontBannerLinkType: string
{
    case Product = 'product';
    case Url = 'url';
    case Internal = 'internal';
}
