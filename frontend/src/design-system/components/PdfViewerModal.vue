<script setup lang="ts">
/**
 * PdfViewerModal (Agent Portal copy) — full-screen, ONE-PAGE-AT-A-TIME,
 * in-app PDF reader.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KEEP IN SYNC (CI-001/CI-002, ADR-003)
 * A second copy of this component lives at
 * `frontend-admin/src/design-system/components/PdfViewerModal.vue`.
 * The two Vue apps duplicate `design-system/` deliberately (ADR-003 —
 * they are separate builds and there is no shared package yet), so any
 * change to the SHARED visual decisions here (chrome, spacing, colours,
 * the loading/error copy) must be mirrored in the other copy.
 *
 * Both copies now PAGE rather than scroll. They still differ in what is
 * deliberately NOT shared and must not be back-ported blindly: this copy
 * carries the ADR-028 §2.4 / TASK-144 learning additions — progress
 * reporting of the furthest page reached, a visible resume affordance,
 * a download button gated on `is_downloadable`, and the `embedded` mode
 * TASK-167 needs. The Admin copy views a product spec sheet (ADR-008) in
 * a real modal and needs none of that.
 * ─────────────────────────────────────────────────────────────────────
 *
 * TASK-157 (human, 2026-08-11: "หน้า PDF ขึ้นทั้งหมดเลย ให้ขึ้นแบบทีละหน้า").
 *
 * This used to be a continuous scroll of every page, with an
 * IntersectionObserver deciding which pages were near the viewport, a
 * render window, and canvas eviction to stop a 50-page document eating
 * ~200 MB of bitmap. All of that machinery existed to make CONTINUOUS
 * SCROLL survive a long document. Paging deletes the problem instead of
 * managing it: exactly one page is ever rasterised, so the memory ceiling
 * is one canvas regardless of whether the file has 3 pages or 300, and
 * the observer, the render window, the eviction pass and the per-page
 * placeholder heights all go with it.
 *
 * ADR-028 §2.4 scope, restated so nobody "completes" it later: no zoom,
 * no search, no thumbnails, no text layer. View-only.
 *
 * ── WHAT PAGING DOES TO THE ADR-028 READING GATE ────────────────────
 * The default gate is "read 100% of the pages", measured from the
 * furthest page the learner reached. Under continuous scroll a fast flick
 * could carry `furthest` to the end of the document in one gesture,
 * because the observer fires for every page that passes through the
 * viewport. Under paging the learner has to actually arrive at each page.
 * The gate did not change; it just became harder to satisfy by accident,
 * which is the direction ADR-028 §1 wanted ("completion is EARNED, not
 * asserted").
 *
 * Fetches the PDF the same way useAuthenticatedMedia.ts does (fetch +
 * credentials:'include'), because `inline_url` comes back as a full
 * absolute URL from Laravel's route() helper rather than a
 * path-prefixed api/client.ts endpoint — and because a private lesson
 * file is never a public URL (CLAUDE.md §5 rule 6). pdfjs needs the raw
 * ArrayBuffer, not a blob object URL, so the composable is not reused.
 *
 * BUNDLE (ADR-028 §5): pdfjs is ~1 MB and the Academy is not the first
 * screen, so `pdfjs-dist` is imported DYNAMICALLY inside load() — it
 * lands in its own chunk that is fetched the first time a learner opens
 * a PDF lesson, never during app boot.
 */
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch, nextTick } from 'vue'
import type { PDFDocumentLoadingTask, PDFDocumentProxy } from 'pdfjs-dist'
import Icon from './Icon.vue'

const props = withDefaults(
  defineProps<{
    /** ADR-028 §2.2 — render from inline_url, never stream_url. */
    inlineUrl: string
    title?: string
    /** Server-measured page count (ModuleLessonResource.page_count). Null when poppler is unavailable. */
    pageCount?: number | null
    /** TASK-147 — jump here on open, behind a VISIBLE affordance (never a silent jump). */
    resumePage?: number | null
    /** ADR-028 §2.2 — a download control exists only when the company said the file may be kept. */
    downloadUrl?: string | null
    /**
     * TASK-167 §2 — render IN the page instead of as an overlay on top of
     * it. The lesson screen is its own route now, so the PDF is that
     * screen's content; stacking a second fixed layer over it would put
     * back the "hardware back exits Academy" problem the routes removed.
     *
     * Reading mode (`expanded`) still goes true full-screen from here —
     * that is the one case where covering everything is the point.
     */
    embedded?: boolean
  }>(),
  { title: '', pageCount: null, resumePage: null, downloadUrl: null, embedded: false },
)

