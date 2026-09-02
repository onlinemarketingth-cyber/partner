<script setup lang="ts">
/**
 * LinkQrModal — the QR dialog shared by all three tabs of LinksHubView
 * (ภาพรวม / ลิงก์สมัครตัวแทน / ลิงก์ชวนทีม).
 *
 * ── WHY A MODAL AND NOT AN INLINE PANEL (2026-09-01, human decision) ──
 *
 * The three link screens became TABLES, and a QR big enough to scan from a
 * phone across a desk does not fit in a table row — sizing it to the row
 * would have made it SMALLER than the 96px inline panel it replaced, which
 * is the opposite of what was asked for. So the row carries only an icon,
 * and the real QR opens here at half the viewer's screen.
 *
 * ── WHY THE ROW SHOWS AN ICON, NOT A THUMBNAIL ──
 *
 * The old per-row generate-on-demand logic existed because a company with
 * dozens of printed signup links would otherwise mean dozens of canvas
 * renders nobody asked to see. A thumbnail in every row would have brought
 * that cost back at exactly the moment the table made rows cheap. An icon
 * costs nothing, and one QR is generated per click — never per row.
 *
 * The PNG is rendered at a fixed 1024px and scaled DOWN by CSS. Generating
 * it at the displayed pixel size would tie its sharpness to the browser
 * window and hand the admin a small, soft file to print from.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Icon from './Icon.vue'
import { generateQrDataUrl } from '@/utils/qrCode'
import { useI18n } from '@/composables/useI18n'

const props = withDefaults(
  defineProps<{
    /** The URL to encode. `null` closes the dialog — there is no separate `show`. */
    url: string | null
    /** Basename for the downloaded PNG, without the extension. */
    filename?: string
    /** One line under the QR saying what scanning it does. */
    caption?: string
  }>(),
  { filename: 'qr', caption: '' },
)

const emit = defineEmits<{ close: [] }>()

const { td } = useI18n()

const dataUrl = ref('')
const generating = ref(false)

watch(
  () => props.url,
  async (url) => {
    dataUrl.value = ''
    if (!url) return
    generating.value = true
    const generated = await generateQrDataUrl(url, 1024)
    // Guard against a second click landing while this await was in flight.
    if (props.url === url) {
      dataUrl.value = generated
      generating.value = false
    }
  },
  { immediate: true },
)

function download(): void {
  if (!dataUrl.value) return
  const a = document.createElement('a')
  a.href = dataUrl.value
  a.download = `${props.filename}.png`
  a.click()
}

function onKey(e: KeyboardEvent): void {
  if (e.key === 'Escape' && props.url) emit('close')
}
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div
      v-if="url"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4"
      @click.self="emit('close')"
    >
      <div class="bg-white rounded-2xl border border-slate-200 shadow-xl p-6 flex flex-col items-center max-w-full">
        <!-- 50% of the SHORTER viewport axis (human decision): a QR is square,
             so min() is what keeps it fully on screen in both orientations. -->
        <div
          class="rounded-xl border border-slate-200 bg-white p-3 flex items-center justify-center shrink-0"
          style="width: min(50vw, 50vh); height: min(50vw, 50vh); min-width: 200px; min-height: 200px"
        >
          <p v-if="generating" class="text-xs text-slate-400">{{ td('links.qr_generating') }}</p>
          <img v-else-if="dataUrl" :src="dataUrl" :alt="td('links.qr_alt')" class="w-full h-full object-contain" />
          <p v-else class="text-xs text-slate-400">{{ td('links.qr_failed') }}</p>
        </div>

        <p v-if="caption" class="mt-4 text-sm font-bold text-slate-700 text-center">{{ caption }}</p>
        <p class="mt-1 text-xs text-slate-400 break-all text-center max-w-md">{{ url }}</p>

        <div class="mt-5 flex items-center gap-2">
          <button
            type="button"
            data-test="download-qr"
            class="btn-primary inline-flex items-center gap-1.5"
            :disabled="!dataUrl"
            @click="download"
          >
            <Icon name="download" :size="14" />
            {{ td('links.qr_download') }}
          </button>
          <button type="button" class="btn-secondary" @click="emit('close')">{{ td('common.close') }}</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
