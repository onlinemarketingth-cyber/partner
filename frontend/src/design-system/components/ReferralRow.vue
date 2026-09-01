<script setup lang="ts">
import { useI18n } from '@/composables/useI18n'
const { td } = useI18n()

/**
 * ReferralRow — one deal in a referral list: client, product, branch,
 * preferred time, current pipeline stage, and TASK-141's one-press
 * "เก็บเงินเลย".
 *
 * Extracted verbatim from ReferralsView.vue (TASK-169 Phase 1) so the same
 * row can be mounted wherever a deal list is shown. No visual change —
 * purely a component boundary.
 *
 * PRESENTATIONAL ONLY: it never calls the API. The parent owns the order
 * map and performs POST /orders, because the order-loading strategy
 * (paging, the duplicate-order 422 path) belongs in one place, not once
 * per call site. TASK-026's co-agent controls stay with the parent too,
 * through the two slots.
 */
import AppCard from './AppCard.vue'
import AppButton from './AppButton.vue'
import Icon from './Icon.vue'
import { computed } from 'vue'
// ADR-026 / CLAUDE.md §7 — the API sends stage labels in ENGLISH by design;
// the Thai belongs to the UI. This row used to print `current_stage.label`
// raw, so one stage read "ลงทะเบียนสำเร็จ" on the pipeline board and
// "Complete Registered" here. One translator, shared with PipelineBoard —
// a second copy is how the two drift apart again.
import { stageLabelTh } from '@/utils/pipelineStages'

export type ReferralRowOrderStatus = 'pending' | 'awaiting_verification' | 'paid' | 'cancelled'

/** Only what the row RENDERS — never the full OrderResource. */
export interface ReferralRowOrder {
  id: number
  order_number: string
  status: ReferralRowOrderStatus
  status_label: string
  /**
   * Has the customer attached a payment slip?
   *
   * This row used to print "รอตรวจสอบสลิป" and offer no way to look at the
   * slip it was naming (human report, 2026-08-21: "ลูกค้าแนบสลิปแล้วแต่
   * Agent เช็คไม่ได้"). The capability was never missing — OrdersView has
   * had a ดูสลิป button since TASK-054 and OrderPolicy::view() has always
   * let an agent read their own order's slip. It was simply not HERE, in
   * the drawer where an agent actually works a client.
   */
  has_slip: boolean
}

export interface ReferralRowItem {
  id: number
  // Optional, not just nullable: ClientResource's nested referrals carry
  // neither relation (the client is the parent there, and coAgent is not
  // eager-loaded), so both keys are simply absent in the client drawer.
  client?: { name: string } | null
  // TASK-026 — null unless this referral's commission is split.
  // TASK-174 — and ABSENT, both of them, while the company's split is
  // switched off: `ReferralResource` drops the pair rather than nulling it,
  // so the read-only "แบ่งคอมฯ กับ …" line below disappears with it. That is
  // the whole mechanism by which this row stops showing a stale split — no
  // flag is threaded down here, because the server already answered.
  co_agent?: { name: string } | null
  split_percentage?: number | null
  product: { name: string } | null
  branch: string
  // Nullable — human request (2026-07-13): "เวลาที่สะดวกนัดไม่ต้อง validate".
  preferred_time: string | null
  current_stage: { key: string; label: string }
}

const props = defineProps<{
  referral: ReferralRowItem
  /** The referral's current ACTIVE order, if the parent has one. */
  order?: ReferralRowOrder | null
  /** This row's own "เก็บเงินเลย" request is in flight. */
  collecting?: boolean
  /** Another row's request is in flight — only one at a time. */
  collectDisabled?: boolean
  /** Failure of this row's pay action, shown next to the button. */
  payError?: string | null
  /**
   * TASK-169 Phase 2 — the surrounding UI already names the client (the
   * client drawer is titled with it), so the product takes the hero line
   * instead. Without this the drawer would repeat one name down the list
   * and, since ClientResource does not send `client` on its nested
   * referrals, that line would be blank.
   */
  hideClient?: boolean
}>()

defineEmits<{
  collect: []
  share: []
  /** Open the attached slip. Only ever emitted when `order.has_slip`. */
  viewSlip: []
}>()

// Chip colours and labels are OrdersView.vue's, verbatim. `status_label`
// comes from the API (OrderStatus::label()), so no screen can word the
// same state differently and no status vocabulary is invented here.
const STATUS_CHIP: Record<ReferralRowOrderStatus, string> = {
  pending: 'bg-surface-warning text-ink-warning border-line-card',
  awaiting_verification: 'bg-brand-50 text-brand-700 border-brand-200',
  paid: 'bg-surface-success text-ink-success border-line-card',
  cancelled: 'bg-surface-chip text-ink-card-muted border-line-card',
}

const orderChipClass = computed(() => (props.order ? STATUS_CHIP[props.order.status] : ''))

