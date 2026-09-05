<script setup lang="ts">
/**
 * "จัดการผู้ใช้ระบบ" — who can get in, with what rights (TASK-259, human
 * request 2026-09-05: "ทำหน้าจัดการ user ระบบ และให้ทำงานได้จริงตามสิทธิ์ที่
 * กำหนดไว้").
 *
 * ── WHY A SCREEN, WHEN "จัดการตัวแทน" ALREADY EDITS USERS ──
 *
 * The agent roster is about SELLING: certifications, uplines, commission,
 * recruitment. Account administration lives there only because that is where
 * the rows happened to be, and it shows: a Company Admin is filed among the
 * agents, "who has admin rights?" cannot be asked, and promoting an existing
 * agent was impossible from any form (TASK-130 hid the option, so in practice
 * it meant editing the database by hand).
 *
 * This screen asks a different question — who has access, at what level, and
 * are those accounts still in use — and it lives in ตั้งค่าระบบ where the
 * human asked for it. The roster is untouched; nothing was moved out of it.
 *
 * ── "ให้ทำงานได้จริงตามสิทธิ์ที่กำหนดไว้" ──
 *
 * Every button on every row is rendered from `permissions`, which the SERVER
 * computes per row by asking UserPolicy — the same Policy that will be
 * consulted when the button is pressed. This screen deliberately does not
 * re-derive "super admin, or same company, and never yourself": a second
 * copy of an authorization rule is how a button ends up offered and then
 * refused, or hidden from somebody who was allowed. When the rule changes,
 * this screen changes with it and nobody has to remember it exists.
 *
 * Two rules are worth stating out loud because they are invisible otherwise:
 *   • SUPER ADMINS ARE NOT LISTED, for anybody. UserPolicy::view() refuses
 *     them as targets and /users excludes them — they are managed from the
 *     command line and audited there (`admin:create-super`). The notice says
 *     so, so an empty admin list is never read as "we have no platform
 *     admin".
 *   • YOU CANNOT DEACTIVATE YOURSELF. The server refuses it; here the button
 *     is simply not rendered, because a refusal after the click reads as a
 *     bug rather than a guard rail.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import { RouterLink } from 'vue-router'

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')
const activeCompany = useActiveCompanyStore()

interface RowPermissions {
  update: boolean
  deactivate: boolean
  restore: boolean
  move_company: boolean
}
interface UserRow {
  id: number
  name: string
  email: string
  role: 'agent' | 'company_admin'
  company: { id: number; name: string } | null
  is_active: boolean
  is_team_leader: boolean
  last_login_at: string | null
  created_at: string
  permissions: RowPermissions
}

function apiErrorMessage(e: unknown, fallback: string): string {
  if (!(e instanceof ApiError)) return fallback

  return e.message && e.message !== `API error ${e.status}` ? e.message : `${fallback} (${e.status})`
}

const rows = ref<UserRow[]>([])
const loading = ref(false)
const loadedOnce = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const filters = ref({ role: '' as '' | 'agent' | 'company_admin', q: '', include_inactive: true })

/**
 * `include_inactive` defaults ON, unlike the agent roster.
 *
 * The question this screen exists for is "who can get into the system" — and
 * a closed account is part of that answer ("was it closed? when? by whom?").
 * Hiding them by default would make a deactivated admin invisible on the one
 * screen meant to account for admins.
 */
function queryString(): string {
  const params = new URLSearchParams()
  params.set('with_permissions', '1')
  params.set('with_last_login', '1')
  if (filters.value.include_inactive) params.set('include_inactive', '1')
  if (filters.value.role) params.set('role', filters.value.role)
  if (filters.value.q.trim()) params.set('q', filters.value.q.trim())
  if (isSuperAdmin.value && activeCompany.companyId) params.set('company_id', String(activeCompany.companyId))

  return params.toString()
}

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: UserRow[] }>(`/users?${queryString()}`)
    rows.value = res.data
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดรายชื่อผู้ใช้ไม่สำเร็จ')
  } finally {
    loading.value = false
    loadedOnce.value = true
  }
}

// ── Role change ────────────────────────────────────────────────────────
const pendingRoleChange = ref<{ user: UserRow; role: 'agent' | 'company_admin' } | null>(null)

function askRoleChange(user: UserRow, role: 'agent' | 'company_admin') {
  pendingRoleChange.value = { user, role }
}

/**
 * Confirmed, never immediate — and the wording says what it costs.
 *
 * Changing a role revokes every token that person is holding (TASK-238), so
 * a promotion signs them out of whatever they had open. That is correct, and
 * it is also the kind of thing an admin should be told BEFORE clicking, not
 * discover from a colleague.
 */
