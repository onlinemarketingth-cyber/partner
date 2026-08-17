<script setup lang="ts">
/**
 * AffiliateLinksView — "My Affiliate Links" (ADR-011 Section 4 / TASK-033).
 *
 * BR-1 (Access Gate): minting a link is gated the same way SWS Referral
 * submission is — AffiliateLinkService::create() rejects with a 422 if
 * this agent hasn't passed Basic certification yet. The "+ สร้างลิงก์ใหม่"
 * button reflects that honestly (disabled + explanatory message) rather
 * than letting the request round-trip to a predictable failure, same
 * pattern as ClientsView.vue's hasPassedBasic guard (which is where that
 * guard — and TASK-067's "have *I* passed Basic" scoping rule — lives now
 * that TASK-169 Phase 4b deleted ReferralsView.vue).
 *
 * Attribution window (attribution_window_days) is read-only here —
 * TASK-033 explicitly puts the admin-side config screen for it out of
 * scope (grouped into TASK-034). GET /affiliate-attribution-settings
 * was Company Admin/Super Admin-only from TASK-032; ag-lead widened
 * show() (not update()) to any authenticated role as a small backend
 * gap-fill so this screen has something to read — see
 * AffiliateAttributionSettingController's own comment.
 *
 * Revoke (destroy()) reuses the exact ConfirmDialog + copy-to-clipboard
 * pattern already established for sales-material share links in
 * ClientsView.vue — nothing new invented, same component vocabulary.
 */
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
// TASK-079 Phase 2 (UX audit) — status codes leaked into the error copy,
// and minting/revoking a link gave no confirmation. Revoke's failure path
// is toasted because the ConfirmDialog stays open over the page banner,
// which would otherwise hide the reason it didn't work.
import { apiErrorMessage } from '@/utils/apiError'
import { useToastStore } from '@/stores/toast'
import { useAuthStore } from '@/stores/auth'
import HeroHeader from '@/design-system/components/HeroHeader.vue'
import AppButton from '@/design-system/components/AppButton.vue'
import EmptyState from '@/design-system/components/EmptyState.vue'
import Icon from '@/design-system/components/Icon.vue'
import LoadingSkeleton from '@/design-system/components/LoadingSkeleton.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
// TASK-082 (2026-08-03, UX audit): affiliate links are homogeneous,
// comparable content — Material's rule is lists for that, cards only for
// heterogeneous blocks, and never cards when the user has to scan
// comparable items (here: which link is actually converting).
import AppCard from '@/design-system/components/AppCard.vue'
import AppList from '@/design-system/components/AppList.vue'

interface ProductOption {
  id: number
  name: string
  price_satang: number
}
interface Certification {
  id: number
  user_id: number
  cert_tier: { id: number; key: string; name: string } | null
}
interface AffiliateLinkItem {
  id: number
  product_id: number | null
  token: string
  public_url: string
  clicks_count: number
  conversions_count: number
  created_at: string
}
interface AttributionSetting {
  attribution_window_days: number
}

const loading = ref(false)
const hasLoadedOnce = ref(false)
const errorMessage = ref('')

const authStore = useAuthStore()
const toast = useToastStore()
const links = ref<AffiliateLinkItem[]>([])
const products = ref<ProductOption[]>([])
const certifications = ref<Certification[]>([])
const attributionSetting = ref<AttributionSetting | null>(null)

// TASK-066 follow-up (human-reported 2026-07-31) — same root cause as
// ProductBrowseView.vue's fix: GET /user-certifications returns the
// FULL company roster for Company Admin/Super Admin, not just their own
// rows, so this gate must filter to `authStore.user?.id` to mean "have
// I myself passed Basic" rather than "has anyone in the company."
const hasPassedBasic = computed(() =>
  certifications.value.some((c) => c.user_id === authStore.user?.id && c.cert_tier?.key === 'basic'),
)

function productName(productId: number | null): string {
  if (!productId) return 'ทุกสินค้า'
  return products.value.find((p) => p.id === productId)?.name ?? `สินค้า #${productId}`
}

const kpis = computed(() => [
  { label: 'ลิงก์ทั้งหมด', value: links.value.length },
  { label: 'คลิกทั้งหมด', value: links.value.reduce((sum, l) => sum + l.clicks_count, 0) },
  { label: 'การแปลงทั้งหมด', value: links.value.reduce((sum, l) => sum + l.conversions_count, 0) },
])

