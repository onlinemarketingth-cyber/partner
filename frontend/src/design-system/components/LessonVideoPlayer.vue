<script setup lang="ts">
import { useI18n } from '@/composables/useI18n'
const { td } = useI18n()

/**
 * LessonVideoPlayer — TASK-147 / ADR-028 §2.5.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KEEP IN SYNC (CI-001/CI-002, ADR-003)
 * A preview-only twin now lives at
 * `frontend-admin/src/design-system/components/LessonVideoPlayer.vue`,
 * used by the Admin lesson preview ("ตัวอย่างที่ตัวแทนจะเห็น") so an
 * author can see the playback surface they are publishing. If the
 * `<video>` element or its attributes change here, mirror them there —
 * otherwise the preview quietly stops describing this player.
 *
 * The Admin copy is a strict SUBSET on purpose: it drops the `position`
 * / `flush` emits and `resumeSeconds`, because a preview must never
 * write `module_lesson_progress` (ADR-028 §2.3). Do not "restore parity"
 * by porting the progress emits over there.
 * ─────────────────────────────────────────────────────────────────────
 *
 * (AuthenticatedMedia.vue remains the shared component for images and
 * for the Admin's short preview clips.)
 *
 * ── WHY THIS IS NOT AuthenticatedMedia ──────────────────────────────
 * `useAuthenticatedMedia` fetches the WHOLE file into a blob before
 * playback. That is correct for an image and fatal for a lesson video:
 * ADR-028 §2.5 spells it out — seeking works, after downloading 200 MB,
 * so "resume at 18:42" on a phone would mean "wait for the entire
 * video, then jump".
 *
 * TASK-143 gave the stream endpoint real HTTP Range support
 * (RangeFileResponder, 206/416), so the right client is a plain
 * `<video src>` that issues its OWN ranged GETs and lets the browser
 * buffer what it needs.
 *
 * ── 2026-09-17: HOW THAT REQUEST PROVES WHO IT IS ───────────────────
 *
 * It used to carry `crossorigin="use-credentials"` and rely on a Sanctum
 * SESSION COOKIE. That stopped being true when the agent portal moved to
 * bearer tokens (ADR-039 — api/client.ts's ensureCsrfCookie() is a
 * documented no-op and its hosts are no longer stateful domains). There
 * was no cookie left to send, and a media element cannot carry an
 * Authorization header — that is a property of the element, not
 * something a client can work around.
 *
 * So every uploaded video lesson asked the stream route anonymously and
 * got 401, and the "ลองใหม่" button below could never succeed, because a
 * 401 is an ANSWER rather than a blip. Images and PDFs survived the same
 * migration only because they are fetched by JS and were fixed on
 * 2026-09-04 (`authHeaders`); this component deliberately cannot take
 * that route — see the blob note above.
 *
 * `inlineUrl` is now a SHORT-LIVED SIGNED URL minted by
 * ModuleLessonResource: the authorization lives in the URL's HMAC rather
 * than in a header the element cannot send, and the server still runs the
 * Module policy and LessonAccessGate before any bytes (CLAUDE.md §5 rule
 * 6 is untouched — nothing was made public). The element therefore needs
 * NO `crossorigin` attribute at all: no credentials are involved, so
 * asking for CORS mode would only add a way for this to fail.
 *
 * The URL expires. See `onError` below for what happens then.
 *
 * ── WHAT THIS COMPONENT DELIBERATELY DOES NOT DO ────────────────────
 * It never computes or shows a completion percentage. ADR-028 §4 (human
 * decision) ruled that a learner is not told how far they got, and the
 * numbers that would answer that question live only on the Admin
 * readout. The native `<video>` scrub bar shows a playback position,
 * which is a navigation control, not a progress-toward-completion
 * meter — and the threshold it would have to be measured against is not
 * exposed to this app at all.
 */
import { computed, nextTick, ref, watch } from 'vue'
import Icon from './Icon.vue'

