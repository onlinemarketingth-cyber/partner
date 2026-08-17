<?php

namespace App\Enums;

// ADR-007 — shared across product_media, product_sales_materials, and
// modules: decides which sibling column holds the real value
// (file_path/content_ref for Upload vs. embed_url/content_ref for
// Embed) and whether processing_status is meaningful at all (Embed
// never needs compression — there's no file of ours to compress).
enum MediaSourceType: string
{
    case Upload = 'upload';
    case Embed = 'embed';
}
