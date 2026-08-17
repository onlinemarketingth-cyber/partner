<script setup lang="ts">
/**
 * CompanyManagementView — "จัดการบริษัท" (Phase 7). Super Admin only —
 * the route guard + AdminHomeView's card both already gate this on
 * auth.user.role, and the backend's CompanyPolicy is the real
 * enforcement either way (Section 5).
 *
 * A Company is the tenant boundary itself (CLAUDE.md §2) — this screen
 * is deliberately minimal (name/slug/active), no cascading actions on
 * deactivate/delete are implemented here since none are defined
 * anywhere in CLAUDE.md yet (see CompanyService's own flagged note).
 */
import { onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'

// ADR-006 Round 3/4 → ADR-011 (TASK-034 update): one commission plan
// type per company. All 6 enum values now have a working
// CommissionService engine (Unilevel: original; Binary: TASK-029;
// Matrix: TASK-030; StairstepBreakaway/Generation: TASK-031; Affiliate:
// TASK-032/033) — the earlier "ไบนารี (อยู่ระหว่างพัฒนา)" framing here
// predates TASK-029 and is now stale for all 5 non-Unilevel types, not
// just Binary; removed rather than left half-corrected. A company still
// needs at least one config row (agent_ranks, commission_binary_settings,
// etc. — see the new "แผนคอมมิชชั่น" screen, CommissionPlansView.vue)
// before its chosen plan type actually calculates anything — that's a
// config-completeness concern, not an "under development" one, so it's
// surfaced as a warning banner there instead of blocking selection here.
type CommissionPlanType = 'unilevel' | 'binary' | 'matrix' | 'stairstep_breakaway' | 'generation' | 'affiliate'

interface CompanyItem {
  id: number
  name: string
  slug: string
  is_active: boolean
  commission_plan_type: CommissionPlanType
  user_count: number
  created_at: string
}

const planTypeLabels: Record<CommissionPlanType, string> = {
  unilevel: 'มาตรฐาน (Unilevel)',
  binary: 'ไบนารี (Binary)',
  matrix: 'เมทริกซ์ (Matrix)',
  stairstep_breakaway: 'Stairstep/Breakaway',
  generation: 'Generation',
  affiliate: 'พันธมิตร (Affiliate)',
}
const planTypeOptions = Object.keys(planTypeLabels) as CommissionPlanType[]

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')
const companies = ref<CompanyItem[]>([])

async function loadCompanies() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get<{ data: CompanyItem[] }>('/companies')
    companies.value = res.data
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(loadCompanies)

function slugify(name: string): string {
  return name
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-|-$)/g, '')
}

// ── Create form ──
const showCreateForm = ref(false)
const createForm = ref<{ name: string; slug: string; commission_plan_type: CommissionPlanType }>({
  name: '',
  slug: '',
  commission_plan_type: 'unilevel',
})
const creating = ref(false)
async function submitCreate() {
  creating.value = true
  errorMessage.value = ''
  try {
    await api.post('/companies', {
      name: createForm.value.name,
      slug: createForm.value.slug || slugify(createForm.value.name),
      commission_plan_type: createForm.value.commission_plan_type,
    })
    createForm.value = { name: '', slug: '', commission_plan_type: 'unilevel' }
    showCreateForm.value = false
    await loadCompanies()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `สร้างไม่สำเร็จ (${e.status})` : 'สร้างไม่สำเร็จ'
  } finally {
    creating.value = false
  }
}

async function toggleActive(company: CompanyItem) {
  try {
    await api.put(`/companies/${company.id}`, { is_active: !company.is_active })
    await loadCompanies()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `อัปเดตไม่สำเร็จ (${e.status})` : 'อัปเดตไม่สำเร็จ'
  }
}

