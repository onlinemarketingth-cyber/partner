<script setup lang="ts">
/**
 * AppListGroupHeader — TASK-082 (2026-08-03, human-confirmed: "หัวข้อกลุ่ม
 * ในลิสต์ ... เอาด้วย").
 *
 * A small caps label that splits a flat list into meaningful sections —
 * "รอจ่าย" / "จ่ายแล้ว" on Commission, pipeline stage on Pipeline, and so
 * on.
 *
 * This is the piece that makes each screen a DIFFERENT SHAPE without using
 * colour. The UX audit found all five list screens rendered one
 * undifferentiated run of rows, and the human explicitly rejected
 * per-page accent colours as the differentiator — so structure has to
 * carry it instead. Grouping does that: Commission splits by payment
 * status, Pipeline by stage, Clients by category. Same components, visibly
 * different silhouette per screen.
 *
 * `count` is optional and rendered muted on the right; it turns the header
 * into a summary line as well as a divider, which is why it sits here and
 * not in each view's markup.
 *
 * Sits BETWEEN <AppList> blocks, not inside one — each group gets its own
 * AppList so the rounded clipping applies per group.
 */
defineProps<{
  label: string
  count?: number | null
}>()
</script>

<template>
  <div class="flex items-baseline justify-between gap-2 px-1 pt-5 pb-2 first:pt-0">
    <h2 class="text-[11px] font-bold uppercase tracking-wide text-ink-card-subtle">{{ label }}</h2>
    <span v-if="count != null" class="text-[11px] font-bold text-ink-card-subtle tabular-nums">{{ count }}</span>
  </div>
</template>
