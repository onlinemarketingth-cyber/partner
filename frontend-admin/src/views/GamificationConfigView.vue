<script setup lang="ts">
/**
 * GamificationConfigView — "ตั้งค่า Gamification" (Phase 7 frontend;
 * the API itself was already fully built in Phase 6 — see
 * TASK-008-gamification.md).
 *
 * BR-5/BR-7: XP amounts are config here, never hardcoded elsewhere.
 * gamification_rules.company_id is nullable — null rows are the
 * platform-wide default (shown but not editable by a Company Admin,
 * only by Super Admin); a Company Admin's own company-specific row
 * overrides the default for their company only.
 *
 * Badge awarding here is the manual write path (always available);
 * Phase 10 additionally auto-awards a badge the moment its
 * condition_config becomes true (see BadgeConditionEvaluator on the
 * backend) — this screen doesn't need to know which path fired, the
 * award-history list below is the same either way.
 *
 * Level tab (Phase 9): level_thresholds is platform-wide config (no
 * per-company override — unlike XP rules/badges), so create/edit/
 * delete here are Super-Admin-only; a Company Admin can still view the
 * curve (read-only) since it affects their own agents' displayed level.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api, ApiError } from '@/api/client'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import CompanyScopeNotice from '@/design-system/components/CompanyScopeNotice.vue'

interface GamificationRule {
  id: number
  company_id: number | null
  source_type: string
  xp_value: number
  is_active: boolean
}
interface BadgeCondition {
  metric: string
  operator: string
  value: number
}
interface Badge {
  id: number
  company_id: number | null
  key: string
  name: string
  description: string
  icon: string
  condition_config: BadgeCondition[] | null
}
interface AgentOption {
  id: number
  name: string
  role: string
  is_active: boolean
}
interface UserBadgeItem {
  id: number
  user: { id: number; name: string } | null
  badge: { id: number; name: string } | null
  earned_at: string
}
interface LevelThresholdItem {
  id: number
  level_number: number
  xp_required: number
}

const SOURCE_TYPES = [
  { value: 'module_completed', label: 'เรียนจบโมดูล' },
  { value: 'exam_passed', label: 'สอบผ่าน' },
  { value: 'referral_submitted', label: 'ส่ง Referral' },
  { value: 'pipeline_stage_advanced', label: 'เลื่อนขั้น Pipeline' },
  { value: 'payment_complete', label: 'ปิดการขาย (Complete Payment)' },
]
function sourceLabel(value: string): string {
  return SOURCE_TYPES.find((s) => s.value === value)?.label ?? value
}

const auth = useAuthStore()
// TASK-209 — the header company scope (ADR-038).
const activeCompany = useActiveCompanyStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

type Tab = 'rules' | 'badges' | 'levels'
const activeTab = ref<Tab>('rules')

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')

const rules = ref<GamificationRule[]>([])
const badges = ref<Badge[]>([])
const agents = ref<AgentOption[]>([])
const awardedBadges = ref<UserBadgeItem[]>([])
const levelThresholds = ref<LevelThresholdItem[]>([])

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [r, b, u, ub, lt] = await Promise.all([
      api.get<{ data: GamificationRule[] }>(activeCompany.scopedPath('/gamification-rules')),
      api.get<{ data: Badge[] }>(activeCompany.scopedPath('/badges')),
      api.get<{ data: AgentOption[] }>(activeCompany.scopedPath('/users')),
      api.get<{ data: UserBadgeItem[] }>(activeCompany.scopedPath('/user-badges')),
      api.get<{ data: LevelThresholdItem[] }>(activeCompany.scopedPath('/level-thresholds')),
    ])
    rules.value = r.data
    badges.value = b.data
    agents.value = u.data.filter((a) => a.role === 'agent' && a.is_active)
    awardedBadges.value = ub.data
    levelThresholds.value = lt.data.sort((a, z) => a.level_number - z.level_number)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `โหลดข้อมูลไม่สำเร็จ (${e.status})` : 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(loadAll)

// ── Level threshold form (Super Admin only — see LevelThresholdPolicy) ──
const showLevelForm = ref(false)
const editingLevelId = ref<number | null>(null)
const levelForm = ref({ level_number: '', xp_required: '' })
const savingLevel = ref(false)
function openCreateLevelForm() {
  editingLevelId.value = null
  levelForm.value = { level_number: '', xp_required: '' }
  showLevelForm.value = true
}
function openEditLevelForm(lt: LevelThresholdItem) {
  editingLevelId.value = lt.id
  levelForm.value = { level_number: String(lt.level_number), xp_required: String(lt.xp_required) }
  showLevelForm.value = true
}
async function submitLevel() {
  savingLevel.value = true
  errorMessage.value = ''
  try {
    const payload = { level_number: Number(levelForm.value.level_number), xp_required: Number(levelForm.value.xp_required) }
    if (editingLevelId.value) {
      await api.put(`/level-thresholds/${editingLevelId.value}`, payload)
    } else {
      await api.post('/level-thresholds', payload)
    }
    showLevelForm.value = false
    await loadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `บันทึกไม่สำเร็จ (${e.status}) — เลข level อาจซ้ำ` : 'บันทึกไม่สำเร็จ'
  } finally {
    savingLevel.value = false
  }
}
async function deleteLevel(lt: LevelThresholdItem) {
  try {
    await api.delete(`/level-thresholds/${lt.id}`)
    await loadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `ลบไม่สำเร็จ (${e.status})` : 'ลบไม่สำเร็จ'
  }
}

// ── Rule form ──
const showRuleForm = ref(false)
const ruleForm = ref({ source_type: 'module_completed', xp_value: '10', company_wide: false })
const savingRule = ref(false)
async function submitRule() {
  savingRule.value = true
  errorMessage.value = ''
  try {
    await api.post('/gamification-rules', {
      source_type: ruleForm.value.source_type,
      xp_value: Number(ruleForm.value.xp_value),
      ...(isSuperAdmin.value && ruleForm.value.company_wide ? { company_id: null } : {}),
    })
    ruleForm.value = { source_type: 'module_completed', xp_value: '10', company_wide: false }
    showRuleForm.value = false
    await loadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `บันทึกไม่สำเร็จ (${e.status}) — อาจมี rule ที่ active อยู่แล้วสำหรับ event นี้` : 'บันทึกไม่สำเร็จ'
  } finally {
    savingRule.value = false
  }
}
async function toggleRuleActive(rule: GamificationRule) {
  try {
    await api.put(`/gamification-rules/${rule.id}`, { is_active: !rule.is_active })
    await loadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `อัปเดตไม่สำเร็จ (${e.status})` : 'อัปเดตไม่สำเร็จ'
  }
}

// ── Badge create/edit form (Phase 10 — condition_config authoring) ──
// Metric/operator options mirror BadgeConditionEvaluator::SUPPORTED_METRICS
// / SUPPORTED_OPERATORS exactly — the backend still validates these
// server-side (Store/UpdateBadgeRequest), this is just the picklist.
const METRICS = [
  { value: 'xp_total', label: 'XP รวม' },
  { value: 'modules_completed_count', label: 'จำนวนโมดูลที่เรียนจบ' },
  { value: 'referrals_completed_count', label: 'จำนวนเคสที่ปิดการขาย' },
]
const OPERATORS = ['>=', '>', '==', '<=', '<']
function canEditBadge(b: Badge): boolean {
  if (isSuperAdmin.value) return true
  return b.company_id !== null && b.company_id === auth.user?.company?.id
}

const showBadgeForm = ref(false)
const editingBadgeId = ref<number | null>(null)
const badgeForm = ref({
  key: '',
  name: '',
  description: '',
  icon: 'star',
  company_wide: false,
  conditions: [] as { metric: string; operator: string; value: string }[],
})
const savingBadge = ref(false)
function openCreateBadgeForm() {
  editingBadgeId.value = null
  badgeForm.value = { key: '', name: '', description: '', icon: 'star', company_wide: false, conditions: [] }
  showBadgeForm.value = true
}
function openEditBadgeForm(b: Badge) {
  editingBadgeId.value = b.id
  badgeForm.value = {
    key: b.key,
    name: b.name,
    description: b.description,
    icon: b.icon,
    company_wide: b.company_id === null,
    conditions: (b.condition_config ?? []).map((c) => ({ metric: c.metric, operator: c.operator, value: String(c.value) })),
  }
  showBadgeForm.value = true
}
function addConditionRow() {
  badgeForm.value.conditions.push({ metric: 'xp_total', operator: '>=', value: '0' })
}
function removeConditionRow(index: number) {
  badgeForm.value.conditions.splice(index, 1)
}
async function submitBadge() {
  savingBadge.value = true
  errorMessage.value = ''
  try {
    const conditionConfig = badgeForm.value.conditions.length
      ? badgeForm.value.conditions.map((c) => ({ metric: c.metric, operator: c.operator, value: Number(c.value) }))
      : null
    if (editingBadgeId.value) {
      await api.put(`/badges/${editingBadgeId.value}`, {
        name: badgeForm.value.name,
        description: badgeForm.value.description,
        icon: badgeForm.value.icon,
        condition_config: conditionConfig,
      })
    } else {
      await api.post('/badges', {
        key: badgeForm.value.key,
        name: badgeForm.value.name,
        description: badgeForm.value.description,
        icon: badgeForm.value.icon,
        condition_config: conditionConfig,
        ...(isSuperAdmin.value && badgeForm.value.company_wide ? { company_id: null } : {}),
      })
    }
    showBadgeForm.value = false
    await loadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `บันทึกไม่สำเร็จ (${e.status}) — key อาจซ้ำ หรือเงื่อนไขไม่ถูกต้อง` : 'บันทึกไม่สำเร็จ'
  } finally {
    savingBadge.value = false
  }
}
async function deleteBadge(b: Badge) {
  try {
    await api.delete(`/badges/${b.id}`)
    await loadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `ลบไม่สำเร็จ (${e.status})` : 'ลบไม่สำเร็จ'
  }
}

// ── Badge award form ──
const showAwardForm = ref(false)
const awardForm = ref({ user_id: '', badge_id: '' })
const awarding = ref(false)
async function submitAward() {
  if (!awardForm.value.user_id || !awardForm.value.badge_id) return
  awarding.value = true
  errorMessage.value = ''
  try {
    await api.post('/user-badges', { user_id: Number(awardForm.value.user_id), badge_id: Number(awardForm.value.badge_id) })
    awardForm.value = { user_id: '', badge_id: '' }
    showAwardForm.value = false
    await loadAll()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? `มอบ badge ไม่สำเร็จ (${e.status})` : 'มอบ badge ไม่สำเร็จ'
  } finally {
    awarding.value = false
  }
}

// TASK-209 — every list above is scoped server-side, so a change of the
// header company has to refetch; nothing here can be re-derived locally.
watch(() => activeCompany.companyId, () => { loadAll() })
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="star"
      icon-color="text-gold-600"
      title="ตั้งค่า Gamification"
      subtitle="อัตรา XP และการมอบ Badge"
      description="gamification_rules — อัตรา XP, เงื่อนไข badge, และ Level curve (BR-5, BR-7)"
      accent-color="gold"
      storage-key="gamification-config"
    >
      <template #tabs>
        <div class="flex gap-1 px-4 py-2 overflow-x-auto">
          <button
            v-for="t in [{ key: 'rules', label: 'อัตรา XP', icon: 'lightning' }, { key: 'badges', label: 'Badge', icon: 'star' }, { key: 'levels', label: 'Level', icon: 'trophy' }]"
            :key="t.key"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold whitespace-nowrap transition-colors"
            :class="activeTab === t.key ? 'bg-gold-50 text-gold-700' : 'text-slate-500 hover:bg-slate-100'"
            @click="activeTab = t.key as Tab"
          >
            <Icon :name="t.icon" :size="14" />

    <CompanyScopeNotice action="ตั้งค่า Gamification" />
            {{ t.label }}
          </button>
        </div>
      </template>
    </HeroHeader>

    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-sm text-rose-700">
      {{ errorMessage }}
    </div>

    <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="4" class="mt-4" />
    <template v-else>
      <!-- XP Rules -->
      <section v-if="activeTab === 'rules'" class="mt-4">
        <div class="flex justify-end mb-2">
          <button class="px-3 py-1.5 rounded-lg bg-gold-600 text-white text-xs font-bold hover:bg-gold-700" @click="showRuleForm = !showRuleForm">
            + เพิ่มอัตรา XP
          </button>
        </div>
        <form v-if="showRuleForm" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3" @submit.prevent="submitRule">
          <div>
            <label class="text-xs font-bold text-slate-500">เหตุการณ์</label>
            <select v-model="ruleForm.source_type" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
              <option v-for="s in SOURCE_TYPES" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500">XP</label>
            <input v-model="ruleForm.xp_value" type="number" min="0" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div v-if="isSuperAdmin" class="col-span-2 flex items-center gap-2">
            <input v-model="ruleForm.company_wide" type="checkbox" id="company_wide" />
            <label for="company_wide" class="text-xs font-bold text-slate-500">ตั้งเป็นค่า default ของทั้งแพลตฟอร์ม (ไม่ผูกบริษัทใดบริษัทหนึ่ง)</label>
          </div>
          <div class="col-span-2 flex justify-end">
            <button type="submit" :disabled="savingRule" class="px-4 py-2 rounded-lg bg-gold-600 text-white text-sm font-bold disabled:opacity-50">
              {{ savingRule ? 'กำลังบันทึก...' : 'บันทึก' }}
            </button>
          </div>
        </form>
        <EmptyState v-if="!rules.length" icon="lightning" title="ยังไม่มีอัตรา XP" />
        <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
          <div v-for="r in rules" :key="r.id" class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
            <div>
              <p class="text-sm font-bold text-slate-900">
                {{ sourceLabel(r.source_type) }}
                <span v-if="r.company_id === null" class="text-xs font-normal text-slate-400">(ค่า default ทั้งแพลตฟอร์ม)</span>
              </p>
              <p class="text-xs text-slate-400">{{ r.xp_value }} XP</p>
            </div>
            <button
              class="text-xs font-bold px-2 py-1 rounded-lg"
              :class="r.is_active ? 'text-emerald-600 bg-emerald-50 hover:bg-emerald-100' : 'text-slate-400 bg-slate-100 hover:bg-slate-200'"
              @click="toggleRuleActive(r)"
            >
              {{ r.is_active ? 'ใช้งานอยู่' : 'ปิดใช้งาน' }}
            </button>
          </div>
        </TransitionGroup>
      </section>

      <!-- Badges -->
      <section v-if="activeTab === 'badges'" class="mt-4">
        <div class="flex justify-end gap-2 mb-2">
          <button class="px-3 py-1.5 rounded-lg border border-gold-600 text-gold-700 text-xs font-bold hover:bg-gold-50" @click="openCreateBadgeForm">
            + สร้าง Badge
          </button>
          <button class="px-3 py-1.5 rounded-lg bg-gold-600 text-white text-xs font-bold hover:bg-gold-700" @click="showAwardForm = !showAwardForm">
            + มอบ Badge (manual)
          </button>
        </div>

        <!-- Create/edit badge (Phase 10 — condition_config authoring) -->
        <form v-if="showBadgeForm" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 space-y-3" @submit.prevent="submitBadge">
          <div class="grid grid-cols-2 gap-3">
            <div v-if="!editingBadgeId">
              <label class="text-xs font-bold text-slate-500">Key (ไม่ซ้ำ, ตัวอังกฤษ)</label>
              <input v-model="badgeForm.key" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
            </div>
            <div>
              <label class="text-xs font-bold text-slate-500">ชื่อ</label>
              <input v-model="badgeForm.name" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
            </div>
            <div class="col-span-2">
              <label class="text-xs font-bold text-slate-500">คำอธิบาย</label>
              <input v-model="badgeForm.description" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
            </div>
            <div>
              <label class="text-xs font-bold text-slate-500">Icon</label>
              <input v-model="badgeForm.icon" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
            </div>
            <div v-if="isSuperAdmin && !editingBadgeId" class="flex items-center gap-2 self-end pb-2">
              <input v-model="badgeForm.company_wide" type="checkbox" id="badge_company_wide" />
              <label for="badge_company_wide" class="text-xs font-bold text-slate-500">ค่า default ทั้งแพลตฟอร์ม</label>
            </div>
          </div>

          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="text-xs font-bold text-slate-500">เงื่อนไขการมอบอัตโนมัติ (ว่าง = มอบเองเท่านั้น, ทุกเงื่อนไขต้องผ่านหมด)</label>
              <button type="button" class="text-xs font-bold text-gold-600 hover:text-gold-700" @click="addConditionRow">+ เพิ่มเงื่อนไข</button>
            </div>
            <div v-for="(c, i) in badgeForm.conditions" :key="i" class="flex gap-2 mb-2">
              <select v-model="c.metric" class="flex-1 px-2 py-1.5 rounded-lg border border-slate-200 text-xs">
                <option v-for="m in METRICS" :key="m.value" :value="m.value">{{ m.label }}</option>
              </select>
              <select v-model="c.operator" class="w-16 px-2 py-1.5 rounded-lg border border-slate-200 text-xs">
                <option v-for="op in OPERATORS" :key="op" :value="op">{{ op }}</option>
              </select>
              <input v-model="c.value" type="number" min="0" class="w-24 px-2 py-1.5 rounded-lg border border-slate-200 text-xs" />
              <button type="button" class="text-rose-600 text-xs font-bold px-1" @click="removeConditionRow(i)">ลบ</button>
            </div>
          </div>

          <div class="flex justify-end gap-2">
            <button type="button" class="btn-secondary" @click="showBadgeForm = false">ยกเลิก</button>
            <button type="submit" :disabled="savingBadge" class="px-4 py-2 rounded-lg bg-gold-600 text-white text-sm font-bold disabled:opacity-50">
              {{ savingBadge ? 'กำลังบันทึก...' : 'บันทึก' }}
            </button>
          </div>
        </form>

        <form v-if="showAwardForm" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3" @submit.prevent="submitAward">
          <div>
            <label class="text-xs font-bold text-slate-500">ตัวแทน</label>
            <select v-model="awardForm.user_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
              <option value="" disabled>เลือกตัวแทน</option>
              <option v-for="a in agents" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500">Badge</label>
            <select v-model="awardForm.badge_id" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
              <option value="" disabled>เลือก badge</option>
              <option v-for="b in badges" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
          </div>
          <div class="col-span-2 flex justify-end">
            <button type="submit" :disabled="awarding" class="px-4 py-2 rounded-lg bg-gold-600 text-white text-sm font-bold disabled:opacity-50">
              {{ awarding ? 'กำลังบันทึก...' : 'มอบ Badge' }}
            </button>
          </div>
        </form>

        <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2 px-1">Badge ทั้งหมด</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mb-4">
          <div v-for="b in badges" :key="b.id" class="bg-white/95 border border-slate-200 rounded-xl p-4">
            <div class="flex items-start justify-between">
              <div class="flex items-center gap-2">
                <Icon :name="b.icon" :size="20" class="text-gold-600 shrink-0" />
                <div>
                  <p class="text-xs font-bold text-slate-900">
                    {{ b.name }}
                    <span v-if="b.company_id === null" class="font-normal text-slate-400">(ทั้งแพลตฟอร์ม)</span>
                  </p>
                  <p class="text-xs text-slate-400">{{ b.description }}</p>
                </div>
              </div>
              <div v-if="canEditBadge(b)" class="flex gap-1 shrink-0">
                <button class="text-xs font-bold text-slate-500 hover:text-slate-700" @click="openEditBadgeForm(b)">แก้ไข</button>
                <button class="text-xs font-bold text-rose-600 hover:text-rose-700" @click="deleteBadge(b)">ลบ</button>
              </div>
            </div>
            <p v-if="b.condition_config?.length" class="text-xs text-emerald-600 font-bold mt-2">
              มอบอัตโนมัติ: {{ b.condition_config.map((c) => `${METRICS.find((m) => m.value === c.metric)?.label ?? c.metric} ${c.operator} ${c.value}`).join(' และ ') }}
            </p>
            <p v-else class="text-xs text-slate-400 mt-2">มอบเองเท่านั้น (ยังไม่ตั้งเงื่อนไข)</p>
          </div>
        </div>

        <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2 px-1">ประวัติการมอบ</h3>
        <EmptyState v-if="!awardedBadges.length" icon="star" title="ยังไม่มีการมอบ badge" />
        <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
          <div v-for="ub in awardedBadges" :key="ub.id" class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
            <p class="text-sm font-bold text-slate-900">{{ ub.user?.name }} — {{ ub.badge?.name }}</p>
            <p class="text-xs text-slate-400">{{ ub.earned_at }}</p>
          </div>
        </TransitionGroup>
      </section>

      <!-- Level thresholds (Phase 9) -->
      <section v-if="activeTab === 'levels'" class="mt-4">
        <p class="text-xs text-slate-400 mb-2 px-1">
          XP รวมของตัวแทน → Level (คำนวณจากตารางนี้ ไม่มีสูตรอัตโนมัติ) — ใช้ร่วมกับ Leaderboard
        </p>
        <div v-if="isSuperAdmin" class="flex justify-end mb-2">
          <button class="px-3 py-1.5 rounded-lg bg-gold-600 text-white text-xs font-bold hover:bg-gold-700" @click="openCreateLevelForm">
            + เพิ่ม Level
          </button>
        </div>
        <form v-if="showLevelForm" class="mb-3 p-4 rounded-xl bg-white/95 border border-slate-200 grid grid-cols-2 gap-3" @submit.prevent="submitLevel">
          <div>
            <label class="text-xs font-bold text-slate-500">Level</label>
            <input v-model="levelForm.level_number" type="number" min="1" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div>
            <label class="text-xs font-bold text-slate-500">XP ที่ต้องใช้</label>
            <input v-model="levelForm.xp_required" type="number" min="0" required class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" />
          </div>
          <div class="col-span-2 flex justify-end gap-2">
            <button type="button" class="btn-secondary" @click="showLevelForm = false">ยกเลิก</button>
            <button type="submit" :disabled="savingLevel" class="px-4 py-2 rounded-lg bg-gold-600 text-white text-sm font-bold disabled:opacity-50">
              {{ savingLevel ? 'กำลังบันทึก...' : 'บันทึก' }}
            </button>
          </div>
        </form>
        <EmptyState v-if="!levelThresholds.length" icon="trophy" title="ยังไม่มีการตั้งค่า Level" />
        <TransitionGroup v-else tag="div" name="list-fade" class="space-y-2">
          <div v-for="lt in levelThresholds" :key="lt.id" class="bg-white/95 border border-slate-200 rounded-xl p-4 flex items-center justify-between">
            <p class="text-sm font-bold text-slate-900">Level {{ lt.level_number }}</p>
            <div class="flex items-center gap-3">
              <p class="text-xs text-slate-400">{{ lt.xp_required.toLocaleString('th-TH') }} XP</p>
              <template v-if="isSuperAdmin">
                <button class="text-xs font-bold text-slate-500 hover:text-slate-700" @click="openEditLevelForm(lt)">แก้ไข</button>
                <button class="text-xs font-bold text-rose-600 hover:text-rose-700" @click="deleteLevel(lt)">ลบ</button>
              </template>
            </div>
          </div>
        </TransitionGroup>
      </section>
    </template>
  </main>
</template>
