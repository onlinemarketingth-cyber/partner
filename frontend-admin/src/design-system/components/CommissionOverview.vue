<script setup lang="ts">
/**
 * EVERY COMMISSION SETTING THIS COMPANY HAS, ON ONE SCREEN, BY TOPIC.
 *
 * ═══ WHY A SECOND VIEW OF THE SAME SETTINGS EARNS ITS KEEP ═══
 *
 * Owner, 2026-09-24: "การ setup ค่าต่างๆ ยังดูยาก ยุ่งยาก ไม่มีลำดับขั้นตอน".
 *
 * The four-step flow cuts along the SERVER's tables — step 2 owns the
 * structural singletons, step 3 owns `commission_rules`, step 4 owns
 * `commission_override_rules`. Three pairs of settings that must agree with
 * each other therefore sit in different tabs, where they cannot be compared
 * by eye, which is exactly how they drift apart. The worst of them is the
 * rank ladder against the seller rate: get those two out of step and the
 * chain stops paying out the top rung it promises (ADR-043).
 *
 * This screen ignores the steps entirely and groups by TOPIC, so those pairs
 * land next to one another. That regrouping — not the prettier rows — is the
 * whole reason it exists.
 *
 * ═══ WHAT IT IS NOT ═══
 *
 * It edits nothing. Every row hands its card id back to the parent, which
 * owns the forms and the writes. That boundary is deliberate: the setup
 * wizard removed on 2026-09-12 was deleted precisely because it became a
 * SECOND set of writes to the same money endpoints ("Do not re-add it"), and
 * a component that grew its own inputs would be that mistake again wearing a
 * different name.
 */
import { computed } from 'vue'
import {
  GROUP_LABELS,
  cardsByGroup,
  visibleCards,
  type CardContext,
  type CardId,
  type CardStatus,
  type CommissionPlanType,
} from '@/constants/commissionCards'

const props = defineProps<{
  planType: CommissionPlanType
  context: CardContext
  /** May this admin change anything? A read-only Company Admin may not. */
  canEdit: boolean
  /**
   * What one sale pays out under the settings as they stand, for the summary
   * strip. Null while it cannot be computed — no plan chosen, no product.
   */
  payout: { baseSatang: number; totalSatang: number; totalPct: number } | null
}>()

const emit = defineEmits<{ edit: [cardId: CardId] }>()

const groups = computed(() => cardsByGroup(props.planType, props.context))

const cards = computed(() => visibleCards(props.planType, props.context))

/**
 * "8 จาก 9" counts only what a company must answer.
 *
 * Optional settings are excluded from both halves on purpose — a counter that
 * reads 8/11 because nobody set a withholding-tax rate tells an admin they
 * are unfinished when they are not, which is the failure the blanket amber
 * banner had before TASK-213.
 */
const required = computed(() => cards.value.filter((card) => card.status(props.context) !== 'optional'))
const answered = computed(() => required.value.filter((card) => card.status(props.context) === 'done').length)
const needsReview = computed(() => required.value.filter((card) => card.status(props.context) === 'review').length)
const unanswered = computed(() => required.value.filter((card) => card.status(props.context) === 'todo').length)

/**
 * RED IS RESERVED FOR ONE SENTENCE BEING TRUE: a deal closing right now pays
 * nobody. That is the same line CommissionReadinessService draws server-side,
 * and drawing it differently here would give the admin two answers about
 * money — the thing that whole Service exists to prevent.
 */
const tone = computed<'ready' | 'incomplete' | 'missing'>(() => {
  if (props.context.companyDefaultRatePct === null && props.planType !== 'stairstep_breakaway') return 'missing'
  if (unanswered.value > 0) return 'incomplete'
  if (needsReview.value > 0) return 'incomplete'

  return 'ready'
})

const STATUS_LABELS: Record<CardStatus, string> = {
  done: 'ตั้งแล้ว',
  todo: 'ยังไม่ตั้ง',
  review: 'ควรทบทวน',
  optional: 'ไม่บังคับ',
}

const STATUS_CLASSES: Record<CardStatus, string> = {
  done: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  todo: 'bg-rose-50 text-rose-700 border-rose-200',
  review: 'bg-amber-50 text-amber-700 border-amber-300',
  optional: 'bg-slate-100 text-slate-500 border-slate-200',
}

const baht = (satang: number): string => (satang / 100).toLocaleString('th-TH')

/**
 * The line under "เงินของคนขาย" that says whether the two rates agree.
 *
 * Only Stairstep has the pairing at all: there a ranked agent is paid their
 * rung and an un-ranked one the flat rate, so the two must match or a new
 * recruit's first sales push the chain's total off the top rung.
 */
const sellerPairing = computed<{ ok: boolean; note: string } | null>(() => {
  if (props.planType !== 'stairstep_breakaway') return null

  const entry = props.context.ranks.find((rank) => rank.thresholdSatang === 0)

  if (!entry || entry.ratePct === null || props.context.companyDefaultRatePct === null) return null

  const ok = entry.ratePct === props.context.companyDefaultRatePct

  return {
    ok,
    note: ok
      ? 'ตรงกันแล้ว'
      : `ไม่ตรงกัน — ดีลแรกของตัวแทนใหม่จะทำให้ยอดรวมไม่เท่ากับอัตราขั้นสูงสุด`,
  }
})
</script>

