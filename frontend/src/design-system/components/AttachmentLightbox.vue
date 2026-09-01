<script setup lang="ts">
import { useI18n } from '@/composables/useI18n'
const { td } = useI18n()

/**
 * AttachmentLightbox — TASK-101.
 *
 * Human request (2026-08-04): "รายละเอียดเพิ่มเติม เป็นรูป PDF เป็น modal
 * แบบเลื่อนซ้ายขวาได้."
 *
 * A full-screen viewer for a product's attachments on the PUBLIC share
 * page, navigable left/right across the whole set.
 *
 * Why a new component rather than reusing the Admin app's
 * MediaPreviewModal: that one lives in `frontend-admin` and depends on
 * `pdfjs-dist` (~1MB) plus authenticated blob fetching. Neither applies
 * here — this page has no Sanctum session, every URL is already public
 * and absolute, and a prospect on mobile data should not download a PDF
 * engine to look at a brochure. PDFs render in a plain <iframe>, i.e. the
 * browser's own viewer.
 *
 * The "เปิดในแท็บใหม่" link is not decoration: iOS Safari and several
 * in-app browsers (LINE, Facebook) refuse to render a PDF inside an
 * iframe and show a blank box instead. That link is the escape hatch for
 * exactly the browsers a customer receiving a LINE link is most likely to
 * be using.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import Icon from './Icon.vue'
// The shared YouTube-URL normaliser. This component used to carry its own
// copy of the regex; it is now one of four call sites reading the same one.
import { toEmbedUrl } from '@/utils/embedUrl'

export interface LightboxItem {
  id: number | string
  kind: 'image' | 'pdf' | 'video' | 'link'
  url: string | null
  label: string
  /** TASK-103 — poster for the strip when the item is not an image (e.g. a YouTube link). */
  thumbUrl?: string | null
}

const props = defineProps<{
  open: boolean
  items: LightboxItem[]
  index: number
}>()

const emit = defineEmits<{
  close: []
  'update:index': [value: number]
}>()

const current = computed<LightboxItem | null>(() => props.items[props.index] ?? null)
const hasMultiple = computed(() => props.items.length > 1)

function go(step: number) {
  if (!hasMultiple.value) return
  // Wraps deliberately: on a short set, hitting a dead end at item 1 of 3
  // reads as a broken button rather than as "you are at the start".
  const next = (props.index + step + props.items.length) % props.items.length
  emit('update:index', next)
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') emit('close')
  else if (event.key === 'ArrowLeft') go(-1)
  else if (event.key === 'ArrowRight') go(1)
}

// Swipe. Tracked on the wrapper, not the media itself, so a swipe that
// starts on a PDF iframe still works — the iframe swallows its own
// pointer events, which is why the threshold is read from the wrapper.
const touchStartX = ref<number | null>(null)
const SWIPE_THRESHOLD = 50

function onTouchStart(event: TouchEvent) {
  touchStartX.value = event.changedTouches[0]?.clientX ?? null
}

function onTouchEnd(event: TouchEvent) {
  const start = touchStartX.value
  const end = event.changedTouches[0]?.clientX ?? null
  touchStartX.value = null
  if (start === null || end === null) return

  const delta = end - start
  if (Math.abs(delta) < SWIPE_THRESHOLD) return
  go(delta < 0 ? 1 : -1)
}

// Body-scroll lock + key listener, both bound only while open.
watch(
  () => props.open,
  (open) => {
    if (typeof document === 'undefined') return
    document.body.style.overflow = open ? 'hidden' : ''
    if (open) window.addEventListener('keydown', onKeydown)
    else window.removeEventListener('keydown', onKeydown)
  },
)