const emit = defineEmits<{
  close: []
  /**
   * Raw positions only — the SERVER decides what they mean (ADR-028 §3).
   *
   * `page` is where the reader IS and becomes `last_page`; `furthest` is
   * the high-water mark for this session. They are reported separately on
   * purpose: ADR-028 §2.3 wants last and max to be able to differ, because
   * "sitting on page 3 having reached page 12" is a different support
   * conversation from "reached page 3". The server keeps its own monotonic
   * max regardless.
   */
  progress: [payload: { page: number; furthest: number; totalPages: number }]
  download: []
}>()

function getCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(^|;\\s*)${name}=([^;]*)`))
  return match?.[2] ? decodeURIComponent(match[2]) : null
}

// ── State ───────────────────────────────────────────────────────────
const loading = ref(true)
const rendering = ref(false)
const errorMessage = ref('')
const currentPage = ref(1)
const furthestPage = ref(1)
const totalPages = ref(0)
const showResumeBanner = ref(false)
/**
 * Snapshotted from props at load time. Reading props.resumePage straight
 * into the banner would make its label creep as the learner turns pages
 * (the parent updates that value live), i.e. the offer would keep moving.
 */
const resumeTargetPage = ref(1)

// shallowRef: a PDFDocumentProxy is a large non-plain object and must
// never be made deeply reactive (Vue would walk the whole worker-backed
// graph).
const pdfDoc = shallowRef<PDFDocumentProxy | null>(null)
// The loading TASK is what owns destroy() (it terminates the worker);
// PDFDocumentProxy does not declare it in pdfjs-dist's own types. Held
// separately so cleanup never depends on an undocumented delegation.
const pdfTask = shallowRef<PDFDocumentLoadingTask | null>(null)
const viewport = ref<HTMLElement | null>(null)
const canvasEl = ref<HTMLCanvasElement | null>(null)

/** Invalidates every async continuation from a superseded load/resize/page turn. */
let renderToken = 0

/** Cap the backing-store resolution — a retina page would otherwise quadruple memory. */
const MAX_DEVICE_PIXEL_RATIO = 2

const canGoBack = computed(() => currentPage.value > 1)
const canGoForward = computed(() => currentPage.value < totalPages.value)

/**
 * TASK-158 (human, 2026-08-11) — READING MODE, toggled by tapping the page
 * counter.
 *
 * On a phone the header and the pager together eat ~110px of a ~660px tall
 * body, and this reader fits the page to the SHORTER axis (see
 * renderCurrentPage) — so on a landscape slide deck like the one this was
 * reported against, that chrome is not costing 110px of height, it is
 * costing width too, because a shorter box means a smaller uniform scale.
 * Hiding it is the single biggest thing that makes the page bigger.
 *
 * The counter is the toggle rather than a separate button because it is the
 * one control that must stay on screen in both states (it is the way back
 * out once the header with its ✕ is gone), and a control that is already
 * always-present is a better switch than one more icon in a row the task is
 * trying to thin out.
 *
 * Escape exits reading mode BEFORE it closes the modal — otherwise the
 * learner's habitual "get me out of this" keystroke would skip a level and
 * throw away their place.
 */
const expanded = ref(false)

// ── pdfjs, loaded on demand ─────────────────────────────────────────
type PdfjsModule = typeof import('pdfjs-dist')
let pdfjsLib: PdfjsModule | null = null

async function loadPdfjs(): Promise<PdfjsModule> {
  if (!pdfjsLib) {
    const lib = await import('pdfjs-dist')
    // Vite worker-asset URL — the recommended pdfjs-dist v6+ setup (it
    // ships only an .mjs worker build; no legacy UMD worker anymore).
    lib.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).href
    pdfjsLib = lib
  }
  return pdfjsLib
}

// ── Load ────────────────────────────────────────────────────────────
async function load() {
  const token = ++renderToken
  loading.value = true
  errorMessage.value = ''
  releaseDocument()
  currentPage.value = 1
  furthestPage.value = 1
  totalPages.value = 0

  try {
    const lib = await loadPdfjs()
    if (token !== renderToken) return

    const headers = new Headers()
    const xsrfToken = getCookie('XSRF-TOKEN')
    if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken)

    const res = await fetch(props.inlineUrl, { method: 'GET', headers, credentials: 'include' })
    if (!res.ok) throw new Error(String(res.status))
    const data = await res.arrayBuffer()
    if (token !== renderToken) return

    const task = lib.getDocument({ data })
    pdfTask.value = task
    const doc = await task.promise
    if (token !== renderToken) {
      void task.destroy()
      return
    }
    pdfDoc.value = doc
    totalPages.value = doc.numPages

    loading.value = false
    await nextTick()
    if (token !== renderToken) return

    await renderCurrentPage()
    reportProgress()

    // TASK-147 — a silent jump reads as a bug the first time, so the
    // reader opens at page 1 and OFFERS the jump instead of taking it.
    const resume = props.resumePage ?? 0
    resumeTargetPage.value = resume
    showResumeBanner.value = resume > 1 && resume <= doc.numPages
  } catch {
    if (token !== renderToken) return
    loading.value = false
    // Never a blank white box (TASK-144 AC) — say what happened and offer retry.
    errorMessage.value = 'เปิดเอกสาร PDF ไม่สำเร็จ'
  }
}

function contentWidth(): number {
  const host = viewport.value
  // 32px = the host's horizontal padding (px-4 on both sides).
  const available = (host?.clientWidth ?? 360) - 32
  return Math.max(240, Math.min(available, 900))
}

function availableHeight(): number {
  const host = viewport.value
  // 32px = the host's vertical padding (py-4). A page is fitted to the
  // SHORTER of width and height so a portrait A4 does not overflow the
  // body and reintroduce scrolling through a single page — the thing this
  // task exists to remove.
  return Math.max(240, (host?.clientHeight ?? 480) - 32)
}

// ── Render exactly one page ─────────────────────────────────────────
async function renderCurrentPage() {
  const doc = pdfDoc.value
  if (!doc) return

  const token = renderToken
  const page = currentPage.value
  rendering.value = true

  try {
    const pdfPage = await doc.getPage(page)
    if (token !== renderToken) return

    const canvas = canvasEl.value
    if (!canvas) return

    const base = pdfPage.getViewport({ scale: 1 })
    // Fit to BOTH axes, then take the smaller scale: fitting width alone
    // is what made a tall page overflow and need scrolling.
    const scale = Math.min(contentWidth() / base.width, availableHeight() / base.height)
    const view = pdfPage.getViewport({ scale })
    const ratio = Math.min(window.devicePixelRatio || 1, MAX_DEVICE_PIXEL_RATIO)

    canvas.width = Math.floor(view.width * ratio)
    canvas.height = Math.floor(view.height * ratio)
    canvas.style.width = `${Math.floor(view.width)}px`
    canvas.style.height = `${Math.floor(view.height)}px`

    const context = canvas.getContext('2d')
    if (!context) return
    context.setTransform(ratio, 0, 0, ratio, 0, 0)

    await pdfPage.render({ canvas, canvasContext: context, viewport: view }).promise
  } catch {
    // A single page failing to draw must not blank the whole reader — the
    // learner can page past it and the chrome stays usable.
  } finally {
    if (token === renderToken) rendering.value = false
  }
}

// ── Navigation ──────────────────────────────────────────────────────
function reportProgress() {
  if (!totalPages.value) return
  // The client reports RAW POSITIONS; ADR-028 §3 rejected letting it
  // assert a percentage or a completion. total_pages is only a fallback
  // denominator — module_lessons.page_count is the real one.
  emit('progress', { page: currentPage.value, furthest: furthestPage.value, totalPages: totalPages.value })
}

async function goToPage(page: number) {
  const target = Math.min(Math.max(1, page), totalPages.value || 1)
  if (target === currentPage.value) return

  renderToken++
  currentPage.value = target
  if (target > furthestPage.value) furthestPage.value = target

  reportProgress()
  await nextTick()
  await renderCurrentPage()
}

function prevPage() {
  if (canGoBack.value) void goToPage(currentPage.value - 1)
}
function nextPage() {
  if (canGoForward.value) void goToPage(currentPage.value + 1)
}

function acceptResume() {
  showResumeBanner.value = false
  void goToPage(resumeTargetPage.value)
}

/**
 * TASK-158 — the viewport's height changes when the chrome goes away, and
 * the page is fitted to it, so the canvas has to be redrawn rather than
 * stretched. Same reasoning as onResize(): rescaling a rasterised page
 * blurs the text.
 *
 * nextTick first — the flex box has to have relaid out before
 * availableHeight() can measure it, or the new page is drawn to the OLD
 * size and only corrects itself on the next page turn.
 */
async function toggleExpanded() {
  expanded.value = !expanded.value
  renderToken++
  await nextTick()
  await renderCurrentPage()
}

/**
 * Arrow keys, and Escape to close. Bound on the dialog root rather than
 * on window so a second overlay cannot receive the same keystroke.
 */
function onKeydown(event: KeyboardEvent) {
  if (event.key === 'ArrowLeft') {
    event.preventDefault()
    prevPage()
  } else if (event.key === 'ArrowRight') {
    event.preventDefault()
    nextPage()
  } else if (event.key === 'Escape') {
    // TASK-158 — one level at a time. Escaping straight out of reading mode
    // to closed would discard the learner's place on a keystroke they press
    // reflexively.
    if (expanded.value) void toggleExpanded()
    else emit('close')
  }
}

/**
 * Horizontal swipe — this is a phone-first screen (375px is the TASK-144
 * target) and a paged reader that can only be advanced by hitting a
 * button is not what a thumb expects.
 *
 * Deliberately only horizontal, and only past a threshold, so a vertical
 * scroll gesture on a zoomed-out page is never eaten as a page turn.
 */
const SWIPE_THRESHOLD = 48
let touchStartX = 0
let touchStartY = 0

function onTouchStart(event: TouchEvent) {
  const touch = event.changedTouches[0]
  if (!touch) return
  touchStartX = touch.clientX
  touchStartY = touch.clientY
}

function onTouchEnd(event: TouchEvent) {
  const touch = event.changedTouches[0]
  if (!touch) return
  const dx = touch.clientX - touchStartX
  const dy = touch.clientY - touchStartY
  if (Math.abs(dx) < SWIPE_THRESHOLD || Math.abs(dx) < Math.abs(dy)) return
  if (dx < 0) nextPage()
  else prevPage()
}

// ── Lifecycle ───────────────────────────────────────────────────────
function releaseDocument() {
  const task = pdfTask.value
  pdfDoc.value = null
  pdfTask.value = null
  // Terminates the pdfjs worker; without it every open leaks one.
  void task?.destroy()
}

let resizeTimer: ReturnType<typeof setTimeout> | null = null
function onResize() {
  if (resizeTimer) clearTimeout(resizeTimer)
  resizeTimer = setTimeout(() => {
    // Re-render at the new size. The page is redrawn rather than scaled,
    // so text stays crisp instead of being stretched.
    renderToken++
    void renderCurrentPage()
  }, 200)
}

watch(
  () => props.inlineUrl,
  (url) => {
    if (url) void load()
  },
  { immediate: true },
)

if (typeof window !== 'undefined') window.addEventListener('resize', onResize)

onMounted(() => {
  // Focus the dialog so the arrow keys work without the learner having to
  // click the page first.
  void nextTick(() => viewport.value?.parentElement?.focus())
})

onBeforeUnmount(() => {
  renderToken++
  if (resizeTimer) clearTimeout(resizeTimer)
  window.removeEventListener('resize', onResize)
  releaseDocument()
})

function onBackdropClick(event: MouseEvent) {
  // TASK-167 — embedded, there IS no backdrop: the surrounding pixels
  // belong to the lesson screen and clicking them must not close the reader.
  if (props.embedded) return
  if (event.target === event.currentTarget) emit('close')
}

/**
 * TASK-167 — laid out as an overlay, or in the page?
 *
 * Embedded is in-flow EXCEPT in reading mode, where the whole point is to
 * give the page every pixel (TASK-158).
 */
const isOverlay = computed(() => !props.embedded || expanded.value)
</script>

<template>
  <!-- Full-bleed on a phone (375px is the target, ADR-028/TASK-144 AC),
       a centred panel from `sm` up. `p-0 sm:p-4` rather than a single
       padding: at 375px every pixel of width is page width. -->
  <div
    :class="
      isOverlay
        ? 'fixed inset-0 z-50 bg-black/70 flex items-stretch sm:items-center justify-center p-0 sm:p-4'
        : 'flex w-full'
    "
    :role="isOverlay ? 'dialog' : undefined"
    :aria-modal="isOverlay ? 'true' : undefined"
    :aria-label="title || 'เอกสาร PDF'"
    @click="onBackdropClick"
  >
    <div
      class="relative bg-surface-card flex flex-col overflow-hidden outline-none"
      :class="
        isOverlay
          ? 'w-full sm:max-w-3xl h-full sm:max-h-[92vh] sm:rounded-2xl shadow-xl'
          : 'w-full h-[68vh] rounded-2xl border border-line-card shadow-sm'
      "
      tabindex="-1"
      @keydown="onKeydown"
    >
      <!-- Header — TASK-158: gone in reading mode. Everything it carried has
           a way back: the counter reappears in the floating pager (and is
           itself the way out), Escape exits, and the download control is a
           deliberate casualty — it is not a reading control, and one tap on
           the counter brings it back. -->
      <div v-if="!expanded" class="flex items-center gap-2 px-3 py-2.5 border-b border-line-card shrink-0">
        <Icon name="document" :size="16" class="text-ink-card-subtle shrink-0" />
        <p class="text-sm font-bold text-ink-card truncate flex-1 min-w-0">{{ title || 'เอกสาร PDF' }}</p>

        <!-- Page counter — TASK-144. Reads as navigation ("where am I in
             this document"), never as a completion percentage: ADR-028 §4
             ruled a learner is not told how close they are to the gate. -->
        <button
          v-if="totalPages"
          type="button"
          class="shrink-0 min-h-[44px] px-2 rounded-lg text-[11px] font-bold text-ink-card-muted tabular-nums whitespace-nowrap hover:bg-surface-chip active:scale-95 transition inline-flex items-center gap-1"
          title="ขยายเต็มจอ"
          aria-label="ขยายเต็มจอ"
          :aria-pressed="expanded"
          @click="toggleExpanded"
        >
          หน้า {{ currentPage }} / {{ totalPages }}
          <Icon name="maximize" :size="13" />
        </button>

        <!-- ADR-028 §2.2 — rendered ONLY when the company marked the file
             downloadable. This is not protection and is not labelled as
             such; it hides a control, nothing more. -->
        <button
          v-if="downloadUrl"
          type="button"
          class="shrink-0 w-11 h-11 flex items-center justify-center rounded-lg text-ink-card-subtle hover:bg-surface-chip active:scale-95 transition"
          title="ดาวน์โหลดไฟล์"
          aria-label="ดาวน์โหลดไฟล์"
          @click="emit('download')"
        >
          <Icon name="download" :size="18" />
        </button>

        <!-- TASK-167 — no ✕ when embedded: the reader is not a layer to
             dismiss, it is the screen's content, and the screen already has
             one way out (the top bar's back button). Two exits that mean
             different things is how a screen starts feeling ambiguous. -->
        <button
          v-if="!embedded"
          type="button"
          class="shrink-0 w-11 h-11 flex items-center justify-center rounded-lg text-ink-card-subtle hover:bg-surface-chip active:scale-95 transition"
          title="ปิด"
          aria-label="ปิด"
          @click="emit('close')"
        >
          <Icon name="x" :size="18" />
        </button>
      </div>


      <!-- Body — ONE page, fitted to the box. `overflow-hidden`, not
           `overflow-y-auto`: the page is scaled to fit both axes, so there
           is deliberately nothing to scroll. -->
      <div
        ref="viewport"
        class="relative flex-1 min-h-0 overflow-hidden bg-surface-chip px-4 py-4 flex items-center justify-center"
        @touchstart.passive="onTouchStart"
        @touchend.passive="onTouchEnd"
      >
        <div v-if="loading" class="flex items-center justify-center text-ink-card-subtle gap-2">
          <Icon name="clock" :size="20" class="animate-pulse" />
          <span class="text-sm font-bold">กำลังโหลด PDF...</span>
        </div>

        <div v-else-if="errorMessage" class="flex flex-col items-center justify-center gap-3 px-6 text-center">
          <Icon name="alert" :size="24" class="text-ink-danger" />
          <span class="text-sm font-bold text-ink-danger">{{ errorMessage }}</span>
          <button
            type="button"
            class="min-h-[44px] px-4 rounded-lg bg-brand-600 text-ink-primary text-xs font-bold hover:bg-brand-700 active:scale-95 transition inline-flex items-center gap-1.5"
            @click="load"
          >
            <Icon name="refresh" :size="14" />
            ลองใหม่
          </button>
        </div>

        <!-- v-show, not v-if: the canvas ref must survive a page turn, or
             renderCurrentPage() would draw into an element that no longer
             exists and the reader would go blank on the second page. -->
        <div v-show="!loading && !errorMessage" class="relative rounded-lg bg-white shadow-sm overflow-hidden">
          <canvas ref="canvasEl" class="block" />
          <div v-if="rendering" class="absolute inset-0 flex items-center justify-center bg-white/60">
            <Icon name="clock" :size="18" class="text-slate-300 animate-pulse" />
          </div>
        </div>

        <!-- ── Resume affordance (TASK-147, restyled TASK-158 rev.2) ───
             Visible, never a silent jump — that part is unchanged and is
             the whole reason this exists.
             What changed (human): it was a solid full-width bar in the
             document flow, ~60px tall, sitting between the header and the
             page and shrinking the page for something the learner dismisses
             in one tap. Now it FLOATS over the top of the page, translucent,
             at half the height — so a transient prompt costs zero layout and
             the page is the same size whether it is showing or not.

             Same fixed dark translucency as the pager, for the same reason:
             it sits on the PDF page, which is white under every tenant
             theme, so surface/ink tokens would be the wrong instrument.

             TAP TARGETS: the ~30px height is below the 44px minimum this app
             holds elsewhere (TASK-079 P3). Accepted deliberately and only
             here — the human asked for half height, both controls are
             OPTIONAL (ignoring the bar loses nothing; the learner is already
             on page 1 and can page normally), and the horizontal padding is
             widened to keep the actual hit area usable. -->
        <div
          v-if="showResumeBanner && !loading && !errorMessage"
          class="absolute inset-x-3 top-3 flex items-center gap-1.5 rounded-full bg-slate-900/55 backdrop-blur-sm shadow-lg pl-3 pr-1 py-1"
        >
          <Icon name="book" :size="13" class="text-white/70 shrink-0" />
          <p class="text-[11px] text-white/80 flex-1 min-w-0 truncate">อ่านค้างไว้ที่หน้า {{ resumeTargetPage }}</p>
          <button
            type="button"
            class="shrink-0 h-7 px-3 rounded-full bg-brand-600 text-ink-primary text-[11px] font-bold active:scale-95 transition"
            @click="acceptResume"
          >
            อ่านต่อหน้า {{ resumeTargetPage }}
          </button>
          <button
            type="button"
            class="shrink-0 h-7 px-2.5 rounded-full text-[11px] font-bold text-white/70 active:scale-95 transition"
            @click="showResumeBanner = false"
          >
            เริ่มใหม่
          </button>
        </div>

        <!-- ── READING MODE controls (TASK-158) ────────────────────────
             Floating over the page instead of taking a row of their own,
             translucent, and icon-only. That is the entire point: in this
             mode the chrome must cost as close to zero layout as possible,
             because the page is scaled to whatever box is left.

             Deliberately NOT surface/ink tokens (TASK-098/ADR-023). Those
             adapt to the tenant's theme, and this bar sits on top of the
             PDF page itself, which is always white regardless of theme —
             a light-themed tenant would get a white bar on white paper.
             Fixed dark translucency is the correct answer for a control
             layered over foreign content.

             `pointer-events-none` on the wrapper with it re-enabled on the
             bar: the dead space either side must not eat a swipe. -->
        <div v-if="expanded && !loading && !errorMessage" class="absolute inset-x-0 bottom-3 pointer-events-none">
          <!-- TASK-158 rev.2 (human) — THE ARROWS GO TO THE EDGES.
               They were three items in one centred pill, which put both of
               them near the middle of the screen: the two worst places for
               a thumb on a phone held one-handed, and the two places most
               likely to be over the part of the slide you are reading.
               Split to the edges, the reach is natural and the middle of
               the page is clear. The counter keeps the centre — it is a
               readout first and a toggle second, so it is the one that
               should not move under the thumb. -->
          <button
            type="button"
            class="pointer-events-auto absolute left-3 bottom-0 w-11 h-11 flex items-center justify-center rounded-full bg-slate-900/55 backdrop-blur-sm text-white/90 shadow-lg active:scale-90 transition disabled:opacity-30"
            :disabled="!canGoBack"
            aria-label="หน้าก่อนหน้า"
            @click="prevPage"
          >
            <Icon name="chevron_left" :size="18" />
          </button>

          <div class="flex justify-center">
            <!-- Still the toggle, and still the only way back to the header.
                 Labelled so it does not read as decoration. -->
            <button
              type="button"
              class="pointer-events-auto min-h-[44px] px-4 rounded-full bg-slate-900/55 backdrop-blur-sm shadow-lg text-[11px] font-bold text-white/90 tabular-nums active:scale-95 transition inline-flex items-center gap-1.5"
              title="ออกจากโหมดเต็มจอ"
              aria-label="ออกจากโหมดเต็มจอ"
              :aria-pressed="expanded"
              @click="toggleExpanded"
            >
              {{ currentPage }} / {{ totalPages }}
              <Icon name="minimize" :size="13" />
            </button>
          </div>

          <button
            type="button"
            class="pointer-events-auto absolute right-3 bottom-0 w-11 h-11 flex items-center justify-center rounded-full bg-slate-900/55 backdrop-blur-sm text-white/90 shadow-lg active:scale-90 transition disabled:opacity-30"
            :disabled="!canGoForward"
            aria-label="หน้าถัดไป"
            @click="nextPage"
          >
            <Icon name="chevron_right" :size="18" />
          </button>
        </div>
      </div>

      <!-- Pager — the whole point of TASK-157. 44px targets, and the arrow
           is disabled rather than hidden at the ends so the control does
           not jump around under the thumb. -->
      <div
        v-if="!expanded && !loading && !errorMessage && totalPages > 1"
        class="flex items-center justify-between gap-3 px-3 py-2.5 border-t border-line-card shrink-0"
      >
        <button
          type="button"
          class="min-h-[44px] px-4 rounded-lg bg-surface-chip text-ink-card text-xs font-bold inline-flex items-center gap-1.5 active:scale-95 transition disabled:opacity-40"
          :disabled="!canGoBack"
          aria-label="หน้าก่อนหน้า"
          @click="prevPage"
        >
          <Icon name="chevron_left" :size="16" />
          ก่อนหน้า
        </button>

        <!-- TASK-158 — the second entry point into reading mode, in the
             control the thumb is already resting near. -->
        <button
          type="button"
          class="min-h-[44px] px-3 rounded-lg text-xs font-bold text-ink-card-muted tabular-nums inline-flex items-center gap-1.5 hover:bg-surface-chip active:scale-95 transition"
          title="ขยายเต็มจอ"
          aria-label="ขยายเต็มจอ"
          :aria-pressed="expanded"
          @click="toggleExpanded"
        >
          {{ currentPage }} / {{ totalPages }}
          <Icon name="maximize" :size="13" />
        </button>

        <button
          type="button"
          class="min-h-[44px] px-4 rounded-lg bg-brand-600 text-ink-primary text-xs font-bold inline-flex items-center gap-1.5 hover:bg-brand-700 active:scale-95 transition disabled:opacity-40"
          :disabled="!canGoForward"
          aria-label="หน้าถัดไป"
          @click="nextPage"
        >
          ถัดไป
          <Icon name="chevron_right" :size="16" />
        </button>
      </div>
    </div>
  </div>
</template>
