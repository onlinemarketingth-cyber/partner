<script setup lang="ts">
/**
 * MyLinksView — "ลิงก์ของฉัน" (TASK-234).
 *
 * ── WHY THIS PAGE EXISTS ──
 *
 * An agent's links were spread across four screens that have nothing else
 * to do with each other: a product share lives on สินค้า, a pay link on
 * คำสั่งซื้อ, a recruit link on ทีมของฉัน, an affiliate link on its own
 * page. Nobody could answer "which of my links is actually working?"
 * without visiting all four and holding the numbers in their head — and
 * three of the four did not show a number at all.
 *
 * ── THE NUMBER THAT DECIDES THE PAGE ──
 *
 * Rows are sorted by LAST OPENED, not by created date and not by clicks.
 * An agent opens this page to answer "is anything happening right now",
 * and the link somebody read a minute ago is the one worth acting on. A
 * created-date sort buries a live link under every link made since; a
 * clicks sort permanently freezes last month's winner at the top.
 */
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import Icon from '@/design-system/components/Icon.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'

/** TrackedLinkResource, field for field. */
interface TrackedLink {
  id: number
  group: string
  group_label: string
  code: string
  short_url: string
  label: string | null
  expires_at: string | null
  revoked_at: string | null
  is_usable: boolean
  click_count: number
  unique_click_count: number
  conversion_count: number
  /** NULL = nobody has opened it yet. NEVER shown as 0% — see below. */
  conversion_rate: number | null
  first_clicked_at: string | null
  last_clicked_at: string | null
  created_at: string
}

const links = ref<TrackedLink[]>([])
const loading = ref(false)
const errorMessage = ref('')
const activeGroup = ref<string>('all')
const copiedId = ref<number | null>(null)

const TABS = [
  { key: 'all', label: 'ทั้งหมด' },
  { key: 'product_share', label: 'แชร์สินค้า' },
  { key: 'payment', label: 'ชำระเงิน' },
  { key: 'team_signup', label: 'ชวนเข้าทีม' },
  { key: 'affiliate', label: 'พันธมิตร' },
] as const

const visible = computed(() =>
  activeGroup.value === 'all' ? links.value : links.value.filter((l) => l.group === activeGroup.value),
)

/**
 * The four summary tiles.
 *
 * "คนไม่ซ้ำ" is the headline rather than raw opens, because raw opens is
 * the number that flatters. An agent who shares one link to one group chat
 * and refreshes it themselves four times has four opens and one person,
 * and the first time they notice that they stop trusting the whole page.
 */