async function loadAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    // Attribution settings returns 204/no body when the company admin
    // hasn't configured one yet (AffiliateAttributionSettingController::
    // show()) — api.get() throws on non-2xx only, so a 204 resolves fine,
    // just with an empty-string body per the client's isJson check; guard
    // against that explicitly rather than assuming `.data` exists.
    const [l, p, uc, settingsRes] = await Promise.all([
      api.get<{ data: AffiliateLinkItem[] }>('/affiliate-links'),
      api.get<{ data: ProductOption[] }>('/products'),
      api.get<{ data: Certification[] }>('/user-certifications'),
      api.get<{ data: AttributionSetting } | ''>('/affiliate-attribution-settings'),
    ])
    links.value = l.data
    products.value = p.data
    certifications.value = uc.data
    attributionSetting.value = settingsRes === '' ? null : settingsRes.data
  } catch (e) {
    errorMessage.value = apiErrorMessage(e, 'โหลดข้อมูลไม่สำเร็จ')
  } finally {
    loading.value = false
    hasLoadedOnce.value = true
  }
}
onMounted(loadAll)

const selectedProductId = ref<string>('')
const creating = ref(false)
const createError = ref('')

async function createLink() {
  creating.value = true
  createError.value = ''
  try {
    const res = await api.post<{ data: AffiliateLinkItem }>('/affiliate-links', {
      product_id: selectedProductId.value ? Number(selectedProductId.value) : undefined,
    })
    links.value = [res.data, ...links.value]
    selectedProductId.value = ''
    // TASK-079 Phase 2 (UX audit): the new row is prepended above the fold
    // but the form itself doesn't change, so the write was easy to miss.
    toast.success('สร้างลิงก์แล้ว')
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      const body = e.body as { errors?: Record<string, string[]> }
      createError.value = body.errors?.agent_id?.[0] ?? 'สร้างลิงก์ไม่สำเร็จ กรุณาลองใหม่'
    } else {
      createError.value = apiErrorMessage(e, 'สร้างลิงก์ไม่สำเร็จ')
    }
  } finally {
    creating.value = false
  }
}

const copiedLinkId = ref<number | null>(null)
async function copyLink(link: AffiliateLinkItem) {
  try {
    await navigator.clipboard.writeText(link.public_url)
    copiedLinkId.value = link.id
    setTimeout(() => {
      if (copiedLinkId.value === link.id) copiedLinkId.value = null
    }, 2000)
  } catch {
    errorMessage.value = 'คัดลอกลิงก์ไม่สำเร็จ — กรุณาคัดลอกด้วยตนเอง'
  }
}

const revokeTargetId = ref<number | null>(null)
const showRevokeConfirm = ref(false)
const revoking = ref(false)

function askRevoke(linkId: number) {
  revokeTargetId.value = linkId
  showRevokeConfirm.value = true
}

