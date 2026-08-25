/**
 * The links hub (2026-08-22).
 *
 * Three menu entries — ลิงก์ชวนทีม, ลิงก์สมัครตัวแทน, ลิงก์ทั้งบริษัท — that
 * nobody could tell apart, collapsed into one page with three tabs.
 *
 * ── WHAT BREAKS SILENTLY ──
 *
 * 1. ALL THREE TABS LOAD ON ARRIVAL. The obvious implementation renders all
 *    three panels and hides two with v-show. Nothing looks wrong: the page
 *    works, the tabs work. It just fires every request of every tab on every
 *    visit — and the ลิงก์ชวนทีม panel walks the ENTIRE user roster fifteen
 *    rows per request (fetchAllPages), so the cost is a dozen requests to
 *    read one tab. Invisible unless somebody opens the network panel.
 *
 * 2. A VISITED TAB REFETCHES EVERY TIME YOU COME BACK. The opposite mistake:
 *    v-if on the ACTIVE tab alone unmounts the others, so switching away and
 *    back re-runs the fetch and throws away the filters the user had set.
 *    Also invisible — it just feels slow and forgetful.
 *
 * 3. THE DEEP LINK LANDS ON THE WRONG TAB. `?agent=<id>` is the "ดูในแท็บ
 *    ลิงก์ชวนทีม" jump from the agent editor. It aims at one specific tab; if
 *    the hub honours `?tab=` and ignores it, the admin arrives at the stats
 *    tab wondering where the agent's links went.
 *
 * 4. A STALE FILTER RIDES ALONG. `?agent=` filters the team tab. Carrying it
 *    into another tab on a manual switch would leave a filter applied that
 *    the user never set and cannot see the source of.
 */
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const replace = vi.fn()
const query: Record<string, string> = {}

vi.mock('vue-router', () => ({
  useRoute: () => ({ query }),
  useRouter: () => ({ replace, push: vi.fn() }),
}))

/*
 * Each panel is stubbed with a component that RECORDS ITS OWN MOUNT. That is
 * the only way to see the lazy-mount contract from outside: the real cost is
 * the fetch inside each panel's onMounted, and a stub that mounts is a fetch
 * that would have fired.
 */
const mounts: string[] = []
function recordingStub(name: string) {
  return {
    name,
    setup() {
      mounts.push(name)

      return () => null
    },
  }
}

vi.mock('../CompanyLinksView.vue', () => ({ default: recordingStub('overview') }))
vi.mock('../CompanySignupLinksView.vue', () => ({ default: recordingStub('signup') }))
vi.mock('../AgentInviteLinksView.vue', () => ({ default: recordingStub('team') }))

import LinksHubView from '../LinksHubView.vue'

function mountHub(q: Record<string, string> = {}) {
  for (const k of Object.keys(query)) delete query[k]
  Object.assign(query, q)
  mounts.length = 0
  replace.mockClear()

  return mount(LinksHubView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot /></div>' },
        CompanyScopeNotice: true,
        Icon: true,
      },
    },
  })
}

function tabButton(wrapper: ReturnType<typeof mountHub>, label: string) {
  const found = wrapper.findAll('button').find((b) => b.text().includes(label))
  if (!found) throw new Error(`Tab "${label}" is missing.`)

  return found
}

describe('LinksHubView — only the tab you are looking at loads', () => {
  it('mounts one panel on arrival, not three', async () => {
    // THE COST THAT HIDES. Three panels mounted is every request of every
    // tab, on a page where somebody reads one.
    mountHub()
    await flushPromises()

    expect(mounts).toEqual(['overview'])
  })

  it('mounts a tab the first time it is opened', async () => {
    const wrapper = mountHub()
    await tabButton(wrapper, 'ลิงก์สมัครตัวแทน').trigger('click')
    await flushPromises()

    expect(mounts).toEqual(['overview', 'signup'])
  })

  it('does NOT remount a tab you come back to', async () => {
    // The opposite mistake: v-if on the active tab alone throws away the
    // panel's data and whatever filters the user had set, every switch.
    const wrapper = mountHub()
    await tabButton(wrapper, 'ลิงก์สมัครตัวแทน').trigger('click')
    await tabButton(wrapper, 'ภาพรวม').trigger('click')
    await tabButton(wrapper, 'ลิงก์สมัครตัวแทน').trigger('click')
    await flushPromises()

    expect(mounts).toEqual(['overview', 'signup'])
  })
})

describe('LinksHubView — the URL says which tab', () => {
  it('opens the tab named in ?tab=', async () => {
    mountHub({ tab: 'signup' })
    await flushPromises()

    // The three screens this replaced each had their own address. Losing that
    // would be a regression dressed as a consolidation.
    expect(mounts).toEqual(['signup'])
  })

  it('falls back to the overview for an unknown tab', async () => {
    mountHub({ tab: 'nonsense' })
    await flushPromises()

    expect(mounts).toEqual(['overview'])
  })

  it('writes the tab back to the URL with replace, not push', async () => {
    // Flipping tabs is not navigation. Pushing would fill the back button
    // with tab flips, so Back stops meaning "the page before this".
    const wrapper = mountHub()
    await tabButton(wrapper, 'ลิงก์ชวนทีม').trigger('click')

    expect(replace).toHaveBeenCalledWith({ query: { tab: 'team' } })
  })
})

describe('LinksHubView — the ?agent= deep link', () => {
  it('lands on the team tab even without ?tab=', async () => {
    // The "ดูในแท็บ ลิงก์ชวนทีม" jump from the agent editor aims at one tab.
    mountHub({ agent: '3' })
    await flushPromises()

    expect(mounts).toEqual(['team'])
  })

  it('beats a ?tab= that says otherwise', async () => {
    mountHub({ tab: 'signup', agent: '3' })
    await flushPromises()

    expect(mounts).toContain('team')
  })

  it('drops the agent filter when the user switches tab by hand', async () => {
    // Otherwise another tab inherits a filter nobody set and cannot see the
    // origin of.
    const wrapper = mountHub({ agent: '3' })
    await tabButton(wrapper, 'ภาพรวม').trigger('click')

    expect(replace).toHaveBeenCalledWith({ query: { tab: 'overview' } })
  })
})
