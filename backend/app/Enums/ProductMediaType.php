<?php

namespace App\Enums;

// ADR-007 — product_media.media_type. Distinct from MediaSourceType
// (upload/embed, which is ORTHOGONAL: an image is always uploaded in
// this system — no "embed an image" use case was requested — but a
// video can be either).
enum ProductMediaType: string
{
    case Image = 'image';
    case Video = 'video';
}
