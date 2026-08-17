<script setup lang="ts">
/**
 * PdfViewerModal (Admin copy) — in-app PDF viewer (ADR-008 Decision 4).
 *
 * ─────────────────────────────────────────────────────────────────────
 * KEEP IN SYNC (CI-001/CI-002, ADR-003)
 * A second copy lives at
 * `frontend/src/design-system/components/PdfViewerModal.vue`.
 * The two Vue apps duplicate `design-system/` on purpose (ADR-003 —
 * separate builds, no shared package yet), so a change to the SHARED
 * visual decisions here (chrome, spacing, colours, loading/error copy)
 * must be mirrored there.
 *
 * ⚠ THE TWO COPIES NOW DISAGREE ON PAGE NAVIGATION.
 * Human request (2026-08-09): "ให้คลิ๊กเป็นลูกศร ซ้าย ขวา แสดงทีละ 1 หน้า".
 * This copy pages one at a time; the Agent Portal copy still renders a
 * continuous scroll because its ADR-028 §2.4 furthest-page progress
 * reporting is built on an IntersectionObserver over stacked pages.
 * Moving the learner to paging is the RIGHT follow-up (a page index is a
 * cleaner progress signal than a scroll observer), but it changes how the
 * completion gate is measured, so it is a decision, not a copy-paste.
 * Until that happens the admin PREVIEW shows a different reading
 * experience from the learner's — flagged to the human, not hidden.
 * ─────────────────────────────────────────────────────────────────────
 *
 * Fetches the PDF the SAME way useAuthenticatedMedia.ts does (fetch +
 * credentials:'include', since `stream_url` comes back as a full
 * absolute URL straight from Laravel's route() helper) — but needs the
 * raw ArrayBuffer for pdfjs-dist, so it doesn't reuse the composable.
 * Every file stays behind the authenticated stream endpoint; no public
 * URL is ever produced (CLAUDE.md §5 rule 6).
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import * as pdfjsLib from 'pdfjs-dist'
import Icon from './Icon.vue'

// Vite worker-asset URL — the recommended pdfjs-dist v6+ setup (ships
// only an .mjs worker build; no legacy UMD worker anymore).
pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).href

const props = withDefaults(
  defineProps<{
    streamUrl: string
    title?: string
    /**
     * Render in the page flow instead of as a full-screen overlay.
     *
     * Human request (2026-08-09): "ให้แสดงผล PDF ทันทีในรูปที่ 1 ไม่ต้อง
     * คลิ๊กอีก 1 รอบ" — the lesson preview should show the document
     * itself, not a button that opens a second modal on top of the modal
     * the admin already opened.
     */
    inline?: boolean
  }>(),
  { inline: false },
)

const emit = defineEmits<{ close: [] }>()

function getCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(^|;\\s*)${name}=([^;]*)`))
  return match?.[2] ? decodeURIComponent(match[2]) : null
}

const loading = ref(true)
const error = ref('')
const pageCanvases = ref<HTMLCanvasElement[]>([])

/**
 * 1-based. Every page is still rendered up front (a spec sheet or a
 * lesson handout is a handful of pages, and rendering on demand would
 * put a visible stall on every arrow press); paging only controls which
 * canvas is in the DOM.
 */
const currentPage = ref(1)
const pageCount = computed(() => pageCanvases.value.length)
const canPrev = computed(() => currentPage.value > 1)
const canNext = computed(() => currentPage.value < pageCount.value)

function prevPage() {
  if (canPrev.value) currentPage.value--
}
function nextPage() {
  if (canNext.value) currentPage.value++
}

let renderToken = 0

async function loadAndRender(url: string) {
  const myToken = ++renderToken
  loading.value = true
  error.value = ''
  pageCanvases.value = []
  currentPage.value = 1

  try {
    const headers = new Headers()
    const xsrfToken = getCookie('XSRF-TOKEN')
    if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken)

    const res = await fetch(url, { method: 'GET', headers, credentials: 'include' })
    if (!res.ok) throw new Error(`Failed to load PDF (${res.status})`)
    const arrayBuffer = await res.arrayBuffer()
    if (myToken !== renderToken) return // superseded by a newer load

    const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise
    if (myToken !== renderToken) return

    const canvases: HTMLCanvasElement[] = []
    for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
      if (myToken !== renderToken) return
      const page = await pdf.getPage(pageNum)
      const viewport = page.getViewport({ scale: 1.35 })
      const canvas = document.createElement('canvas')
      canvas.width = viewport.width
      canvas.height = viewport.height
      const context = canvas.getContext('2d')
      if (!context) continue
      await page.render({ canvas, canvasContext: context, viewport }).promise
      canvases.push(canvas)
    }
    if (myToken !== renderToken) return
    pageCanvases.value = canvases
  } catch {
    error.value = 'โหลด PDF ไม่สำเร็จ'
  } finally {
    if (myToken === renderToken) loading.value = false
  }
}

// Mounts exactly one canvas — the current page. Re-runs on either the
// document changing or the page changing.
const pagesContainer = ref<HTMLDivElement | null>(null)
watch(
  [pageCanvases, currentPage, pagesContainer],
  () => {
    const container = pagesContainer.value
    if (!container) return
    container.innerHTML = ''
    const canvas = pageCanvases.value[currentPage.value - 1]
    if (!canvas) return
    canvas.className = 'shadow-sm rounded-lg max-w-full h-auto'
    container.appendChild(canvas)
  },
  { flush: 'post' },
)

watch(
  () => props.streamUrl,
  (url) => {
    if (url) loadAndRender(url)
  },
  { immediate: true },
)

// Arrow keys page the document. Bound to the window only in overlay mode:
// inline, the viewer is one element among many on the screen and hijacking
// the arrow keys would break scrolling the page around it.
function onKeydown(e: KeyboardEvent) {
  if (e.key === 'ArrowLeft') prevPage()
  else if (e.key === 'ArrowRight') nextPage()
}
onMounted(() => {
  if (!props.inline) window.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => {
  renderToken++ // cancel any in-flight render
  if (!props.inline) window.removeEventListener('keydown', onKeydown)
})

function onBackdropClick(event: MouseEvent) {
  if (event.target === event.currentTarget) emit('close')
}
</script>

<template>
  <component
    :is="inline ? 'div' : 'div'"
    :class="inline
      ? 'w-full'
      : 'fixed inset-0 z-50 bg-black/70 flex items-center justify-center p-4'"
    style="font-family: Kanit, sans-serif"
    @click="inline ? undefined : onBackdropClick($event)"
  >
    <div
      :class="inline
        ? 'w-full bg-white rounded-xl border border-slate-200 flex flex-col overflow-hidden'
        : 'relative w-full max-w-3xl h-full max-h-[90vh] bg-white rounded-2xl shadow-xl flex flex-col overflow-hidden'"
    >
      <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-slate-100 shrink-0">
        <p class="text-sm font-bold text-slate-900 truncate flex items-center gap-1.5 min-w-0">
          <Icon name="document" :size="16" class="text-slate-400 shrink-0" />
          {{ title || 'เอกสาร PDF' }}
        </p>
        <button
          v-if="!inline"
          class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition shrink-0"
          title="ปิด"
          @click="emit('close')"
        >
          <Icon name="x" :size="18" />
        </button>
      </div>

      <div :class="inline ? 'bg-slate-100 px-4 py-4' : 'flex-1 overflow-y-auto bg-slate-100 px-4 py-4'">
        <div v-if="loading" class="min-h-[240px] flex items-center justify-center text-slate-400 gap-2">
          <Icon name="clock" :size="20" class="animate-pulse" />
          <span class="text-sm font-bold">กำลังโหลด PDF...</span>
        </div>
        <div v-else-if="error" class="min-h-[240px] flex flex-col items-center justify-center text-rose-400 gap-2">
          <Icon name="alert" :size="24" />
          <span class="text-sm font-bold text-rose-600">{{ error }}</span>
        </div>
        <div v-else ref="pagesContainer" class="flex flex-col items-center"></div>
      </div>

      <!--
        Page controls. Rendered whenever the document loaded, even for a
        single-page PDF: hiding them there would make a 1-page file look
        like a broken multi-page one, and the counter is the only place
        that says how long the document is.
      -->
      <div
        v-if="!loading && !error && pageCount"
        class="flex items-center justify-center gap-3 px-4 py-2.5 border-t border-slate-100 bg-white shrink-0"
      >
        <button
          class="w-9 h-9 flex items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-default"
          :disabled="!canPrev"
          title="หน้าก่อนหน้า"
          @click="prevPage"
        >
          <Icon name="chevron_left" :size="16" />
        </button>
        <span class="text-xs font-bold text-slate-600 tabular-nums select-none">
          หน้า {{ currentPage }} / {{ pageCount }}
        </span>
        <button
          class="w-9 h-9 flex items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-default"
          :disabled="!canNext"
          title="หน้าถัดไป"
          @click="nextPage"
        >
          <Icon name="chevron_right" :size="16" />
        </button>
      </div>
    </div>
  </component>
</template>
