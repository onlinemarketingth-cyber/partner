<script setup lang="ts">
/**
 * LessonVideoPlayer (Admin PREVIEW copy) — TASK-149.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KEEP IN SYNC (CI-001/CI-002, ADR-003)
 * The learner's copy lives at
 * `frontend/src/design-system/components/LessonVideoPlayer.vue`.
 * The two Vue apps duplicate `design-system/` on purpose (ADR-003 —
 * separate builds, no shared package yet). This copy exists so the Admin
 * lesson preview shows the SAME playback surface an agent gets; if the
 * learner's `<video>` element or its attributes change there, change
 * them here too or the preview starts lying to the admin about what they
 * are publishing.
 *
 * Colour classes deliberately differ: the Agent Portal runs on the
 * TASK-098 surface/ink tokens, Admin on plain slate/brand. That is a
 * known, accepted divergence (same as the two PdfViewerModal copies) —
 * the STRUCTURE is what must stay in sync, not the palette.
 * ─────────────────────────────────────────────────────────────────────
 *
 * ── WHAT THIS COPY DELIBERATELY DROPS, AND WHY IT MUST STAY DROPPED ──
 * The learner's copy emits `position` / `flush`, which AcademyView.vue
 * feeds into `useLessonProgress` → `PUT /module-lessons/{id}/progress`.
 * This copy has NO such emits and takes NO `resumeSeconds`, so an admin
 * skimming a video in the preview can never create a
 * `module_lesson_progress` row for themselves (ADR-028 §2.3).
 *
 * That is enforced structurally, not by convention: `frontend-admin` has
 * no `useLessonProgress` composable at all and calls no progress
 * endpoint anywhere. Do not add either "for parity" — a preview that
 * writes progress is a preview that quietly changes the data it is
 * previewing.
 *
 * Everything else is the learner's element verbatim, including what is
 * NOT on it: no `crossorigin` attribute.
 *
 * 2026-09-17 — it carried `crossorigin="use-credentials"` until the agent
 * portal's video stopped playing entirely. `inline_url` for a video is now
 * a short-lived SIGNED url (ModuleLessonResource), because a `<video>`
 * element can carry neither an Authorization header nor — since ADR-039 —
 * a session cookie. The Policy check and LessonAccessGate still run before
 * any bytes (CLAUDE.md §5 rule 6); the proof of identity simply moved into
 * the URL.
 *
 * This console DOES authenticate with a cookie, so `use-credentials` was
 * harmless here and the preview kept working while the learner's player
 * did not. It comes off anyway: a preview whose element differs from the
 * one it previews has stopped being a preview, which is the whole point of
 * the KEEP IN SYNC block above.
 *
 * `preload="metadata"` avoids pulling the body just to open a preview.
 */
import { ref } from 'vue'
import Icon from './Icon.vue'

const props = withDefaults(
  defineProps<{
    /** ADR-028 §2.2 — render from inline_url. stream_url is the download URL. */
    inlineUrl: string
    class?: string
  }>(),
  { class: '' },
)

const failed = ref(false)
/** Bumped to force the browser to re-request the source after a failure. */
const reloadKey = ref(0)

function onError() {
  failed.value = true
}

function retry() {
  failed.value = false
  reloadKey.value += 1
}
</script>

<template>
  <div :class="props.class">
    <!-- Error state — never a silent black box. -->
    <div
      v-if="failed"
      class="w-full rounded-lg border border-slate-200 bg-slate-50 px-4 py-6 flex flex-col items-center gap-3 text-center"
    >
      <Icon name="alert" :size="22" class="text-rose-600" />
      <p class="text-sm font-bold text-rose-600">เล่นวิดีโอไม่สำเร็จ</p>
      <button
        type="button"
        class="px-4 py-2 rounded-lg bg-brand-600 text-white text-xs font-bold hover:bg-brand-700 transition inline-flex items-center gap-1.5"
        @click="retry"
      >
        <Icon name="refresh" :size="14" />
        ลองใหม่
      </button>
    </div>

    <video
      v-else
      :key="reloadKey"
      :src="inlineUrl"
      controls
      playsinline
      preload="metadata"
      class="w-full rounded-lg bg-black"
      @error="onError"
    />
  </div>
</template>