<template>
  <div class="space-y-4" data-test="commission-overview">
    <div
      class="flex flex-wrap items-center gap-x-6 gap-y-4 rounded-2xl px-5 py-5 sm:px-6"
      :class="tone === 'missing' ? 'bg-rose-900' : 'bg-slate-900'"
      data-test="overview-summary"
    >
      <div class="flex-1 min-w-[240px] space-y-1">
        <p class="text-[12.5px] tracking-wide text-slate-400">
          <template v-if="payout">ตั้งมาทั้งหมดแล้ว ดีล ฿{{ baht(payout.baseSatang) }} จ่ายออกรวม</template>
          <!--
            Not every plan can state one total from settings alone — Binary
            pays on a cycle, Matrix and Generation on a shape. Rather than
            duplicate five engines here to put a number in a strip, those
            plans are pointed at the worked example that already does it.
          -->
          <template v-else>ดูยอดจ่ายต่อดีลของแผนนี้ได้ที่ผังการจ่าย ด้านล่างของขั้นที่ 2</template>
        </p>
        <p v-if="tone === 'missing'" class="text-[13px] font-bold text-rose-200" data-test="overview-nobody-paid">
          ดีลที่ปิดได้ตอนนี้จะไม่มีใครได้เงิน
        </p>
      </div>

      <div v-if="payout" class="text-left sm:text-right" data-test="overview-total">
        <div class="text-[30px] font-bold leading-tight tabular-nums text-emerald-400">฿{{ baht(payout.totalSatang) }}</div>
        <div class="text-[13px] text-slate-400">{{ payout.totalPct }}% ของฐานที่ใช้คิด</div>
      </div>

      <!-- Decoration only, and only while the two figures are side by side:
           once the strip wraps on a narrow screen it becomes a stray line. -->
      <div class="hidden h-[52px] w-px bg-slate-700 sm:block" aria-hidden="true"></div>

      <div class="text-left sm:text-right" data-test="overview-progress">
        <div
          class="text-[30px] font-bold leading-tight tabular-nums"
          :class="answered === required.length ? 'text-emerald-400' : 'text-amber-400'"
        >
          {{ answered }} / {{ required.length }}
        </div>
        <div class="text-[13px] text-slate-400">
          ตั้งแล้ว<template v-if="needsReview > 0"> · เหลือ {{ needsReview }} ข้อควรทบทวน</template>
        </div>
      </div>
    </div>

    <div v-for="group in groups" :key="group.group" class="space-y-2">
      <p class="px-1 text-[12px] font-bold uppercase tracking-wider text-slate-500">
        {{ GROUP_LABELS[group.group] }}
      </p>

      <div
        class="overflow-hidden rounded-2xl border bg-white"
        :class="group.group === 'seller' && sellerPairing ? 'border-l-4 border-brand-600 border-brand-200' : 'border-slate-200'"
      >
        <!--
          The pairing banner appears on ONE group and only on the plan that
          has the pairing. A decorative strip on every group would make the
          one that matters invisible.
        -->
        <div
          v-if="group.group === 'seller' && sellerPairing"
          class="flex flex-wrap items-center gap-3 px-5 py-2.5"
          :class="sellerPairing.ok ? 'bg-brand-50' : 'bg-amber-50'"
          data-test="overview-seller-pairing"
        >
          <span class="text-[12.5px] font-bold" :class="sellerPairing.ok ? 'text-brand-700' : 'text-amber-800'">
            สองค่านี้ต้องตรงกัน
          </span>
          <span class="flex-1 text-[12.5px]" :class="sellerPairing.ok ? 'text-brand-600' : 'text-amber-700'">
            {{ sellerPairing.note }}
          </span>
        </div>

        <div
          v-for="card in group.cards"
          :key="card.id"
          class="grid gap-2 border-b border-slate-100 px-4 py-3.5 last:border-b-0 sm:grid-cols-[minmax(0,230px)_minmax(0,1fr)_auto_auto] sm:items-center sm:gap-4 sm:px-5"
          :class="card.status(context) === 'review' ? 'bg-amber-50/60' : ''"
          :data-test="`overview-row-${card.id}`"
        >
          <span class="text-[13.5px] font-bold text-slate-900">{{ card.question }}</span>

          <span class="min-w-0">
            <span class="block text-[13.5px] text-slate-700">{{ card.answer(context) }}</span>
            <!--
              CLAUDE.md Section 7 puts long explanations behind an ⓘ, never
              inline. This is the exception that rule allows for: one line,
              shown only when the setting is NOT settled, saying what happens
              if it is left alone. An admin scanning for what to fix should
              not have to open anything to find out why a row is amber.
            -->
            <span
              v-if="card.status(context) === 'review' || card.status(context) === 'todo'"
              class="mt-1 block text-[12px] leading-relaxed"
              :class="card.status(context) === 'review' ? 'text-amber-800' : 'text-slate-500'"
              :data-test="`overview-why-${card.id}`"
            >
              {{ card.why }}
            </span>
          </span>

          <span
            class="justify-self-start rounded-full border px-3 py-1 text-[11.5px] font-bold"
            :class="STATUS_CLASSES[card.status(context)]"
          >
            {{ STATUS_LABELS[card.status(context)] }}
          </span>

          <button
            v-if="canEdit"
            type="button"
            class="justify-self-start rounded-lg border border-slate-300 bg-white px-4 py-1.5 text-[12.5px] font-bold text-slate-700 hover:border-brand-600 hover:text-brand-600 sm:justify-self-end"
            :data-test="`overview-edit-${card.id}`"
            @click="emit('edit', card.id)"
          >
            แก้ไข
          </button>
          <span v-else class="justify-self-start text-[12px] text-slate-400 sm:justify-self-end">ดูได้อย่างเดียว</span>
        </div>
      </div>
    </div>
  </div>
</template>
