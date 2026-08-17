<script setup lang="ts">
/**
 * MediaPreviewModal — unified click-to-preview lightbox with left/right
 * navigation across a list of items, replacing the previous per-gallery
 * behaviors (media gallery's plain "open in new tab" embed tiles;
 * spec-attachment gallery's separate image-only lightbox / PdfViewerModal /
 * new-tab embed). Human-requested 2026-07-19: clicking ANY tile in a grid
 * opens this modal, arrow-navigable across every item, larger content area,
 * type-aware rendering:
 *  - image   → large AuthenticatedMedia <img>
 *  - video   → AuthenticatedMedia <video> with controls
 *  - pdf     → in-app pdf.js multi-page render (same fetch+pdfjs approach
 *              as PdfViewerModal.vue, inlined here so it can re-render on
 *              index change without juggling two separate modals)
 *  - youtube → responsive iframe embed (youtube.com/embed/<id>)
 *  - embed   → generic external link (can't safely iframe an arbitrary
 *              cross-origin URL) — opens in a new tab instead
 *
 * Deliberately generic/reusable — the caller normalizes its own item
 * shape (ProductMediaItem / ProductSpecAttachmentItem / etc.) into
 * PreviewItem[] rather than this component knowing about any specific
 * domain model.
 */
import { computed, onBeforeUnmount, onMounted, onUnmounted, ref, watch } from 'vue'
import * as pdfjsLib from 'pdfjs-dist'
import Icon from './Icon.vue'
import AuthenticatedMedia from './AuthenticatedMedia.vue'
// The shared YouTube-URL normaliser (see its header: a `watch?v=` URL sets
// X-Frame-Options and renders as a dead grey box).
import { toEmbedUrl } from '@/utils/embedUrl'

pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).href

export interface PreviewItem {
  id: number | string
  kind: 'image' | 'video' | 'pdf' | 'youtube' | 'embed'
  streamUrl: string | null // authenticated stream (image/video/pdf)
  embedUrl: string | null // external URL (youtube/generic embed)
  label?: string
}

const props = defineProps<{
  items: PreviewItem[]
  index: number
}>()

const emit = defineEmits<{
  close: []
  'update:index': [number]
}>()

const current = computed<PreviewItem | null>(() => props.items[props.index] ?? null)

function go(delta: number) {
  const next = props.index + delta
  if (next < 0 || next >= props.items.length) return
  emit('update:index', next)
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'ArrowLeft') go(-1)
  else if (e.key === 'ArrowRight') go(1)
  else if (e.key === 'Escape') emit('close')
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))