async function confirmRevoke() {
  if (!revokeTargetId.value) return
  revoking.value = true
  try {
    await api.delete(`/affiliate-links/${revokeTargetId.value}`)
    links.value = links.value.filter((l) => l.id !== revokeTargetId.value)
    showRevokeConfirm.value = false
    toast.success('ยกเลิกลิงก์แล้ว')
  } catch (e) {
    // Toast, not just the banner: the ConfirmDialog is still open on the
    // failure path and covers the top-of-page banner entirely.
    const message = apiErrorMessage(e, 'ยกเลิกลิงก์ไม่สำเร็จ')
    errorMessage.value = message
    toast.error(message)
  } finally {
    revoking.value = false
    revokeTargetId.value = null
  }
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('th-TH', { dateStyle: 'medium' })
}
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <HeroHeader
      icon="link"
      title="ลิงก์พันธมิตร"
      subtitle="สร้างลิงก์ติดตามผลเพื่อแนะนำลูกค้า"
      description="แชร์ลิงก์นี้ให้ลูกค้า — ทุกคลิกและทุกการสมัครจะถูกบันทึกและนับเครดิตให้คุณ (ADR-011)"
      :kpis="kpis"
      accent-color="brand"
      storage-key="affiliate-links"
      back-page="/"
      back-label="หน้าหลัก"
    >
      <template #actions>
        <span
          v-if="attributionSetting"
          class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-surface-chip text-xs font-bold text-ink-card-muted whitespace-nowrap"
          title="ตั้งค่าโดยแอดมิน — คลิกล่าสุดต้องอยู่ในช่วงเวลานี้จึงจะนับเป็นการแปลง"
        >
          <Icon name="clock" :size="14" />
          หน้าต่างนับเครดิต {{ attributionSetting.attribution_window_days }} วัน
        </span>
      </template>
    </HeroHeader>

    <!-- TASK-079 Phase 2 (UX audit): dead-end error banner — retry lets the
         agent recover without reloading the whole SPA. -->
    <div v-if="errorMessage" class="mt-4 px-4 py-3 rounded-xl bg-surface-danger border border-line-card text-sm text-ink-danger flex items-center justify-between gap-3">
      <span>{{ errorMessage }}</span>
      <button
        type="button"
        class="shrink-0 min-h-[44px] px-3 py-2 rounded-lg text-xs font-bold text-ink-danger bg-rose-100 hover:bg-rose-200 active:scale-95 transition"
        @click="loadAll"
      >
        ลองใหม่
      </button>
    </div>

    <!-- TASK-079 Phase 3 (UX audit finding D): skeleton → real content was a
         single-frame hard swap, which reads as a flash on a phone. .content-fade
         lives in assets/main.css (and is neutralised under
         prefers-reduced-motion). <Transition> takes exactly ONE child per
         branch, hence the wrapper <div>s — and this view must stay
         single-rooted or App.vue's <Transition mode="out-in"> around
         <RouterView> breaks (the multi-root Fragment regression). -->
    <Transition name="content-fade">
      <LoadingSkeleton v-if="loading && !hasLoadedOnce" type="list" :rows="3" class="mt-4" />
      <div v-else>
        <!-- BR-1 gate — honest reflection, same pattern as ClientsView.vue -->
        <div
          v-if="!hasPassedBasic"
          class="mt-4 flex items-start gap-3 px-4 py-3 rounded-xl bg-surface-warning border border-line-card text-sm text-ink-warning"
        >
          <Icon name="alert" :size="16" class="mt-0.5 shrink-0" />
          <span>คุณต้องผ่านการรับรอง Basic ก่อนจึงจะสร้างลิงก์พันธมิตรได้ (BR-1) — ไปที่หน้า Academy เพื่อเริ่มเรียน</span>
        </div>

        <!-- Generate new link -->
        <div v-else class="mt-4 bg-surface-card/95 border border-line-card rounded-xl p-4">
          <p class="text-sm font-bold text-ink-card mb-2">สร้างลิงก์ใหม่</p>
          <div v-if="createError" class="mb-2 px-3 py-2 rounded-lg bg-surface-danger border border-line-card text-xs text-ink-danger">
            {{ createError }}
          </div>
          <!-- Always stacked: `sm:` responds to the VIEWPORT, but this app
               renders inside a fixed max-w-md column, so on a 2560px desktop
               `sm:flex-row` fired and crammed a product select next to a
               button in 384px. Same root cause as the list-row squeeze. -->
          <div class="flex flex-col gap-2">
            <select v-model="selectedProductId" class="bg-surface-input text-ink-input flex-1 min-h-[44px] px-3 py-2 rounded-lg border border-line-input text-sm">
              <option value="">ทุกสินค้า (ไม่ระบุ)</option>
              <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <AppButton :loading="creating" @click="createLink">+ สร้างลิงก์ใหม่</AppButton>
          </div>
        </div>

        <EmptyState
          v-if="!links.length"
          icon="link"
          title="ยังไม่มีลิงก์พันธมิตร"
          message="สร้างลิงก์แรกของคุณด้านบนเพื่อเริ่มแชร์และติดตามผล"
          class="mt-4"
        />
        <!-- TASK-082 (UX audit): the per-link card is gone — affiliate links
             are homogeneous, comparable rows (the agent is scanning for
             which one converts), and Material's rule is a list for exactly
             that, never cards. Rows butt together inside one <AppList>, so
             `space-y-2` is gone too.

             NO GROUP HEADERS HERE, deliberately: the four other list screens
             group by a status field, but AffiliateLinkItem carries no
             active/revoked (or expiry) field at all — revoke DELETEs the row
             and it simply leaves `links`, so every link in this array is by
             definition live. Inventing a group would mean inventing data, so
             this screen stays one ungrouped list. If the backend ever grows
             a revoked_at here (ClientsView's share links already have one),
             this is where "ใช้งานได้ / ยกเลิกแล้ว" headers would go. -->
        <AppList v-else class="mt-4">
          <!-- No `tag`: TransitionGroup renders as a fragment so the rows
               stay DIRECT children of AppList, which its
               `[&>*:last-child]:border-b-0` rule depends on. -->
          <TransitionGroup name="list-fade">
            <!-- Not `interactive`: the row itself isn't tappable, its two
                 icon buttons are — a whole-row press state would promise an
                 action that doesn't exist. -->
            <AppCard v-for="link in links" :key="link.id" variant="flat">
              <div class="flex items-start justify-between gap-3">
                <!-- Flex-squeeze bug fix (2026-08-03, human-reported at 768px on
                     the Referrals screen: the text wrapped to ONE CHARACTER PER
                     LINE). Same root cause here: `min-w-0` without `flex-1`
                     resolves to `flex: 0 1 auto`, so the column shrank toward
                     min-content instead of taking the leftover width. The right
                     column already had `shrink-0`, and this row is deliberately
                     NOT stacked below `sm` (unlike the other four lists): its
                     right side is only two 44px icon buttons, no
                     `whitespace-nowrap` status chip, so ~250px is still left for
                     the text at 375px. -->
                <div class="flex items-start gap-3 min-w-0 flex-1">
                  <Icon name="link" :size="18" class="text-ink-brand mt-0.5 shrink-0" />
                  <!-- TASK-081 (typography audit): the raw URL was the same
                       11-14px weight class as everything else, competing with
                       the numbers that actually matter. It is a copy-target,
                       not reading matter — demoted to the lightest tier; the
                       hero numbers live in the footer below. -->
                  <div class="min-w-0">
                    <p class="text-sm font-bold text-ink-card">{{ productName(link.product_id) }}</p>
                    <p class="text-[11px] text-ink-card-subtle truncate">{{ link.public_url }}</p>
                    <p class="text-[11px] text-ink-card-subtle mt-0.5">สร้างเมื่อ {{ formatDate(link.created_at) }}</p>
                  </div>
                </div>
                <!-- TASK-079 Phase 3 (UX audit): these were 32px icon-only
                     buttons — under the 44px minimum and with no press state.
                     aria-label added alongside the existing title: the title
                     is a hover tooltip, which a touchscreen never shows, so
                     it was doing nothing for the actual users of this app. -->
                <div class="flex items-center gap-1 shrink-0">
                  <button
                    class="w-11 h-11 flex items-center justify-center rounded-lg text-ink-card-subtle hover:bg-surface-chip hover:text-ink-brand transition-all active:scale-90"
                    title="คัดลอกลิงก์"
                    aria-label="คัดลอกลิงก์"
                    @click="copyLink(link)"
                  >
                    <Icon :name="copiedLinkId === link.id ? 'check' : 'copy'" :size="16" />
                  </button>
                  <button
                    class="w-11 h-11 flex items-center justify-center rounded-lg text-ink-card-subtle hover:bg-surface-danger hover:text-ink-danger transition-all active:scale-90"
                    title="ยกเลิกลิงก์"
                    aria-label="ยกเลิกลิงก์"
                    @click="askRevoke(link.id)"
                  >
                    <Icon name="trash" :size="16" />
                  </button>
                </div>
              </div>
              <!-- TASK-081 (typography audit): clicks/conversions ARE the
                   reason an agent opens this screen, yet they rendered at the
                   same text-sm as their own labels and the URL — no hero
                   value anywhere in the row. Numbers promoted to text-xl over
                   small caps labels; the labels were already at the right
                   tier and are unchanged. -->
              <div class="mt-3 pt-3 border-t border-line-card-subtle flex items-center gap-6">
                <div>
                  <p class="text-[11px] text-ink-card-subtle uppercase tracking-wide font-bold">คลิก</p>
                  <p class="text-xl font-bold text-ink-card leading-tight">{{ link.clicks_count }}</p>
                </div>
                <div>
                  <p class="text-[11px] text-ink-card-subtle uppercase tracking-wide font-bold">การแปลง</p>
                  <p class="text-xl font-bold text-ink-card leading-tight">{{ link.conversions_count }}</p>
                </div>
              </div>
            </AppCard>
          </TransitionGroup>
        </AppList>
      </div>
    </Transition>

    <ConfirmDialog
      v-model:show="showRevokeConfirm"
      title="ยืนยันการยกเลิกลิงก์"
      body="ลิงก์นี้จะใช้งานไม่ได้อีกต่อไป แต่สถิติคลิก/การแปลงที่บันทึกไว้แล้วจะยังอยู่"
      variant="danger"
      :busy="revoking"
      @confirm="confirmRevoke"
    />
  </main>
</template>
