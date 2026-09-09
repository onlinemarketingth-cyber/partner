/**
 * 2026-09-09 (human: "เสนอ UI ใหม่มาเลย ไม่เข้าใจ", with a screenshot of the
 * แคตตาล็อกกลาง page showing three identical grey notices where the tabs
 * should be).
 *
 * Two problems, one of them an outright bug.
 *
 *   THE BADGE WAS INSIDE THE TAB BUTTON. `<PlatformScopeBadge>` sat in the
 *   v-for, between each tab's icon and its label — so it rendered once per
 *   tab, three times, each a block element inside a button. The tabs stopped
 *   looking like tabs and the page opened with three paragraphs of the same
 *   sentence.
 *
 *   THE PAGE NEVER SAID WHAT IT WAS NOT. Two things in this system are called
 *   "กลาง": this catalogue of NAMES, and สินค้ากลาง — real products with no
 *   owning company that every company can sell. The human was looking for the
 *   second, landed on the first, found it empty, and nothing on the screen
 *   told them they were in the wrong room.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    patch: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
}))

vi.mock('vue-router', async () => {
  const actual = await vi.importActual<typeof import('vue-router')>('vue-router')

  return { ...actual, useRoute: () => ({ query: {} }), useRouter: () => ({ push: vi.fn() }) }
})

import CatalogManagementView from '../CatalogManagementView.vue'
import { useAuthStore } from '@/stores/auth'

async function mountView() {
  const wrapper = mount(CatalogManagementView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="actions" /><slot name="tabs" /><slot /></div>' },
        EmptyState: {
          props: ['title', 'message'],
          template: '<div data-test="empty"><p>{{ title }}</p><p>{{ message }}</p></div>',
        },
        Icon: true,
        LoadingSkeleton: true,
        IconPicker: true,
        ConfirmDialog: true,
        RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
      },
    },
  })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  get.mockReset()
  get.mockResolvedValue({ data: [] })

  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('CatalogManagementView — the page explains itself', () => {
  it('shows the platform-scope notice exactly once', async () => {
    /*
     * The bug, as a count. Three was not a styling accident — the badge was
     * inside the tab loop, so every tab carried a copy of it and the tab row
     * became three stacked paragraphs.
     */
    const wrapper = await mountView()

    const notices = wrapper.findAll('p').filter((p) => p.text().includes('ระดับแพลตฟอร์ม'))

    expect(notices).toHaveLength(1)
  })

  it('still shows all three tabs as tabs', async () => {
    // The other half of the same bug: with a block element inside them, the
    // tab buttons were unreadable. They have to survive the fix.
    const wrapper = await mountView()
    const labels = wrapper.findAll('button').map((b) => b.text())

    expect(labels.some((l) => l.includes('รายการแคตตาล็อก'))).toBe(true)
    expect(labels.some((l) => l.includes('แบรนด์'))).toBe(true)
    expect(labels.some((l) => l.includes('หมวดหมู่'))).toBe(true)
  })

  it('says this is not where สินค้ากลาง lives, and offers the door', async () => {
    /*
     * The sentence that would have saved an afternoon. An admin hunting for
     * "products every company can sell" has no way to know that this page,
     * whose name also ends in กลาง, is a list of names with nothing for sale
     * on it.
     */
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('สินค้ากลางที่ทุกบริษัทขายได้')

    const door = wrapper.findAll('a').find((a) => a.text().includes('ไปหน้าสินค้า'))
    expect(door).toBeTruthy()
    expect(JSON.parse(door!.attributes('data-to')!)).toEqual({ name: 'product-catalog' })
  })

  it('drops the ADR code from what a person reads', async () => {
    // "ADR-036" is a document number in our repository. It told the reader
    // nothing and made the page look like it was written for somebody else.
    const wrapper = await mountView()

    expect(wrapper.text()).not.toContain('ADR-036')
  })

  it('an empty catalogue says what the thing is for', async () => {
    /*
     * "ยังไม่มีหมวดหมู่ในแคตตาล็อกกลาง" restates the heading and the obvious.
     * On a page somebody has already told us they do not understand, the
     * empty state is the one place with room to explain.
     */
    const wrapper = await mountView()

    expect(wrapper.find('[data-test="empty"]').text()).toContain('ไม่ใช่ของขาย')
  })
})
