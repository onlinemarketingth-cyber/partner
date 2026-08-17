/**
 * ReferralRow — TASK-169 Phase 2's `hideClient` contract.
 *
 * What breaks SILENTLY if these assertions are lost:
 *
 *  1. THE HERO LINE GOES BLANK. `ClientResource` does not send `client` on
 *     its nested referrals (the client is the parent there), so the drawer
 *     renders this row with `hide-client`. If that branch regresses, the
 *     row's biggest, boldest line renders `undefined` → an empty <p>. No
 *     error, no failing build — just a deal list of nameless rows in the
 *     one place an agent goes to collect money.
 *
 *  2. THE PRODUCT NAME PRINTS TWICE. With `hideClient` the product is
 *     promoted OUT of the metadata line into the hero. Losing the
 *     `v-if="!hideClient"` on the metadata half is invisible in a build and
 *     merely looks like clutter in a screenshot, so it is asserted by
 *     COUNT, not by presence.
 *
 *  3. THE ICON STOPS MATCHING WHAT THE ROW IS ABOUT. `user_plus` when the
 *     row is a person, `cart` when the row is a product. Purely visual, so
 *     nothing else can catch it.
 *
 *  4. A SETTLED ORDER OFFERS "เก็บเงินเลย" AGAIN. The order/no-order fork
 *     decides whether an agent can create a SECOND order for a deal that
 *     already has one — collect and share are mutually exclusive by
 *     `v-if`/`v-else-if`, one wrong condition silently swaps them. (TASK-191
 *     §3.1 REVERSES the old paid-hides-share rule: the share button is now
 *     expected in EVERY order state, paid included — it is the one place a
 *     paid voucher can be re-sent, per TASK-189/190.)
 *
 * PRESENTATIONAL ONLY: this component never calls the API (see its own
 * header), so there is nothing to mock here — the row is mounted with
 * plain props and the two actions are asserted as EMITS, which is the
 * whole of its contract with the parent.
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ReferralRow, {
  type ReferralRowItem,
  type ReferralRowOrder,
} from '../ReferralRow.vue'
import Icon from '../Icon.vue'

function referralFixture(overrides: Partial<ReferralRowItem> = {}): ReferralRowItem {
  return {
    id: 1,
    client: { name: 'คุณสมชาย ใจดี' },
    co_agent: null,
    split_percentage: null,
    product: { name: 'แพ็กเกจสุขภาพประจำปี' },
    branch: 'สาขาสีลม',
    // Nullable by design (human request, 2026-07-13) — the row must render
    // "ยังไม่ระบุ" rather than an Invalid Date.
    preferred_time: null,
    current_stage: { key: 'complete_registered', label: 'Complete Registered' },
    ...overrides,
  }
}

function occurrences(haystack: string, needle: string): number {
  return haystack.split(needle).length - 1
}

/** The row's hero line — TASK-081 promoted exactly one element to text-lg. */
function heroText(wrapper: ReturnType<typeof mount>): string {
  return wrapper.find('p.text-lg').text()
}

describe('ReferralRow', () => {
  it('puts the CLIENT in the hero line when hideClient is false (the Referrals screen)', () => {
    const wrapper = mount(ReferralRow, {
      props: { referral: referralFixture() },
    })

    expect(heroText(wrapper)).toBe('คุณสมชาย ใจดี')
    // The product is supporting metadata here, not the headline.
    expect(wrapper.text()).toContain('แพ็กเกจสุขภาพประจำปี')
    expect(wrapper.text()).toContain('สาขาสีลม')
    expect(wrapper.text()).toContain('ยังไม่ระบุ')
    expect(wrapper.findComponent(Icon).props('name')).toBe('user_plus')
  })

  it('promotes the PRODUCT to the hero line when hideClient is true, exactly once (the client drawer)', () => {
    const wrapper = mount(ReferralRow, {
      props: { referral: referralFixture(), hideClient: true },
    })

    expect(heroText(wrapper)).toBe('แพ็กเกจสุขภาพประจำปี')
    // Not merely "present" — present ONCE. A duplicated product name is the
    // exact regression the metadata half's v-if guards against.
    expect(occurrences(wrapper.text(), 'แพ็กเกจสุขภาพประจำปี')).toBe(1)
    // The drawer is already titled with the client; repeating it down the
    // list is what this prop exists to stop.
    expect(wrapper.text()).not.toContain('คุณสมชาย ใจดี')
    // Still shows the rest of the metadata line.
    expect(wrapper.text()).toContain('สาขาสีลม')
    expect(wrapper.findComponent(Icon).props('name')).toBe('cart')
  })

  it('never renders an empty hero line when hideClient is true and the product is null', () => {
    // A referral whose product was deleted still has to render. Without the
    // fallback the drawer's biggest line is blank — which reads as a broken
    // screen, not as missing data.
    const wrapper = mount(ReferralRow, {
      props: { referral: referralFixture({ product: null }), hideClient: true },
    })

    expect(heroText(wrapper)).toBe('ไม่ระบุสินค้า')
  })

  it('offers exactly one action per order state — collect with no order, share once one exists in ANY status', async () => {
    const paid: ReferralRowOrder = { status: 'paid', status_label: 'ชำระเงินแล้ว' }
    const pending: ReferralRowOrder = { status: 'pending', status_label: 'รอชำระเงิน' }
    const labels = (w: ReturnType<typeof mount>) => w.findAll('button').map((b) => b.text())

    // (a) No order yet → the one-press collect action (TASK-141).
    const fresh = mount(ReferralRow, { props: { referral: referralFixture(), hideClient: true } })
    expect(labels(fresh).some((t) => t.includes('เก็บเงินเลย'))).toBe(true)
    expect(labels(fresh).some((t) => t.includes('แชร์ลิงก์ชำระเงิน'))).toBe(false)
    await fresh.find('button').trigger('click')
    expect(fresh.emitted('collect')).toHaveLength(1)

    // (b) An unpaid order → re-share the link it already has, never a second order.
    const unpaid = mount(ReferralRow, {
      props: { referral: referralFixture(), hideClient: true, order: pending },
    })
    expect(unpaid.text()).toContain('รอชำระเงิน')
    expect(labels(unpaid).some((t) => t.includes('แชร์ลิงก์ชำระเงิน'))).toBe(true)
    expect(labels(unpaid).some((t) => t.includes('เก็บเงินเลย'))).toBe(false)
    await unpaid.find('button').trigger('click')
    expect(unpaid.emitted('share')).toHaveLength(1)

    // (c) TASK-191 §3.1 — Paid REVERSES the old "no action at all" rule. The
    // status is readable AND the share button is now offered too: this link
    // is where TASK-189's post-payment voucher renders, and nothing else
    // re-surfaces it to the customer after the fact (TASK-190). Collecting a
    // SECOND order is still refused — that half of the fork is unchanged.
    const settled = mount(ReferralRow, {
      props: { referral: referralFixture(), hideClient: true, order: paid },
    })
    expect(settled.text()).toContain('ชำระเงินแล้ว')
    expect(labels(settled).some((t) => t.includes('เก็บเงินเลย'))).toBe(false)
    expect(labels(settled).some((t) => t.includes('แชร์ลิงก์ชำระเงิน'))).toBe(true)
    await settled.find('button').trigger('click')
    expect(settled.emitted('share')).toHaveLength(1)
  })
})
