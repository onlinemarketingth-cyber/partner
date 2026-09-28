/**
 * ADR-049 — the "ทีมของฉัน" entry on Home follows `can_recruit`.
 *
 * Owner, 2026-09-28: "ผมไม่เจอ ทีมของฉัน". An agent who had passed Basic but
 * held no team-leader flag and had no reports yet saw no way in at all, so
 * "ชวนเข้าทีม" was unreachable for the person the plan needs to recruit.
 * Under ADR-049 the server says who may recruit; Home only follows it.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
    downloadAbsolute: vi.fn(),
  },
  ApiError: class extends Error {},
}))

import HomeView from '../HomeView.vue'
import { useAuthStore } from '@/stores/auth'

function homePayload(directReports: number) {
  return {
    profile: { id: 7, name: 'สมชาย ใจดี', avatar_url: null },
    gamification: { level_number: 1, total_xp: 0, level_xp_floor: 0, next_level_xp: 100, badges_count: 0 },
    goals: [],
    task_counts: { follow_ups_due: 0, open_deals: 0, failed_exams: 0 },
    unread_notifications: 0,
    direct_reports_count: directReports,
  }
}

async function mountHome(user: Record<string, unknown>, directReports = 0) {
  get.mockImplementation(async (path: string) => {
    if (path === '/me/home') return { data: homePayload(directReports) }
    if (path === '/me/tasks') return { data: { follow_ups: [], open_deals: [], failed_exams: [] } }
    if (path === '/announcement-settings') return { data: { repeat_count: 4, display_style: 'bottom_sheet' } }

    return { data: [] }
  })
  useAuthStore().user = { id: 7, name: 'สมชาย ใจดี', role: 'agent', ...user } as never

  const wrapper = mount(HomeView, {
    global: {
      stubs: {
        RouterLink: { props: ['to'], template: '<a :data-to="to"><slot /></a>' },
        AnnouncementModal: true,
        AnnouncementBanner: true,
        ProgressRing: true,
        Icon: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

function hasTeamEntry(wrapper: Awaited<ReturnType<typeof mountHome>>) {
  return wrapper.find('[data-to="/my-team"]').exists()
}

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
})

describe('the ทีมของฉัน entry', () => {
  it('shows for an agent the server says may recruit, even with no flag and no reports', async () => {
    expect(hasTeamEntry(await mountHome({ is_team_leader: false, can_recruit: true }))).toBe(true)
  })

  it('stays hidden for an agent who may not recruit and has no team', async () => {
    expect(hasTeamEntry(await mountHome({ is_team_leader: false, can_recruit: false }))).toBe(false)
  })

  it('can_recruit wins over a stale flag', async () => {
    expect(hasTeamEntry(await mountHome({ is_team_leader: true, can_recruit: false }))).toBe(false)
  })

  it('still shows for someone with reports, whatever their recruiting right', async () => {
    expect(hasTeamEntry(await mountHome({ is_team_leader: false, can_recruit: false }, 2))).toBe(true)
  })

  it('falls back to the flag for a user cached before can_recruit existed', async () => {
    expect(hasTeamEntry(await mountHome({ is_team_leader: true }))).toBe(true)
  })
})
