/**
 * ADR-052 — Promotion สำหรับสมาชิก: saving and deleting a promotion end in the
 * one global "saved" dialog, after the list has been re-read from the server;
 * a refusal raises nothing and keeps its error on screen.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()
const del = vi.fn()

const { ApiErrorStub } = vi.hoisted(() => ({
  ApiErrorStub: class extends Error {
    status = 422
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: (...args: unknown[]) => post(...args),
    delete: (...args: unknown[]) => del(...args),
    patch: vi.fn(),
    postForm: vi.fn(),
  },
  ApiError: ApiErrorStub,
}))

import AgentPromotionsView from '../AgentPromotionsView.vue'
import { saveFeedbackState } from '@/composables/useSaveFeedback'
import { useAuthStore } from '@/stores/auth'

function promo(over: Record<string, unknown> = {}) {
  return {
    id: 5,
    company_id: 4,
    product_id: null,
    product_name: null,
    name: 'โบนัสเดือนตุลา',
    description: null,
    target_type: 'all_agents',
    target_cert_tier_id: null,
    target_cert_tier_name: null,
    target_cert_tier_mode: 'exact',
    target_agent_ids: [],
    bonus_type: 'percentage',
    bonus_value: 500,
    payout_timing: 'immediate',
    status: 'active',
    is_currently_active: true,
    starts_at: '2026-10-01',
    ends_at: null,
    created_by: 1,
    created_at: '2026-09-30T00:00:00Z',
    ...over,
  }
}

let listed: unknown[] = []

function deferred<T>() {
  let resolve!: (v: T) => void
  const promise = new Promise<T>((res) => {
    resolve = res
  })

  return { promise, resolve }
}

async function mountView() {
  const wrapper = mount(AgentPromotionsView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="actions" /></div>' },
        CompanyScopeNotice: true,
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        BuddhistDateInput: true,
        ConfirmDialog: {
          props: ['show', 'title', 'body', 'variant', 'busy'],
          template: '<div v-if="show" data-test="confirm" :data-variant="variant"><p>{{ body }}</p><button data-test="confirm-yes" @click="$emit(\'confirm\')">ok</button></div>',
        },
      },
    },
  })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  post.mockReset()
  del.mockReset()
  listed = [promo()]
  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith('/agent-promotions')) return { data: listed }

    return { data: [], meta: { last_page: 1 } }
  })
  useAuthStore().user = { id: 1, name: 'ผู้ดูแล', role: 'company_admin' } as never
})

describe('AgentPromotionsView — ADR-052 saved dialog', () => {
  it('raises the dialog only after the save resolved, naming the promotion as the SERVER stored it, over the re-read list', async () => {
    const wrapper = await mountView()
    await wrapper.find('[data-test="edit-promotion"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="promotion-name"]').setValue('  พิมพ์ไว้  ')

    const pending = deferred<{ data: unknown }>()
    put.mockReturnValue(pending.promise)
    await wrapper.find('[data-test="promotion-form"]').trigger('submit')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    const stored = promo({ name: 'โบนัสเดือนตุลา (ปรับแล้ว)' })
    listed = [stored]
    pending.resolve({ data: stored })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('โบนัสเดือนตุลา (ปรับแล้ว)')
    expect(saveFeedbackState.body).not.toContain('พิมพ์ไว้')
    expect(wrapper.find('[data-test="promotion-form"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('โบนัสเดือนตุลา (ปรับแล้ว)')
  })

  it('keeps the form open with its error, and raises no dialog, when the save is refused', async () => {
    put.mockRejectedValue(new ApiErrorStub('ช่วงวันที่ซ้อนกัน'))
    const wrapper = await mountView()
    await wrapper.find('[data-test="edit-promotion"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="promotion-form"]').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.find('[data-test="promotion-form-error"]').text()).toContain('ช่วงวันที่ซ้อนกัน')
  })

  it('asks (danger) before deleting, then raises the dialog once the re-read list no longer has it', async () => {
    del.mockImplementation(async () => {
      listed = []
    })
    const wrapper = await mountView()

    await wrapper.find('[data-test="delete-promotion"]').trigger('click')
    expect(wrapper.find('[data-test="confirm"]').attributes('data-variant')).toBe('danger')
    expect(del).not.toHaveBeenCalled()

    const readsBefore = get.mock.calls.filter(([p]) => String(p).startsWith('/agent-promotions')).length
    await wrapper.find('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/agent-promotions/5')
    expect(get.mock.calls.filter(([p]) => String(p).startsWith('/agent-promotions')).length).toBe(readsBefore + 1)
    expect(saveFeedbackState.body).toContain('ลบ Promotion "โบนัสเดือนตุลา" แล้ว')
    expect(wrapper.find('[data-test="delete-promotion"]').exists()).toBe(false)
  })
})
