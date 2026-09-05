/**
 * TASK-258 — the three filters the human actually asked for, and the menu.
 *
 *   "ผมต้องการ Log รวมว่าใครทำอะไรไปบ้างที่ผ่านมาในระบบ อยู่ใน เมนู Setting
 *    พร้อมระบบ Filter ค้นหาตามวันที่เวลา เป็นช่วง และรายบุคคล และกิจกรรม"
 *
 * The person filter and the date range already existed. The other two thirds
 * of that sentence did not, and both failures were invisible:
 *
 *   • THE ACTIVITY FILTER WAS UNUSABLE, not missing. It was a text box whose
 *     placeholder read "เช่น commission_rule.created" — it worked perfectly
 *     for anybody who could recite the backend's action keys, which is
 *     nobody. A screenshot of that screen looks complete.
 *
 *   • THE SCREEN WAS UNFINDABLE, not absent. Tab 1 of 4 on a page called
 *     "นโยบายและรายงาน" — every test passed, every feature worked, and the
 *     human's answer when asked where the log was, was that there wasn't one.
 *
 * So these tests are about reachability and about the filter being made of
 * names rather than keys.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const get = vi.fn()
const replace = vi.fn()
const query: Record<string, string> = {}

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
}))

vi.mock('vue-router', async () => {
  const actual = await vi.importActual<typeof import('vue-router')>('vue-router')

  return { ...actual, useRoute: () => ({ query }), useRouter: () => ({ replace, push: vi.fn() }) }
})

import ActivityLogPanel from '../ActivityLogPanel.vue'
import PolicyReportView from '../PolicyReportView.vue'
import routerConfig from '@/router'
import { AUDIT_ACTION_GROUPS, auditActionLabel } from '@/utils/auditActions'
import { useAuthStore } from '@/stores/auth'

const ROW = {
  id: 1,
  company_id: 4,
  actor_name: 'เกรียงยศ โอหุยหะนภา',
  action: 'user.role_changed',
  auditable_type: 'App\\Models\\User',
  auditable_id: 9,
  old_values: null,
  new_values: null,
  ip_address: '203.0.113.9',
  created_at: '2026-09-05T03:00:00Z',
}

async function mountPanel() {
  const wrapper = mount(ActivityLogPanel, {
    global: {
      stubs: { EmptyState: true, Icon: true, LoadingSkeleton: true, DateRangeFilter: true },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountPanel>>

const lastPath = (): string => {
  const audit = get.mock.calls.map((c) => c[0] as string).filter((p) => p.startsWith('/audit-logs'))

  return audit[audit.length - 1]!
}

async function apply(wrapper: Wrapper) {
  await wrapper.findAll('button').find((b) => b.text() === 'กรอง')!.trigger('click')
  await flushPromises()
}

beforeEach(() => {
  get.mockReset()
  replace.mockReset()
  for (const k of Object.keys(query)) delete query[k]
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/users')) return { data: [{ id: 7, name: 'เกรียงยศ โอหุยหะนภา' }], meta: { last_page: 1 } }

    return { data: [ROW], meta: { current_page: 1, last_page: 1, total: 1, per_page: 15 } }
  })

  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
})

describe('ActivityLogPanel — filtering by activity', () => {
  it('offers activities by name, not by database key', async () => {
    // The whole point. An admin recognises "เปลี่ยนบทบาท"; nobody types
    // `user.role_changed`.
    const wrapper = await mountPanel()
    const options = wrapper.find('[data-test="action-filter"]').findAll('option')

    const labels = options.map((o) => o.text())
    expect(labels).toContain('เปลี่ยนบทบาท')
    expect(labels).toContain('เข้าสู่ระบบไม่สำเร็จ')
    expect(labels.some((l) => l.includes('user.role_changed'))).toBe(false)
  })

  it('starts on everything, so the log is a log before it is a search', async () => {
    const wrapper = await mountPanel()

    expect(lastPath()).not.toContain('action=')
    expect(wrapper.find('[data-test="action-filter"]').findAll('option')[0]!.text()).toBe('ทุกกิจกรรม')
  })

  it('sends the chosen activity to the API', async () => {
    const wrapper = await mountPanel()
    await wrapper.find('[data-test="action-filter"]').setValue('auth.login_failed')

    await apply(wrapper)

    expect(lastPath()).toContain('action=auth.login_failed')
  })

  it('can ask for a whole group at once', async () => {
    /*
     * "ทุกอย่างเกี่ยวกับผู้ใช้" is one click, not eleven, because the group
     * option sends the prefix and the API matches with LIKE. If that filter
     * ever becomes an exact match, this test is what fails — the per-action
     * options would keep working and hide the regression.
     */
    const wrapper = await mountPanel()
    await wrapper.find('[data-test="action-filter"]').setValue('user.')

    await apply(wrapper)

    expect(lastPath()).toContain('action=user.')
  })

  it('names the activity in the chip, in Thai', async () => {
    // A narrowed list that does not say what it is narrowed to reads as "the
    // system did almost nothing this month".
    const wrapper = await mountPanel()
    await wrapper.find('[data-test="action-filter"]').setValue('auth.login_failed')
    await apply(wrapper)

    expect(wrapper.find('[data-test="filter-chip"]').text()).toContain('เข้าสู่ระบบไม่สำเร็จ')
  })

  it('covers every action group with a Thai label', () => {
    // A missing label is not cosmetic here: an action absent from this file
    // cannot be selected at all, so the row it would have found stays hidden
    // behind "ทุกกิจกรรม" forever.
    for (const group of AUDIT_ACTION_GROUPS) {
      expect(group.actions.length).toBeGreaterThan(0)
      for (const action of group.actions) {
        expect(auditActionLabel(action.value)).toBe(action.label)
        expect(action.label).not.toContain('.')
      }
    }
  })

  it('shows an unmapped action as its raw key rather than hiding the row', () => {
    // The failure mode has to be a cosmetic gap on one row, never a missing
    // row and never a row that reads "something happened, we won't say what".
    expect(auditActionLabel('something.new_tomorrow')).toBe('something.new_tomorrow')
  })
})

