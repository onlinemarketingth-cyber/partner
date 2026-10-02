/**
 * ADR-052 — ClientDetailModal's save used to switch back to the read view
 * silently. It now raises the "saved" dialog, after the view shows the
 * record the SERVER stored.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

const get = vi.fn()
const put = vi.fn()

const { ApiErrorStub } = vi.hoisted(() => ({
  ApiErrorStub: class extends Error {
    constructor(
      public status: number,
      public body: unknown = null,
    ) {
      super(`API error ${status}`)
    }
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...a: unknown[]) => get(...a),
    put: (...a: unknown[]) => put(...a),
    download: vi.fn(),
  },
  ApiError: ApiErrorStub,
}))

import ClientDetailModal from '../ClientDetailModal.vue'
import { useAuthStore } from '@/stores/auth'

function client(over: Record<string, unknown> = {}) {
  return {
    id: 9,
    referring_agent_id: 1,
    name: 'ลูกค้า เดิม',
    phone: '0810000000',
    email: null,
    national_id_masked: null,
    national_id: null,
    consent_given_at: null,
    health_notes: null,
    status: { key: 'new', label: 'ใหม่' },
    lead_source: null,
    client_category_id: null,
    date_of_birth: null,
    address: null,
    province: null,
    occupation: null,
    referrals: [],
    created_at: '2026-09-01T00:00:00Z',
    ...over,
  }
}

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  get.mockImplementation(async (url: string) => {
    if (url === '/clients/9') return { data: client() }

    return { data: [] }
  })
  useAuthStore().user = { id: 1, name: 'admin', role: 'super_admin' } as never
})

async function mountOpen() {
  const wrapper = mount(ClientDetailModal, {
    props: { clientId: 9 },
    global: { stubs: { Teleport: true, Icon: true, LoadingSkeleton: true } },
  })
  await flushPromises()

  return wrapper
}

async function openEditor(wrapper: ReturnType<typeof mount>) {
  await wrapper.findAll('button').find((b) => b.text().includes('แก้ไข'))!.trigger('click')
}

function saveButton(wrapper: ReturnType<typeof mount>) {
  return wrapper.findAll('button').find((b) => b.text() === 'บันทึก')!
}

describe('ClientDetailModal — ADR-052 save feedback', () => {
  it('save: dialog only after the PUT resolved, and the read view shows the SERVER’s record', async () => {
    let resolvePut: (v: unknown) => void = () => {}
    put.mockImplementation(() => new Promise((r) => (resolvePut = r)))
    const wrapper = await mountOpen()

    await openEditor(wrapper)
    await wrapper.find('form input').setValue('ชื่อที่พิมพ์')
    await saveButton(wrapper).trigger('click')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    resolvePut({ data: client({ name: 'ชื่อที่เซิร์ฟเวอร์เก็บ' }) })
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/clients/9', expect.objectContaining({ name: 'ชื่อที่พิมพ์' }))
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.text()).toContain('ชื่อที่เซิร์ฟเวอร์เก็บ')
    expect(wrapper.text()).not.toContain('ชื่อที่พิมพ์')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('ชื่อที่เซิร์ฟเวอร์เก็บ')
    expect(wrapper.emitted('saved')?.[0]?.[0]).toMatchObject({ name: 'ชื่อที่เซิร์ฟเวอร์เก็บ' })
  })

  it('save: a 422 raises no dialog, keeps the editor open and shows the field error', async () => {
    put.mockRejectedValue(new ApiErrorStub(422, { errors: { phone: ['เบอร์โทรไม่ถูกต้อง'] } }))
    const wrapper = await mountOpen()

    await openEditor(wrapper)
    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.find('form').exists()).toBe(true)
    expect(wrapper.text()).toContain('เบอร์โทรไม่ถูกต้อง')
    expect(wrapper.emitted('saved')).toBeUndefined()
  })

  it('save: a 403 raises no dialog and says the admin may not edit this client', async () => {
    put.mockRejectedValue(new ApiErrorStub(403))
    const wrapper = await mountOpen()

    await openEditor(wrapper)
    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('คุณไม่มีสิทธิ์แก้ไขข้อมูลลูกค้ารายนี้')
  })
})