onBeforeUnmount(() => {
  if (typeof document !== 'undefined') document.body.style.overflow = ''
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="open && current"
        class="fixed inset-0 z-[1100] bg-black/90 flex flex-col"
        @touchstart.passive="onTouchStart"
        @touchend.passive="onTouchEnd"
      >
        <!-- Top bar: counter + close. Sits ABOVE the media rather than
             floating over it, so it never covers the top of a document. -->
        <div class="shrink-0 flex items-center gap-3 px-4 h-14 text-white">
          <span v-if="hasMultiple" class="text-sm font-bold tabular-nums">
            {{ index + 1 }} / {{ items.length }}
          </span>
          <span class="flex-1 min-w-0 truncate text-sm text-white/70">{{ current.label }}</span>
          <a
            v-if="current.url"
            :href="current.url"
            target="_blank"
            rel="noopener noreferrer"
            class="shrink-0 min-h-[44px] px-3 flex items-center text-xs font-bold text-white/80 hover:text-white"
          >
            {{ td('common.open_new_tab') }}
          </a>
          <button
            type="button"
            class="shrink-0 w-11 h-11 rounded-full flex items-center justify-center text-white/80 hover:text-white hover:bg-white/10 active:scale-90 transition"
            :aria-label="td('common.close2')"
            @click="emit('close')"
          >
            <Icon name="x" :size="20" />
          </button>
        </div>

        <!-- Stage -->
        <div class="relative flex-1 min-h-0 flex items-center justify-center px-2 pb-2">
          <img
            v-if="current.kind === 'image' && current.url"
            :src="current.url"
            :alt="current.label"
            class="max-w-full max-h-full object-contain"
          />
          <video
            v-else-if="current.kind === 'video' && current.url"
            :src="current.url"
            controls
            class="max-w-full max-h-full bg-black"
          ></video>
          <iframe
            v-else-if="current.url"
            :src="current.kind === 'link' ? toEmbedUrl(current.url) : current.url"
            class="w-full h-full bg-white rounded-lg"
            frameborder="0"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
            allowfullscreen
          ></iframe>
          <p v-else class="text-sm text-white/60">{{ td('file.cannot_open') }}</p>

          <!-- Arrows. 44px targets, and vertically centred on the stage
               rather than the viewport so they never collide with the
               top bar on a short screen. -->
          <button
            v-if="hasMultiple"
            type="button"
            class="absolute left-2 top-1/2 -translate-y-1/2 w-11 h-11 rounded-full bg-black/50 text-white flex items-center justify-center hover:bg-black/70 active:scale-90 transition"
            :aria-label="td('common.prev')"
            @click="go(-1)"
          >
            <Icon name="chevron_left" :size="22" />
          </button>
          <button
            v-if="hasMultiple"
            type="button"
            class="absolute right-2 top-1/2 -translate-y-1/2 w-11 h-11 rounded-full bg-black/50 text-white flex items-center justify-center hover:bg-black/70 active:scale-90 transition"
            :aria-label="td('common.next2')"
            @click="go(1)"
          >
            <Icon name="chevron_right" :size="22" />
          </button>
        </div>

        <!-- Thumbnail strip — the direct-jump path. Without it, reaching
             item 8 of 9 means eight taps. -->
        <div v-if="hasMultiple" class="shrink-0 flex gap-2 px-4 py-3 overflow-x-auto">
          <button
            v-for="(item, idx) in items"
            :key="item.id"
            type="button"
            class="shrink-0 w-12 h-12 rounded-lg overflow-hidden border-2 transition bg-white/10 flex items-center justify-center"
            :class="idx === index ? 'border-white' : 'border-transparent opacity-60 hover:opacity-100'"
            @click="emit('update:index', idx)"
          >
            <img
              v-if="item.kind === 'image' && item.url"
              :src="item.url"
              class="w-full h-full object-cover"
            />
            <img
              v-else-if="item.thumbUrl"
              :src="item.thumbUrl"
              class="w-full h-full object-cover"
            />
            <Icon v-else :name="item.kind === 'video' ? 'play' : item.kind === 'pdf' ? 'document' : 'link'" :size="16" class="text-white/80" />
          </button>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