async function confirmRoleChange() {
  const pending = pendingRoleChange.value
  if (!pending) return

  try {
    await api.put(`/users/${pending.user.id}`, { role: pending.role })
    successMessage.value = pending.role === 'company_admin'
      ? `${pending.user.name} เป็นผู้ดูแลบริษัทแล้ว`
      : `${pending.user.name} กลับเป็นตัวแทนแล้ว`
    await load()
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'เปลี่ยนบทบาทไม่สำเร็จ')
  } finally {
    pendingRoleChange.value = null
  }
}

// ── Deactivate / restore ───────────────────────────────────────────────
const pendingDeactivate = ref<UserRow | null>(null)

async function confirmDeactivate() {
  const user = pendingDeactivate.value
  if (!user) return

  try {
    await api.delete(`/users/${user.id}`)
    successMessage.value = `ปิดบัญชี ${user.name} แล้ว — เข้าระบบไม่ได้ทันที`
    await load()
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'ปิดบัญชีไม่สำเร็จ')
  } finally {
    pendingDeactivate.value = null
  }
}

async function restore(user: UserRow) {
  try {
    await api.post(`/users/${user.id}/restore`)
    successMessage.value = `กู้คืนบัญชี ${user.name} แล้ว`
    await load()
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'กู้คืนบัญชีไม่สำเร็จ')
  }
}

// ── Reset password ─────────────────────────────────────────────────────
const resetting = ref<UserRow | null>(null)
const newPassword = ref('')
const resetError = ref('')

function askReset(user: UserRow) {
  resetting.value = user
  newPassword.value = ''
  resetError.value = ''
}

/**
 * The admin types the password; it is never generated here and never shown
 * back afterwards. There is no email system in this product (see
 * StoreUserRequest), so a temporary password is handed over in person or by
 * chat — and a value this screen invented would be one nobody chose and one
 * the admin would have to copy from a toast before it disappeared.
 */
async function submitReset() {
  const user = resetting.value
  if (!user) return
  if (newPassword.value.length < 8) {
    resetError.value = 'รหัสผ่านต้องยาวอย่างน้อย 8 ตัว มีพิมพ์ใหญ่ พิมพ์เล็ก และตัวเลข'

    return
  }

  try {
    await api.post(`/users/${user.id}/reset-password`, { password: newPassword.value })
    successMessage.value = `ตั้งรหัสผ่านใหม่ให้ ${user.name} แล้ว — ระบบถอนการเข้าใช้งานเดิมทั้งหมดของบัญชีนี้`
    resetting.value = null
    newPassword.value = ''
  } catch (e) {
    resetError.value = apiErrorMessage(e, 'ตั้งรหัสผ่านใหม่ไม่สำเร็จ')
  }
}

function formatDateTime(iso: string | null): string {
  if (iso === null) return '—'

  return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })
}

const roleLabel = (role: string) => (role === 'company_admin' ? 'ผู้ดูแลบริษัท' : 'ตัวแทน')

const adminCount = computed(() => rows.value.filter((r) => r.role === 'company_admin' && r.is_active).length)

watch(() => activeCompany.companyId, () => load())
onMounted(() => {
  load()
  if (isSuperAdmin.value) activeCompany.loadCompanies()
})
</script>

