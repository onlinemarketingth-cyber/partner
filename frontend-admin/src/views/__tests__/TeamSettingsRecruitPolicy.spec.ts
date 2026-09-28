/**
 * ADR-049 — "ใครชวนเข้าทีมได้" on the ตั้งค่าทีม page.
 *
 * Owner: "ผมเห็นด้วยทั้ง 1-3". The choice is per company, it reads what the
 * server says is in force (default: everyone who passed Basic), and it is
 * saved with the rest of the row in ONE request.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const put = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: vi.fn(),
    delete: vi.fn(),
    patch: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
}))

import TeamVisibilitySettingsView from '../TeamVisibilitySettingsView.vue'
import { useAuthStore } from '@/stores/auth'

async function mountView(data: Record<string, unknown>) {
  get.mockResolvedValue({ data })
  put.mockResolvedValue({ data })

  const wrapper = mount(TeamVisibilitySettingsView, {
    global: {
      stubs: {
        HeroHeader: true,
        CompanyScopeNotice: true,
        Icon: true,
        InfoPopover: { template: '<span class="info"><slot /></span>' },
      },
    },
  })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
  put.mockReset()
  useAuthStore().user = { id: 1, name: 'แอดมิน', role: 'company_admin' } as never
})

describe('ใครชวนเข้าทีมได้', () => {
  it('shows the policy the server has in force', async () => {
    const wrapper = await mountView({ client_visibility_level: 'counts_only', is_enabled: true, recruit_policy: 'designated' })

    expect((wrapper.find('[data-test="recruit-designated"]').element as HTMLInputElement).checked).toBe(true)
    expect((wrapper.find('[data-test="recruit-all_certified"]').element as HTMLInputElement).checked).toBe(false)
  })

  it('a company with no saved row reads as open to everyone who passed Basic', async () => {
    const wrapper = await mountView({})

    expect((wrapper.find('[data-test="recruit-all_certified"]').element as HTMLInputElement).checked).toBe(true)
  })

  it('is not dimmed or disabled when the team page is switched off', async () => {
    const wrapper = await mountView({ client_visibility_level: 'counts_only', is_enabled: false, recruit_policy: 'all_certified' })

    const radio = wrapper.find('[data-test="recruit-designated"]')
    expect(radio.element.matches(':disabled')).toBe(false)
    expect(radio.element.closest('.opacity-50')).toBeNull()
  })

  it('saves the choice with the rest of the row in one request', async () => {
    const wrapper = await mountView({ client_visibility_level: 'names', is_enabled: true, recruit_policy: 'all_certified' })

    await wrapper.find('[data-test="recruit-designated"]').setValue(true)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(put).toHaveBeenCalledTimes(1)
    expect(put).toHaveBeenCalledWith('/team-visibility-settings', {
      client_visibility_level: 'names',
      is_enabled: true,
      recruit_policy: 'designated',
    })
  })

  it('keeps the long explanation behind the ⓘ', async () => {
    const wrapper = await mountView({})
    const section = wrapper.find('[data-test="recruit-policy"]')

    expect(section.find('.info').text()).toContain('รออนุมัติ')
    expect(section.text()).toContain('ทุกคนที่ผ่าน Basic แล้ว')
    expect(section.text()).toContain('เฉพาะคนที่แอดมินเปิดสิทธิ์')
  })
})
