<script setup lang="ts">
/**
 * Sprint UAT-FIX-DOC B-U01 — Reusable Loading Skeleton
 *
 * แสดง shimmer placeholder ระหว่างที่ Index/Detail page กำลัง fetch data
 * แทน blank blue-gradient screen (ที่ user เข้าใจผิดว่า app broken)
 *
 * Types:
 *   - list      : HeroHeader placeholder + 6 skeleton rows
 *   - detail    : HeroHeader placeholder + 2-column detail layout
 *   - dashboard : HeroHeader placeholder + KPI grid + chart placeholder
 *
 * Usage:
 *   <LoadingSkeleton v-if="loading" type="list" />
 *   <div v-else class="page-content">...</div>
 */
defineProps({
    type: {
        type: String,
        default: 'list', // list | detail | dashboard
        validator: (v: string) => ['list', 'detail', 'dashboard'].includes(v),
    },
    rows: {
        type: Number,
        default: 6,
    },
})
</script>

<template>
    <div class="skeleton-wrap px-4 lg:px-6 pb-4 lg:pb-6 w-full"
         style="font-family: var(--app-font);">
        <!-- ━━━ Hero header placeholder ━━━ -->
        <div class="sk-hero rounded-2xl border border-line-card bg-surface-card/95 shadow-sm p-5 mb-4">
            <div class="flex items-start gap-4">
                <div class="sk-shimmer sk-icon"></div>
                <div class="flex-1 space-y-2">
                    <div class="sk-shimmer sk-line sk-line-title"></div>
                    <div class="sk-shimmer sk-line sk-line-sub"></div>
                </div>
                <div class="sk-shimmer sk-btn"></div>
            </div>
            <!-- KPI row placeholder -->
            <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div v-for="k in 4" :key="'kpi-'+k" class="space-y-1">
                    <div class="sk-shimmer sk-line sk-line-sm"></div>
                    <div class="sk-shimmer sk-line sk-line-kpi"></div>
                </div>
            </div>
        </div>

        <!-- ━━━ List rows ━━━ -->
        <template v-if="type === 'list'">
            <div class="space-y-2">
                <div v-for="i in rows" :key="'row-'+i"
                     class="sk-row rounded-xl border border-line-card bg-surface-card/95 p-4 flex items-center gap-4">
                    <div class="sk-shimmer sk-avatar"></div>
                    <div class="flex-1 space-y-2">
                        <div class="sk-shimmer sk-line" :style="{ width: (60 + (i * 5) % 30) + '%' }"></div>
                        <div class="sk-shimmer sk-line sk-line-sm" :style="{ width: (40 + (i * 7) % 25) + '%' }"></div>
                    </div>
                    <div class="hidden sm:block sk-shimmer sk-line sk-line-amt"></div>
                    <div class="sk-shimmer sk-pill"></div>
                </div>
            </div>
        </template>

        <!-- ━━━ Detail (2-column) ━━━ -->
        <template v-else-if="type === 'detail'">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="lg:col-span-2 space-y-4">
                    <div v-for="i in 3" :key="'d-'+i"
                         class="rounded-xl border border-line-card bg-surface-card/95 p-5 space-y-3">
                        <div class="sk-shimmer sk-line sk-line-title" style="width: 30%"></div>
                        <div class="sk-shimmer sk-line" style="width: 100%"></div>
                        <div class="sk-shimmer sk-line" style="width: 85%"></div>
                        <div class="sk-shimmer sk-line" style="width: 70%"></div>
                    </div>
                </div>
                <div class="space-y-4">
                    <div v-for="i in 2" :key="'ds-'+i"
                         class="rounded-xl border border-line-card bg-surface-card/95 p-5 space-y-3">
                        <div class="sk-shimmer sk-line sk-line-title" style="width: 50%"></div>
                        <div class="sk-shimmer sk-line" style="width: 90%"></div>
                        <div class="sk-shimmer sk-line" style="width: 60%"></div>
                    </div>
                </div>
            </div>
        </template>

        <!-- ━━━ Dashboard ━━━ -->
        <template v-else-if="type === 'dashboard'">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4">
                <div v-for="i in 4" :key="'kd-'+i"
                     class="rounded-xl border border-line-card bg-surface-card/95 p-4 space-y-2">
                    <div class="sk-shimmer sk-line sk-line-sm" style="width: 50%"></div>
                    <div class="sk-shimmer sk-line sk-line-kpi" style="width: 70%"></div>
                </div>
            </div>
            <div class="rounded-xl border border-line-card bg-surface-card/95 p-5">
                <div class="sk-shimmer sk-line sk-line-title mb-4" style="width: 25%"></div>
                <div class="sk-shimmer sk-chart"></div>
            </div>
        </template>
    </div>
</template>

<style scoped>
/* ━━━ Shimmer keyframe (single source of truth) ━━━ */
@keyframes sv-shimmer {
    0%   { background-position: -800px 0; }
    100% { background-position: 800px 0; }
}
.sk-shimmer {
    display: block;
    background: linear-gradient(90deg,
        rgba(226, 232, 240, 0.35) 0%,
        rgba(203, 213, 225, 0.75) 50%,
        rgba(226, 232, 240, 0.35) 100%);
    background-size: 1600px 100%;
    animation: sv-shimmer 1.4s linear infinite;
    border-radius: 6px;
}

/* Element shapes */
.sk-icon      { width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0; }
.sk-avatar    { width: 40px; height: 40px; border-radius: 999px; flex-shrink: 0; }
.sk-btn       { width: 120px; height: 38px; border-radius: 10px; flex-shrink: 0; }
.sk-pill      { width: 84px; height: 26px; border-radius: 999px; flex-shrink: 0; }
.sk-chart     { height: 220px; border-radius: 10px; }

/* Text lines */
.sk-line          { height: 12px; width: 60%; }
.sk-line-sm       { height: 10px; width: 40%; }
.sk-line-title    { height: 16px; width: 45%; }
.sk-line-sub      { height: 12px; width: 65%; }
.sk-line-kpi      { height: 20px; width: 55%; }
.sk-line-amt      { height: 14px; width: 90px; flex-shrink: 0; }

.sk-row {
    min-height: 68px;
}
</style>
