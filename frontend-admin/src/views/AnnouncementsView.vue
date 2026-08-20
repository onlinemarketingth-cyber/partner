<script setup lang="ts">
/**
 * AnnouncementsView — "ข่าวสารถึง Agent" (newsfeed/announcements CRUD).
 *
 * Admin-side CRUD for announcements (backend already shipped +
 * route-registered; this is UI-only). Pinned items are always sorted
 * first in the list. Same Super-Admin-only nullable company_id field
 * rule as RewardCenterView.vue's reward items (null = platform-wide).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import BuddhistDateInput from '@/design-system/components/BuddhistDateInput.vue'
import { compressImageToFit } from '@/utils/imageCompression'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'

// Mirrors StoreAnnouncementRequest/UpdateAnnouncementRequest's
// 'image' => [...'max:5120'...] rule (5120 KB). Kept as a named
// constant here rather than re-deriving it from the 422 response, since
// the whole point is to compress BEFORE hitting the network — see
// human request 2026-07-23 "ให้ย่อไฟล์ให้เท่ากับที่เรากำหนดไว้ใน server".
const ANNOUNCEMENT_IMAGE_MAX_BYTES = 5 * 1024 * 1024
function formatMb(bytes: number): string {
  return (bytes / 1024 / 1024).toFixed(1)
}

function apiErrorMessage(e: unknown, fallback: string): string {
  if (!(e instanceof ApiError)) return fallback
  return e.message && e.message !== `API error ${e.status}` ? e.message : `${fallback} (${e.status})`
}
function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' })
}
// BuddhistDateInput only handles 'date' | 'datetime-local' ISO
// day-precision strings; published_at/expires_at are full datetimes
// server-side, so this screen uses the 'datetime-local' mode (matches
// how ReferralsView.vue already uses it for preferred_time).
function toDatetimeLocal(iso: string): string {
  if (!iso) return ''
  // Accepts either a bare "YYYY-MM-DDTHH:mm" (already local-shaped, e.g.
  // an in-progress form value) or a full ISO timestamp from the API.
  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(iso)) return iso
  const d = new Date(iso)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

const auth = useAuthStore()
// TASK-209 — the header company scope (ADR-038).
const activeCompany = useActiveCompanyStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

interface CompanyOption { id: number; name: string }
interface CertTierOption { id: number; key: string; name: string }
type Audience = 'all_agents' | 'cert_tier'
// Per TASK-042 §4 (BR-7 resolution): 'exact' = today's only behavior,
// 'and_above' compares cert_tiers.sort_order (agent's highest passed
// tier) >= target tier's sort_order. Backend defaults to 'exact' when
// omitted, so this mirrors that default client-side too.
type CertTierMode = 'exact' | 'and_above'
type VideoSourceType = 'upload' | 'embed'
// TASK-080 — mirrors App\Enums\AnnouncementBannerPage. Kept as a literal
// union (not a plain string[]) so a typo in the page picker below is a
// compile error here rather than a 422 the admin only sees at save time.
type BannerPage = 'home' | 'products' | 'announcements'
const BANNER_PAGE_OPTIONS: Array<{ value: BannerPage; label: string }> = [
  { value: 'home', label: 'หน้าหลัก' },
  { value: 'products', label: 'หน้าสินค้า' },
  { value: 'announcements', label: 'หน้าข่าวสาร' },
]
interface AnnouncementItem {
  id: number
  company_id: number | null
  title: string
  content: string
  audience: Audience
  target_cert_tier_id: number | null
  target_cert_tier_name: string | null
  target_cert_tier_mode: CertTierMode
  is_pinned: boolean
  // TASK-080 — display switches. Not exclusive: an announcement may be
  // both a popup modal and an inline banner. `banner_pages: null` means
  // "every page" (see the migration docblock) — an admin who turns the
  // banner on without picking pages still gets a visible banner.
  show_as_modal: boolean
  show_as_banner: boolean
  banner_pages: BannerPage[] | null
  published_at: string | null
  expires_at: string | null
  created_by: number
  created_by_name: string | null
  created_at: string
  // Human request (2026-07-23): "สามารถเพิ่มรูป และวิดีโอในประกาศได้"
  image_url: string | null
  video: { type: VideoSourceType; url: string } | null
}

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const announcements = ref<AnnouncementItem[]>([])
// Pinned items always first — everything else keeps the server's own
// order (most-recent-first, per Laravel's default index() ordering).
const sortedAnnouncements = computed(() => [...announcements.value].sort((a, b) => Number(b.is_pinned) - Number(a.is_pinned)))

async function loadAnnouncements() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: AnnouncementItem[] }>(activeCompany.scopedPath('/announcements'))
    announcements.value = res.data
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดข้อมูลไม่สำเร็จ')
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}

const companies = ref<CompanyOption[]>([])
const certTiers = ref<CertTierOption[]>([])
const lookupsLoaded = ref(false)
async function ensureLookupsLoaded() {
  if (lookupsLoaded.value) return
  try {
    const requests: Promise<unknown>[] = [api.get<{ data: CertTierOption[] }>('/cert-tiers')]
    // TASK-209 P4 — company list from the global store (see AgentRosterView).
    if (isSuperAdmin.value) requests.push(activeCompany.loadCompanies())
    const [ct] = await Promise.all(requests)
    certTiers.value = (ct as { data: CertTierOption[] }).data
    companies.value = activeCompany.companies
    lookupsLoaded.value = true
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดข้อมูลประกอบไม่สำเร็จ')
  }
}

// ── TASK-076 (2026-08-02, human request: "ระบบ banner ข่าวสารให้เปิด
// อย่างน้อย 4 ครั้ง ถึงไม่ขึ้น และสามารถกำหนดได้จาก admin") — how many
// times the Agent Portal's full-screen announcement modal auto-pops
// before it stops (BR-7: admin-editable, per-company override of the
// platform default). Same singleton show/update shape as
// video-processing-settings above.
// TASK-077 (2026-08-02, human-confirmed via AskUserQuestion) extends the
// same singleton with display_style — ONE global value per company, not
// per-announcement.
type AnnouncementDisplayStyle = 'full_screen' | 'bottom_sheet' | 'centered_card' | 'bottom_strip'
const DISPLAY_STYLE_OPTIONS: Array<{ value: AnnouncementDisplayStyle; label: string; hint: string }> = [
  { value: 'full_screen', label: 'เต็มจอจริง', hint: 'คลุมทั้งหน้าจอ ไม่มีพื้นหลังโปร่ง เห็นแอปด้านหลังเลย เน้นสุด' },
  { value: 'bottom_sheet', label: 'แผ่นเลื่อนจากด้านล่าง (ค่าเดิม)', hint: 'เลื่อนขึ้นจากด้านล่าง เห็นพื้นหลังโปร่งด้านบน' },
  { value: 'centered_card', label: 'การ์ดกึ่งกลางจอ', hint: 'การ์ดขนาดกะทัดรัดอยู่กึ่งกลางจอ ไม่บังทั้งหน้าจอ' },
  { value: 'bottom_strip', label: 'แถบเล็กด้านล่าง', hint: 'แถบเล็กค้างอยู่ด้านล่าง ไม่บล็อกการใช้งานส่วนอื่น แตะเพื่อดูรายละเอียดเต็ม หรือปัดปิดได้' },
]
const bannerSettingsForm = ref<{ repeat_count: number; display_style: AnnouncementDisplayStyle }>({
  repeat_count: 4,
  display_style: 'bottom_sheet',
})
const repeatCountCompanyId = ref<string | number>('') // Super Admin only — '' = platform default (read-only here)
const savingRepeatCount = ref(false)
const repeatCountError = ref('')
const repeatCountSaved = ref(false)

async function loadRepeatCount() {
  repeatCountError.value = ''
  try {
    const companyParam = isSuperAdmin.value && repeatCountCompanyId.value !== '' ? `?company_id=${repeatCountCompanyId.value}` : ''
    const res = await api.get<{ data: { repeat_count: number; display_style: AnnouncementDisplayStyle } }>(`/announcement-settings${companyParam}`)
    bannerSettingsForm.value.repeat_count = res.data.repeat_count
    bannerSettingsForm.value.display_style = res.data.display_style
  } catch (e) {
    repeatCountError.value = apiErrorMessage(e, 'โหลดการตั้งค่าไม่สำเร็จ')
  }
}
async function saveRepeatCount() {
  savingRepeatCount.value = true
  repeatCountError.value = ''
  repeatCountSaved.value = false
  try {
    const payload: Record<string, unknown> = {
      repeat_count: bannerSettingsForm.value.repeat_count,
      display_style: bannerSettingsForm.value.display_style,
    }
    if (isSuperAdmin.value && repeatCountCompanyId.value !== '') payload.company_id = Number(repeatCountCompanyId.value)
    await api.put('/announcement-settings', payload)
    repeatCountSaved.value = true
    setTimeout(() => {
      repeatCountSaved.value = false
    }, 2000)
  } catch (e) {
    repeatCountError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    savingRepeatCount.value = false
  }
}
watch(repeatCountCompanyId, () => {
  if (isSuperAdmin.value) loadRepeatCount()
})

// Human request (2026-08-02): "นำการตั้งค่า banner ไปใส่ที่ modal setting" —
// moved out of the always-visible page body into a modal opened from the
// gear icon next to "+ สร้างประกาศ".
const showSettingsModal = ref(false)
async function openSettingsModal() {
  showSettingsModal.value = true
  if (isSuperAdmin.value) await ensureLookupsLoaded()
}

onMounted(() => {
  loadAnnouncements()
  loadRepeatCount()
})

const showForm = ref(false)
const editingId = ref<number | null>(null)
const saving = ref(false)
const formError = ref('')
const form = ref({
  company_id: '' as string | number,
  title: '',
  content: '',
  audience: 'all_agents' as Audience,
  target_cert_tier_id: '' as string | number,
  target_cert_tier_mode: 'exact' as CertTierMode,
  is_pinned: false,
  // TASK-080 — same defaults as the DB columns (modal on, banner off), so
  // a newly-created announcement behaves identically whether the admin
  // touches these switches or not.
  show_as_modal: true,
  show_as_banner: false,
  banner_pages: [] as BannerPage[],
  published_at: '',
  expires_at: '',
})

// ── Image/video attachments (human request, 2026-07-23: "สามารถเพิ่มรูป
// และวิดีโอในประกาศได้") — kept as separate refs from `form` above rather
// than folded in, since these are Files/preview state, never JSON-
// serializable form values. video is upload-OR-embed (mirrors the
// backend's App\Enums\MediaSourceType mutual exclusion) — videoMode
// picks which of videoFile/videoEmbedUrl is the live input.
const imageFile = ref<File | null>(null)
const imagePreviewUrl = ref<string | null>(null) // local blob preview of a newly-picked file
const existingImageUrl = ref<string | null>(null) // already-saved image, when editing
const removeImage = ref(false)

const videoMode = ref<'' | VideoSourceType>('')
const videoFile = ref<File | null>(null)
const videoEmbedUrl = ref('')
const existingVideo = ref<{ type: VideoSourceType; url: string } | null>(null)
const removeVideo = ref(false)

// Human request (2026-07-23): "ให้ย่อไฟล์ให้เท่ากับที่เรากำหนดไว้ใน server
// ไม่ขึ้น error แบบนี้ หรือ ถ้าใหญ่เกินไปจริงๆ ให้เขียนแจ้งเตือนขนาดไฟล์ที่
// upload และขนาดที่ upload ได้" — images are auto-compressed client-side to
// fit (see compressImageToFit); video cannot be safely re-encoded in the
// browser (no ffmpeg.wasm in this stack — server-side VideoProcessingJob is
// the only compressor that exists), so oversized videos are instead
// rejected up front with a message stating both the file's actual size and
// the admin-configured limit, before ever attempting the upload.
const compressingImage = ref(false)
const imageSizeError = ref('')
const videoSizeError = ref('')
const videoMaxUploadMb = ref<number | null>(null)

async function loadVideoMaxUploadMb(): Promise<void> {
  try {
    const companyParam = isSuperAdmin.value && form.value.company_id !== '' ? `?company_id=${form.value.company_id}` : ''
    const res = await api.get<{ data: { max_upload_mb: number } }>(`/video-processing-settings${companyParam}`)
    videoMaxUploadMb.value = res.data.max_upload_mb
  } catch {
    videoMaxUploadMb.value = null // unknown limit — backend's own validation is still the real gatekeeper
  }
}
// Super Admin can change which company a platform post's video limit
// applies to mid-form (the company picker) — refetch so the shown/
// enforced limit always matches the company currently selected.
watch(
  () => form.value.company_id,
  () => {
    if (isSuperAdmin.value && showForm.value) loadVideoMaxUploadMb()
  },
)

function resetMediaState() {
  if (imagePreviewUrl.value) URL.revokeObjectURL(imagePreviewUrl.value)
  imageFile.value = null
  imagePreviewUrl.value = null
  existingImageUrl.value = null
  removeImage.value = false
  imageSizeError.value = ''
  compressingImage.value = false
  videoMode.value = ''
  videoFile.value = null
  videoEmbedUrl.value = ''
  existingVideo.value = null
  removeVideo.value = false
  videoSizeError.value = ''
}
async function onImageChange(e: Event): Promise<void> {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  imageSizeError.value = ''
  if (!file) return

  compressingImage.value = true
  try {
    const result = await compressImageToFit(file, ANNOUNCEMENT_IMAGE_MAX_BYTES)
    if (result.size > ANNOUNCEMENT_IMAGE_MAX_BYTES) {
      imageSizeError.value = `รูปภาพขนาด ${formatMb(result.size)} MB ใหญ่เกินไปแม้บีบอัดแล้ว (สูงสุด ${formatMb(ANNOUNCEMENT_IMAGE_MAX_BYTES)} MB) กรุณาเลือกรูปอื่นหรือครอปให้เล็กลง`
      input.value = ''
      return
    }
    if (imagePreviewUrl.value) URL.revokeObjectURL(imagePreviewUrl.value)
    imageFile.value = result
    imagePreviewUrl.value = URL.createObjectURL(result)
    removeImage.value = false
  } finally {
    compressingImage.value = false
  }
}
function clearImage(): void {
  if (imagePreviewUrl.value) URL.revokeObjectURL(imagePreviewUrl.value)
  imageFile.value = null
  imagePreviewUrl.value = null
  imageSizeError.value = ''
  if (existingImageUrl.value) removeImage.value = true
  existingImageUrl.value = null
}
function onVideoFileChange(e: Event): void {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  videoSizeError.value = ''
  if (file && videoMaxUploadMb.value) {
    const maxBytes = videoMaxUploadMb.value * 1024 * 1024
    if (file.size > maxBytes) {
      videoSizeError.value = `ไฟล์วิดีโอขนาด ${formatMb(file.size)} MB เกินขนาดสูงสุดที่อัปโหลดได้ (${videoMaxUploadMb.value} MB) กรุณาบีบอัดไฟล์ให้เล็กลงก่อน แล้วเลือกใหม่`
      input.value = ''
      videoFile.value = null
      return
    }
  }
  videoFile.value = file
  removeVideo.value = false
}
function clearVideo(): void {
  videoFile.value = null
  videoEmbedUrl.value = ''
  videoMode.value = ''
  videoSizeError.value = ''
  if (existingVideo.value) removeVideo.value = true
  existingVideo.value = null
}

watch(
  () => form.value.audience,
  (a) => {
    if (a !== 'cert_tier') {
      form.value.target_cert_tier_id = ''
      form.value.target_cert_tier_mode = 'exact'
    }
  },
)

function resetForm() {
  form.value = {
    // TASK-209 §5 — default to the header scope; '' (= ทั้งแพลตฟอร์ม) stays
    // selectable, because platform-wide is a business choice, not the
    // absence of one.
    company_id: activeCompany.companyId ?? '',
    title: '',
    content: '',
    audience: 'all_agents',
    target_cert_tier_id: '',
    target_cert_tier_mode: 'exact',
    is_pinned: false,
    show_as_modal: true,
    show_as_banner: false,
    banner_pages: [],
    published_at: '',
    expires_at: '',
  }
  editingId.value = null
  formError.value = ''
  resetMediaState()
}
async function openCreateForm() {
  resetForm()
  showForm.value = true
  await Promise.all([ensureLookupsLoaded(), loadVideoMaxUploadMb()])
}
async function openEditForm(item: AnnouncementItem) {
  editingId.value = item.id
  form.value = {
    company_id: item.company_id ?? '',
    title: item.title,
    content: item.content,
    audience: item.audience,
    target_cert_tier_id: item.target_cert_tier_id ?? '',
    target_cert_tier_mode: item.target_cert_tier_mode ?? 'exact',
    is_pinned: item.is_pinned,
    // Rows authored before TASK-080 have no value at all — fall back to the
    // same defaults the DB columns carry so editing an old announcement
    // never silently flips its behaviour.
    show_as_modal: item.show_as_modal ?? true,
    show_as_banner: item.show_as_banner ?? false,
    banner_pages: [...(item.banner_pages ?? [])],
    published_at: item.published_at ? toDatetimeLocal(item.published_at) : '',
    expires_at: item.expires_at ? toDatetimeLocal(item.expires_at) : '',
  }
  resetMediaState()
  existingImageUrl.value = item.image_url
  existingVideo.value = item.video
  if (item.video) {
    videoMode.value = item.video.type
    if (item.video.type === 'embed') videoEmbedUrl.value = item.video.url
  }
  formError.value = ''
  showForm.value = true
  await Promise.all([ensureLookupsLoaded(), loadVideoMaxUploadMb()])
}
function closeForm() {
  showForm.value = false
}

function validateForm(): string {
  if (!form.value.title.trim()) return 'กรุณาระบุหัวข้อประกาศ'
  if (!form.value.content.trim()) return 'กรุณาระบุเนื้อหาประกาศ'
  if (form.value.audience === 'cert_tier' && !form.value.target_cert_tier_id) return 'กรุณาเลือก Cert Tier เป้าหมาย'
  if (form.value.published_at && form.value.expires_at && form.value.expires_at <= form.value.published_at) {
    return 'วันหมดอายุต้องอยู่หลังวันเผยแพร่'
  }
  return ''
}

function validateMedia(): string {
  if (imageSizeError.value) return imageSizeError.value
  if (videoSizeError.value) return videoSizeError.value
  if (videoMode.value === 'upload' && !videoFile.value) return 'กรุณาเลือกไฟล์วิดีโอ'
  if (videoMode.value === 'embed' && !videoEmbedUrl.value.trim()) return 'กรุณาระบุลิงก์วิดีโอ'
  return ''
}

async function submitForm() {
  const validation = validateForm() || validateMedia()
  if (validation) {
    formError.value = validation
    return
  }
  saving.value = true
  formError.value = ''
  try {
    // Image/video attachments (2026-07-23): a NEW file (image or video)
    // can only travel as multipart/form-data — everything else in this
    // form stays plain JSON via api.put/api.post, matching every other
    // text-only field submission in this codebase. '_method=PUT' spoofs
    // the update verb through a POST, the same pattern already
    // established in AcademyManagementView.vue's lesson-video replace.
    const hasNewFile = !!imageFile.value || !!videoFile.value
    if (hasNewFile) {
      const fd = new FormData()
      if (editingId.value) fd.append('_method', 'PUT')
      if (isSuperAdmin.value && form.value.company_id !== '') fd.append('company_id', String(form.value.company_id))
      fd.append('title', form.value.title)
      fd.append('content', form.value.content)
      fd.append('audience', form.value.audience)
      if (form.value.audience === 'cert_tier') {
        fd.append('target_cert_tier_id', String(form.value.target_cert_tier_id))
        fd.append('target_cert_tier_mode', form.value.target_cert_tier_mode)
      }
      fd.append('is_pinned', form.value.is_pinned ? '1' : '0')
      // TASK-080 — booleans travel as '1'/'0' (multipart has no JSON
      // types; this is the same wire shape is_pinned above already uses,
      // which Laravel's 'boolean' rule accepts). An array has to be sent
      // as repeated 'banner_pages[]' entries — a single comma-joined
      // string would fail the 'array' rule. An empty selection therefore
      // sends no key at all, which the backend reads as "unchanged"; the
      // banner then falls back to its own "null = every page" default.
      fd.append('show_as_modal', form.value.show_as_modal ? '1' : '0')
      fd.append('show_as_banner', form.value.show_as_banner ? '1' : '0')
      form.value.banner_pages.forEach((page) => fd.append('banner_pages[]', page))
      if (form.value.published_at) fd.append('published_at', form.value.published_at)
      if (form.value.expires_at) fd.append('expires_at', form.value.expires_at)
      if (imageFile.value) fd.append('image', imageFile.value)
      else if (removeImage.value) fd.append('remove_image', '1')
      if (videoMode.value) fd.append('video_source_type', videoMode.value)
      if (videoFile.value) fd.append('video', videoFile.value)
      if (videoMode.value === 'embed') fd.append('video_embed_url', videoEmbedUrl.value.trim())
      if (!videoMode.value && removeVideo.value) fd.append('remove_video', '1')

      const path = editingId.value ? `/announcements/${editingId.value}` : '/announcements'
      await api.postForm(path, fd)
    } else {
      const payload = {
        ...(isSuperAdmin.value ? { company_id: form.value.company_id === '' ? null : Number(form.value.company_id) } : {}),
        title: form.value.title,
        content: form.value.content,
        audience: form.value.audience,
        target_cert_tier_id: form.value.audience === 'cert_tier' ? Number(form.value.target_cert_tier_id) : null,
        target_cert_tier_mode: form.value.audience === 'cert_tier' ? form.value.target_cert_tier_mode : null,
        is_pinned: form.value.is_pinned,
        // TASK-080 — an empty page selection is sent as null, not [], to
        // match the "null = every page" convention the banner renderer
        // reads (see the AnnouncementItem type above).
        show_as_modal: form.value.show_as_modal,
        show_as_banner: form.value.show_as_banner,
        banner_pages: form.value.banner_pages.length ? form.value.banner_pages : null,
        published_at: form.value.published_at || null,
        expires_at: form.value.expires_at || null,
        ...(removeImage.value ? { remove_image: true } : {}),
        ...(removeVideo.value ? { remove_video: true } : {}),
        ...(videoMode.value === 'embed'
          ? { video_source_type: 'embed', video_embed_url: videoEmbedUrl.value.trim() }
          : {}),
      }
      if (editingId.value) {
        await api.put(`/announcements/${editingId.value}`, payload)
      } else {
        await api.post('/announcements', payload)
      }
    }
    closeForm()
    await loadAnnouncements()
  } catch (e) {
    formError.value = apiErrorMessage(e, 'บันทึกไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

// TASK-066 (human-reported 2026-07-31) — native window.confirm() replaced
// with the ConfirmDialog modal.
const pendingDeleteAnnouncement = ref<AnnouncementItem | null>(null)
function deleteAnnouncement(item: AnnouncementItem) {
  pendingDeleteAnnouncement.value = item
}
async function confirmDeleteAnnouncement() {
  const item = pendingDeleteAnnouncement.value
  if (!item) return
  try {
    await api.delete(`/announcements/${item.id}`)
    announcements.value = announcements.value.filter((x) => x.id !== item.id)
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'ลบไม่สำเร็จ')
  } finally {
    pendingDeleteAnnouncement.value = null
  }
}

function audienceLabel(item: AnnouncementItem): string {
  if (item.audience !== 'cert_tier') return 'Agent ทั้งหมด'
  const tierName = item.target_cert_tier_name ?? '-'
  return `Cert Tier: ${tierName}${item.target_cert_tier_mode === 'and_above' ? ' ขึ้นไป' : ''}`
}
// TASK-080 — suffix for the Banner chip. Empty/absent banner_pages means
// "every page" (the migration's own convention), so it is spelled out
// rather than shown as a blank chip an admin would read as misconfigured.
function bannerPagesLabel(item: AnnouncementItem): string {
  const pages = item.banner_pages ?? []
  if (!pages.length) return ' · ทุกหน้า'
  const labels = pages.map((p) => BANNER_PAGE_OPTIONS.find((o) => o.value === p)?.label ?? p)

  return ` · ${labels.join(', ')}`
}
function contentPreview(content: string): string {
  return content.length > 140 ? content.slice(0, 140) + '…' : content
}

// TASK-209 — every list above is scoped server-side, so a change of the
// header company has to refetch; nothing here can be re-derived locally.
watch(() => activeCompany.companyId, () => { loadAnnouncements() })
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader icon="megaphone" title="ข่าวสารถึง Agent" subtitle="สร้างประกาศ — แสดงเป็น Banner Modal เต็มจอบน Agent Portal (Mobile) และในหน้ารวมข่าวสาร" accent-color="brand" storage-key="announcements">
      <template #actions>
        <!-- Human request (2026-08-02): "ใส่รูปเฟือง setting ที่ข้างปุ่ม
             สร้างประกาศ" — opens the Banner Settings modal below. -->
        <button
          type="button"
          class="w-10 h-10 rounded-xl border border-slate-200 bg-white text-slate-500 hover:text-brand-600 hover:border-brand-300 flex items-center justify-center shrink-0"
          title="ตั้งค่า Banner ข่าวสาร"
          @click="openSettingsModal"
        >
          <Icon name="settings" :size="18" />

    <CompanyScopeNotice action="จัดการประกาศ" />
        </button>
        <button
          class="btn-primary"
          @click="openCreateForm"
        >
          + สร้างประกาศ
        </button>
      </template>
    </HeroHeader>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />
    <template v-else>
      <EmptyState
        v-if="!announcements.length"
        icon="megaphone"
        title="ยังไม่มีประกาศ"
        message="สร้างข่าวสารหรือประกาศแรกถึง Agent"
        cta-label="+ สร้างประกาศแรก"
        :cta-disabled="false"
        class="mt-4"
        @cta="openCreateForm"
      />
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2 mt-4">
        <div v-for="item in sortedAnnouncements" :key="item.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="flex items-start gap-3 min-w-0">
              <!-- Image thumbnail (human request, 2026-07-23) takes the place
                   of the plain type icon when present; the icon still shows
                   otherwise, unchanged from before. -->
              <img v-if="item.image_url" :src="item.image_url" class="w-9 h-9 rounded-lg object-cover border border-slate-200 mt-0.5 shrink-0" />
              <Icon v-else :name="item.is_pinned ? 'pin' : 'megaphone'" :size="18" :class="item.is_pinned ? 'text-amber-600' : 'text-brand-600'" class="mt-0.5 shrink-0" />
              <div class="min-w-0">
                <p class="text-sm font-bold text-slate-900 truncate flex items-center gap-2 flex-wrap">
                  {{ item.title }}
                  <span v-if="item.is_pinned" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-50 text-amber-700">ปักหมุด</span>
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">{{ audienceLabel(item) }}</span>
                  <span v-if="isSuperAdmin && item.company_id === null" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">
                    ทั้งแพลตฟอร์ม
                  </span>
                  <span v-if="item.video" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 flex items-center gap-0.5">
                    <Icon name="play" :size="10" /> วิดีโอ
                  </span>
                  <!-- TASK-080 — at-a-glance display config. Rows authored
                       before TASK-080 report show_as_modal = true, so the
                       Modal chip stays on them exactly as they behave. -->
                  <span v-if="item.show_as_modal" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">Modal</span>
                  <span v-if="item.show_as_banner" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">
                    Banner{{ bannerPagesLabel(item) }}
                  </span>
                </p>
                <p class="text-xs text-slate-500 mt-1">{{ contentPreview(item.content) }}</p>
                <p class="text-xs text-slate-400 mt-1">
                  เผยแพร่ {{ item.published_at ? formatDate(item.published_at) : 'ทันที' }}
                  <span v-if="item.expires_at"> · หมดอายุ {{ formatDate(item.expires_at) }}</span>
                </p>
              </div>
            </div>
            <div class="flex gap-1 shrink-0">
              <button class="text-xs font-bold text-slate-500 hover:text-slate-700 px-2 py-1 flex items-center gap-1" @click="openEditForm(item)">
                <Icon name="edit" :size="14" /> แก้ไข
              </button>
              <button class="text-xs font-bold text-rose-600 hover:text-rose-700 px-2 py-1 flex items-center gap-1" @click="deleteAnnouncement(item)">
                <Icon name="trash" :size="14" /> ลบ
              </button>
            </div>
          </div>
        </div>
      </TransitionGroup>
    </template>

    <!-- ═══════════ Create/Edit modal ═══════════ -->
    <!-- Human request (2026-07-23): "เพิ่มพื้นที่ modal 60% ของหน้า" — widened
         from max-w-lg (~32rem) to 60% of the viewport width (min-width
         guard so it never gets uncomfortably narrow on small screens). -->
    <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="closeForm">
      <div class="w-[60vw] min-w-[320px] max-w-[60vw] bg-white rounded-2xl shadow-lg p-5 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm font-bold text-slate-900">{{ editingId ? 'แก้ไขประกาศ' : 'สร้างประกาศใหม่' }}</p>
          <button class="text-slate-400 hover:text-slate-600" @click="closeForm">
            <Icon name="x" :size="18" />
          </button>
        </div>
        <form class="space-y-3" @submit.prevent="submitForm">
          <div v-if="isSuperAdmin">
            <label class="text-sm font-bold text-slate-500">บริษัท (Super Admin — เว้นว่าง = ทั้งแพลตฟอร์ม)</label>
            <select v-model="form.company_id" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option value="">— ทั้งแพลตฟอร์ม —</option>
              <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div>
            <label class="text-sm font-bold text-slate-500">หัวข้อ</label>
            <input v-model="form.title" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div>
            <label class="text-sm font-bold text-slate-500">เนื้อหา</label>
            <textarea v-model="form.content" rows="4" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"></textarea>
          </div>
          <div>
            <label class="text-sm font-bold text-slate-500">กลุ่มเป้าหมาย</label>
            <select v-model="form.audience" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option value="all_agents">Agent ทั้งหมด</option>
              <option value="cert_tier">ตาม Cert Tier</option>
            </select>
          </div>
          <div v-if="form.audience === 'cert_tier'">
            <label class="text-sm font-bold text-slate-500">Cert Tier เป้าหมาย</label>
            <select v-model="form.target_cert_tier_id" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
              <option value="" disabled>เลือก Tier</option>
              <option v-for="ct in certTiers" :key="ct.id" :value="ct.id">{{ ct.name }}</option>
            </select>
            <div class="mt-2 inline-flex rounded-lg border border-slate-200 p-0.5 bg-slate-50">
              <button
                type="button"
                class="px-3 py-1 rounded-md text-xs font-bold transition-colors"
                :class="form.target_cert_tier_mode === 'exact' ? 'bg-brand-600 text-white' : 'text-slate-500 hover:text-slate-700'"
                @click="form.target_cert_tier_mode = 'exact'"
              >
                เฉพาะระดับนี้เท่านั้น
              </button>
              <button
                type="button"
                class="px-3 py-1 rounded-md text-xs font-bold transition-colors"
                :class="form.target_cert_tier_mode === 'and_above' ? 'bg-brand-600 text-white' : 'text-slate-500 hover:text-slate-700'"
                @click="form.target_cert_tier_mode = 'and_above'"
              >
                ระดับนี้ขึ้นไป
              </button>
            </div>
          </div>

          <!-- ═══ รูปภาพ + วิดีโอ (human request, 2026-07-23) ═══ -->
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-sm font-bold text-slate-500">รูปภาพ (ไม่บังคับ)</label>
              <p class="text-[11px] text-slate-400 mt-0.5">สูงสุด {{ formatMb(ANNOUNCEMENT_IMAGE_MAX_BYTES) }} MB — ระบบจะย่อขนาดให้อัตโนมัติถ้าเกิน</p>
              <div v-if="compressingImage" class="mt-1 flex items-center justify-center gap-1.5 h-24 w-24 rounded-lg border border-dashed border-slate-300 text-slate-400 text-xs font-bold">
                <Icon name="refresh" :size="16" class="animate-spin" /> กำลังบีบอัด...
              </div>
              <div v-else-if="imagePreviewUrl || existingImageUrl" class="mt-1 relative w-fit">
                <img :src="imagePreviewUrl || existingImageUrl!" class="h-24 w-24 object-cover rounded-lg border border-slate-200" />
                <button
                  type="button"
                  class="absolute -top-2 -right-2 w-5 h-5 rounded-full bg-rose-600 text-white flex items-center justify-center hover:bg-rose-700"
                  @click="clearImage"
                >
                  <Icon name="x" :size="12" />
                </button>
              </div>
              <label v-else class="mt-1 flex items-center justify-center gap-1.5 h-24 w-24 rounded-lg border border-dashed border-slate-300 text-slate-400 hover:text-slate-600 hover:border-slate-400 cursor-pointer text-xs font-bold">
                <Icon name="image" :size="18" />
                <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onImageChange" />
              </label>
              <p v-if="imageSizeError" class="text-[11px] text-rose-600 mt-1">{{ imageSizeError }}</p>
            </div>

            <div>
              <label class="text-sm font-bold text-slate-500">วิดีโอ (ไม่บังคับ)</label>
              <p v-if="videoMaxUploadMb" class="text-[11px] text-slate-400 mt-0.5">อัปโหลดไฟล์ได้สูงสุด {{ videoMaxUploadMb }} MB</p>
              <div class="mt-1 inline-flex rounded-lg border border-slate-200 p-0.5 bg-slate-50 mb-2">
                <button
                  type="button"
                  class="px-3 py-1 rounded-md text-xs font-bold transition-colors"
                  :class="videoMode === 'upload' ? 'bg-brand-600 text-white' : 'text-slate-500 hover:text-slate-700'"
                  @click="videoMode = videoMode === 'upload' ? '' : 'upload'"
                >
                  อัปโหลดไฟล์
                </button>
                <button
                  type="button"
                  class="px-3 py-1 rounded-md text-xs font-bold transition-colors"
                  :class="videoMode === 'embed' ? 'bg-brand-600 text-white' : 'text-slate-500 hover:text-slate-700'"
                  @click="videoMode = videoMode === 'embed' ? '' : 'embed'"
                >
                  ลิงก์ (YouTube ฯลฯ)
                </button>
              </div>

              <!-- upload mode, existing uploaded video, no new file picked yet: show
                   the "already has a video" state with replace/remove, rather than
                   a misleadingly-empty file picker. -->
              <div v-if="videoMode === 'upload' && existingVideo?.type === 'upload' && !videoFile" class="flex items-center gap-2 text-xs text-slate-500">
                <Icon name="play" :size="14" class="text-brand-600 shrink-0" />
                <span class="truncate">มีวิดีโออัปโหลดอยู่แล้ว</span>
                <label class="text-brand-600 font-bold hover:text-brand-700 cursor-pointer whitespace-nowrap">
                  เปลี่ยนไฟล์
                  <input type="file" accept="video/mp4,video/quicktime,video/webm" class="hidden" @change="onVideoFileChange" />
                </label>
                <button type="button" class="text-rose-600 font-bold hover:text-rose-700 whitespace-nowrap" @click="clearVideo">ลบ</button>
              </div>
              <div v-else-if="videoMode === 'upload'" class="flex items-center gap-2">
                <label class="flex items-center gap-1.5 px-3 py-2 rounded-lg border border-dashed border-slate-300 text-slate-500 hover:text-slate-700 hover:border-slate-400 cursor-pointer text-xs font-bold">
                  <Icon name="upload" :size="14" />
                  {{ videoFile ? videoFile.name : 'เลือกไฟล์วิดีโอ' }}
                  <input type="file" accept="video/mp4,video/quicktime,video/webm" class="hidden" @change="onVideoFileChange" />
                </label>
              </div>
              <div v-else-if="videoMode === 'embed'" class="flex items-center gap-2">
                <input
                  v-model="videoEmbedUrl"
                  placeholder="https://youtube.com/watch?v=..."
                  class="flex-1 px-3 py-2 rounded-lg border border-slate-200 text-sm"
                />
                <button v-if="existingVideo" type="button" class="text-rose-600 font-bold hover:text-rose-700 text-xs whitespace-nowrap" @click="clearVideo">ลบ</button>
              </div>
              <p v-if="videoSizeError" class="text-[11px] text-rose-600 mt-1">{{ videoSizeError }}</p>
            </div>
          </div>

          <label class="flex items-center gap-2 text-sm font-bold text-slate-500">
            <input v-model="form.is_pinned" type="checkbox" />
            ปักหมุดไว้บนสุด
          </label>

          <!-- ═══ TASK-080: รูปแบบการแสดงผลบน Agent Portal ═══
               Human request (2026-08-03): "ผมอยากได้ระบบข่าวสาร สามารถแสดง
               เป็นแบบ banner ได้แบบ Product" — modal และ banner ไม่ผูกกัน
               เลือกอย่างใดอย่างหนึ่ง ทั้งสองอย่าง หรือไม่เลือกเลยก็ได้ -->
          <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
            <p class="text-sm font-bold text-slate-700">การแสดงผลบน Agent Portal</p>
            <label class="mt-2 flex items-center gap-2 text-sm font-bold text-slate-500">
              <input v-model="form.show_as_modal" type="checkbox" />
              เด้งเป็น Modal
            </label>
            <p class="text-[11px] text-slate-400 ml-6">เด้งขึ้นอัตโนมัติเมื่อ agent เปิดแอป (จำนวนครั้งและรูปแบบตั้งได้ที่ปุ่มเฟือง)</p>

            <label class="mt-2 flex items-center gap-2 text-sm font-bold text-slate-500">
              <input v-model="form.show_as_banner" type="checkbox" />
              แสดงเป็น Banner
            </label>
            <p class="text-[11px] text-slate-400 ml-6">
              Banner จะใช้ “รูปภาพ” ของประกาศนี้เป็นภาพแสดง — ถ้าไม่เลือกหน้าใดเลย จะแสดงทุกหน้า
            </p>

            <!-- Hidden, not cleared, when the banner is off — an admin who
                 toggles the switch twice must get their page selection back. -->
            <div v-if="form.show_as_banner" class="mt-2 ml-6 flex flex-wrap gap-x-4 gap-y-1.5">
              <label v-for="opt in BANNER_PAGE_OPTIONS" :key="opt.value" class="flex items-center gap-2 text-sm font-bold text-slate-500">
                <input v-model="form.banner_pages" type="checkbox" :value="opt.value" />
                {{ opt.label }}
              </label>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-sm font-bold text-slate-500">เผยแพร่เมื่อ (ว่าง = ทันที, คีย์วันที่เป็น พ.ศ.)</label>
              <div class="mt-1">
                <BuddhistDateInput v-model="form.published_at" type="datetime-local" />
              </div>
            </div>
            <div>
              <label class="text-sm font-bold text-slate-500">หมดอายุเมื่อ (ไม่บังคับ)</label>
              <div class="mt-1">
                <BuddhistDateInput v-model="form.expires_at" type="datetime-local" />
              </div>
            </div>
          </div>
          <div v-if="formError" class="px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-xs text-rose-700">{{ formError }}</div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" @click="closeForm">ยกเลิก</button>
            <button type="submit" :disabled="saving" class="btn-primary">
              {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- ═══════════ Banner Settings modal (TASK-076) ═══════════
         Human request (2026-08-02): "นำการตั้งค่า banner ไปใส่ที่ modal
         setting" — moved out of the page body into its own modal, opened
         via the gear icon in HeroHeader's actions slot. -->
    <div v-if="showSettingsModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="showSettingsModal = false">
      <div class="w-full max-w-md bg-white rounded-2xl shadow-lg p-5 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm font-bold text-slate-900 flex items-center gap-1.5">
            <Icon name="settings" :size="16" class="text-slate-400" /> ตั้งค่า Banner ข่าวสาร
          </p>
          <button class="text-slate-400 hover:text-slate-600" @click="showSettingsModal = false">
            <Icon name="x" :size="18" />
          </button>
        </div>

        <div v-if="isSuperAdmin" class="mb-3">
          <label class="text-xs font-bold text-slate-500">บริษัท</label>
          <select v-model="repeatCountCompanyId" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
            <option value="">— ทั้งแพลตฟอร์ม (ค่าเริ่มต้น) —</option>
            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>

        <div>
          <p class="text-sm font-bold text-slate-700">Modal เต็มจอบน Mobile</p>
          <p class="text-xs text-slate-400 mt-0.5">
            จำนวนครั้งสูงสุดที่ประกาศแต่ละรายการจะเด้งขึ้นอัตโนมัติให้ agent เห็น ก่อนที่จะไม่ขึ้นอีก
          </p>
          <div class="mt-2 flex items-center gap-2 flex-wrap">
            <input
              v-model.number="bannerSettingsForm.repeat_count"
              type="number"
              min="1"
              max="50"
              class="w-24 px-3 py-2 rounded-lg border border-slate-200 text-sm"
              :disabled="isSuperAdmin && repeatCountCompanyId === ''"
            />
            <span class="text-xs text-slate-500">ครั้ง</span>
          </div>
          <p v-if="isSuperAdmin && repeatCountCompanyId === ''" class="text-[11px] text-amber-600 mt-1">
            เลือกบริษัทก่อนจึงจะบันทึกค่าเฉพาะบริษัทได้ (ค่าที่แสดงตอนนี้คือค่าเริ่มต้นของแพลตฟอร์ม อ่านได้อย่างเดียว)
          </p>
        </div>

        <!-- TASK-077 (2026-08-02, human-confirmed via AskUserQuestion) —
             รูปแบบการแสดง banner: ตั้งค่ากลางค่าเดียวต่อบริษัท ใช้ร่วมกับ
             repeat_count ด้านบน ไม่ใช่ต่อประกาศ -->
        <div class="mt-4 pt-4 border-t border-slate-100">
          <p class="text-sm font-bold text-slate-700">รูปแบบการแสดง Banner</p>
          <p class="text-xs text-slate-400 mt-0.5">เลือกลักษณะการแสดงผลของ modal ประกาศบน Agent Portal</p>
          <div class="mt-2 space-y-2">
            <label
              v-for="opt in DISPLAY_STYLE_OPTIONS"
              :key="opt.value"
              class="flex items-start gap-2 p-2.5 rounded-lg border cursor-pointer transition-colors"
              :class="bannerSettingsForm.display_style === opt.value ? 'border-brand-600 bg-brand-50' : 'border-slate-200 hover:border-slate-300'"
            >
              <input
                v-model="bannerSettingsForm.display_style"
                type="radio"
                name="display_style"
                :value="opt.value"
                class="mt-0.5"
                :disabled="isSuperAdmin && repeatCountCompanyId === ''"
              />
              <span>
                <span class="block text-sm font-bold text-slate-700">{{ opt.label }}</span>
                <span class="block text-xs text-slate-400 mt-0.5">{{ opt.hint }}</span>
              </span>
            </label>
          </div>
        </div>

        <p v-if="repeatCountError" class="text-[11px] text-rose-600 mt-3">{{ repeatCountError }}</p>
        <div class="flex justify-end items-center gap-2 pt-4">
          <span v-if="repeatCountSaved" class="text-xs font-bold text-emerald-600">บันทึกแล้ว</span>
          <button type="button" class="btn-secondary" @click="showSettingsModal = false">ปิด</button>
          <button
            type="button"
            :disabled="savingRepeatCount || (isSuperAdmin && repeatCountCompanyId === '')"
            class="btn-primary"
            @click="saveRepeatCount"
          >
            {{ savingRepeatCount ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
        </div>
      </div>
    </div>

    <!-- TASK-066 — replaces native window.confirm(). Bug fix (2026-08-01,
         human-reported: sub-menu nav needed a hard refresh to render) —
         this was a SIBLING of <main>, making the template a multi-root
         Fragment, which breaks App.vue's <Transition mode="out-in"> around
         <RouterView> (see AgentManagementView.vue's identical fix for the
         full explanation). Moved inside <main>. -->
    <ConfirmDialog
      :show="pendingDeleteAnnouncement !== null"
      variant="danger"
      :body='pendingDeleteAnnouncement ? `ยืนยันลบประกาศ "${pendingDeleteAnnouncement.title}"?` : ""'
      @confirm="confirmDeleteAnnouncement"
      @update:show="(v) => { if (!v) pendingDeleteAnnouncement = null }"
    />
  </main>
</template>
