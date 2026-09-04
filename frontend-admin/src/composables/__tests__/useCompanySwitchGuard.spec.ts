/**
 * Switching company while a RECORD page is open.
 *
 * Human-reported 2026-09-04: the header switched, the page underneath did
 * not, and a half-typed product edit was gone. The human's rule — switching
 * wins, but ask first when there is unsaved work — is only worth anything if
 * all four halves hold, so all four are pinned here:
 *
 *   nothing typed → switch happens, page leaves for the list, no dialog
 *   unsaved work  → dialog; "เปลี่ยนบริษัท" switches and leaves
 *                          "แก้ไขต่อ" changes NOTHING, not even the picker
 *
 * The last one is the one a refactor breaks silently: an implementation that
 * writes the company first and undoes it afterwards passes a casual click-
 * through and still leaves every screen that watches `companyId` reloading
 * for a company the human just declined.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref, nextTick } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'

const push = vi.fn()

vi.mock('vue-router', () => ({
  useRouter: () => ({ push, replace: vi.fn() }),
  useRoute: () => ({ name: 'product-edit', params: { id: 7 }, query: {} }),
}))

vi.mock('@/api/client', () => ({
  api: { get: vi.fn().mockResolvedValue({ data: [] }), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
  ApiError: class extends Error {},
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
}))

import { useCompanySwitchGuard } from '../useCompanySwitchGuard'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import { useAuthStore } from '@/stores/auth'

/** A page that has the guard mounted, with dirtiness the test controls. */
function mountRecordPage(dirty = ref(false)) {
  let api: ReturnType<typeof useCompanySwitchGuard> | undefined

  const wrapper = mount(
    defineComponent({
      setup() {
        api = useCompanySwitchGuard({
          isDirty: () => dirty.value,
          leaveTo: { name: 'product-catalog' },
        })

        return () => h('div')
      },
    }),
  )

  return { wrapper, guard: api!, dirty }
}

function superAdminStore() {
  const auth = useAuthStore()
  // Only a Super Admin can switch at all — a Company Admin's scope is
  // pinned server-side, so the picker is a label for them.
  auth.user = { id: 1, name: 'ผู้ดูแล', role: 'super_admin' } as never

  const store = useActiveCompanyStore()
  store.companies = [
    { id: 1, name: 'ไทยประกันชีวิต', slug: 'thailife' },
    { id: 2, name: 'Genesenn', slug: 'genesenn' },
  ]
  store.setCompany(1)

  return store
}

/*
 * NO setActivePinia HERE, deliberately.
 *
 * vitest.setup.ts already creates one Pinia per test and installs it BOTH as
 * the active instance and as the mount plugin. Creating another here would
 * only replace the active one — the mounted component would keep using the
 * plugin's, and the composable's store and the test's store would be two
 * different objects that never see each other. That is not a hypothetical:
 * it is what these tests did on the first run, and every assertion failed
 * for a reason that had nothing to do with the code under test.
 */
beforeEach(() => {
  push.mockReset()
  localStorage.clear()
})

describe('useCompanySwitchGuard', () => {
  it('lets the switch through untouched when nothing has been typed', async () => {
    const store = superAdminStore()
    mountRecordPage(ref(false))

    const allowed = await store.requestCompany(2)

    expect(allowed).toBe(true)
    expect(store.companyId).toBe(2)
    // …and does not sit on the old company's record afterwards.
    expect(push).toHaveBeenCalledWith({ name: 'product-catalog' })
  })

  it('asks before throwing away unsaved work', async () => {
    const store = superAdminStore()
    const { guard } = mountRecordPage(ref(true))

    const pending = store.requestCompany(2)
    await nextTick()

    expect(guard.asking.value).toBe(true)
    // Nothing has moved while the human is still reading the question.
    expect(store.companyId).toBe(1)

    guard.confirmLeave()

    expect(await pending).toBe(true)
    expect(store.companyId).toBe(2)
    expect(push).toHaveBeenCalledWith({ name: 'product-catalog' })
  })

  it('"แก้ไขต่อ" changes NOTHING — not the company, not the page', async () => {
    const store = superAdminStore()
    const { guard } = mountRecordPage(ref(true))

    const pending = store.requestCompany(2)
    await nextTick()
    guard.stay()

    expect(await pending).toBe(false)
    // THE POINT: the value was never written, so the picker still reads the
    // company this page belongs to and no screen watching companyId fired.
    expect(store.companyId).toBe(1)
    expect(push).not.toHaveBeenCalled()
    expect(guard.asking.value).toBe(false)
  })

  it('stops guarding once the record page is gone', async () => {
    const store = superAdminStore()
    const { wrapper } = mountRecordPage(ref(true))

    wrapper.unmount()
    await flushPromises()

    // A guard left registered by an unmounted page would hang every future
    // switch on a dialog nobody can see.
    expect(await store.requestCompany(2)).toBe(true)
    expect(store.companyId).toBe(2)
  })

  it('does not ask when the picker names the company already selected', async () => {
    const store = superAdminStore()
    const { guard } = mountRecordPage(ref(true))

    expect(await store.requestCompany(1)).toBe(true)
    expect(guard.asking.value).toBe(false)
  })
})