// ── PDF render — same fetch (credentials:'include') + pdfjs-dist
// approach as PdfViewerModal.vue, inlined so pages re-render whenever
// the user arrows to a different pdf item. ──
function getCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(^|;\\s*)${name}=([^;]*)`))
  return match?.[2] ? decodeURIComponent(match[2]) : null
}
const pdfLoading = ref(false)
const pdfError = ref('')
const pdfPagesContainer = ref<HTMLDivElement | null>(null)
// Bug fix 2026-07-19: this used to append <canvas> elements straight to
// pdfPagesContainer.value inside the async render loop. That's an
// imperative DOM write racing Vue's own mount/patch cycle — the
// immediate:true watch below fires during this component's setup(),
// before the template (and therefore the ref) is bound, so an early or
// cached-fast fetch/pdfjs resolution could run appendChild() against a
// still-null ref and silently drop every page. Fixed the same way
// PdfViewerModal.vue always did it: collect canvases into a reactive
// array, then a SEPARATE watcher with flush:'post' does the actual
// DOM insertion only after Vue guarantees the ref is bound.
const pdfPageCanvases = ref<HTMLCanvasElement[]>([])
let renderToken = 0

async function loadPdf(url: string) {
  const myToken = ++renderToken
  pdfLoading.value = true
  pdfError.value = ''
  pdfPageCanvases.value = []

  try {
    const headers = new Headers()
    const xsrfToken = getCookie('XSRF-TOKEN')
    if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken)

    const res = await fetch(url, { method: 'GET', headers, credentials: 'include' })
    if (!res.ok) throw new Error(`Failed to load PDF (${res.status})`)
    const arrayBuffer = await res.arrayBuffer()
    if (myToken !== renderToken) return

    const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise
    if (myToken !== renderToken) return

    const canvases: HTMLCanvasElement[] = []
    for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
      if (myToken !== renderToken) return
      const page = await pdf.getPage(pageNum)
      const viewport = page.getViewport({ scale: 1.5 })
      const canvas = document.createElement('canvas')
      canvas.width = viewport.width
      canvas.height = viewport.height
      const context = canvas.getContext('2d')
      if (!context) continue
      await page.render({ canvas, canvasContext: context, viewport }).promise
      if (myToken !== renderToken) return
      canvas.className = 'shadow-sm rounded-lg max-w-full h-auto'
      canvases.push(canvas)
    }
    if (myToken !== renderToken) return
    pdfPageCanvases.value = canvases
  } catch {
    pdfError.value = 'โหลด PDF ไม่สำเร็จ'
  } finally {
    if (myToken === renderToken) pdfLoading.value = false
  }
}

watch(
  pdfPageCanvases,
  (canvases) => {
    const container = pdfPagesContainer.value
    if (!container) return
    container.innerHTML = ''
    canvases.forEach((canvas) => container.appendChild(canvas))
  },
  { flush: 'post' },
)

watch(
  current,
  (item) => {
    if (item?.kind === 'pdf' && item.streamUrl) loadPdf(item.streamUrl)
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  renderToken++ // cancel any in-flight render
})
</script>

<template>
  <div class="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4" style="font-family: Kanit, sans-serif" @click.self="emit('close')">
    <!-- Close -->
    <button
      class="absolute top-4 right-4 z-10 w-10 h-10 flex items-center justify-center rounded-full bg-black/50 text-white hover:bg-black/70 transition"
      title="ปิด (Esc)"
      @click="emit('close')"
    >
      <Icon name="x" :size="20" />
    </button>

    <!-- Counter -->
    <div v-if="items.length > 1" class="absolute top-4 left-4 z-10 px-3 py-1.5 rounded-full bg-black/50 text-white text-xs font-bold">
      {{ index + 1 }} / {{ items.length }}
    </div>

    <!-- Prev / Next -->
    <button
      v-if="items.length > 1"
      class="absolute left-2 sm:left-4 z-10 w-11 h-11 flex items-center justify-center rounded-full bg-black/50 text-white hover:bg-black/70 transition disabled:opacity-30 disabled:cursor-not-allowed"
      title="ก่อนหน้า"
      :disabled="index === 0"
      @click.stop="go(-1)"
    >
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="15 18 9 12 15 6" />
      </svg>
    </button>
    <button
      v-if="items.length > 1"
      class="absolute right-2 sm:right-4 z-10 w-11 h-11 flex items-center justify-center rounded-full bg-black/50 text-white hover:bg-black/70 transition disabled:opacity-30 disabled:cursor-not-allowed"
      title="ถัดไป"
      :disabled="index === items.length - 1"
      @click.stop="go(1)"
    >
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="9 18 15 12 9 6" />
      </svg>
    </button>

    <!-- Content -->
    <div class="w-full h-full max-w-5xl flex flex-col items-center justify-center" @click.stop>
      <template v-if="current">
        <AuthenticatedMedia v-if="current.kind === 'image'" :src="current.streamUrl" type="image" class="max-w-full max-h-[85vh] rounded-lg object-contain" />

        <AuthenticatedMedia v-else-if="current.kind === 'video'" :src="current.streamUrl" type="video" class="max-w-full max-h-[85vh] rounded-lg" />

        <div v-else-if="current.kind === 'youtube'" class="w-full max-w-3xl aspect-video">
          <iframe
            :src="toEmbedUrl(current.embedUrl)"
            class="w-full h-full rounded-lg"
            frameborder="0"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
            allowfullscreen
          ></iframe>
        </div>

        <div v-else-if="current.kind === 'pdf'" class="w-full h-full max-w-3xl bg-slate-100 rounded-2xl overflow-y-auto px-4 py-4">
          <div v-if="pdfLoading" class="h-full flex items-center justify-center text-slate-400 gap-2">
            <Icon name="clock" :size="20" class="animate-pulse" />
            <span class="text-sm font-bold">กำลังโหลด PDF...</span>
          </div>
          <div v-else-if="pdfError" class="h-full flex flex-col items-center justify-center text-rose-500 gap-2">
            <Icon name="alert" :size="24" />
            <span class="text-sm font-bold">{{ pdfError }}</span>
          </div>
          <div ref="pdfPagesContainer" class="flex flex-col items-center gap-4"></div>
        </div>

        <!-- Generic embed — can't safely iframe an arbitrary external URL cross-origin -->
        <a
          v-else
          :href="current.embedUrl ?? '#'"
          target="_blank"
          rel="noopener"
          class="flex flex-col items-center justify-center gap-3 text-white bg-black/40 rounded-2xl px-10 py-16 hover:bg-black/60 transition"
        >
          <Icon name="link" :size="32" />
          <span class="text-sm font-bold">เปิดลิงก์ในแท็บใหม่</span>
        </a>

        <p v-if="current.label" class="mt-3 text-center text-white/70 text-xs px-4 truncate max-w-full">{{ current.label }}</p>
      </template>
    </div>
  </div>
</template>
