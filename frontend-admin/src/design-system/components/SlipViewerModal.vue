<script setup lang="ts">
/**
 * SlipViewerModal — look at a payment slip without leaving the screen.
 *
 * 2026-09-10 (human: "แก้ปุ่มดูสลิป ตอนนี้เป็นการ download เปลี่ยนเป็น Modal
 * ดูสลิป").
 *
 * ── WHY DOWNLOADING WAS THE WRONG VERB ──
 *
 * The button said ดูสลิป and did something else: it put a file in the
 * Downloads folder, where the admin then had to find it, open it in another
 * app, compare it against a row they could no longer see, and afterwards
 * delete it. Checking a slip is the single most common thing anyone does on
 * this screen, and every one of them left a file behind.
 *
 * ── THE FETCH IS NOT OPTIONAL ──
 *
 * `GET /orders/{id}/slip` is access-checked (OrderPolicy), so a plain
 * `<img src>` cannot show it — the browser would not send the session. The
 * bytes are fetched with credentials and turned into an object URL, and that
 * URL is revoked when the modal closes, because a slip left in memory is a
 * customer's bank record left in memory.
 *
 * Downloading is still offered, as a secondary action, for the times someone
 * genuinely needs the file.
 */
import { onUnmounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import Icon from './Icon.vue'

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
    error.value = e instanceof ApiError
      ? (e.status === 404 ? 'คำสั่งซื้อนี้ไม่มีสลิปแนบไว้' : `เปิดสลิปไม่สำเร็จ (${e.status})`)
      : 'เปิดสลิปไม่สำเร็จ'
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
  } catch {
    error.value = 'ดาวน์โหลดสลิปไม่สำเร็จ'
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="orderId !== null" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-slate-900/60" @click="emit('close')" />

      <div class="relative w-full max-w-lg max-h-[90vh] bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 shrink-0">
          <div class="min-w-0">
            <h2 class="text-sm font-bold text-slate-800">สลิปการโอนเงิน</h2>
            <p v-if="orderNumber" class="text-xs text-slate-400 truncate">{{ orderNumber }}</p>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <button
              v-if="objectUrl"
              type="button"
              data-test="download-slip"
              class="min-h-[36px] px-3 inline-flex items-center gap-1.5 rounded-lg border border-slate-300 text-xs font-bold text-slate-700 hover:bg-slate-50"
              @click="download"
            >
              <Icon name="download" :size="14" /> ดาวน์โหลด
            </button>
            <button
              type="button"
              class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700"
              @click="emit('close')"
            >
              <Icon name="x" :size="18" />
            </button>
          </div>
        </div>

        <div class="p-4 overflow-y-auto bg-slate-50 flex items-center justify-center min-h-[12rem]">
          <p v-if="loading" class="text-sm text-slate-400">กำลังโหลดสลิป...</p>
          <p v-else-if="error" data-test="slip-error" class="text-sm font-bold text-rose-600">{{ error }}</p>
          <img
            v-else-if="objectUrl && isImage"
            data-test="slip-image"
            :src="objectUrl"
            alt="สลิปการโอนเงิน"
            class="max-w-full max-h-[70vh] object-contain rounded-lg bg-white"
          />
          <!-- Not an image. Saying so beats a broken-image icon, which reads
               as "the slip is corrupt" rather than "this one is a PDF". -->
          <p v-else-if="objectUrl" class="text-sm text-slate-500 text-center">
            ไฟล์สลิปนี้ไม่ใช่รูปภาพ — กดดาวน์โหลดเพื่อเปิดดู
          </p>
        </div>
      </div>
    </div>
  </Teleport>
</template>
