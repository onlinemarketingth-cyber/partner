/**
 * ADR-052 — on ทีมของฉัน, a write is announced only once the screen shows
 * what the server now holds.
 *
 * confirmApprove() used to FILTER the pending queue locally and toast
 * before re-reading the roster; confirmRevoke() toasted before its re-read.
 * Pinned here:
 *   - approve: toast after PUT resolves AND both the queue and the roster
 *     have been re-read; the queue and roster are the SERVER's, and the
 *     name in the toast is the one the server answered with;
 *   - approve rejected: no success toast, the recruit is still pending;
 *   - a failed re-read after a successful approve is an info toast, not a
 *     failed approve;
 *   - revoke: asks first (danger), toasts only after the links re-read.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const put = vi.fn()
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
    put: (...args: unknown[]) => put(...args),
    patch: vi.fn(),
    delete: (...args: unknown[]) => del(...args),
  },
  ApiError: FakeApiError,
}))

import MyTeamView from '../MyTeamView.vue'
import ConfirmDialog from '@/design-system/components/ConfirmDialog.vue'
import { useAuthStore, type AuthUser } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const ZERO = {
  client_count: 0,
  deals_by_stage: {},
  total_deals: 0,
  closed_deals: 0,
  sales_satang: 0,
  commission_satang: 0,
  my_override_satang: 0,
}

function node(id: number, name: string) {
  return { agent_id: id, name, avatar_url: null, cert_tier: null, has_children: false, ...ZERO }
}

function team(nodes: unknown[]) {
  return {
    data: {
      is_leader: true,
      visibility_level: 'names',
      parent_id: null,
      totals: { member_count: nodes.length, ...ZERO },
      nodes,
    },
  }
}

const RECRUIT = {
  id: 50,
  name: 'ผู้สมัคร ก',
  avatar_url: null,
  registered_at: '2026-09-30T10:00:00Z',
  email_verified: true,
  invite_link: null,
}

function inviteLink(overrides: Record<string, unknown> = {}) {
  return {
    id: 8,
    company_id: 1,
    agent_id: 9,
    label: 'ลิงก์งานแฟร์',
    token: 'tok',
    public_url: 'https://x.test/j/tok',
    short_url: null,
    used_count: 0,
    max_uses: null,
    expires_at: null,
    revoked_at: null,
    is_usable: true,
    created_at: '2026-09-01T00:00:00Z',
    ...overrides,
  }
}

interface Reloads {
  team?: () => Promise<unknown>
  recruits?: () => Promise<unknown>
  links?: () => Promise<unknown>
}

/** Each endpoint answers its first call with the page-load fixture, later calls with the reload. */
function wire(reloads: Reloads = {}) {
  const calls: Record<string, number> = {}
  get.mockImplementation((path: string) => {
    calls[path] = (calls[path] ?? 0) + 1
    const first = calls[path] === 1
    if (path === '/me/team')
      return first ? Promise.resolve(team([node(1, 'ลูกทีมเดิม')])) : (reloads.team?.() ?? Promise.resolve(team([])))
    if (path === '/agent-approvals/my-recruits')
      return first ? Promise.resolve({ data: [RECRUIT] }) : (reloads.recruits?.() ?? Promise.resolve({ data: [] }))
    if (path === '/agent-invite-links')
      return first ? Promise.resolve({ data: [inviteLink()] }) : (reloads.links?.() ?? Promise.resolve({ data: [] }))
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

async function mountTeam() {
  const wrapper = mount(MyTeamView, {
    global: {
      stubs: {
        HeroHeader: true,
        Icon: true,
        LoadingSkeleton: true,
        ShareLinkModal: true,
        ConfirmDialog: true,
        BuddhistDateInput: true,
        Teleport: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

async function openRecruitAndApprove(wrapper: Awaited<ReturnType<typeof mountTeam>>) {
  const row = wrapper.findAll('button').find((b) => b.text().includes(RECRUIT.name))
  if (!row) throw new Error('no pending-recruit row')
  await row.trigger('click')
  const approve = wrapper.findAll('button').find((b) => b.text().includes('อนุมัติเข้าทีม'))
  if (!approve) throw new Error('no approve button in the recruit sheet')
  await approve.trigger('click')
  await flushPromises()
}

const successes = () => useToastStore().toasts.filter((t) => t.variant === 'success')

beforeEach(() => {
  setActivePinia(createPinia())
  useAuthStore().user = { id: 9, name: 'หัวหน้าทีม', can_recruit: true, is_team_leader: true } as unknown as AuthUser
  get.mockReset()
  put.mockReset()
  del.mockReset()
})

describe('MyTeamView — approve reads queue and roster back from the server (ADR-052)', () => {
  it('toasts only after PUT and both re-reads; shows the SERVER\'s queue, roster and name', async () => {
    const roster = deferred<unknown>()
    wire({ team: () => roster.promise, recruits: () => Promise.resolve({ data: [] }) })
    put.mockResolvedValue({ data: { id: 50, name: 'ผู้สมัคร ก (ชื่อในระบบ)' } })
    const wrapper = await mountTeam()

    await openRecruitAndApprove(wrapper)

    expect(put).toHaveBeenCalledWith('/agent-approvals/50/approve')
    // Roster re-read still in flight — nothing announced yet.
    expect(successes()).toHaveLength(0)

    roster.resolve(team([node(1, 'ลูกทีมเดิม'), node(50, 'สมาชิกใหม่จากเซิร์ฟเวอร์')]))
    await flushPromises()

    expect(successes().map((t) => t.message)).toEqual(['รับ ผู้สมัคร ก (ชื่อในระบบ) เข้าทีมแล้ว'])
    expect(wrapper.text()).toContain('สมาชิกใหม่จากเซิร์ฟเวอร์')
    // The queue is the server's (empty), not a local filter of the old one.
    expect(get.mock.calls.filter((c) => c[0] === '/agent-approvals/my-recruits')).toHaveLength(2)
    expect(wrapper.findAll('button').some((b) => b.text().includes(RECRUIT.name))).toBe(false)
  })

  it('the queue shows what the server says, even when that is not what a local filter would give', async () => {
    const other = { ...RECRUIT, id: 51, name: 'ผู้สมัคร ข (มาใหม่)' }
    wire({ recruits: () => Promise.resolve({ data: [other] }) })
    put.mockResolvedValue({ data: { id: 50, name: 'ผู้สมัคร ก' } })
    const wrapper = await mountTeam()

    await openRecruitAndApprove(wrapper)

    expect(wrapper.text()).toContain('ผู้สมัคร ข (มาใหม่)')
  })

  it('a rejected approve: no success toast, the recruit is still pending, no re-read', async () => {
    wire()
    put.mockRejectedValue(new FakeApiError(422, {}))
    const wrapper = await mountTeam()

    await openRecruitAndApprove(wrapper)

    expect(successes()).toHaveLength(0)
    expect(useToastStore().toasts.some((t) => t.variant === 'error')).toBe(true)
    expect(wrapper.text()).toContain(RECRUIT.name)
    expect(get.mock.calls.filter((c) => c[0] === '/agent-approvals/my-recruits')).toHaveLength(1)
  })

  it('a failed queue re-read after a successful approve is an info toast, not a failed approve', async () => {
    wire({ recruits: () => Promise.reject(new FakeApiError(500, {})) })
    put.mockResolvedValue({ data: { id: 50, name: 'ผู้สมัคร ก' } })
    const wrapper = await mountTeam()

    await openRecruitAndApprove(wrapper)

    const toasts = useToastStore().toasts
    expect(toasts.filter((t) => t.variant === 'success')).toHaveLength(1)
    expect(toasts.some((t) => t.variant === 'error')).toBe(false)
    expect(toasts.filter((t) => t.variant === 'info').map((t) => t.message)).toEqual([
      'โหลดข้อมูลล่าสุดไม่สำเร็จ — ข้อมูลบนหน้าจออาจไม่เป็นปัจจุบัน',
    ])
    // The approve DID happen, so the recruit is not left offered for approval.
    expect(wrapper.findAll('button').some((b) => b.text().includes(RECRUIT.name))).toBe(false)
  })
})

describe('MyTeamView — revoking an invite link (ADR-052)', () => {
  it('asks first (danger), then toasts only after the links re-read, showing the SERVER\'s status', async () => {
    const links = deferred<unknown>()
    wire({ links: () => links.promise })
    del.mockResolvedValue('')
    const wrapper = await mountTeam()

    const trash = wrapper.findAll('button').find((b) => b.find('icon-stub[name="trash"]').exists())
    await trash!.trigger('click')
    const dialog = wrapper.findComponent(ConfirmDialog)
    expect(dialog.props('show')).toBe(true)
    expect(dialog.props('variant')).toBe('danger')
    expect(del).not.toHaveBeenCalled()

    dialog.vm.$emit('confirm')
    await flushPromises()
    expect(del).toHaveBeenCalledWith('/agent-invite-links/8')
    expect(successes()).toHaveLength(0)

    links.resolve({ data: [inviteLink({ revoked_at: '2026-10-01T00:00:00Z', is_usable: false })] })
    await flushPromises()

    expect(successes().map((t) => t.message)).toEqual(['ยกเลิกลิงก์แล้ว'])
    // The server's verdict: revoked, so no revoke control remains.
    expect(wrapper.findAll('button').some((b) => b.find('icon-stub[name="trash"]').exists())).toBe(false)
  })
})
