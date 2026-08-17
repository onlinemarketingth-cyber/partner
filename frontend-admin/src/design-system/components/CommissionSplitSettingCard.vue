<script setup lang="ts">
/**
 * CommissionSplitSettingCard — TASK-174's per-company switch for TASK-026's
 * co-agent commission split (human decision D2, 2026-08-12: per company, not
 * a platform config, so a company can turn it on without a deploy).
 *
 * WHERE IT LIVES. Mounted on ThemeSettingsView ("ตั้งค่าระบบ"), directly under
 * the team-visibility card, because that is where this codebase already keeps
 * per-company `*_settings` switches (video processing, team visibility) — one
 * card, one endpoint, one save button each. Deliberately NOT a new screen and
 * NOT a seventh tab on CommissionPlansView: that screen configures RATES per
 * plan type, whereas this is a company-wide feature switch, and it is a
 * tab-lazy screen where a money switch would be one click further from the
 * other kill switches an admin goes looking for.
 *
 * WHY IT IS ITS OWN COMPONENT while its two neighbours are inlined: the §6
 * pre-enable warning below is the only piece of TASK-174's UI with real
 * behaviour (it depends on the SAVED state vs the PENDING state, not just on
 * the form), and a money warning that silently stops rendering is exactly the
 * kind of regression that needs a test. ThemeSettingsView is ~2,000 lines and
 * mounting it needs the theme store, the company list and half a dozen other
 * reads; this card needs one mocked GET.
 *
 * WHAT IT DOES NOT DO. It does not hide anything and does not decide
 * anything. The switch is enforced server-side (CommissionSplitSettingService
 * is the one predicate — spec §4); this card only reads and writes the
 * setting, and the Agent Portal reflects the result.
 */
