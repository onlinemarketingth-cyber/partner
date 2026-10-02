/**
 * ADR-052 — the order is the feature: write → server says yes → screen shows
 * the server's values → THEN the dialog. Each test pins one edge of that.
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import SaveFeedbackHost from '@/design-system/components/SaveFeedbackHost.vue'
import {
  SAVED_BUT_STALE_BODY,
  confirmSaved,
  notifySaved,
  saveFeedbackState,
} from '@/composables/useSaveFeedback'

describe('confirmSaved', () => {
  it('raises the dialog only after the write AND the re-read have finished', async () => {
    const order: string[] = []

    await confirmSaved(
      async () => {
        order.push('write')
        expect(saveFeedbackState.show).toBe(false)

        return { name: 'stored' }
      },
      {
        apply: async () => {
          order.push('apply')
          expect(saveFeedbackState.show).toBe(false)
        },
      },
    )

    expect(order).toEqual(['write', 'apply'])
    expect(saveFeedbackState.show).toBe(true)
  })

  it('never claims success when the server refuses — and lets the caller see the error', async () => {
    const refusal = new Error('422')

    await expect(confirmSaved(async () => Promise.reject(refusal))).rejects.toBe(refusal)

    expect(saveFeedbackState.show).toBe(false)
    expect(saveFeedbackState.count).toBe(0)
  })

  it('quotes the STORED value, not the typed one', async () => {
    await confirmSaved(async () => ({ account: '123-4-56789-0' }), {
      message: (res) => `บันทึกบัญชี ${res.account} แล้ว`,
    })

    expect(saveFeedbackState.body).toBe('บันทึกบัญชี 123-4-56789-0 แล้ว')
  })

  it('a re-read that fails after a successful write says so instead of pretending the screen is current', async () => {
    await confirmSaved(async () => ({}), {
      apply: async () => {
        throw new Error('network')
      },
    })

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe(SAVED_BUT_STALE_BODY)
  })

  it('starts every test closed (vitest.setup.ts resets it)', () => {
    expect(saveFeedbackState.show).toBe(false)
  })
})

describe('SaveFeedbackHost', () => {
  it('renders the notice and closes on ตกลง', async () => {
    const wrapper = mount(SaveFeedbackHost)
    notifySaved('บันทึกการตั้งค่าขั้นแล้ว')
    await nextTick()

    expect(wrapper.text()).toContain('บันทึกสำเร็จ')
    expect(wrapper.text()).toContain('บันทึกการตั้งค่าขั้นแล้ว')

    await wrapper.find('button').trigger('click')

    expect(saveFeedbackState.show).toBe(false)
  })
})
