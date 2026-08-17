<script setup lang="ts">
/**
 * AppList — TASK-082 (2026-08-03, human request: "พื้นผิวการ์ดแบบเดียว
 * ทั้งแอป คุณเสนอแก้เรื่องนี้").
 *
 * The container for `<AppCard variant="flat">` rows. It exists so the five
 * scan-and-compare screens (Clients / Referrals / Pipeline / Commission /
 * AffiliateLinks) stop being a stack of identical floating cards and
 * become one dense, scannable list — which is what Material prescribes for
 * homogeneous content.
 *
 * Two jobs, both of which the rows themselves cannot do:
 *  1. Clip the rows into a single rounded surface, so the group reads as
 *     ONE object rather than N boxes.
 *  2. Suppress the trailing hairline. Each flat row draws its own
 *     `border-b`; only the parent knows which row is last, hence
 *     `[&>*:last-child]:border-b-0` here instead of a prop on every row.
 *
 * Deliberately NOT a card: no shadow, and a hairline border only. The
 * point of the exercise is to remove one layer of boxing, not to move it
 * outwards.
 */
</script>

<template>
  <div class="bg-surface-card/95 border border-line-card rounded-2xl overflow-hidden [&>*:last-child]:border-b-0">
    <slot />
  </div>
</template>