const props = withDefaults(
  defineProps<{
    /** ADR-028 §2.2 — render from inline_url. stream_url is the download button's URL. */
    inlineUrl: string
    /** Server-probed (ffprobe). Null when unavailable — then no resume affordance is offered. */
    durationSeconds?: number | null
    /** Where this learner left off, in seconds. Null/0 = start from the beginning. */
    resumeSeconds?: number | null
    class?: string
  }>(),
  { durationSeconds: null, resumeSeconds: null, class: '' },
)

const emit = defineEmits<{
  /** Raw position in whole seconds. The parent throttles; see useLessonProgress. */
  position: [seconds: number]
  /** "Send whatever is pending, now" — pause, ended, or the player closing. */
  flush: []
  /**
   * TASK-167 rev.2 — the video REACHED ITS END, as distinct from `flush`,
   * which also fires on a pause. Only the parent can decide what "finished"
   * should trigger, and it needs to tell the two apart: a pause at 80% is
   * not the same event as the credits rolling.
   */
  ended: []
  /**
   * 2026-09-17 — "this URL no longer works; fetch me a new one."
   *
   * `inlineUrl` is signed and expires (2 hours). A learner who leaves a
   * lesson open over lunch comes back to a src that answers 403 on its next
   * ranged GET, and retrying the SAME url would fail forever — the exact
   * dead end the 401 bug produced.
   *
   * The parent owns the lesson payload, so only it can mint a fresh URL.
   * This component asks; it does not know what a signature is.
   */
  refresh: []
}>()

const videoEl = ref<HTMLVideoElement | null>(null)
const failed = ref(false)
/** Bumped to force the browser to re-request the source after a failure. */
const reloadKey = ref(0)
const resumeDismissed = ref(false)

/**
 * TASK-147 — a silent jump reads as a bug the first time, so the video
 * starts at 0 and the resume is OFFERED.
 *
 * Suppressed near either end: "ดูต่อจาก 00:03" is noise, and offering to
 * resume at the last few seconds of a clip is worse than useless.
 */
const RESUME_EDGE_SECONDS = 10

const resumeOffered = computed(() => {
  const at = props.resumeSeconds ?? 0
  if (resumeDismissed.value || failed.value) return false
  if (at < RESUME_EDGE_SECONDS) return false
  const duration = props.durationSeconds
  if (duration !== null && at > duration - RESUME_EDGE_SECONDS) return false
  return true
})

function formatClock(totalSeconds: number): string {
  const seconds = Math.max(0, Math.floor(totalSeconds))
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = seconds % 60
  const mm = h > 0 ? String(m).padStart(2, '0') : String(m)
  return (h > 0 ? `${h}:` : '') + `${mm}:${String(s).padStart(2, '0')}`
}

const resumeLabel = computed(() => `ดูต่อจาก ${formatClock(props.resumeSeconds ?? 0)}`)

function acceptResume() {
  const el = videoEl.value
  resumeDismissed.value = true
  if (!el) return
  el.currentTime = props.resumeSeconds ?? 0
  void el.play().catch(() => {
    // Autoplay policies can refuse a programmatic play(); the seek still
    // happened, so the learner just presses play themselves.
  })
}

function dismissResume() {
  resumeDismissed.value = true
}

function onTimeUpdate() {
  const el = videoEl.value
  if (!el) return
  emit('position', Math.floor(el.currentTime))
}

function onPauseOrEnded() {
  const el = videoEl.value
  if (el) emit('position', Math.floor(el.currentTime))
  emit('flush')
}

function onEnded() {
  onPauseOrEnded()
  emit('ended')
}

/**
 * Where playback was when the source died, so a refresh does not send the
 * learner back to 00:00.
 *
 * Not the same thing as `resumeSeconds`, which is the server's record of a
 * previous session and is offered rather than applied (see resumeOffered).
 * This one is applied silently, because nothing visible happened from the
 * learner's point of view: the picture stopped and came back.
 */
const positionBeforeFailure = ref<number | null>(null)

