<?php

namespace App\Support\Media;

/**
 * 2026-09-09 — the folder a product's uploaded file lives under.
 *
 * Every media service builds its path the same way:
 *
 *     "product-media/{$product->company_id}/{$product->id}"
 *
 * which was fine while a product always had a company. ADR-040 made
 * `products.company_id` nullable, and interpolating NULL into a string
 * yields "" — so a platform product's files would land in
 * `product-media//11`, a path with an empty segment. Storage drivers differ
 * on whether they collapse that, which means the path a file is WRITTEN to
 * and the path it is later READ from can disagree: the upload succeeds and
 * the image 404s afterwards, which is the worst shape a bug can take here.
 *
 * A named segment instead. It also reads correctly to a human staring at the
 * disk: these files belong to the platform, not to a company whose id got
 * lost.
 */
final class OwnerDirectory
{
    /** The path segment for a company id that may legitimately be NULL. */
    public static function for(?int $companyId): string
    {
        return $companyId === null ? 'platform' : (string) $companyId;
    }
}