const totals = computed(() => ({
  active: links.value.filter((l) => l.is_usable).length,
  clicks: links.value.reduce((sum, l) => sum + l.click_count, 0),
  unique: links.value.reduce((sum, l) => sum + l.unique_click_count, 0),
  conversions: links.value.reduce((sum, l) => sum + l.conversion_count, 0),
}))

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: TrackedLink[] }>('/tracked-links')
    links.value = res.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดลิงก์ไม่สำเร็จ (${e.status})` : 'โหลดลิงก์ไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

async function copy(link: TrackedLink) {
  try {
    await navigator.clipboard.writeText(link.short_url)
    copiedId.value = link.id
    setTimeout(() => {
      if (copiedId.value === link.id) copiedId.value = null
    }, 2000)
  } catch {
    // Clipboard blocked (insecure context, or permission denied). The URL
    // is on screen and selectable, so a toast about something the agent
    // can still do by hand would be noise.
  }
}

// ── Campaign label ──────────────────────────────────────────────────────
const editingId = ref<number | null>(null)
const labelDraft = ref('')
const savingLabel = ref(false)

function startEditLabel(link: TrackedLink) {
  editingId.value = link.id
  labelDraft.value = link.label ?? ''
}

async function saveLabel(link: TrackedLink) {
  savingLabel.value = true
  try {
    await api.put(`/tracked-links/${link.id}`, { label: labelDraft.value.trim() || null })
    link.label = labelDraft.value.trim() || null
    editingId.value = null
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `บันทึกชื่อไม่สำเร็จ (${e.status})` : 'บันทึกชื่อไม่สำเร็จ'
  } finally {
    savingLabel.value = false
  }
}

// ── Labels ──────────────────────────────────────────────────────────────
function relative(iso: string | null): string {
  if (!iso) return 'ยังไม่มีคนเปิด'

  const diffMinutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000)
  if (diffMinutes < 1) return 'เมื่อสักครู่'
  if (diffMinutes < 60) return `${diffMinutes} นาทีที่แล้ว`
  if (diffMinutes < 60 * 24) return `${Math.round(diffMinutes / 60)} ชม.ที่แล้ว`

  return `${Math.round(diffMinutes / 1440)} วันที่แล้ว`
}

/**
 * "—" when nothing has been opened, never "0%".
 *
 * They are opposite messages. "0%" tells the agent this link is failing and
 * they should change something; "—" tells them nobody has looked yet and
 * the thing to change is where they shared it. The API already sends null
 * for exactly this reason; collapsing it here would undo that.
 */
function rateLabel(link: TrackedLink): string {
  return link.conversion_rate === null ? '—' : `${link.conversion_rate}%`
}

onMounted(load)
</script>

<template>
  <main class="px-4 py-6 max-w-3xl mx-auto">
    <header class="mb-4">
      <h1 class="text-lg font-bold text-ink-card">ลิงก์ของฉัน</h1>
      <p class="text-xs text-ink-card-subtle mt-1">
        ลิงก์ทุกอันที่คุณสร้างไว้ รวมอยู่ที่เดียว พร้อมจำนวนคนที่เปิดจริง
      </p>
    </header>

    <div v-if="errorMessage" class="mb-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-4">
      <div class="bg-surface-card border border-line-card rounded-xl px-3 py-2.5">
        <p class="text-[10px] font-bold text-ink-card-subtle">ลิงก์ที่ใช้งานได้</p>
        <p class="text-xl font-extrabold text-ink-card mt-0.5">{{ totals.active }}</p>
      </div>
      <div class="bg-surface-card border border-line-card rounded-xl px-3 py-2.5">
        <p class="text-[10px] font-bold text-ink-card-subtle">คนไม่ซ้ำ</p>
        <p class="text-xl font-extrabold text-ink-card mt-0.5">{{ totals.unique }}</p>
        <p class="text-[10px] text-ink-card-subtle">เปิดรวม {{ totals.clicks }} ครั้ง</p>
      </div>
      <div class="bg-surface-card border border-line-card rounded-xl px-3 py-2.5">
        <p class="text-[10px] font-bold text-ink-card-subtle">เกิดผลลัพธ์</p>
        <p class="text-xl font-extrabold text-ink-card mt-0.5">{{ totals.conversions }}</p>
      </div>
      <div class="bg-surface-card border border-line-card rounded-xl px-3 py-2.5">
        <p class="text-[10px] font-bold text-ink-card-subtle">อัตราแปลงรวม</p>
        <p class="text-xl font-extrabold text-ink-card mt-0.5">
          {{ totals.unique > 0 ? `${Math.round((totals.conversions / totals.unique) * 1000) / 10}%` : '—' }}
        </p>
      </div>
    </div>

    <div class="flex gap-1.5 overflow-x-auto pb-1 mb-3">
      <button
        v-for="tab in TABS"
        :key="tab.key"
        type="button"
        class="shrink-0 px-3 py-1.5 rounded-lg text-xs font-bold border"
        :class="activeGroup === tab.key
          ? 'bg-surface-chip text-ink-chip border-line-card'
          : 'bg-surface-card text-ink-card-subtle border-line-card'"
        @click="activeGroup = tab.key"
      >
        {{ tab.label }}
      </button>
    </div>

    <LoadingSkeleton v-if="loading && !links.length" type="list" :rows="3" />
    <EmptyState
      v-else-if="!visible.length"
      icon="link"
      title="ยังไม่มีลิงก์ในหมวดนี้"
      description="ลิงก์จะมาอยู่ที่นี่เองเมื่อคุณกดแชร์สินค้า สร้างคำสั่งซื้อ หรือสร้างลิงก์ชวนเข้าทีม"
    />
    <div v-else class="space-y-2">
      <div
        v-for="link in visible"
        :key="link.id"
        class="bg-surface-card border border-line-card rounded-xl p-3"
        :class="link.is_usable ? '' : 'opacity-60'"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-1.5 flex-wrap">
              <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-surface-chip text-ink-chip">
                {{ link.group_label }}
              </span>
              <span v-if="!link.is_usable" class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-slate-100 text-slate-500">
                ปิดแล้ว
              </span>
            </div>

            <div v-if="editingId === link.id" class="mt-1.5 flex items-center gap-1.5">
              <input
                v-model="labelDraft"
                type="text"
                placeholder="เช่น โพสต์กลุ่ม LINE 20 ส.ค."
                class="flex-1 px-2 py-1 rounded-lg border border-line-card text-xs bg-surface-card text-ink-card"
              />
              <button class="text-xs font-bold text-ink-brand" :disabled="savingLabel" @click="saveLabel(link)">
                บันทึก
              </button>
              <button class="text-xs text-ink-card-subtle" @click="editingId = null">ยกเลิก</button>
            </div>
            <button
              v-else
              type="button"
              class="mt-1 flex items-center gap-1 text-sm font-bold text-ink-card text-left"
              @click="startEditLabel(link)"
            >
              <span class="truncate">{{ link.label || 'ตั้งชื่อแคมเปญ' }}</span>
              <Icon name="pencil" :size="11" class="text-ink-card-subtle shrink-0" />
            </button>

            <button
              type="button"
              class="mt-1 flex items-center gap-1.5 text-xs font-bold text-ink-brand max-w-full"
              @click="copy(link)"
            >
              <span class="truncate">{{ link.short_url }}</span>
              <Icon :name="copiedId === link.id ? 'check' : 'copy'" :size="12" class="shrink-0" />
            </button>

            <p class="text-[11px] text-ink-card-subtle mt-1">เปิดล่าสุด {{ relative(link.last_clicked_at) }}</p>
          </div>

          <div class="flex gap-3 shrink-0 text-right">
            <div>
              <p class="text-base font-extrabold text-ink-card">{{ link.unique_click_count }}</p>
              <p class="text-[10px] font-bold text-ink-card-subtle">คน</p>
            </div>
            <div>
              <p class="text-base font-extrabold text-ink-card">{{ link.conversion_count }}</p>
              <p class="text-[10px] font-bold text-ink-card-subtle">สำเร็จ</p>
            </div>
            <div>
              <p class="text-base font-extrabold text-ink-card">{{ rateLabel(link) }}</p>
              <p class="text-[10px] font-bold text-ink-card-subtle">อัตรา</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
</template>
