<?php

namespace App\Enums;

// TASK-072 — human-confirmed via AskUserQuestion (2026-08-02): banners can
// be placed at exactly 3 fixed spots on the Agent Portal "สินค้า" page
// (ProductBrowseView.vue). Default is Top (the original, only-ever spot
// before this task) so existing banners keep their current position with
// no data migration needed beyond the column default.
//   Top    — original spot: under search/filter, above the category row.
//   Middle — between the category row and "แนะนำสำหรับคุณ".
//   Bottom — directly above the main product grid (bottom of the page).
enum StorefrontBannerPlacement: string
{
    case Top = 'top';
    case Middle = 'middle';
    case Bottom = 'bottom';
}
