<?php

// TASK-164 — the DESIGNED STARTER PALETTES every company is seeded with.
//
// WHY THIS IS A CONFIG FILE AND NOT A PHP CLASS
// ---------------------------------------------
// BR-7: these hex values are a business value, not logic. They were
// supplied by the human via ag-lead on 2026-08-11 — TASK-161 §5.1 said
// plainly that if starter sets were ever wanted, "those hex values are
// BR-7 and must come from the human". They now have. Nothing here may be
// invented, substituted or "improved" by an agent; changing a colour is a
// business decision, and this file is the one place to point a
// non-developer at when they want a different gold.
//
// ThemePresetService holds NO colours (its COLOR_FIELDS is a list of
// COLUMN NAMES); it reads this file and writes the rows.
//
// SHAPE
// -----
// `key`    — the idempotency handle used for seeding. NOT the name: an
//            admin-visible name could in principle be edited, and keying
//            the seed on it would either duplicate the preset or refuse to
//            recognise it. The key never changes and is never shown.
// `name`   — the Thai label the admin sees.
// `colors` — exactly ThemePresetService::COLOR_FIELDS, no more and no
//            fewer (a test asserts this, so a typo here fails the suite
//            rather than silently seeding a half preset).
//
// Gradient configs use the color1/color2/angle spelling — the canonical
// one. The frontend's gradientStops() also tolerates from/to for older
// stored rows; new data must not add to that ambiguity.
// Solid backgrounds use {color: ...}, which is what the theme store reads
// (`c?.color ?? c?.hex`).
// `card_shadow` must be one of ''|none|sm|md|lg|xl (UpdateThemeRequest).
return [
    /*
     * The five palettes, in the order the human listed them — which is
     * also the order they are seeded in and therefore the order they
     * appear in the Admin list.
     */
    'designed' => [
        [
            'key' => 'gold_classic',
            'name' => 'ทองคลาสสิก',
            'colors' => [
                'primary_hex' => '#B08D46',
                'accent_hex' => '#D7CDB0',
                'nav_bg_hex' => '#000000',
                'nav_bg_type' => 'solid',
                'nav_bg_config' => null,
                'nav_text_hex' => '#E8E2D4',
                'nav_active_hex' => '#D7CDB0',
                'card_bg_hex' => '#111111',
                'card_text_hex' => '#F3EBDA',
                'card_border_hex' => '#3A3A3A',
                'card_shadow' => 'md',
                'background_type' => 'gradient',
                'background_config' => ['color1' => '#0B0B0B', 'color2' => '#1C1710', 'angle' => 160],
            ],
        ],
        [
            'key' => 'corporate_navy',
            'name' => 'น้ำเงินองค์กร',
            'colors' => [
                'primary_hex' => '#1E3A8A',
                'accent_hex' => '#F59E0B',
                'nav_bg_hex' => '#FFFFFF',
                'nav_bg_type' => 'solid',
                'nav_bg_config' => null,
                'nav_text_hex' => '#1E293B',
                'nav_active_hex' => '#1E3A8A',
                'card_bg_hex' => '#FFFFFF',
                'card_text_hex' => '#0F172A',
                'card_border_hex' => '#E2E8F0',
                'card_shadow' => 'sm',
                'background_type' => 'solid',
                'background_config' => ['color' => '#F1F5F9'],
            ],
        ],
        [
            'key' => 'health_green',
            'name' => 'เขียวสุขภาพ',
            'colors' => [
                'primary_hex' => '#0F766E',
                'accent_hex' => '#34D399',
                'nav_bg_hex' => '#FFFFFF',
                'nav_bg_type' => 'solid',
                'nav_bg_config' => null,
                'nav_text_hex' => '#134E4A',
                'nav_active_hex' => '#0F766E',
                'card_bg_hex' => '#FFFFFF',
                'card_text_hex' => '#0F172A',
                'card_border_hex' => '#D1FAE5',
                'card_shadow' => 'sm',
                'background_type' => 'gradient',
                'background_config' => ['color1' => '#F0FDFA', 'color2' => '#E6F4F1', 'angle' => 160],
            ],
        ],
        [
            'key' => 'neutral_grey',
            'name' => 'เทาสุภาพ',
            'colors' => [
                'primary_hex' => '#334155',
                'accent_hex' => '#64748B',
                'nav_bg_hex' => '#FFFFFF',
                'nav_bg_type' => 'solid',
                'nav_bg_config' => null,
                'nav_text_hex' => '#1E293B',
                'nav_active_hex' => '#334155',
                'card_bg_hex' => '#FFFFFF',
                'card_text_hex' => '#0F172A',
                'card_border_hex' => '#E2E8F0',
                'card_shadow' => 'sm',
                'background_type' => 'solid',
                'background_config' => ['color' => '#F8FAFC'],
            ],
        ],
        [
            'key' => 'premium_purple',
            'name' => 'ม่วงพรีเมียม',
            'colors' => [
                'primary_hex' => '#7C3AED',
                'accent_hex' => '#C4B5FD',
                'nav_bg_hex' => '#14101F',
                'nav_bg_type' => 'solid',
                'nav_bg_config' => null,
                'nav_text_hex' => '#E9E4F5',
                'nav_active_hex' => '#C4B5FD',
                'card_bg_hex' => '#1B1530',
                'card_text_hex' => '#F1EDFA',
                'card_border_hex' => '#332A4D',
                'card_shadow' => 'md',
                'background_type' => 'gradient',
                'background_config' => ['color1' => '#0E0A18', 'color2' => '#241B3A', 'angle' => 160],
            ],
        ],
    ],
];
