/**
 * CommissionSplitSettingCard — TASK-174's per-company switch, and spec §6's
 * pre-enable warning.
 *
 * WHY THIS FILE EXISTS. §6 is the one part of TASK-174 that is not "hide a
 * thing": turning the switch back ON makes every still-unpaid referral that
 * kept a stored `co_agent_id` resume splitting — money behaviour changing on
 * deals nobody touched. That is deliberate (the data was preserved on
 * purpose, §3), but the spec is explicit that it "must not be a surprise".
 *
 * What breaks SILENTLY if these assertions are lost:
 *
 *  1. THE WARNING DISAPPEARS. Nothing errors. An admin flips a switch, saves,
 *     and N deals start paying two people instead of one — discovered at
 *     payout, which is exactly the audit problem this task exists to remove.
 *
 *  2. THE WARNING FIRES AT THE WRONG TIME. Shown while it is already on, it
 *     is noise that gets ignored; shown while turning it OFF it is simply
 *     wrong (D1: switching off never splits, so nothing resumes).
 *
 *  3. A MISSING COUNT RENDERS AS A REASSURING ZERO. The API omits
 *     `pending_referrals_with_stored_split` for non-admins, and `?? 0` on it
 *     would tell the person flipping a money switch that nothing will change
 *     when in truth nobody measured.
 *
 *  4. THE COUNT GOES STALE. It describes the state BEFORE the flip, so the
 *     card re-reads after saving rather than trusting what it loaded once.
 *
 * The API is mocked at `@/api/client`; the setting, its authorization and its
 * tenant isolation are enforced and tested server-side
 * (CommissionSplitSettingController, UpdateCommissionSplitSettingRequest, BR-6).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()

const { FakeApiError } = vi.hoisted(() => ({
  FakeApiError: class extends Error {
    constructor(
      public status: number,
      public body: unknown,
    ) {
      super(`API error ${status}`)
    }
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
  ApiError: FakeApiError,
}))

import CommissionSplitSettingCard from '../CommissionSplitSettingCard.vue'

const WARNING_HEADLINE = 'กำลังจะเปิดกลับมา'

interface SettingPayload {
  is_enabled: boolean
  pending_referrals_with_stored_split?: number
}

/** Company Admin by default — no company picker, company_id resolved server-side. */
async function mountCard(
  payload: SettingPayload,
  { isSuperAdmin = false, companyId = null }: { isSuperAdmin?: boolean; companyId?: number | null } = {},
) {
  get.mockResolvedValue({ data: payload })
  const wrapper = mount(CommissionSplitSettingCard, {
    props: { companyId, isSuperAdmin },
    global: { stubs: { Icon: true } },
  })
  await flushPromises()
  return wrapper
}

/** The switch is the only non-submit button on the card. */
function toggle(wrapper: Awaited<ReturnType<typeof mountCard>>) {
  const button = wrapper
    .findAll('button')
    .find((b) => b.attributes('aria-label')?.includes('การแบ่งคอมมิชชั่น'))
  if (!button) throw new Error('no on/off toggle on the card')
  return button
}

/**
 * Presses บันทึก. The card saves on the FORM's submit (same shape as the
 * neighbouring cards on ThemeSettingsView), and jsdom does not synthesise a
 * submit event from a click on a `type="submit"` button — so the button's
 * existence is asserted here and the submit is dispatched on the form, which
 * is the event a real press actually produces.
 */
async function save(wrapper: Awaited<ReturnType<typeof mountCard>>) {
  const button = wrapper.findAll('button').find((b) => b.attributes('type') === 'submit')
  if (!button) throw new Error('no save button on the card')
  expect(button.text()).toContain('บันทึก')
  await wrapper.find('form').trigger('submit')
  await flushPromises()
}