describe('ActivityLogPanel — the date range people actually use', () => {
  it('offers 7 and 30 days, because a log is read by recency', async () => {
    // DateRangeFilter already offers เดือนนี้ / ทั้งปี / Q1-Q4 — the shapes a
    // REPORT is read in. "What happened since I last looked" has no quarter
    // in it.
    const wrapper = await mountPanel()

    await wrapper.find('[data-test="last-7-days"]').trigger('click')
    await flushPromises()

    const path = lastPath()
    expect(path).toContain('date_from=')
    expect(path).toContain('date_to=')
  })

  it('asks for seven days including today, not eight', async () => {
    // Off-by-one on a date range is the kind of thing nobody checks and
    // everybody quotes: "the last 7 days" that silently means 8.
    const wrapper = await mountPanel()

    await wrapper.find('[data-test="last-7-days"]').trigger('click')
    await flushPromises()

    const params = new URLSearchParams(lastPath().split('?')[1])
    const from = new Date(params.get('date_from')!)
    const to = new Date(params.get('date_to')!)

    expect(Math.round((to.getTime() - from.getTime()) / 86_400_000)).toBe(6)
  })

  it('clears every filter at once, including the one in the URL', async () => {
    const wrapper = await mountPanel()
    await wrapper.find('[data-test="action-filter"]').setValue('auth.login_failed')
    await apply(wrapper)

    await wrapper.find('[data-test="clear-filters"]').trigger('click')
    await flushPromises()

    expect(lastPath()).not.toContain('action=')
    expect(JSON.stringify(replace.mock.calls[replace.mock.calls.length - 1])).not.toContain('actor')
  })
})

describe('The activity log is reachable', () => {
  const router = createRouter({ history: createMemoryHistory(), routes: routerConfig.getRoutes() })

  it('has its own route, not only a tab inside the reports page', () => {
    // Being tab 1 of 4 is why the human said there was no log: the screen
    // existed and nobody arrived at it.
    expect(router.hasRoute('activity-log')).toBe(true)
    expect(router.resolve({ name: 'activity-log' }).path).toBe('/activity-log')
  })

  it('is listed in the ตั้งค่าระบบ menu', () => {
    // The actual deliverable of this task — "อยู่ใน เมนู Setting". A route
    // with no menu entry is the same invisibility in a different place.
    const here = path.dirname(fileURLToPath(import.meta.url))
    const source = fs.readFileSync(
      path.join(here, '..', '..', 'design-system', 'components', 'AdminNavigation.vue'),
      'utf8',
    )

    const settingsPillar = source.slice(
      source.indexOf("label: { th: 'ตั้งค่าระบบ'"),
      source.indexOf("name: 'voucher-redeem'"),
    )

    expect(settingsPillar).toContain("name: 'activity-log'")
    expect(settingsPillar).toContain('บันทึกการใช้งานระบบ')
  })
})

describe('The reports page keeps its audit tab', () => {
  it('renders the same log component rather than a copy of it', async () => {
    /*
     * The log moved to its own page; the tab it used to be stays, because a
     * bookmark and a link somebody was sent must not land on a tab that
     * quietly stopped working.
     *
     * Asserting the COMPONENT is what appears — not "the page contains a
     * table" — is the point: a second implementation pasted back in here
     * would satisfy any looser check and would drift the first time either
     * copy is edited.
     */
    const wrapper = mount(PolicyReportView, {
      global: {
        stubs: {
          HeroHeader: { template: '<div><slot name="tabs" /></div>' },
          EmptyState: true,
          Icon: true,
          LoadingSkeleton: true,
          PlatformScopeBadge: true,
          ActivityLogPanel: { name: 'ActivityLogPanel', template: '<div data-test="log-panel" />' },
        },
      },
    })
    await flushPromises()

    expect(wrapper.find('[data-test="log-panel"]').exists()).toBe(true)
  })
})