function formatDateTime(iso: string | null): string {
  if (!iso) return 'ยังไม่ระบุ'
  return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <AppCard variant="flat">
    <!-- Flex-squeeze bug fix (2026-08-03, human-reported at 768px: the
         client name wrapped to ONE CHARACTER PER LINE). The name column
         needs `flex-1 min-w-0` and the action column `shrink-0`, or the
         `whitespace-nowrap` stage chip wins the width and crushes the name.
         Stacking below `sm` as well, mobile-first: at 375px a long stage
         label ("Finish 1st Doctor Meeting") leaves too little room for a
         name on the same line however the flex is tuned. -->
    <div class="flex flex-col gap-2">
      <!-- TASK-081 (typography audit): the client name is the one thing the
           agent scans a deal list for — promoted to text-lg;
           branch/preferred-time/product and the co-agent split line are
           supporting metadata. -->
      <div class="flex items-start gap-3 min-w-0 flex-1">
        <Icon :name="hideClient ? 'cart' : 'user_plus'" :size="18" class="text-ink-brand mt-1 shrink-0" />
        <div class="min-w-0">
          <p class="text-lg font-bold text-ink-card leading-tight">
            {{ hideClient ? (referral.product?.name ?? 'ไม่ระบุสินค้า') : referral.client?.name }}
          </p>
          <p class="text-xs text-ink-card-muted mt-0.5">
            <template v-if="!hideClient">{{ referral.product?.name }} · </template>{{ referral.branch }} · เวลาที่สะดวก {{ formatDateTime(referral.preferred_time) }}
          </p>
          <!-- TASK-026 -->
          <p v-if="referral.co_agent" class="text-xs text-ink-card-muted">
            แบ่งคอมฯ กับ {{ referral.co_agent.name }} ({{ referral.split_percentage }}%)
          </p>
        </div>
      </div>
      <!-- TASK-141 — `flex-wrap` matters: this row carries up to four
           controls (co-agent, stage chip, order-status chip, pay action) and
           at 375px they must wrap onto a second line rather than crush the
           stage label. -->
      <div class="flex flex-wrap items-center gap-2 shrink-0 pl-8">
        <!-- TASK-026's co-agent button (the parent owns the edit cutoff). -->
        <slot name="actions-start" />
        <!--
          A STATUS, NOT AN ACTION. It used to be a solid `bg-brand-50` pill
          sitting immediately left of "เก็บเงินเลย" — on a dark tenant theme
          that made it the highest-contrast element in the row, so it read as
          the primary button and did nothing when pressed. A label that looks
          more pressable than the real button is worse than no label.
          Muted chip + an explicit "ขั้นตอน:" prefix says what it is.
        -->
        <span class="text-xs text-ink-card-muted bg-surface-chip border border-line-card px-2 py-1 rounded-lg whitespace-nowrap">
          {{ td('pipeline.step') }} <span class="font-bold text-ink-card">{{ stageLabelTh(referral.current_stage) }}</span>
        </span>

        <!-- TASK-141 requirement 3 — the payment state is readable WITHOUT
             pressing anything. Same chip shape/colours as the order list. -->
        <span
          v-if="order"
          class="text-[11px] font-bold px-2 py-0.5 rounded-full border whitespace-nowrap"
          :class="orderChipClass"
        >
          {{ order.status_label }}
        </span>

        <!-- TASK-191 §3.1 — REVERSES the previous "paid: no action at all"
             rule. That reasoning ("re-sharing a settled pay link only
             confuses a customer who has already paid") no longer holds:
             TASK-189 made this same link the one place a paid VOUCHER
             renders, and TASK-190 exists specifically because nothing else
             re-surfaces that link to a customer after payment. So the
             button now shows in EVERY order status, not just before
             payment — label/click handler unchanged either way. -->
        <!--
             SEE THE SLIP THE ROW IS TALKING ABOUT (human, 2026-08-21).

             The chip above already says "รอตรวจสอบสลิป". Naming a document
             and giving no way to open it is the part that reads as broken:
             the agent collected this payment, the customer told them the
             slip was sent, and the screen agreed — while offering nothing to
             press.

             The slip WAS reachable, on /orders, which an agent gets to from
             the Home quick links. That is the wrong place: this drawer is
             where an agent works one client, and making them leave it, find
             the order in a list and come back is why it read as "เช็คไม่ได้".

             FIRST in the row on purpose. Looking at what the customer sent
             comes before re-sharing a link to a bill they have already paid.
        -->
        <AppButton
          v-if="order?.has_slip"
          variant="secondary"
          size="sm"
          @click="$emit('viewSlip')"
        >
          <Icon name="download" :size="14" />
          {{ td('order.view_slip') }}
        </AppButton>

        <AppButton
          v-if="order"
          variant="secondary"
          size="sm"
          @click="$emit('share')"
        >
          <Icon name="share" :size="14" />
          {{ td('order.share_pay_link') }}
        </AppButton>
        <!-- ONE press: creates the order AND opens the share sheet on the
             returned pay link. -->
        <AppButton
          v-else-if="!order"
          size="sm"
          :loading="collecting"
          :disabled="collectDisabled"
          @click="$emit('collect')"
        >
          <Icon name="money" :size="14" />
          {{ td('pipeline.collect_now') }}
        </AppButton>
      </div>
    </div>

    <!-- Per-row failure, next to the button that caused it — same pattern as
         OrdersView.vue's actionError banner. -->
    <div
      v-if="payError"
      class="mt-2 ml-8 flex items-start gap-2 rounded-lg bg-surface-danger border border-line-card px-3 py-2 text-xs text-ink-danger"
    >
      <Icon name="alert" :size="14" class="mt-0.5 shrink-0" />
      <span>{{ payError }}</span>
    </div>

    <!-- TASK-026's inline co-agent editor. -->
    <slot name="footer" />
  </AppCard>
</template>