async function changePlanType(company: CompanyItem, planType: CommissionPlanType) {
  if (planType === company.commission_plan_type) return
  try {
    await api.put(`/companies/${company.id}`, { commission_plan_type: planType })
    await loadCompanies()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `อัปเดตไม่สำเร็จ (${e.status})` : 'อัปเดตไม่สำเร็จ'
  }
}
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="building"
      title="จัดการบริษัท"
      subtitle="รายชื่อบริษัท (Tenant) ทั้งแพลตฟอร์ม"
      description="มองเห็นได้เฉพาะ Super Admin — ข้ามบริษัททั้งแพลตฟอร์ม (Section 5)"
      accent-color="brand"
      storage-key="company-management"
    >
      <template #actions>
        <button
          class="btn-primary"
          @click="showCreateForm = !showCreateForm"
        >
          + เพิ่มบริษัท
        </button>
      </template>
    </HeroHeader>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <form
      v-if="showCreateForm"
      class="mt-4 p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3"
      @submit.prevent="submitCreate"
    >
      <div>
        <label class="text-xs font-bold text-slate-500">ชื่อบริษัท</label>
        <input v-model="createForm.name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
      </div>
      <div>
        <label class="text-xs font-bold text-slate-500">Slug (ไม่บังคับ — สร้างอัตโนมัติจากชื่อ)</label>
        <input v-model="createForm.slug" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
      </div>
      <div class="col-span-2">
        <label class="text-xs font-bold text-slate-500">รูปแบบค่าคอมมิชชั่น (เลือกได้ 1 แบบต่อบริษัท)</label>
        <select v-model="createForm.commission_plan_type" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
          <option v-for="pt in planTypeOptions" :key="pt" :value="pt">{{ planTypeLabels[pt] }}</option>
        </select>
        <p v-if="createForm.commission_plan_type !== 'unilevel'" class="mt-1 text-xs text-slate-400">
          ต้องตั้งค่าที่หน้า "แผนคอมมิชชั่น" ก่อน ระบบจึงจะคำนวณค่าคอมมิชชั่นตามรูปแบบนี้ได้
        </p>
      </div>
      <div class="col-span-2 flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="showCreateForm = false">ยกเลิก</button>
        <button type="submit" :disabled="creating" class="btn-primary">
          {{ creating ? 'กำลังบันทึก...' : 'บันทึก' }}
        </button>
      </div>
    </form>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="3" class="mt-4" />
    <template v-else>
      <EmptyState v-if="!companies.length" icon="building" title="ยังไม่มีบริษัทในระบบ" class="mt-4" />
      <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2 mt-4">
        <div v-for="c in companies" :key="c.id" class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center justify-between gap-3">
          <div class="flex items-start gap-3 min-w-0">
            <Icon name="building" :size="18" class="text-brand-600 mt-0.5 shrink-0" />
            <div class="min-w-0">
              <p class="text-sm font-bold text-slate-900">{{ c.name }}</p>
              <p class="text-xs text-slate-400">/{{ c.slug }} · {{ c.user_count }} ผู้ใช้งาน</p>
              <div class="mt-1.5 flex items-center gap-1.5">
                <select
                  :value="c.commission_plan_type"
                  class="text-xs font-bold px-2 py-1 rounded-lg border border-slate-200 bg-white text-slate-600"
                  @change="changePlanType(c, ($event.target as HTMLSelectElement).value as CommissionPlanType)"
                >
                  <option v-for="pt in planTypeOptions" :key="pt" :value="pt">{{ planTypeLabels[pt] }}</option>
                </select>
                <RouterLink
                  v-if="c.commission_plan_type !== 'unilevel'"
                  :to="{ name: 'commission-plan-settings' }"
                  class="text-[11px] font-bold px-1.5 py-0.5 rounded bg-slate-50 text-slate-500 hover:bg-slate-100 hover:text-brand-600"
                >
                  ตั้งค่าแผนคอมมิชชั่น
                </RouterLink>
              </div>
            </div>
          </div>
          <button
            class="text-xs font-bold px-2 py-1 rounded-lg shrink-0"
            :class="c.is_active ? 'text-emerald-600 bg-emerald-50 hover:bg-emerald-100' : 'text-slate-400 bg-slate-100 hover:bg-slate-200'"
            @click="toggleActive(c)"
          >
            {{ c.is_active ? 'ใช้งานอยู่' : 'ปิดใช้งาน' }}
          </button>
        </div>
      </TransitionGroup>
    </template>
  </main>
</template>
