/**
 * ADR-052 — every write on "ตั้งค่า Gamification" re-reads the lists from the
 * server, THEN says it saved. Deletes ask first.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()
const del = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: (...args: unknown[]) => post(...args),
    delete: (...args: unknown[]) => del(...args),
    patch: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {
    constructor(
      public status: number,
      public body: unknown = null,
    ) {
      super(`API error ${status}`)
    }
  },
}))

import GamificationConfigView from '../GamificationConfigView.vue'
import { ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { saveFeedbackState } from '@/composables/useSaveFeedback'

/** `noUncheckedIndexedAccess` — fail loudly when an expected element is missing. */
function nth<T>(items: T[], index: number): T {
  const item = items[index < 0 ? items.length + index : index]
  if (item === undefined) throw new Error(`no element at index ${index}`)

  return item
}

interface Db {
  rules: unknown[]
  badges: unknown[]
  levels: unknown[]
}
let db: Db

function serveGets() {
  get.mockImplementation((path: string) => {
    if (path.startsWith('/gamification-rules')) return Promise.resolve({ data: db.rules })
    if (path.startsWith('/badges')) return Promise.resolve({ data: db.badges })
    if (path.startsWith('/users')) return Promise.resolve({ data: [] })
    if (path.startsWith('/user-badges')) return Promise.resolve({ data: [] })
    if (path.startsWith('/level-thresholds')) return Promise.resolve({ data: db.levels })

    return Promise.reject(new Error(`unexpected GET ${path}`))
  })
}

async function mountView() {
  serveGets()
  const wrapper = mount(GamificationConfigView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="tabs" /></div>' },
        CompanyScopeNotice: true,
        Icon: true,
        LoadingSkeleton: true,
        EmptyState: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

const tab = async (w: Awaited<ReturnType<typeof mountView>>, label: string) => {
  await w.findAll('button').find((b) => b.text() === label)!.trigger('click')
}
const buttonByText = (w: Awaited<ReturnType<typeof mountView>>, text: string) =>
  w.findAll('button').filter((b) => b.text().trim() === text)

beforeEach(() => {
  ;[get, put, post, del].forEach((m) => m.mockReset())
  db = {
    rules: [{ id: 1, company_id: 5, source_type: 'exam_passed', xp_value: 50, is_active: true }],
    badges: [{ id: 7, company_id: null, key: 'star', name: 'ดาวรุ่ง', description: 'd', icon: 'star', condition_config: null }],
    levels: [{ id: 3, level_number: 2, xp_required: 100 }],
  }
  useAuthStore().user = { id: 1, name: 'ซูเปอร์', role: 'super_admin' } as never
})

describe('GamificationConfigView — save feedback (ADR-052)', () => {
  it('saving a level shows the modal only after the server answered and the list was re-read', async () => {
    const wrapper = await mountView()
    await tab(wrapper, 'Level')
    await nth(buttonByText(wrapper, 'แก้ไข'), 0).trigger('click')
    const inputs = wrapper.findAll('form input[type="number"]')
    await nth(inputs, 1).setValue('150')

    let resolvePut: (v: unknown) => void = () => {}
    put.mockReturnValue(new Promise((r) => (resolvePut = r)))
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(saveFeedbackState.show).toBe(false)

    // The server stored something other than what was typed.
    db.levels = [{ id: 3, level_number: 2, xp_required: 175 }]
    resolvePut({ data: { id: 3, level_number: 2, xp_required: 175 } })
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('บันทึก Level 2 แล้ว')
    expect(wrapper.text()).toContain('175 XP')
    expect(wrapper.text()).not.toContain('150 XP')
  })

  it('a failed save raises no modal and shows the error', async () => {
    const wrapper = await mountView()
    await nth(buttonByText(wrapper, '+ เพิ่มอัตรา XP'), 0).trigger('click')
    post.mockRejectedValue(new ApiError(422, null))

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.text()).toContain('บันทึกไม่สำเร็จ (422)')
  })

  it('deleting a badge asks first, then deletes and shows the modal', async () => {
    const wrapper = await mountView()
    await tab(wrapper, 'Badge')
    await nth(buttonByText(wrapper, 'ลบ'), 0).trigger('click')

    expect(del).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('ลบ Badge ดาวรุ่ง ยืนยันหรือไม่?')

    del.mockResolvedValue(undefined)
    db.badges = []
    await nth(buttonByText(wrapper, 'ยืนยัน'), 0).trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/badges/7')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe('ลบ Badge "ดาวรุ่ง" แล้ว')
    expect(wrapper.text()).not.toContain('ดาวรุ่ง')
  })

  it('deleting a level asks first; cancelling sends nothing', async () => {
    const wrapper = await mountView()
    await tab(wrapper, 'Level')
    await nth(buttonByText(wrapper, 'ลบ'), 0).trigger('click')
    expect(wrapper.text()).toContain('ลบ Level 2')

    await nth(buttonByText(wrapper, 'ยกเลิก'), -1).trigger('click')
    await flushPromises()

    expect(del).not.toHaveBeenCalled()
    expect(saveFeedbackState.show).toBe(false)
  })

  it('toggling a rule shows the state the server stored, with the modal', async () => {
    const wrapper = await mountView()
    put.mockResolvedValue({ data: { id: 1, company_id: 5, source_type: 'exam_passed', xp_value: 50, is_active: false } })
    db.rules = [{ id: 1, company_id: 5, source_type: 'exam_passed', xp_value: 50, is_active: false }]

    await nth(buttonByText(wrapper, 'ใช้งานอยู่'), 0).trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/gamification-rules/1', { is_active: false })
    expect(saveFeedbackState.body).toBe('ปิดใช้งานอัตรา XP "สอบผ่าน" แล้ว')
    expect(buttonByText(wrapper, 'ปิดใช้งาน')).toHaveLength(1)
  })

  it('a write whose re-read fails still reports the save, but says the screen may be behind', async () => {
    const wrapper = await mountView()
    put.mockResolvedValue({ data: { id: 1, company_id: 5, source_type: 'exam_passed', xp_value: 50, is_active: false } })
    get.mockRejectedValue(new ApiError(500, null))

    await nth(buttonByText(wrapper, 'ใช้งานอยู่'), 0).trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('โหลดข้อมูลล่าสุดกลับมาไม่สำเร็จ')
  })
})
