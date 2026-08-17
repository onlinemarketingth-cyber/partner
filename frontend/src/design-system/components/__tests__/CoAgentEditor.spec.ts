/**
 * CoAgentEditor — TASK-026's split-commission control, as rehomed by
 * TASK-169 Phase 4a.
 *
 * WHY THIS FILE IS NOT OPTIONAL. These two fields decide WHO GETS PAID:
 * when the referral reaches Complete Payment, CommissionService writes two
 * immutable BR-4 ledger rows off `co_agent_id` + `split_percentage` instead
 * of one. Nothing else in the Agent Portal can set them after creation.
 *
 * What breaks SILENTLY if these assertions are lost:
 *
 *  1. THE WRONG AGENT IS PAID. The payload is two nullable fields and the
 *     mapping between the form and them is the whole feature. Sending
 *     `split_percentage` while `co_agent_id` is null (or vice versa) is
 *     rejected by SetCoAgentRequest's both-or-neither rule, so a broken
 *     mapping shows up as an unexplained 422 — but sending the WRONG
 *     NUMBER is accepted, and nobody finds out until payout.
 *
 *  2. A SPLIT CAN NO LONGER BE REMOVED. "ไม่แบ่งคอมมิชชั่น" must send BOTH
 *     fields as null. If the percentage lingers, the clear either 422s or —
 *     worse — silently keeps a split the agent believes they deleted.
 *
 *  3. THE 1–99 GUARD DISAPPEARS. The percentage box is disabled until a
 *     co-agent is picked and Save is disabled until a percentage is typed.
 *     Losing either turns a validation the agent can see into a 422 they
 *     cannot act on.
 *
 *  4. THE CONTROL OUTLIVES THE CUTOFF. Past Complete Payment the ledger row
 *     already exists and BR-4 forbids rewriting it; the server refuses. A
 *     control offered there is a control that can only fail.
 *
 *  5. A FAILED WRITE READS AS A SUCCESSFUL ONE. The 422 branch carries the
 *     server's own reason (the cutoff, "must be a different agent", the
 *     range). If `saved` fires on a rejection, the host re-fetches, shows
 *     the OLD split, and the agent has no idea the write did not happen.
 *
 * The API is mocked at `@/api/client` — this asserts the component's wiring,
 * not the backend's rules. Those are enforced and tested server-side
 * (ReferralService::setCoAgent, SetCoAgentRequest, ReferralPolicy / BR-6).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const patch = vi.fn()

/**
 * The real ApiError is exported from the module being mocked, so the 422
 * branch needs a stand-in `instanceof` still recognises (same trick as
 * ClientsView.spec.ts).
 */
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
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: (...args: unknown[]) => patch(...args),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: FakeApiError,
}))

import CoAgentEditor, { type CoAgentEditorReferral } from '../CoAgentEditor.vue'
import AppButton from '../AppButton.vue'

const OPTIONS = [
  { id: 7, name: 'ตัวแทน ก' },
  { id: 8, name: 'ตัวแทน ข' },
]

function referralFixture(overrides: Partial<CoAgentEditorReferral> = {}): CoAgentEditorReferral {
  return {
    id: 1,
    co_agent: null,
    split_percentage: null,
    current_stage: { key: 'complete_registered', label: 'Complete Registered' },
    ...overrides,
  }
}

function mountEditor(referral: CoAgentEditorReferral = referralFixture()) {
  return mount(CoAgentEditor, { props: { referral, options: OPTIONS } })
}

/** The trigger is the only plain <button> until the editor is open. */
function trigger(wrapper: ReturnType<typeof mountEditor>) {
  const button = wrapper.findAll('button').find((b) => b.text().includes('คอมฯ'))
  if (!button) throw new Error('no co-agent trigger on the row')
  return button
}

async function openEditor(wrapper: ReturnType<typeof mountEditor>) {
  await trigger(wrapper).trigger('click')
  return wrapper
}

/** AppButton renders a real <button :disabled>, so this reads the DOM. */
function saveButton(wrapper: ReturnType<typeof mountEditor>) {
  return wrapper.findComponent(AppButton).find('button')
}

/**
 * Choose the <option> at `index` on the editor's single <select>.
 *
 * The value is READ OFF that option rather than written here, so these tests
 * still assert against whatever the component actually bound — renaming an id
 * in the fixture cannot leave an assertion passing against a stale literal.
 */
async function selectOption(wrapper: ReturnType<typeof mountEditor>, index: number) {
  const option = wrapper.findAll('option')[index]
  if (!option) throw new Error(`no <option> at index ${index}`)
  await wrapper.find('select').setValue((option.element as HTMLOptionElement).value)
}

