/**
 * ADR-052 — ลิงก์ชวนทีม: the screen's one write (revoking a team leader's
 * recruit link) asks first, then ends in the one global "saved" dialog only
 * after the list has been re-read; a refusal raises nothing.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const del = vi.fn()

const { ApiErrorStub } = vi.hoisted(() => ({
  ApiErrorStub: class extends Error {
    status = 500
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    delete: (...args: unknown[]) => del(...args),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    postForm: vi.fn(),
  },
  ApiError: ApiErrorStub,
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

import AgentInviteLinksView from '../AgentInviteLinksView.vue'
import { SAVED_BUT_STALE_BODY, saveFeedbackState } from '@/composables/useSaveFeedback'

function link(over: Record<string, unknown> = {}) {
  return {
    id: 21,
    company_id: 4,
    agent_id: 9,
    label: 'ทีมภาคเหนือ',
    token: 'tok',
    public_url: 'https://example.test/r/tok',
    used_count: 1,
    max_uses: null,
    expires_at: null,
    revoked_at: null,
    is_usable: true,
    created_at: '2026-09-01T00:00:00Z',
    ...over,
  }
}

const AGENTS = [{ id: 9, name: 'หัวหน้า เหนือ', is_team_leader: true }]
let links: unknown[] = []
let failLinksRead = false

async function mountView() {
  const wrapper = mount(AgentInviteLinksView, {
    props: { embedded: true },
    global: {
      stubs: {
        HeroHeader: true,
        CompanyScopeNotice: true,
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        LinkQrModal: true,
        ConfirmDialog: {
          props: ['show', 'title', 'body', 'variant', 'busy'],
          template: '<div v-if="show" data-test="confirm" :data-variant="variant"><button data-test="confirm-yes" @click="$emit(\'confirm\')">ok</button></div>',
        },
      },
    },
  })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  get.mockReset()
  del.mockReset()
  links = [link()]
  failLinksRead = false
  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith('/agent-invite-links')) {
      if (failLinksRead) throw new ApiErrorStub('down')

      return { data: links }
    }

    return { data: AGENTS }
  })
})

describe('AgentInviteLinksView — ADR-052 saved dialog', () => {
  it('asks (danger) first and sends nothing until confirmed', async () => {
    const wrapper = await mountView()

    await wrapper.find('[data-test="revoke-team-link"]').trigger('click')

    expect(wrapper.find('[data-test="confirm"]').attributes('data-variant')).toBe('danger')
    expect(del).not.toHaveBeenCalled()
    expect(saveFeedbackState.show).toBe(false)
  })

  it('raises the dialog after the revoke resolved and the re-read list shows the link revoked', async () => {
    del.mockImplementation(async () => {
      // The server's copy carries a label the screen had not seen yet.
      links = [link({ label: 'ทีมภาคเหนือ (เดิม)', revoked_at: '2026-10-01T00:00:00Z', is_usable: false })]
    })
    const wrapper = await mountView()

    await wrapper.find('[data-test="revoke-team-link"]').trigger('click')
    await wrapper.find('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/agent-invite-links/21')
    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toContain('ทีมภาคเหนือ (เดิม)')
    expect(saveFeedbackState.body).toContain('หัวหน้า เหนือ')
    expect(wrapper.find('[data-test="revoke-team-link"]').exists()).toBe(false)
  })

  it('shows the error and raises no dialog when the revoke is refused', async () => {
    del.mockRejectedValue(new ApiErrorStub('refused'))
    const wrapper = await mountView()

    await wrapper.find('[data-test="revoke-team-link"]').trigger('click')
    await wrapper.find('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(false)
    expect(wrapper.find('.bg-rose-50').exists()).toBe(true)
  })

  it('says the screen may be behind when the revoke landed but the re-read failed', async () => {
    del.mockImplementation(async () => {
      failLinksRead = true
    })
    const wrapper = await mountView()

    await wrapper.find('[data-test="revoke-team-link"]').trigger('click')
    await wrapper.find('[data-test="confirm-yes"]').trigger('click')
    await flushPromises()

    expect(saveFeedbackState.show).toBe(true)
    expect(saveFeedbackState.body).toBe(SAVED_BUT_STALE_BODY)
  })
})