describe('CommissionSplitSettingCard (TASK-174 §6)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows the pending count BEFORE enabling — not before, not after the flip', async () => {
    const wrapper = await mountCard({
      is_enabled: false,
      pending_referrals_with_stored_split: 12,
    })

    // Off and untouched: nothing is about to change, so no warning.
    expect(wrapper.text()).not.toContain(WARNING_HEADLINE)

    await toggle(wrapper).trigger('click')

    // Flipped to ON but NOT SAVED — this is the moment the admin must see it.
    expect(wrapper.text()).toContain(WARNING_HEADLINE)
    expect(wrapper.text()).toContain('12')
    // And it says what the number means, not just the number.
    expect(wrapper.text()).toContain('จะกลับมาแบ่งคอมมิชชั่นทันทีที่กดบันทึก')

    // Flipping back cancels the intent, so the warning goes with it.
    await toggle(wrapper).trigger('click')
    expect(wrapper.text()).not.toContain(WARNING_HEADLINE)
  })

  it('does NOT warn when the split is already on, including when turning it OFF', async () => {
    const wrapper = await mountCard({
      is_enabled: true,
      pending_referrals_with_stored_split: 12,
    })

    expect(wrapper.text()).not.toContain(WARNING_HEADLINE)

    // Turning it off is D1's "do not split" direction — nothing resumes.
    await toggle(wrapper).trigger('click')
    expect(wrapper.text()).not.toContain(WARNING_HEADLINE)
  })

  it('says it does not know rather than printing 0 when the API omits the count', async () => {
    const wrapper = await mountCard({ is_enabled: false })

    await toggle(wrapper).trigger('click')

    expect(wrapper.text()).toContain(WARNING_HEADLINE)
    expect(wrapper.text()).toContain('ระบบไม่ได้ส่งจำนวนดีลที่ค้างอยู่มาให้')
    // A confident zero here would read as "nothing will change".
    expect(wrapper.text()).not.toContain('จะกลับมาแบ่งคอมมิชชั่นทันทีที่กดบันทึก')
  })

  it('PUTs the new value and re-reads, so the count is never one save out of date', async () => {
    const wrapper = await mountCard({
      is_enabled: false,
      pending_referrals_with_stored_split: 12,
    })
    await toggle(wrapper).trigger('click')

    put.mockResolvedValue({ data: { is_enabled: true, pending_referrals_with_stored_split: 0 } })
    get.mockResolvedValue({ data: { is_enabled: true, pending_referrals_with_stored_split: 0 } })
    get.mockClear()

    await save(wrapper)

    // A Company Admin never puts company_id on the wire — the server resolves
    // it from the session (BR-6).
    expect(put).toHaveBeenCalledWith('/commission-split-settings', { is_enabled: true })
    expect(get).toHaveBeenCalledWith('/commission-split-settings')
    // Saved state is now ON, so the pre-enable warning is no longer "about to".
    expect(wrapper.text()).not.toContain(WARNING_HEADLINE)
  })

  it('scopes the read and the write to the picked company for a Super Admin', async () => {
    const wrapper = await mountCard(
      { is_enabled: false, pending_referrals_with_stored_split: 3 },
      { isSuperAdmin: true, companyId: 42 },
    )

    expect(get).toHaveBeenCalledWith('/commission-split-settings?company_id=42')

    await toggle(wrapper).trigger('click')
    put.mockResolvedValue({ data: { is_enabled: true } })
    await save(wrapper)

    expect(put).toHaveBeenCalledWith('/commission-split-settings', {
      company_id: 42,
      is_enabled: true,
    })
  })

  it('asks a Super Admin to pick a company first instead of reading anybody’s setting', async () => {
    const wrapper = mount(CommissionSplitSettingCard, {
      props: { companyId: null, isSuperAdmin: true },
      global: { stubs: { Icon: true } },
    })
    await flushPromises()

    expect(get).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('เลือกบริษัทด้านบนก่อน')
  })

  it('renders as OFF when the setting cannot be read (fail closed)', async () => {
    get.mockRejectedValue(new FakeApiError(500, null))
    const wrapper = mount(CommissionSplitSettingCard, {
      props: { companyId: null, isSuperAdmin: false },
      global: { stubs: { Icon: true } },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('โหลดค่าตั้งการแบ่งคอมมิชชั่นไม่สำเร็จ')
    // An unreadable money switch must not render as a confident "on".
    expect(toggle(wrapper).attributes('title')).toBe('ปิดใช้งาน')
  })
})
