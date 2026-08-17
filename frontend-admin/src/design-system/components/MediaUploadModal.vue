<script setup lang="ts">
/**
 * MediaUploadModal — drag-and-drop upload dialog with real per-file
 * progress (human reference: a clean "Drop your video here" dropzone +
 * an in-progress file row showing thumbnail/icon, filename, size, a
 * live progress bar, and a cancel button).
 *
 * Deliberately generic/reusable (not hard-coded to product media): the
 * caller supplies `uploadFn`, which must return an
 * `api.postFormWithProgress`-shaped `ProgressUpload` (see
 * @/api/client.ts) so this component never needs to know the target
 * endpoint or how to build each file's FormData (media_type/source_type
 * differ per feature — e.g. product media vs. spec attachments).
 *
 * Auto-uploads every file the moment it's dropped/selected (matches the
 * reference: the "Class meeting" row is already mid-upload, there's no
 * separate "start upload" step).
 */
import { reactive, ref, computed } from 'vue'
import Icon from './Icon.vue'

interface UploadFnResult {
  promise: Promise<unknown>
  abort: () => void
}

const props = defineProps<{
  title: string
  accept: string
  hint?: string
  uploadFn: (file: File, onProgress: (fraction: number) => void) => UploadFnResult
  /** Optional — when provided, the modal also renders an "add by link"
   * section (e.g. YouTube/Vimeo embed URLs). Add-by-link has no
   * byte-progress (it's a plain JSON POST, not a file upload), and
   * unlike files, any number of links can be added one after another
   * without re-opening the modal. */
  embedFn?: (url: string) => Promise<unknown>
  embedPlaceholder?: string
}>()

const emit = defineEmits<{
  close: []
  /** fired once per file/link that finishes successfully — caller reloads its list */
  uploaded: []
}>()

interface QueueItem {
  id: string
  file: File
  previewUrl: string | null
  progress: number // 0–1
  status: 'uploading' | 'done' | 'error'
  errorMessage: string
  abort: () => void
}

const queue = ref<QueueItem[]>([])
const isDragging = ref(false)
const fileInputRef = ref<HTMLInputElement | null>(null)

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function startUpload(file: File) {
  const id = `${file.name}-${file.size}-${Date.now()}-${Math.random().toString(36).slice(2)}`
  // reactive() (not a plain object) — pushing a raw object into a
  // reactive array and then mutating that SAME raw reference later (via
  // this closure) bypasses Vue's proxy entirely, so `trigger` never
  // fires and the template silently stops updating (bug: progress bar
  // stuck at 0% even though the upload was actually completing fine —
  // only the local blob preview, computed once at creation time, ever
  // showed). Wrapping with reactive() up front means every mutation
  // through this same reference IS the proxy mutation, so it updates
  // correctly wherever queue.value is read.
  const item: QueueItem = reactive({
    id,
    file,
    previewUrl: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
    progress: 0,
    status: 'uploading',
    errorMessage: '',
    abort: () => {},
  })
  queue.value.push(item)

  const { promise, abort } = props.uploadFn(file, (fraction) => {
    item.progress = fraction
  })
  item.abort = abort

  promise
    .then(() => {
      item.status = 'done'
      item.progress = 1
      emit('uploaded')
    })
    .catch((e) => {
      // An aborted upload (user clicked cancel) shouldn't show as an error row.
      if (queue.value.find((q) => q.id === id)) {
        item.status = 'error'
        item.errorMessage = e instanceof Error ? e.message : 'อัปโหลดไม่สำเร็จ'
      }
    })
}

function addFiles(fileList: FileList | null) {
  if (!fileList) return
  Array.from(fileList).forEach(startUpload)
}

function onDrop(e: DragEvent) {
  isDragging.value = false
  addFiles(e.dataTransfer?.files ?? null)
}

function onFileInputChange(e: Event) {
  addFiles((e.target as HTMLInputElement).files)
  ;(e.target as HTMLInputElement).value = ''
}

function openFilePicker() {
  fileInputRef.value?.click()
}

function cancelItem(item: QueueItem) {
  if (item.status === 'uploading') item.abort()
  if (item.previewUrl) URL.revokeObjectURL(item.previewUrl)
  queue.value = queue.value.filter((q) => q.id !== item.id)
}

const anyUploading = computed(() => queue.value.some((q) => q.status === 'uploading'))

// ── Add by link (optional — only rendered when embedFn is supplied) ──
interface EmbedItem {
  id: string
  url: string
}
const embedUrl = ref('')
const addingEmbed = ref(false)
const embedError = ref('')
const addedEmbeds = ref<EmbedItem[]>([])

async function addEmbed() {
  if (!embedUrl.value || !props.embedFn) return
  addingEmbed.value = true
  embedError.value = ''
  try {
    await props.embedFn(embedUrl.value)
    addedEmbeds.value.push({ id: `${Date.now()}-${Math.random().toString(36).slice(2)}`, url: embedUrl.value })
    embedUrl.value = ''
    emit('uploaded')
  } catch (e) {
    embedError.value = e instanceof Error ? e.message : 'เพิ่มลิงก์ไม่สำเร็จ — ตรวจสอบว่าเป็น URL ที่ถูกต้อง'
  } finally {
    addingEmbed.value = false
  }
}

function handleClose() {
  if (anyUploading.value) return
  queue.value.forEach((q) => q.previewUrl && URL.revokeObjectURL(q.previewUrl))
  emit('close')
}
</script>

