/**
 * The header row that opens menus must paint over the row that does not.
 *
 * ═══ THE BUG ═══
 *
 * 2026-09-22, owner: "เมนูเปลี่ยนบริษัท อยู่ใต้ Z-index sub menu".
 *
 * The company switcher's dropdown is `z-[60]` — higher than the sticky
 * header's 50 — and the sub-menu row underneath painted over it anyway,
 * slicing the search box off the top of the list.
 *
 * Both header rows carry `backdrop-blur-xl`, and a backdrop-filter CREATES A
 * STACKING CONTEXT. So the dropdown's 60 only ever competed inside its own
 * nav, and the two nav rows were ordered against each other by DOM position
 * alone — where the sub-menu comes second and therefore wins.
 *
 * Raising the dropdown's number would have changed nothing. That is the part
 * worth remembering, and the reason these tests name the CONTEXTS rather
 * than the dropdown.
 *
 * ═══ WHY IT ASSERTS CLASSES ═══
 *
 * jsdom does not lay out or composite, so no test here can observe one
 * element covering another. What it can do is hold the two rows' ordering in
 * place against the one change that brings the bug back: somebody tidying an
 * "unnecessary" z-index off a row whose sibling looks unrelated.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const activeRoute = { value: { name: 'commission-payouts', path: '/commission/payouts', query: {} } }

vi.mock('vue-router', () => ({
  useRoute: () => activeRoute.value,
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
  createRouter: vi.fn(() => ({ beforeEach: vi.fn(), afterEach: vi.fn(), onError: vi.fn() })),
  createWebHistory: vi.fn(),
}))

vi.mock('@/api/client', () => ({
  api: { get: vi.fn(async () => ({ data: [] })), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), postForm: vi.fn(), download: vi.fn() },
  ApiError: class extends Error {},
}))

import AdminNavigation from '@/design-system/components/AdminNavigation.vue'
import CompanySwitcher from '@/design-system/components/CompanySwitcher.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

function mountNav() {
  return mount(AdminNavigation, {
    global: {
      stubs: {
        AppLogo: true,
        Icon: true,
        NotificationBell: true,
        CompanySwitcher: true,
        GlobalSearch: true,
      },
    },
  })
}

beforeEach(() => {
  /*
   * The sub-menu row only renders for the ACTIVE pillar, and the whole bug
   * needs that row to exist — so the route has to be one that has sub-items.
   * Commission is the pillar the owner was on when they found it.
   */
  activeRoute.value = { name: 'commission-payouts', path: '/commission/payouts', query: {} }
  useAuthStore().user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('the two header rows are ordered explicitly, not by DOM position', () => {
  it('renders both rows, which is the state the bug needs', () => {
    // A page with no sub-menu never showed the bug at all, which is why it
    // survived until somebody opened the switcher on a page that has one.
    const nav = mountNav()

    expect(nav.find('nav').exists()).toBe(true)
    expect(nav.findAll('[data-test^="nav-sub-"]').length).toBeGreaterThan(0)
  })

  it('puts the row holding the company switcher above its sibling', () => {
    const nav = mountNav()
    const row1 = nav.find('nav')

    expect(row1.classes()).toContain('relative')
    expect(row1.classes()).toContain('z-10')
    // The blur is what made the ordering necessary in the first place: drop
    // it and this row stops being a stacking context.
    expect(row1.classes()).toContain('backdrop-blur-xl')
  })

  it('keeps the sub-menu row below it', () => {
    const nav = mountNav()
    const row2 = nav.findAll('[data-test^="nav-sub-"]')[0]?.element.closest('div.backdrop-blur-xl')

    expect(row2).not.toBeNull()
    expect(row2?.classList.contains('relative')).toBe(true)
    expect(row2?.classList.contains('z-0')).toBe(true)
  })
})

describe('the dropdown itself', () => {
  it('opens inside the header rather than escaping to the page', () => {
    /*
     * `absolute`, against the switcher's own box. Were it `fixed` it would
     * leave the header entirely and the row ordering above would not be the
     * fix — so this is the assumption the fix rests on, pinned.
     */
    const active = useActiveCompanyStore()
    active.companies = [{ id: 1, name: 'บริษัททดสอบ', slug: 'test' }] as never
    active.selectedId = 1

    const switcher = mount(CompanySwitcher, { global: { stubs: { Icon: true } } })

    expect(switcher.find('[class*="absolute"]').exists()).toBe(false) // shut until pressed

    switcher.get('button').trigger('click')

    return switcher.vm.$nextTick().then(() => {
      const panel = switcher.find('div.absolute')

      expect(panel.exists()).toBe(true)
      expect(panel.classes()).toContain('z-[60]')
    })
  })
})
