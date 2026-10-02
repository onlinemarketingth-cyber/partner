/**
 * ADR-052 — revoking an affiliate link: the list on screen comes from the
 * server after the write, and "revoked" is only said once it is true.
 *
 * confirmRevoke() used to drop the row locally and never re-read, so the
 * counts and every other row were whatever the page had loaded earlier.
 * Pinned here:
 *   - the success toast appears only after DELETE resolves AND the list has
 *     been re-read; the rows/KPIs then show the SERVER's numbers;
 *   - a rejected DELETE gives no success toast and the row is still there;
 *   - a failed re-read after a successful DELETE is an INFO toast about the
 *     screen being possibly out of date — never a failed revoke.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const del = vi.fn()

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
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: (...args: unknown[]) => del(...args),
  },
  ApiError: FakeApiError,
}))

import AffiliateLinksView from '../AffiliateLinksView.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import { useAuthStore, type AuthUser } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const SELF_ID = 9

function link(id: number, clicks: number) {
  return {
    id,
    product_id: null,
    token: `t${id}`,
    public_url: `https://x.test/l/t${id}`,
    short_url: `https://s.test/${id}`,
    clicks_count: clicks,
    conversions_count: 0,
    created_at: '2026-09-01T00:00:00Z',
  }
}

/** First list load, then the re-read after the write (or its failure). */
function wire(reload: () => Promise<unknown>) {
  let linkLoads = 0
  get.mockImplementation((path: string) => {
    if (path === '/affiliate-links') {
      linkLoads++
      return linkLoads === 1 ? Promise.resolve({ data: [link(1, 5), link(2, 3)] }) : reload()
    }
    if (path === '/products') return Promise.resolve({ data: [] })
    if (path === '/user-certifications')
      return Promise.resolve({ data: [{ id: 1, user_id: SELF_ID, cert_tier: { id: 1, key: 'basic', name: 'Basic' } }] })
    if (path === '/affiliate-attribution-settings') return Promise.resolve('')
    throw new Error(`unexpected GET ${path}`)
  })
}

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((res) => {
    resolve = res
  })
  return { promise, resolve }
}

async function mountAndAskRevoke(linkId: number) {
  const wrapper = mount(AffiliateLinksView, {
    global: { stubs: { HeroHeader: true, Icon: true, LoadingSkeleton: true, ConfirmDialog: true, Transition: false } },
  })
  await flushPromises()
  const revokeButtons = wrapper.findAll('button').filter((b) => b.find('icon-stub[name="trash"]').exists())
  await revokeButtons[linkId - 1]!.trigger('click')
  return wrapper
}

const successes = () => useToastStore().toasts.filter((t) => t.variant === 'success')

beforeEach(() => {
  setActivePinia(createPinia())
  useAuthStore().user = { id: SELF_ID, name: 'เอเจนต์' } as AuthUser
  get.mockReset()
  del.mockReset()
})

describe('AffiliateLinksView — revoke reads the list back from the server (ADR-052)', () => {
  it('asks first, then toasts only after DELETE and the re-read; rows show the SERVER\'s numbers', async () => {
    const reload = deferred<unknown>()
    wire(() => reload.promise)
    del.mockResolvedValue('')
    const wrapper = await mountAndAskRevoke(1)

    const dialog = wrapper.findComponent(ConfirmDialog)
    expect(dialog.props('show')).toBe(true)
    expect(dialog.props('variant')).toBe('danger')
    expect(del).not.toHaveBeenCalled()

    dialog.vm.$emit('confirm')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/affiliate-links/1')
    // DELETE answered, re-read still in flight — nothing claimed yet.
    expect(successes()).toHaveLength(0)

    // The server's list: link 2 now has 42 clicks (the page had 3).
    reload.resolve({ data: [link(2, 42)] })
    await flushPromises()

    expect(successes().map((t) => t.message)).toEqual(['ยกเลิกลิงก์แล้ว'])
    expect(wrapper.text()).toContain('42')
    expect(wrapper.text()).not.toContain('https://s.test/1')
    expect(wrapper.findComponent(ConfirmDialog).props('show')).toBe(false)
  })

  it('after a revoke the list is exactly what the server returns (revoked links are left out server-side)', async () => {
    // The backend now excludes revoked links; the screen must not filter on
    // its own, so whatever the server lists is what is shown.
    wire(() => Promise.resolve({ data: [link(2, 3)] }))
    del.mockResolvedValue('')
    const wrapper = await mountAndAskRevoke(1)

    wrapper.findComponent(ConfirmDialog).vm.$emit('confirm')
    await flushPromises()

    expect(wrapper.text()).not.toContain('https://s.test/1')
    expect(wrapper.text()).toContain('https://s.test/2')
  })

  it('a rejected DELETE: no success toast, the row is still listed, the dialog stays open', async () => {
    wire(() => Promise.resolve({ data: [] }))
    del.mockRejectedValue(new FakeApiError(500, {}))
    const wrapper = await mountAndAskRevoke(1)

    wrapper.findComponent(ConfirmDialog).vm.$emit('confirm')
    await flushPromises()

    expect(successes()).toHaveLength(0)
    expect(useToastStore().toasts.some((t) => t.variant === 'error')).toBe(true)
    expect(wrapper.text()).toContain('https://s.test/1')
    expect(wrapper.findComponent(ConfirmDialog).props('show')).toBe(true)
    // Only the first load — a failed write is not followed by a re-read.
    expect(get.mock.calls.filter((c) => c[0] === '/affiliate-links')).toHaveLength(1)
  })

  it('a failed re-read after a successful DELETE is an info toast, not a failed revoke', async () => {
    wire(() => Promise.reject(new FakeApiError(500, {})))
    del.mockResolvedValue('')
    const wrapper = await mountAndAskRevoke(1)

    wrapper.findComponent(ConfirmDialog).vm.$emit('confirm')
    await flushPromises()

    const toasts = useToastStore().toasts
    expect(toasts.some((t) => t.variant === 'error')).toBe(false)
    expect(toasts.filter((t) => t.variant === 'info').map((t) => t.message)).toEqual([
      'ยกเลิกลิงก์แล้ว แต่โหลดรายการใหม่ไม่สำเร็จ — ข้อมูลบนหน้าจออาจไม่เป็นปัจจุบัน',
    ])
    expect(wrapper.text()).not.toContain('https://s.test/1')
  })
})
