<?php

namespace App\Enums;

// ADR-008 — product_spec_attachments.media_type. Distinct from
// ProductMediaType (image|video) — a spec sheet gallery has no video
// use case, but does need pdf, which the hero/thumbnail gallery
// (product_media) was never designed for (ADR-008 Decision 2).
enum ProductSpecAttachmentType: string
{
    case Image = 'image';
    case Pdf = 'pdf';
}