import { computed, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import Icon from './Icon.vue'

const props = defineProps<{
  /**
   * The company the page's Super Admin picker has selected, or `null`.
   *
   * A Company Admin has no picker and this stays `null` for them — their
   * company_id is resolved SERVER-side on every call (BR-6), so it is only
   * ever put on the wire for a Super Admin. Same contract as the video and
   * team-visibility cards on this page, and the reason `ready` below is not
   * simply `companyId !== null`.
   */
  companyId: number | null
  isSuperAdmin: boolean
}>()

/** A Super Admin must pick a company first; a Company Admin always has one. */
const ready = computed(() => !props.isSuperAdmin || props.companyId !== null)

interface CommissionSplitSettings {
  is_enabled: boolean
  /**
   * Spec §6 — how many referrals still carry a stored `co_agent_id` and have
   * no BR-4 ledger row yet, i.e. how many deals RESUME splitting the moment
   * this switch goes back on.
   *
   * OPTIONAL because the API omits it for an Agent (a company-wide backlog
   * figure is not an Agent's business). An admin always gets it, but the
   * type must not promise a number the response is allowed not to contain —
   * `?? 0` on a missing count would print a reassuring "0 deals" for a
   * figure nobody actually measured.
   */
  pending_referrals_with_stored_split?: number
}

/** What the SERVER currently holds. Never mutated by the toggle. */
const saved = ref<CommissionSplitSettings>({ is_enabled: false })
/** What the admin has dialled in but not yet saved. */
const isEnabled = ref(false)

const loading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const savedFlash = ref(false)

/**
 * Spec §6, the whole point of this card: "turning it back ON must not be a
 * surprise."
 *
 * TRUE only while the saved state is OFF and the admin has flipped the toggle
 * to ON without saving yet — i.e. exactly at the moment they are ABOUT TO
 * switch it on. Not shown when it is already on (nothing is about to change)
 * and not shown when they are turning it off (D1: switching off never splits,
 * so nothing resumes).
 */
const aboutToEnable = computed(() => isEnabled.value && !saved.value.is_enabled)

/**
 * The count is reported EXACTLY as the server gave it, including "we were not
 * told". A pre-enable warning that quietly renders 0 for a missing figure is
 * worse than one that admits it does not know: the admin would read it as
 * "nothing will change" and flip a money switch on that basis.
 */
const pendingCount = computed(() => saved.value.pending_referrals_with_stored_split)

async function load(): Promise<void> {
  if (!ready.value) return
  loading.value = true
  errorMessage.value = ''
  try {
    const path =
      props.isSuperAdmin && props.companyId !== null
        ? `/commission-split-settings?company_id=${props.companyId}`
        : '/commission-split-settings'
    const res = await api.get<{ data: CommissionSplitSettings }>(path)
    saved.value = res.data
    isEnabled.value = res.data.is_enabled
  } catch (e) {
    // Fail CLOSED on the display too: an unreadable setting must not render
    // as a confident "เปิดอยู่".
    saved.value = { is_enabled: false }
    isEnabled.value = false
    errorMessage.value =
      e instanceof ApiError
        ? `โหลดค่าตั้งการแบ่งคอมมิชชั่นไม่สำเร็จ (${e.status})`
        : 'โหลดค่าตั้งการแบ่งคอมมิชชั่นไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  if (!ready.value) {
    errorMessage.value = 'กรุณาเลือกบริษัทก่อนบันทึก'

    return
  }
  saving.value = true
  errorMessage.value = ''
  savedFlash.value = false
  try {
    // company_id is only accepted from a Super Admin; for a Company Admin the
    // backend ignores it entirely and scopes the write to their own row
    // (BR-6, and UpdateCommissionSplitSettingRequest re-checks the role).
    await api.put('/commission-split-settings', {
      ...(props.isSuperAdmin && props.companyId !== null ? { company_id: props.companyId } : {}),
      is_enabled: isEnabled.value,
    })
    savedFlash.value = true
    setTimeout(() => (savedFlash.value = false), 2000)
    // Re-read rather than assume: the PUT response carries a FRESH §6 count,
    // and the next thing the admin may do is flip it back — a stale count
    // under a live switch is the one number on this card that must never lie.
    await load()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `บันทึกไม่สำเร็จ (${e.status})` : 'บันทึกไม่สำเร็จ'
  } finally {
    saving.value = false
  }
}

// Follows the page's own Super Admin company picker, exactly as the video and
// team-visibility cards do — the two can never end up describing different
// companies.
watch(() => props.companyId, load, { immediate: true })
</script>

<template>
  <!--
    No `mt-4` / `max-w-lg` of its own: the host lays these settings cards out
    in a grid (ThemeSettingsView), so spacing and width belong to the grid.
    A card that also sets its own margin and cap fights the column it sits in
    and mis-aligns against its neighbours.
  -->
  <section class="bg-white/95 border border-slate-200 rounded-xl p-5">
    <p class="text-base font-bold text-slate-500 mb-1 flex items-center gap-1.5">
      <Icon name="users" :size="14" /> การแบ่งคอมมิชชั่นกับตัวแทนร่วม
    </p>
    <p class="text-xs text-slate-400 mb-3 leading-relaxed">
      เปิดอยู่ = ตัวแทนระบุ "ตัวแทนร่วม" และเปอร์เซ็นต์ที่แบ่งได้ในแต่ละดีล และเมื่อดีลถึงขั้น
      "ชำระเงินแล้ว" ระบบจะบันทึกคอมมิชชั่นเป็น 2 รายการแทน 1 รายการ · ปิดอยู่ =
      คอมมิชชั่นเต็มจำนวนเข้าตัวแทนผู้แนะนำคนเดียว และช่องกรอกตัวแทนร่วมจะหายไปทั้งหมดจาก Agent
      Portal (ข้อมูลที่เคยกรอกไว้ไม่ถูกลบ)
      (BR-7 — ค่านี้เป็น config ที่แก้ไขได้เสมอ ไม่ hardcode)
    </p>

    <p v-if="errorMessage" class="mb-2 text-xs font-bold text-rose-600">{{ errorMessage }}</p>

    <div v-if="ready">
      <p v-if="loading" class="text-xs text-slate-400">กำลังโหลด...</p>
      <form v-else class="space-y-4" @submit.prevent="save">
        <div class="flex items-start gap-3">
          <button
            type="button"
            aria-label="เปิด/ปิด การแบ่งคอมมิชชั่นกับตัวแทนร่วม"
            @click="isEnabled = !isEnabled"
            class="relative w-14 h-7 shrink-0 rounded-full border transition-colors flex items-center px-1"
            :class="isEnabled ? 'bg-brand-600 border-brand-600' : 'bg-slate-100 border-slate-200'"
            :title="isEnabled ? 'เปิดใช้งาน' : 'ปิดใช้งาน'"
          >
            <div
              class="absolute top-1 bottom-1 w-5 rounded-full shadow bg-white transition-all duration-300"
              :class="isEnabled ? 'translate-x-7' : 'translate-x-0'"
            ></div>
          </button>
          <div class="min-w-0">
            <p class="text-sm font-bold text-slate-900">เปิดให้ตัวแทนแบ่งคอมมิชชั่นกันเองในดีล</p>
            <p class="text-xs text-slate-400 leading-relaxed">
              ปิดอยู่ = ตัวแทนจะไม่เห็นเมนูแบ่งคอมฯ ทั้งในหน้าลูกค้าและตอนส่ง Referral
            </p>
          </div>
        </div>

        <!--
          Spec §6 — the consequence, shown BEFORE the save, not discovered
          after it. Turning the switch back on makes every still-unpaid deal
          that kept a stored split resume splitting, with nobody touching
          those deals. Semantic amber (warning), per the Admin design
          standards: the colour carries meaning, it is not decoration.
        -->
        <div
          v-if="aboutToEnable"
          class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3"
        >
          <Icon name="alert" :size="16" class="text-amber-600 shrink-0 mt-0.5" />
          <div class="min-w-0">
            <p class="text-xs font-bold text-amber-700">
              กำลังจะเปิดกลับมา — ตรวจสอบดีลที่ค้างอยู่ก่อน
            </p>
            <p v-if="pendingCount !== undefined" class="text-xs text-amber-700 leading-relaxed">
              มีดีลที่ยังไม่ได้บันทึกคอมมิชชั่น
              <span class="font-bold">{{ pendingCount }}</span>
              รายการ ที่ยังเก็บ "ตัวแทนร่วม" ไว้จากตอนก่อนปิดระบบ —
              ดีลเหล่านี้จะกลับมาแบ่งคอมมิชชั่นทันทีที่กดบันทึก โดยไม่มีใครแก้ไขดีลนั้นเลย
              (ข้อมูลถูกเก็บไว้ตั้งใจ ไม่ได้ลบ) ส่วนคอมมิชชั่นที่บันทึกลงบัญชีไปแล้วไม่เปลี่ยน (BR-4)
            </p>
            <p v-else class="text-xs text-amber-700 leading-relaxed">
              ระบบไม่ได้ส่งจำนวนดีลที่ค้างอยู่มาให้ จึงยังไม่ทราบว่ามีกี่รายการที่จะกลับมาแบ่งคอมมิชชั่น
              — โปรดตรวจสอบก่อนเปิด
            </p>
          </div>
        </div>

        <div class="flex items-center justify-end gap-2">
          <span v-if="savedFlash" class="text-xs font-bold text-emerald-600">บันทึกแล้ว</span>
          <button type="submit" :disabled="saving" class="btn-primary">
            {{ saving ? 'กำลังบันทึก...' : 'บันทึกการแบ่งคอมฯ' }}
          </button>
        </div>
      </form>
    </div>
    <p v-else class="text-xs text-slate-400">เลือกบริษัทด้านบนก่อน</p>
  </section>
</template>
