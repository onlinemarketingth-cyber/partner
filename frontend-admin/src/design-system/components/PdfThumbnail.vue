<script setup lang="ts">
/**
 * PdfThumbnail — renders page 1 of a Sanctum-protected PDF as a small
 * canvas thumbnail, with its own loading/error state.
 *
 * Used wherever a PDF's server-generated thumbnail_path isn't ready yet
 * (GeneratePdfThumbnail job still pending/processing) but stream_url
 * already is — file_path is set synchronously at upload, only the
 * thumbnail job lags behind. Human-requested 2026-07-19: show a live
 * preview + loading state here too, instead of a static "กำลังประมวลผล..."
 * placeholder while waiting for the background job.
 *
 * Fetch + pdfjs-dist approach ported from MediaPreviewModal.vue's PDF
 * branch (which itself was fixed to match PdfViewerModal.vue's proven
 * pattern — collect into a ref, write to the DOM only once Vue
 * guarantees the ref is bound, avoiding a mount-timing race).
 */
import { onBeforeUnmount, ref, watch } from 'vue'
import * as pdfjsLib from 'pdfjs-dist'
import Icon from './Icon.vue'

pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).href

const props = defineProps<{
  streamUrl: string | null
}>()

function getCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(^|;\\s*)${name}=([^;]*)`))
  return match?.[2] ? decodeURIComponent(match[2]) : null
}

const loading = ref(true)
const error = ref(false)
const canvasEl = ref<HTMLCanvasElement | null>(null)
let renderToken = 0

async function render(url: string) {
  const myToken = ++renderToken
  loading.value = true
  error.value = false

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

    const page = await pdf.getPage(1)
    const unscaledViewport = page.getViewport({ scale: 1 })
    const scale = 240 / unscaledViewport.width
    const viewport = page.getViewport({ scale })

    // Wait for the next DOM patch so the canvas ref (behind v-show, not
    // v-if — see template) is guaranteed to already be mounted before
    // we touch it, same fix as MediaPreviewModal.vue's PDF render.
    await new Promise((resolve) => requestAnimationFrame(resolve))
    if (myToken !== renderToken || !canvasEl.value) return

    canvasEl.value.width = viewport.width
    canvasEl.value.height = viewport.height
    const context = canvasEl.value.getContext('2d')
    if (!context) throw new Error('Canvas context unavailable')
    await page.render({ canvas: canvasEl.value, canvasContext: context, viewport }).promise
    if (myToken !== renderToken) return
  } catch {
    error.value = true
  } finally {
    if (myToken === renderToken) loading.value = false
  }
}

watch(
  () => props.streamUrl,
  (url) => {
    if (url) render(url)
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  renderToken++
})
</script>

<template>
  <div class="relative w-full h-full flex items-center justify-center bg-slate-100">
    <canvas v-show="!loading && !error" ref="canvasEl" class="max-w-full max-h-full"></canvas>
    <div v-if="loading" class="absolute inset-0 flex items-center justify-center text-slate-300">
      <Icon name="clock" :size="20" class="animate-pulse" />
    </div>
    <div v-else-if="error" class="absolute inset-0 flex items-center justify-center text-slate-300">
      <Icon name="document" :size="20" />
    </div>
  </div>
</template>
