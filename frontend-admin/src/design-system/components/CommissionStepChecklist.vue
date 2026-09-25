<script setup lang="ts">
/**
 * WHAT THIS STEP IS STILL ASKING — beside the step, not instead of it.
 *
 * ═══ WHY THE STEP FLOW NEEDS THIS AT ALL ═══
 *
 * Owner, 2026-09-24: "ไม่มีลำดับขั้นตอน".
 *
 * A step on this screen is not one question. Step 2 can hold the plan chip,
 * the basis, the PV table, a rank ladder and three structural settings at
 * once — a wall of controls whose only ordering is the order they were built
 * in. The step pill above says done / ยังไม่ครบ, which tells an admin THAT
 * something is missing without ever saying WHICH.
 *
 * This rail says which, in the same question-shaped words the overview uses,
 * and jumps to it. It is the step flow's half of B+C1: the overview groups by
 * topic across the whole screen, this groups by step within one.
 *
 * ═══ WHAT IT IS NOT ═══
 *
 * It owns no input, exactly like CommissionOverview — the setup wizard
 * deleted on 2026-09-12 became a SECOND set of writes to the same money
 * endpoints, and the note where it stood says "Do not re-add it". Every row
 * hands a card id up; the step's own forms keep the writes.
 *
 * It is also not a second copy of the step's contents: it lists the DECISIONS
 * the step owns, never the controls. A row per control would drift the moment
 * a form grows a field, and would say nothing the form does not already say.
 */
import { computed } from 'vue'
import {
  stepProgress,
  visibleCards,
  type CardContext,
  type CardId,
  type CardStatus,
  type CommissionPlanType,
} from '@/constants/commissionCards'

const props = defineProps<{
  planType: CommissionPlanType
  context: CardContext
  step: 2 | 3 | 4
  /** A read-only Company Admin still navigates; they just cannot change. */
  canEdit: boolean
}>()

const emit = defineEmits<{ focus: [cardId: CardId] }>()

const cards = computed(() => visibleCards(props.planType, props.context).filter((card) => card.step === props.step))

const progress = computed(() => stepProgress(props.planType, props.context, props.step))

/**
 * The first row that still wants an answer — the one thing this rail exists
 * to name. `todo` outranks `review` because an unanswered setting can leave a
 * deal paying nobody, while a flagged one is at least paying something.
 */
const nextUp = computed<CardId | null>(() => {
  const todo = cards.value.find((card) => card.status(props.context) === 'todo')

  if (todo) return todo.id

  return cards.value.find((card) => card.status(props.context) === 'review')?.id ?? null
})

const STATUS_DOT: Record<CardStatus, string> = {
  done: 'bg-emerald-500',
  todo: 'bg-rose-500',
  review: 'bg-amber-500',
  optional: 'bg-slate-300',
}

const STATUS_LABELS: Record<CardStatus, string> = {
  done: 'ตั้งแล้ว',
  todo: 'ยังไม่ตั้ง',
  review: 'ควรทบทวน',
  optional: 'ไม่บังคับ',
}
</script>

<template>
  <aside class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4" data-test="step-checklist">
    <div class="flex items-baseline justify-between gap-2">
      <p class="text-[12px] font-bold uppercase tracking-wider text-slate-500">ขั้นนี้ถามอะไรบ้าง</p>
      <!--
        Counts only what must be answered — see stepProgress()'s own note. A
        rail reading 2/4 because nobody set a withholding-tax rate would be
        telling an admin they are unfinished when they are not.
      -->
      <span
        class="shrink-0 text-[12px] font-bold tabular-nums"
        :class="progress.answered === progress.total ? 'text-emerald-600' : 'text-amber-600'"
        data-test="step-checklist-progress"
      >
        {{ progress.answered }}/{{ progress.total }}
      </span>
    </div>

    <ul class="mt-3 space-y-1">
      <li v-for="card in cards" :key="card.id">
        <button
          type="button"
          class="w-full rounded-xl px-3 py-2.5 text-left transition-colors hover:bg-white"
          :class="card.id === nextUp ? 'bg-white ring-1 ring-brand-300' : ''"
          :data-test="`step-checklist-${card.id}`"
          @click="emit('focus', card.id)"
        >
          <span class="flex items-start gap-2.5">
            <span
              class="mt-[6px] h-2 w-2 shrink-0 rounded-full"
              :class="STATUS_DOT[card.status(context)]"
              :aria-label="STATUS_LABELS[card.status(context)]"
            ></span>
            <span class="min-w-0 flex-1">
              <span class="block text-[13px] font-bold leading-snug text-slate-800">{{ card.question }}</span>
              <span class="mt-0.5 block truncate text-[12px] text-slate-500">{{ card.answer(context) }}</span>
            </span>
          </span>
        </button>
      </li>
    </ul>

    <!--
      One line, only while something is outstanding, naming the row rather
      than repeating the count. CLAUDE.md Section 7 sends long explanations
      behind an ⓘ; this is a pointer, not an explanation.
    -->
    <p v-if="nextUp && canEdit" class="mt-3 px-1 text-[12px] leading-relaxed text-slate-500" data-test="step-checklist-next">
      ถัดไป: {{ cards.find((card) => card.id === nextUp)?.question }}
    </p>
  </aside>
</template>
