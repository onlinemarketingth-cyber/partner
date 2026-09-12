<script setup lang="ts">
/**
 * CommissionReadinessBanner — 2026-09-11 (owner): "หากยังไม่ได้มีการ setup
 * ค่าคอม ให้แจ้งเตือนในทุกหน้า หลังจากมีการตั้งค่าแล้วไม่แสดง หากมีการ setup
 * ค่าคอมไม่ครบถ้วนที่ไม่สมบูรณ์ให้เตือนผู้ใช้"
 *
 * ── WHY A BANNER AND NOT A SCREEN ──
 *
 * A commission rate that was never configured is silent by design: a deal
 * closes, the order is immutable, and CommissionService writes no ledger row
 * because there is no rate to write one from. Nobody finds out until an agent
 * asks where their money went. There is no screen anybody would think to open
 * for a thing that has not happened yet, so the warning has to come to them.
 *
 * ── ADMIN CONSOLE ONLY (owner decision) ──
 *
 * The agent portal deliberately does not render this and the endpoint refuses
 * an agent outright. An agent cannot fix a commission rate, so the only thing
 * this could tell them is that the company is not going to pay them — on
 * every page, with no way to act. That is a morale problem, not a help.
 *
 * ── AND WHY IT CAN BE DISMISSED, BUT ONLY UNTIL TOMORROW ──
 *
 * Both halves of that are load-bearing. A banner nobody can silence stops
 * being read — an admin mid-task will learn to look past the strip, and then
 * look past it on the day it turns red. A banner that never comes back stops
 * being a banner: one dismissal on the day it first appears would buy silence
 * for the entire month it takes for the first unpaid commission to surface.
 * So: dismissible, per company and per state, until the date changes.
 */
import { computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '@/design-system/components/Icon.vue'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import { useAuthStore } from '@/stores/auth'
import { useCommissionReadinessStore } from '@/stores/commissionReadiness'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const activeCompany = useActiveCompanyStore()
const readiness = useCommissionReadinessStore()

/** Where an admin with the right to fix it is sent. */
const SETTINGS_ROUTE = '/commission-plans'

/**
 * NOT on the commission settings screen itself.
 *
 * That page carries its own banner, reading this same store (one source of
 * truth), with the step vocabulary and the jump button this strip only
 * gestures at. Two stacked banners saying the same sentence is how both stop
 * being read — and "ทุกหน้า" is still honoured, because the page the admin is
 * on is warning them more loudly than this would.
 */
const onSettingsScreen = computed(() => route.path === SETTINGS_ROUTE)

/**
 * `auth.status` is checked, not just `isAuthenticated`: on a hard refresh the
 * user is null until /me answers, and a banner that flashed in and out on
 * every reload would read as a glitch rather than a warning. The login screen
 * is excluded the same way — nobody to warn, and nothing they could do.
 */
const visible = computed(() =>
  auth.status === 'ready'
  && auth.isAuthenticated
  && !route.meta.public
  && !onSettingsScreen.value
  && readiness.shouldShow)

const isMissing = computed(() => readiness.state === 'missing')

/**
 * RED says the money consequence in one sentence, because "ไม่ได้ตั้งค่า" on
 * its own reads as a settings chore somebody will get to.
 *
 * AMBER names WHAT is incomplete and HOW MANY, straight from the server's
 * first issue — ordered worst-first there, already carrying its own numbers,
 * already in Thai. Re-phrasing it here would be a second copy of a sentence
 * about money in a second place.
 */
const headline = computed(() => {
  if (isMissing.value) return 'ยังไม่ได้ตั้งค่าคอมมิชชั่น — ดีลที่ปิดได้จะไม่มีใครได้เงิน'

  return readiness.issues[0]?.label ?? 'ตั้งค่าคอมมิชชั่นยังไม่ครบถ้วน'
})

/**
 * The second line names the step, in the vocabulary of the 4-step flow the
 * button lands on. "ติดอยู่ที่ขั้นที่ 3" is a place to go; "incomplete" is a
 * feeling.
 */
const detail = computed(() => {
  const step = readiness.blockingStep
  const stuck = step === null ? '' : `ติดอยู่ที่ขั้นที่ ${step} · `

  if (isMissing.value) {
    const total = readiness.productsTotal

    return total > 0
      ? `${stuck}สินค้า ${total} จาก ${total} รายการยังไม่มีอัตราค่าคอมที่ใช้ได้`
      : `${stuck}ยังไม่มีอัตราค่าคอมที่ใช้ได้`
  }

  // Amber: the headline already carries the first issue, so the detail line
  // carries the rest — an admin fixing one gap should be able to see the next
  // one without hunting for it.
  const rest = readiness.issues.slice(1).map((i) => i.label).join(' · ')

  return rest ? `${stuck}${rest}` : stuck.replace(/ · $/, '')
})

function goToSettings(): void {
  void router.push(SETTINGS_ROUTE)
}

/*
 * Loaded on mount and re-asked when the working company changes. NOT on route
 * change: this component is mounted once in the app shell and never unmounts,
 * so a per-route fetch would put a request on every navigation — the thing
 * "ทุกหน้า" must not be allowed to cost.
 */
onMounted(() => {
  void readiness.ensureLoaded()
})

watch(() => activeCompany.companyId, () => {
  void readiness.ensureLoaded()
})

/*
 * A hard refresh mounts this before /me answers, so the mount-time call above
 * returns immediately (the store refuses to ask on behalf of nobody). This is
 * what picks it up once the session is known — and on logout, drops the
 * previous user's verdict so the next one cannot inherit it.
 */
watch(() => auth.isAuthenticated, (isAuthenticated) => {
  if (isAuthenticated) void readiness.ensureLoaded()
  else readiness.reset()
})
</script>

<template>
  <div
    v-if="visible"
    class="mx-4 mt-3 lg:mx-8 flex items-start gap-3.5 rounded-2xl border px-4 py-3.5"
    :class="isMissing ? 'bg-rose-50 border-rose-200' : 'bg-amber-50 border-amber-200'"
    data-test="commission-readiness-banner"
    role="status"
  >
    <Icon
      name="alert"
      :size="21"
      class="shrink-0 mt-0.5"
      :class="isMissing ? 'text-rose-700' : 'text-amber-700'"
    />
    <div class="flex-1 min-w-0">
      <p
        class="text-[15px] font-extrabold"
        :class="isMissing ? 'text-rose-800' : 'text-amber-800'"
        data-test="commission-readiness-headline"
      >
        {{ headline }}
      </p>
      <p
        v-if="detail"
        class="mt-0.5 text-[13px]"
        :class="isMissing ? 'text-rose-700' : 'text-amber-700'"
        data-test="commission-readiness-detail"
      >
        {{ detail }}
      </p>
      <!--
        The Company Admin half of the owner's decision. Since 2026-09-11 they
        may read every commission number and write none of them, so they get
        the message and an instruction that leads somewhere real — never a
        button that 403s. House rule: "อันไหนสิทธิ์ company admin ทำไม่ได้
        ต้องซ่อน ไม่ใช่ให้ error 403".
      -->
      <p
        v-if="!readiness.canFix"
        class="mt-1 text-[13px] font-bold"
        :class="isMissing ? 'text-rose-700' : 'text-amber-700'"
        data-test="commission-readiness-contact-admin"
      >
        กรุณาติดต่อผู้ดูแลระบบ
      </p>
    </div>
    <button
      v-if="readiness.canFix"
      type="button"
      class="btn-primary shrink-0 h-9"
      :class="isMissing ? 'bg-rose-600 hover:bg-rose-700' : 'bg-amber-600 hover:bg-amber-700'"
      data-test="commission-readiness-action"
      @click="goToSettings"
    >
      ไปตั้งค่าคอมมิชชั่น
    </button>
    <button
      type="button"
      class="shrink-0 p-1.5 rounded-lg transition-colors"
      :class="isMissing ? 'text-rose-500 hover:bg-rose-100' : 'text-amber-600 hover:bg-amber-100'"
      aria-label="ปิดการแจ้งเตือนนี้จนถึงพรุ่งนี้"
      title="ปิดไว้ก่อน — จะแจ้งเตือนอีกครั้งพรุ่งนี้"
      data-test="commission-readiness-dismiss"
      @click="readiness.dismiss()"
    >
      <Icon name="close" :size="16" />
    </button>
  </div>
</template>
