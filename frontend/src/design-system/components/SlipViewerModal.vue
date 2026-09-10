<script setup lang="ts">
/**
 * SlipViewerModal — look at a payment slip without leaving the screen.
 *
 * 2026-09-10 (human: "ดูสลิปยังเป็นแบบ Download แก้เป็นดูแบบ Modal").
 *
 * ── WHY DOWNLOADING WAS THE WRONG VERB ──
 *
 * The button said ดูสลิป and did something else: it put a file in Downloads,
 * where the agent then had to find it, open it in another app, compare it
 * against a card they could no longer see, and afterwards delete it. On a
 * phone — which is where this app is actually used — that is several taps
 * through a file manager, and the file stays on the device afterwards.
 *
 * ── THE FETCH IS NOT OPTIONAL ──
 *
 * `GET /orders/{id}/slip` is access-checked (OrderPolicy), so a plain
 * `<img src>` cannot show it — the browser would not send the token. The
 * bytes are fetched through the api client and turned into an object URL,
 * and that URL is revoked when the modal closes, because a slip left in
 * memory is a customer's bank record left in memory.
 *
 * Downloading is still offered as a secondary action, for the times someone
 * genuinely needs the file.
 *
 * Ported from the admin console's component of the same name. Two apps, two
 * design systems, one behaviour — the tokens differ, the rules do not.
 */
import { onUnmounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import { apiErrorMessage } from '@/utils/apiError'
import Icon from './Icon.vue'
import { useI18n } from '@/composables/useI18n'

const { td } = useI18n()

const props = defineProps<{
  orderId: number | null
  orderNumber?: string | null
}>()
const emit = defineEmits<{ (e: 'close'): void }>()

const objectUrl = ref<string | null>(null)
const isImage = ref(true)
const loading = ref(false)
const error = ref('')

function release(): void {
  if (objectUrl.value) {
    URL.revokeObjectURL(objectUrl.value)
    objectUrl.value = null
  }
}

async function load(id: number): Promise<void> {
  release()
  loading.value = true
  error.value = ''

  try {
    const blob = await api.getBlob(`/orders/${id}/slip`)
    // A slip is normally a photo, but nothing stops a future upload path
    // accepting a PDF — and an <img> pointed at one shows a broken icon
    // rather than saying so.
    isImage.value = blob.type.startsWith('image/')
    objectUrl.value = URL.createObjectURL(blob)
  } catch (e) {
    error.value = apiErrorMessage(e, 'เปิดสลิปไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

watch(
  () => props.orderId,
  (id) => {
    if (id === null) release()
    else void load(id)
  },
  { immediate: true },
)

onUnmounted(release)

async function download(): Promise<void> {
  if (props.orderId === null) return

  try {
    await api.download(`/orders/${props.orderId}/slip`, `slip-${props.orderNumber ?? props.orderId}.jpg`)
  } catch (e) {
    error.value = apiErrorMessage(e, 'ดาวน์โหลดสลิปไม่สำเร็จ')
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="orderId !== null" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-slate-900/70" @click="emit('close')" />

      <div class="relative w-full max-w-lg max-h-[90vh] bg-surface-card border border-line-card rounded-2xl shadow-xl flex flex-col overflow-hidden">
        <div class="flex items-center justify-between px-4 py-3 border-b border-line-card shrink-0">
          <div class="min-w-0">
            <h2 class="text-sm font-bold text-ink-card">{{ td('order.view_slip') }}</h2>
            <p v-if="orderNumber" class="text-xs text-ink-card-subtle truncate">{{ orderNumber }}</p>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <button
              v-if="objectUrl"
              type="button"
              data-test="download-slip"
              class="min-h-[44px] px-3 inline-flex items-center gap-1.5 rounded-lg border border-line-card text-xs font-bold text-ink-card hover:bg-surface-chip"
              @click="download"
            >
              <Icon name="download" :size="14" /> {{ td('common.download') }}
            </button>
            <button
              type="button"
              class="w-11 h-11 flex items-center justify-center rounded-lg text-ink-card-subtle hover:bg-surface-chip hover:text-ink-card"
              @click="emit('close')"
            >
              <Icon name="x" :size="18" />
            </button>
          </div>
        </div>

        <div class="p-4 overflow-y-auto flex items-center justify-center min-h-[12rem]">
          <p v-if="loading" class="text-sm text-ink-card-subtle">{{ td('common.loading') }}</p>
          <p v-else-if="error" data-test="slip-error" class="text-sm font-bold text-ink-danger text-center">{{ error }}</p>
          <img
            v-else-if="objectUrl && isImage"
            data-test="slip-image"
            :src="objectUrl"
            :alt="td('order.view_slip')"
            class="max-w-full max-h-[70vh] object-contain rounded-lg"
          />
          <!-- Not an image. Saying so beats a broken-image icon, which reads
               as "the slip is corrupt" rather than "this one is a PDF". -->
          <p v-else-if="objectUrl" class="text-sm text-ink-card-muted text-center">
            ไฟล์สลิปนี้ไม่ใช่รูปภาพ — กดดาวน์โหลดเพื่อเปิดดู
          </p>
        </div>
      </div>
    </div>
  </Teleport>
</template>
