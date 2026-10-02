/**
 * 2026-10-02 — the cron warning a Super Admin sees.
 *
 * ── WHAT BREAKS SILENTLY IF THESE ASSERTIONS ARE LOST ──
 *
 * 1. A DEAD CRON IS INVISIBLE AGAIN. Nine jobs (ranks, renewals, emails…)
 *    fail without an error when schedule:run stops; this banner is the only
 *    place anyone learns of it.
 * 2. A FALSE ALARM. A failed CHECK is not proof cron is down — saying so
 *    would send an admin to hPanel for nothing, and teach them to ignore it.
 * 3. THE WRONG AUDIENCE. Only a Super Admin can fix a cron entry, and the
 *    endpoint 403s everyone else — they must never even send the request.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()

vi.mock('@/api/client', () => ({
  api: { get: (...args: unknown[]) => get(...args), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  ApiError: class extends Error {},
}))

const route = { path: '/dashboard', meta: {} as Record<string, unknown> }

vi.mock('vue-router', () => ({ useRoute: () => route, useRouter: () => ({ push: vi.fn() }) }))

import SchedulerHealthBanner from '../SchedulerHealthBanner.vue'
import { useAuthStore } from '@/stores/auth'

const health = (over: Record<string, unknown>) => ({
  data: { last_run_at: null, minutes_since_last_run: null, is_running: false, stale_after_minutes: 10, ...over },
})

async function mountAs(role: 'super_admin' | 'company_admin') {
  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ใช้', role } as never
  auth.status = 'ready'
  const wrapper = mount(SchedulerHealthBanner, { global: { stubs: { Icon: true, InfoPopover: true } } })
  await flushPromises()

  return wrapper
}

const banner = (w: Awaited<ReturnType<typeof mountAs>>) => w.find('[data-test="scheduler-health-banner"]')

beforeEach(() => {
  get.mockReset()
  route.meta = {}
})

describe('SchedulerHealthBanner', () => {
  it('says so when cron has never run', async () => {
    get.mockResolvedValue(health({}))
    const w = await mountAs('super_admin')

    expect(get).toHaveBeenCalledWith('/platform/scheduler-health')
    expect(banner(w).exists()).toBe(true)
    expect(w.text()).toContain('งานอัตโนมัติ (cron) ยังไม่เคยทำงาน')
  })

  it('says how long it has been quiet', async () => {
    get.mockResolvedValue(
      health({ last_run_at: '2026-10-02T01:00:00+07:00', minutes_since_last_run: 150, is_running: false }),
    )
    const w = await mountAs('super_admin')

    expect(w.text()).toContain('หยุดทำงานมา 2 ชั่วโมง')
    expect(w.text()).toContain('ทำงานครั้งล่าสุด')
  })

  it('stays hidden while cron is running', async () => {
    get.mockResolvedValue(health({ last_run_at: '2026-10-02T01:00:00+07:00', minutes_since_last_run: 0, is_running: true }))
    const w = await mountAs('super_admin')

    expect(banner(w).exists()).toBe(false)
  })

  it('a failed check raises no alarm', async () => {
    get.mockRejectedValue(new Error('network'))
    const w = await mountAs('super_admin')

    expect(banner(w).exists()).toBe(false)
  })

  it('a Company Admin never asks and never sees it', async () => {
    get.mockResolvedValue(health({}))
    const w = await mountAs('company_admin')

    expect(get).not.toHaveBeenCalled()
    expect(banner(w).exists()).toBe(false)
  })
})
