<?php

namespace App\Enums;

/**
 * TASK-097 / ADR-022 — which of a product's two galleries a media row
 * belongs to.
 *
 * Human request (2026-08-04): "ทำไม up รูปปกสินค้า แล้วรายละเอียดสินค้า
 * ขึ้นด้วย ต้องแยกกัน และรูปสินค้าสามารถ Upload ได้หลายรูปเหมือน Shopee."
 *
 * Cover  — "รูปสินค้า": the Shopee-style product photo set. Multiple
 *          images, ordered; exactly one carries is_primary and that one
 *          is the storefront card thumbnail. Images only (see
 *          StoreProductMediaRequest) — a video cannot be a product photo.
 * Detail — "รายละเอียดสินค้า": the long-form gallery (images, uploaded
 *          video, YouTube embeds) shown further down the product page.
 *          This is what the column defaults to, so every row that existed
 *          before this enum keeps its original meaning.
 */
enum ProductMediaPurpose: string
{
    case Cover = 'cover';
    case Detail = 'detail';
}