<template>
  <div class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" @click.self="handleClose">
    <!-- Human request (2026-07-23): add/edit modals widened to 60% of the
         viewport, same pattern as AnnouncementsView. Shared upload modal —
         used by ProductEditView for adding media/sales materials. -->
    <div class="w-[60vw] min-w-[320px] max-w-[60vw] bg-white rounded-2xl shadow-2xl overflow-hidden">
      <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
        <h3 class="text-sm font-bold text-slate-800">{{ title }}</h3>
        <button
          class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30 disabled:cursor-not-allowed"
          :disabled="anyUploading"
          :title="anyUploading ? 'กำลังอัปโหลด รอสักครู่' : 'ปิด'"
          @click="handleClose"
        >
          <Icon name="x" :size="18" />
        </button>
      </div>

      <div class="p-5 space-y-4">
        <!-- Dropzone -->
        <div
          class="rounded-2xl border-2 border-dashed flex flex-col items-center justify-center text-center px-6 py-10 cursor-pointer transition-colors"
          :class="isDragging ? 'border-brand-500 bg-brand-50/60' : 'border-slate-200 bg-slate-50/60 hover:border-brand-300'"
          @click="openFilePicker"
          @dragover.prevent="isDragging = true"
          @dragleave.prevent="isDragging = false"
          @drop.prevent="onDrop"
        >
          <div class="w-14 h-14 rounded-full bg-brand-50 flex items-center justify-center mb-3">
            <Icon name="upload" :size="24" class="text-brand-600" />
          </div>
          <p class="text-sm font-bold text-slate-700">ลากไฟล์มาวางที่นี่</p>
          <p class="text-xs text-slate-400 mt-1">หรือคลิกเพื่อเลือกไฟล์ — เลือกได้หลายไฟล์พร้อมกัน</p>
          <p v-if="hint" class="text-[11px] text-slate-400 mt-3">{{ hint }}</p>
          <input ref="fileInputRef" type="file" :accept="accept" multiple class="hidden" @change="onFileInputChange" />
        </div>

        <!-- Upload queue -->
        <div v-if="queue.length" class="space-y-2 max-h-64 overflow-y-auto">
          <div v-for="item in queue" :key="item.id" class="flex items-center gap-3 p-2.5 rounded-xl border border-slate-100 bg-slate-50/60">
            <div class="shrink-0 w-10 h-10 rounded-lg overflow-hidden bg-white border border-slate-200 flex items-center justify-center">
              <img v-if="item.previewUrl" :src="item.previewUrl" class="w-full h-full object-cover" />
              <Icon v-else name="upload" :size="16" class="text-slate-400" />
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center justify-between gap-2">
                <p class="text-xs font-bold text-slate-700 truncate">{{ item.file.name }}</p>
                <span class="text-[10px] text-slate-400 shrink-0">{{ formatSize(item.file.size) }}</span>
              </div>
              <div class="mt-1.5 h-1.5 rounded-full bg-slate-200 overflow-hidden">
                <div
                  class="h-full rounded-full transition-all duration-150"
                  :class="item.status === 'error' ? 'bg-rose-500' : 'bg-brand-600'"
                  :style="{ width: `${Math.round(item.progress * 100)}%` }"
                ></div>
              </div>
              <p class="text-[10px] mt-1" :class="item.status === 'error' ? 'text-rose-500' : 'text-slate-400'">
                {{ item.status === 'error' ? item.errorMessage : item.status === 'done' ? 'เสร็จสิ้น' : `${Math.round(item.progress * 100)}%` }}
              </p>
            </div>
            <button class="shrink-0 text-slate-400 hover:text-rose-600" title="ยกเลิก" @click="cancelItem(item)">
              <Icon name="x" :size="14" />
            </button>
          </div>
        </div>

        <!-- Add by link — multiple, one after another -->
        <div v-if="embedFn" class="pt-1 border-t border-slate-100 space-y-2">
          <p class="text-xs font-bold text-slate-500 pt-3">หรือเพิ่มด้วยลิงก์</p>
          <div class="flex items-center gap-1.5">
            <input
              v-model="embedUrl"
              type="url"
              :placeholder="embedPlaceholder ?? 'วางลิงก์วิดีโอ (YouTube/Vimeo embed URL)'"
              class="flex-1 px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs"
              @keyup.enter="addEmbed"
            />
            <button
              class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-bold disabled:opacity-50 shrink-0"
              :disabled="addingEmbed || !embedUrl"
              @click="addEmbed"
            >
              {{ addingEmbed ? '...' : '+ เพิ่มลิงก์' }}
            </button>
          </div>
          <p v-if="embedError" class="text-[11px] font-bold text-rose-500">{{ embedError }}</p>
          <div v-if="addedEmbeds.length" class="space-y-1 max-h-32 overflow-y-auto">
            <div v-for="e in addedEmbeds" :key="e.id" class="flex items-center gap-2 px-2.5 py-1.5 rounded-lg bg-emerald-50 border border-emerald-100">
              <Icon name="check" :size="12" class="text-emerald-600 shrink-0" />
              <span class="text-[11px] text-emerald-700 truncate">{{ e.url }}</span>
            </div>
          </div>
        </div>
      </div>

      <div class="px-5 py-3.5 border-t border-slate-100 flex justify-end">
        <button
          class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-bold disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="anyUploading"
          @click="handleClose"
        >
          {{ anyUploading ? 'กำลังอัปโหลด...' : 'เสร็จสิ้น' }}
        </button>
      </div>
    </div>
  </div>
</template>
