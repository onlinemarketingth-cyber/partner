<script setup lang="ts">
/**
 * 2026-10-02 — tells a Super Admin when the platform's scheduled jobs have
 * stopped running (cron on the host). Owner: "เพิ่ม".
 *
 * Nine jobs hang off `schedule:run` — rank recalculation, renewal
 * commissions, agent notification emails, follow-up reminders, binary
 * matching, promotion credits, supplier auto-receipt and two clean-ups — and
 * none of them errors when cron is dead. They just never happen, and the
 * first sign used to be a commission that should have changed and did not.
 *
 * Reads GET /platform/scheduler-health (SchedulerHeartbeatService). Shown to
 * Super Admins only, because the endpoint is theirs and only they can fix a
 * cron entry; everyone else never sends the request. Re-checked every few
 * minutes so a fix made in hPanel turns the banner off without a reload.
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/api/client'
import Icon from '@/design-system/components/Icon.vue'
import InfoPopover from '@/design-system/components/InfoPopover.vue'
import { useAuthStore } from '@/stores/auth'

interface SchedulerHealth {
  last_run_at: string | null
  minutes_since_last_run: number | null
  is_running: boolean
  stale_after_minutes: number
}

/** How often the open console re-asks. Five minutes: cheap, and quick enough after a fix. */
const RECHECK_MS = 5 * 60 * 1000

const auth = useAuthStore()
const route = useRoute()

const health = ref<SchedulerHealth | null>(null)
let timer: number | null = null

const isSuperAdmin = computed(() => auth.isAuthenticated && auth.user?.role === 'super_admin')

const visible = computed(() =>
  isSuperAdmin.value && !route.meta.public && health.value !== null && !health.value.is_running)

const headline = computed(() => {
  const h = health.value
  if (!h) return ''
  if (h.minutes_since_last_run === null) return 'งานอัตโนมัติ (cron) ยังไม่เคยทำงาน'

  return `งานอัตโนมัติ (cron) หยุดทำงานมา ${formatAge(h.minutes_since_last_run)}`
})

const lastRunText = computed(() => {
  const at = health.value?.last_run_at
  if (!at) return 'ยังไม่มีบันทึกการทำงาน'

  return `ทำงานครั้งล่าสุด ${new Date(at).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })}`
})

function formatAge(minutes: number): string {
  if (minutes < 60) return `${minutes} นาที`
  const hours = Math.floor(minutes / 60)
  if (hours < 48) return `${hours} ชั่วโมง`

  return `${Math.floor(hours / 24)} วัน`
}

async function check(): Promise<void> {
  if (!isSuperAdmin.value) {
    health.value = null

    return
  }
  try {
    const res = await api.get<{ data: SchedulerHealth }>('/platform/scheduler-health')
    health.value = res.data
  } catch {
    // A failed CHECK is not evidence that cron is down; say nothing rather
    // than raise an alarm the admin cannot act on.
  }
}

onMounted(() => {
  void check()
  timer = window.setInterval(() => void check(), RECHECK_MS)
})

onUnmounted(() => {
  if (timer !== null) window.clearInterval(timer)
})

watch(isSuperAdmin, () => void check())
</script>

<template>
  <div
    v-if="visible"
    class="mx-4 mt-3 lg:mx-8 flex items-start gap-3.5 rounded-2xl border px-4 py-3.5 bg-rose-50 border-rose-200"
    data-test="scheduler-health-banner"
    role="status"
  >
    <Icon name="alert" :size="21" class="shrink-0 mt-0.5 text-rose-700" />
    <div class="flex-1 min-w-0">
      <p class="text-[15px] font-extrabold text-rose-800 flex items-center gap-1.5" data-test="scheduler-health-headline">
        {{ headline }}
        <InfoPopover label="งานอัตโนมัติ (cron)">
          ระบบมีงานที่ต้องทำเองตามเวลา ได้แก่ จัดขั้นเอเจนต์, ค่าคอมต่ออายุ, ส่งอีเมลแจ้งเตือน,
          เตือนนัดติดตามลูกค้า, จับคู่ Binary, เครดิตโปรโมชัน และยืนยันรับสินค้าอัตโนมัติ
          ถ้า cron บนเซิร์ฟเวอร์ไม่ทำงาน งานเหล่านี้จะไม่เกิดขึ้นเลยโดยไม่มี error ให้เห็น
          ตรวจที่ Hostinger hPanel → Advanced → Cron Jobs ว่าคำสั่ง schedule:run ยังอยู่และเรียก PHP 8.3
        </InfoPopover>
      </p>
      <p class="mt-0.5 text-[13px] text-rose-700" data-test="scheduler-health-detail">
        {{ lastRunText }} · การจัดขั้น ค่าคอมต่ออายุ และอีเมลแจ้งเตือนจะไม่เกิดจนกว่าจะแก้
      </p>
    </div>
  </div>
</template>