function onError() {
  const el = videoEl.value
  if (el && el.currentTime > 0) positionBeforeFailure.value = el.currentTime

  failed.value = true
  emit('flush')
}

/**
 * Re-requesting the SAME url is the one thing that cannot help.
 *
 * The likely cause of a failure here is an expired signature, and an expired
 * signature stays expired. So retry asks the parent for a fresh lesson first;
 * the `inlineUrl` prop changing is what actually reloads the element (see the
 * watcher below). `reloadKey` still gets bumped for the case where the URL
 * comes back identical — a genuine network blip — because then nothing else
 * would tell the element to try again.
 */
function retry() {
  failed.value = false
  reloadKey.value += 1
  emit('refresh')
}

/*
 * A new signed URL arrived: clear the error, and put the learner back where
 * they were. `loadeddata` rather than an immediate assignment because
 * currentTime cannot be set on an element that has not loaded metadata yet —
 * it is silently ignored, which would look exactly like the seek working and
 * then being undone.
 */
watch(
  () => props.inlineUrl,
  () => {
    failed.value = false
    reloadKey.value += 1

    const at = positionBeforeFailure.value
    if (at === null) return

    positionBeforeFailure.value = null
    void nextTick(() => {
      const el = videoEl.value
      if (!el) return
      el.addEventListener('loadeddata', () => { el.currentTime = at }, { once: true })
    })
  },
)

defineExpose({ flush: () => emit('flush') })
</script>

<template>
  <div :class="props.class">
    <!-- Resume affordance — visible, never a silent seek (TASK-147). -->
    <div
      v-if="resumeOffered"
      class="mb-2 flex items-center gap-2 px-3 py-2 rounded-lg bg-surface-chip border border-line-card"
    >
      <Icon name="play" :size="14" class="text-ink-card-subtle shrink-0" />
      <p class="text-xs text-ink-card-muted flex-1 min-w-0">{{ td('media.resume') }}</p>
      <button
        type="button"
        class="shrink-0 min-h-[44px] px-3 rounded-lg bg-brand-600 text-ink-primary text-xs font-bold hover:bg-brand-700 active:scale-95 transition"
        @click="acceptResume"
      >
        {{ resumeLabel }}
      </button>
      <button type="button" class="shrink-0 min-h-[44px] px-2 text-xs font-bold text-ink-card-subtle" @click="dismissResume">
        {{ td('common.restart') }}
      </button>
    </div>

    <!-- Error state — never a silent black box. -->
    <div
      v-if="failed"
      class="w-full max-w-md rounded-lg border border-line-card bg-surface-chip px-4 py-6 flex flex-col items-center gap-3 text-center"
    >
      <Icon name="alert" :size="22" class="text-ink-danger" />
      <p class="text-sm font-bold text-ink-danger">{{ td('media.video_failed') }}</p>
      <button
        type="button"
        class="min-h-[44px] px-4 rounded-lg bg-brand-600 text-ink-primary text-xs font-bold hover:bg-brand-700 active:scale-95 transition inline-flex items-center gap-1.5"
        @click="retry"
      >
        <Icon name="refresh" :size="14" />
        {{ td('common.retry') }}
      </button>
    </div>

    <!--
      NO `crossorigin` attribute, deliberately (see the header comment).
      `inlineUrl` is a signed URL: there are no credentials to send, so
      putting the element in CORS mode would add a failure path and buy
      nothing. It carried `use-credentials` until 2026-09-17, aimed at a
      session cookie this app had already stopped having.

      `preload="metadata"` rather than "auto": we want duration + the
      ability to seek without pulling the body on a mobile connection.
    -->
    <video
      v-else
      :key="reloadKey"
      ref="videoEl"
      :src="inlineUrl"
      controls
      playsinline
      preload="metadata"
      class="w-full max-w-md rounded-lg bg-black"
      @timeupdate="onTimeUpdate"
      @pause="onPauseOrEnded"
      @ended="onEnded"
      @error="onError"
    />
  </div>
</template>