describe('CoAgentEditor (TASK-026 split commission — TASK-169 Phase 4a)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('SETS a co-agent with the exact PATCH ReferralsView has always sent', async () => {
    const wrapper = await openEditor(mountEditor())

    // Selected by OPTION, not by typing a literal: the <option> binds the raw
    // numeric id, and asserting the payload is only meaningful if the value
    // travels the same way it does for a real thumb. The value is READ OFF the
    // option rather than written here, so a change to the fixture's ids cannot
    // make this test pass against a stale number.
    //
    // Why not `findAll('option')[1].setSelected()`, which is what this used to
    // say: @vue/test-utils made `DOMWrapper.setSelected` private, so it stopped
    // type-checking (TS2341, seven of them across two spec files). Setting the
    // <select>'s value is the supported equivalent and fires the same `change`.
    await selectOption(wrapper, 1)
    await wrapper.find('input[type="number"]').setValue('30')
    patch.mockResolvedValue({})

    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(patch).toHaveBeenCalledWith('/referrals/1/co-agent', {
      co_agent_id: 7,
      split_percentage: 30,
    })
    // Integers on the wire, never the strings the form holds — the backend
    // validates `integer` and a "30" would be a silent 422.
    const [, body] = patch.mock.calls[0] as [string, Record<string, unknown>]
    expect(typeof body.co_agent_id).toBe('number')
    expect(typeof body.split_percentage).toBe('number')

    expect(wrapper.emitted('saved')).toHaveLength(1)
    expect(wrapper.emitted('error')).toBeUndefined()
  })

  it('CLEARS an existing split by sending BOTH fields as null', async () => {
    const wrapper = await openEditor(
      mountEditor(referralFixture({ co_agent: { id: 7, name: 'ตัวแทน ก' }, split_percentage: 30 })),
    )

    // The form opened prefilled with the existing split…
    expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('7')
    expect((wrapper.find('input[type="number"]').element as HTMLInputElement).value).toBe('30')

    // …and "ไม่แบ่งคอมมิชชั่น" is option 0.
    await selectOption(wrapper, 0)
    patch.mockResolvedValue({})

    await saveButton(wrapper).trigger('click')
    await flushPromises()

    // The percentage the agent had typed must NOT ride along: a lingering
    // split_percentage with a null co_agent_id is precisely what
    // SetCoAgentRequest's both-or-neither rule rejects.
    expect(patch).toHaveBeenCalledWith('/referrals/1/co-agent', {
      co_agent_id: null,
      split_percentage: null,
    })
  })

  it('gates the percentage exactly as before: box disabled with no co-agent, Save disabled with no percentage', async () => {
    const wrapper = await openEditor(mountEditor())
    const percent = wrapper.find('input[type="number"]')

    // (a) No co-agent chosen → nothing to apportion, so the box is inert and
    // Save is live (saving "no split" is a legitimate no-op/clear).
    expect(percent.attributes('disabled')).toBeDefined()
    expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()

    // (b) Co-agent chosen, percentage still empty → the both-or-neither rule
    // is unsatisfiable, so Save must be the thing that is inert.
    await selectOption(wrapper, 1)
    expect(percent.attributes('disabled')).toBeUndefined()
    expect(saveButton(wrapper).attributes('disabled')).toBeDefined()

    // (c) Percentage typed → saveable.
    await percent.setValue('30')
    expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()

    // The 1–99 range is the server's (SetCoAgentRequest: min:1, max:99);
    // the box states it rather than inventing a different one.
    expect(percent.attributes('min')).toBe('1')
    expect(percent.attributes('max')).toBe('99')
  })

  it('shows an EXISTING co-agent and offers "แก้ไข", offers "+ แบ่งคอมฯ" when there is none', () => {
    const shared = mountEditor(
      referralFixture({ co_agent: { id: 8, name: 'ตัวแทน ข' }, split_percentage: 45 }),
    )
    expect(shared.text()).toContain('ตัวแทน ข')
    expect(shared.text()).toContain('45%')
    expect(trigger(shared).text()).toBe('แก้ไขคอมฯ ร่วม')

    const solo = mountEditor()
    expect(solo.text()).toContain('ยังไม่ได้แบ่งกับใคร')
    expect(trigger(solo).text()).toBe('+ แบ่งคอมฯ')
  })

  it('renders NOTHING once the referral is at or past Complete Payment (BR-4 cutoff)', () => {
    for (const key of ['complete_payment', 'ongoing_next_meeting']) {
      const wrapper = mountEditor(
        referralFixture({ current_stage: { key, label: key }, co_agent: { id: 7, name: 'ตัวแทน ก' }, split_percentage: 30 }),
      )
      expect(wrapper.find('button').exists()).toBe(false)
      expect(wrapper.text()).toBe('')
    }
  })

  it('surfaces the server’s own 422 reason and does NOT report the write as saved', async () => {
    const wrapper = await openEditor(mountEditor())
    await selectOption(wrapper, 1)
    await wrapper.find('input[type="number"]').setValue('30')

    const reason = 'TASK-026: co_agent_id must be a different agent than the referring agent.'
    patch.mockRejectedValue(new FakeApiError(422, { errors: { co_agent_id: [reason] } }))

    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.emitted('error')).toEqual([[reason]])
    expect(wrapper.emitted('saved')).toBeUndefined()
    // The editor stays OPEN on failure — closing it would throw away what
    // the agent typed for a write that never happened.
    expect(wrapper.find('select').exists()).toBe(true)
  })
})