<template>
  <div class="px-4 lg:px-6 pb-4 lg:pb-6 w-full" style="font-family: Kanit, sans-serif;">
    <HeroHeader
      icon="users"
      title="จัดการผู้ใช้ระบบ"
      subtitle="ใครเข้าระบบได้บ้าง สิทธิ์ระดับไหน และยังใช้งานอยู่หรือไม่"
      accent-color="brand"
      storage-key="user-management"
    />

    <!-- The two invisible rules, said once, where they are read. -->
    <div class="mt-4 mb-3 px-4 py-3 rounded-xl bg-slate-50 border border-dashed border-slate-200 text-xs text-slate-500">
      หน้านี้จัดการ <strong>ผู้ดูแลบริษัท</strong> และ <strong>ตัวแทน</strong> —
      <strong>ไม่แสดง Super Admin</strong> เพราะสร้างและจัดการจากบรรทัดคำสั่งเท่านั้น (<code>admin:create-super</code>)
      ไม่ใช่เพราะยังไม่มี · ปุ่มแต่ละแถวขึ้นตาม<strong>สิทธิ์จริงของคุณ</strong> ที่เซิร์ฟเวอร์เป็นคนตอบ
      และคุณจะปิดบัญชีตัวเองไม่ได้
    </div>

    <div class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 flex flex-wrap items-end gap-3">
      <div>
        <label class="block text-xs font-bold text-slate-500 mb-1">บทบาท</label>
        <select v-model="filters.role" data-test="role-filter" class="px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white min-w-[12rem]" @change="load">
          <option value="">ทุกบทบาท</option>
          <option value="company_admin">ผู้ดูแลบริษัท</option>
          <option value="agent">ตัวแทน</option>
        </select>
      </div>
      <div class="flex-1 min-w-[16rem]">
        <label class="block text-xs font-bold text-slate-500 mb-1">ค้นหา (ชื่อ / อีเมล / เบอร์)</label>
        <input v-model="filters.q" data-test="user-search" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" @keyup.enter="load" />
      </div>
      <label class="flex items-center gap-2 text-xs font-bold text-slate-600 cursor-pointer pb-2">
        <input v-model="filters.include_inactive" type="checkbox" class="rounded border-slate-300" @change="load" />
        แสดงบัญชีที่ปิดแล้วด้วย
      </label>
      <button class="btn-primary" @click="load">
        ค้นหา
      </button>
    </div>

    <div v-if="successMessage" class="mb-3 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-sm text-emerald-800" data-test="success">{{ successMessage }}</div>
    <div v-if="errorMessage" class="mb-3 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700" data-test="error">{{ errorMessage }}</div>

    <LoadingSkeleton v-if="loading && !loadedOnce" type="list" :rows="5" />
    <template v-else>
      <EmptyState v-if="!rows.length" icon="users" title="ไม่พบผู้ใช้ตามเงื่อนไขนี้" />
      <div v-else>
        <p class="mb-2 text-xs text-slate-500">
          ผู้ดูแลบริษัทที่ใช้งานอยู่ <strong>{{ adminCount }}</strong> คน · ทั้งหมด {{ rows.length }} รายการ
        </p>
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white/95">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-100 text-left">
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500">ชื่อ</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500">อีเมล</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500">บทบาท</th>
                <th v-if="isSuperAdmin" class="px-4 py-2.5 text-xs font-bold text-slate-500">บริษัท</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 whitespace-nowrap">เข้าระบบล่าสุด</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500">สถานะ</th>
                <th class="px-4 py-2.5 text-xs font-bold text-slate-500 text-right">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="user in rows" :key="user.id" class="border-b border-slate-50 last:border-0" :class="user.is_active ? '' : 'bg-slate-50/60'">
                <td class="px-4 py-2.5 font-bold text-slate-900">
                  {{ user.name }}
                  <span v-if="user.id === auth.user?.id" class="ml-1 text-[10px] font-bold text-slate-400">(คุณ)</span>
                  <span v-if="user.is_team_leader" class="ml-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-slate-100 text-slate-500">หัวหน้าทีม</span>
                </td>
                <td class="px-4 py-2.5 text-slate-600">{{ user.email }}</td>
                <td class="px-4 py-2.5">
                  <span
                    class="text-[11px] font-bold px-2 py-0.5 rounded-full"
                    :class="user.role === 'company_admin' ? 'bg-brand-50 text-brand-700' : 'bg-slate-100 text-slate-600'"
                  >
                    {{ roleLabel(user.role) }}
                  </span>
                </td>
                <td v-if="isSuperAdmin" class="px-4 py-2.5 text-slate-500 text-xs">{{ user.company?.name ?? '—' }}</td>
                <!-- Null is spelled out. "—" alone would be read as "never",
                     and the true statement is narrower than that. -->
                <td class="px-4 py-2.5 text-xs whitespace-nowrap" :class="user.last_login_at ? 'text-slate-500' : 'text-slate-400'">
                  {{ user.last_login_at ? formatDateTime(user.last_login_at) : 'ไม่พบบันทึกการเข้าระบบ' }}
                </td>
                <td class="px-4 py-2.5">
                  <span class="text-xs font-bold" :class="user.is_active ? 'text-emerald-600' : 'text-slate-400'">
                    {{ user.is_active ? 'ใช้งาน' : 'ปิดบัญชี' }}
                  </span>
                </td>
                <td class="px-4 py-2.5">
                  <div class="flex items-center justify-end gap-1.5 flex-wrap">
                    <!-- Every button below is gated on the SERVER's answer for
                         this row, never on a rule re-derived here. -->
                    <button
                      v-if="user.permissions.update && user.is_active && user.role === 'agent'"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
                      data-test="promote"
                      @click="askRoleChange(user, 'company_admin')"
                    >
                      ให้เป็นผู้ดูแล
                    </button>
                    <button
                      v-if="user.permissions.update && user.is_active && user.role === 'company_admin'"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
                      data-test="demote"
                      @click="askRoleChange(user, 'agent')"
                    >
                      ถอดสิทธิ์ผู้ดูแล
                    </button>
                    <button
                      v-if="user.permissions.update && user.is_active"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
                      data-test="reset-password"
                      @click="askReset(user)"
                    >
                      ตั้งรหัสผ่านใหม่
                    </button>
                    <RouterLink
                      :to="{ name: 'activity-log', query: { actor: user.id } }"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
                      data-test="view-activity"
                    >
                      ดูกิจกรรม
                    </RouterLink>
                    <button
                      v-if="user.permissions.deactivate && user.is_active"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-rose-200 text-rose-600 hover:bg-rose-50"
                      data-test="deactivate"
                      @click="pendingDeactivate = user"
                    >
                      ปิดบัญชี
                    </button>
                    <button
                      v-if="user.permissions.restore && !user.is_active"
                      class="text-xs font-bold px-2 py-1 rounded-lg border border-emerald-200 text-emerald-700 hover:bg-emerald-50"
                      data-test="restore"
                      @click="restore(user)"
                    >
                      กู้คืนบัญชี
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- Reset password. Inline rather than a modal component: it is one field
         and one button, and the sentence above it is the important part. -->
    <div v-if="resetting" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" data-test="reset-dialog">
      <div class="bg-white rounded-2xl p-5 w-full max-w-md">
        <p class="text-sm font-bold text-slate-900">ตั้งรหัสผ่านใหม่ให้ {{ resetting.name }}</p>
        <p class="mt-1 text-xs text-slate-500">
          ระบบนี้ไม่มีการส่งอีเมล — คุณต้องแจ้งรหัสผ่านนี้ให้เจ้าตัวเอง
          และเมื่อบันทึกแล้ว <strong>การเข้าใช้งานเดิมทั้งหมดของบัญชีนี้จะถูกถอนทันที</strong>
        </p>
        <input
          v-model="newPassword"
          type="text"
          data-test="new-password"
          placeholder="อย่างน้อย 8 ตัว มีพิมพ์ใหญ่ พิมพ์เล็ก ตัวเลข"
          class="mt-3 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm"
        />
        <p v-if="resetError" class="mt-2 text-xs font-bold text-rose-600">{{ resetError }}</p>
        <div class="mt-4 flex justify-end gap-2">
          <button class="btn-secondary" @click="resetting = null">ยกเลิก</button>
          <button class="btn-primary" data-test="submit-reset" @click="submitReset">บันทึกรหัสผ่าน</button>
        </div>
      </div>
    </div>

    <ConfirmDialog
      :show="pendingRoleChange !== null"
      :title="pendingRoleChange?.role === 'company_admin' ? 'ให้สิทธิ์ผู้ดูแลบริษัท' : 'ถอดสิทธิ์ผู้ดูแลบริษัท'"
      :body="pendingRoleChange?.role === 'company_admin'
        ? `${pendingRoleChange?.user.name} จะเข้าถึงข้อมูลทั้งบริษัทได้ — ลูกค้า คำสั่งซื้อ ค่าคอมมิชชั่น และการจัดการผู้ใช้ · ระบบจะถอนการเข้าใช้งานเดิมของเขาทั้งหมด เขาต้องเข้าสู่ระบบใหม่`
        : `${pendingRoleChange?.user.name} จะกลับไปเห็นเฉพาะข้อมูลของตัวเอง และจะถูกถอนการเข้าใช้งานเดิมทั้งหมดทันที`"
      confirm-label="ยืนยัน"
      variant="primary"
      @confirm="confirmRoleChange"
      @cancel="pendingRoleChange = null"
    />

    <ConfirmDialog
      :show="pendingDeactivate !== null"
      title="ปิดบัญชีผู้ใช้"
      :body="`${pendingDeactivate?.name} จะเข้าระบบไม่ได้ทันที และการเข้าใช้งานที่ค้างอยู่จะถูกถอนทั้งหมด · ข้อมูลเดิมทั้งหมดยังอยู่ครบ และกู้คืนบัญชีได้ภายหลัง`"
      confirm-label="ปิดบัญชี"
      variant="danger"
      @confirm="confirmDeactivate"
      @cancel="pendingDeactivate = null"
    />
  </div>
</template>
