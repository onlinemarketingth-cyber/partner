/**
 * TASK-259 — "ทำหน้าจัดการ user ระบบ และให้ทำงานได้จริงตามสิทธิ์ที่กำหนดไว้".
 *
 * The second half of that sentence is what these tests are for. A screen with
 * buttons on other people's accounts has three ways to be wrong, and all
 * three look fine in a screenshot:
 *
 *  1. IT OFFERS WHAT THE SERVER WILL REFUSE. Every button here is rendered
 *     from the `permissions` object the API computes per row by asking
 *     UserPolicy. If this screen re-derived the rule ("super admin, or same
 *     company, and never yourself"), the copy would drift and an admin would
 *     meet a 403 from a button the product showed them.
 *
 *  2. IT HIDES WHAT THE SERVER WOULD ALLOW. The same defect in the other
 *     direction, and the one nobody reports as a bug — they just conclude
 *     the feature does not exist. (That is exactly how this task started:
 *     promoting an agent was possible in the API and impossible in the UI.)
 *
 *  3. IT DOES SOMETHING IRREVERSIBLE QUIETLY. Deactivating an account and
 *     changing a role both revoke every session that person is holding
 *     (TASK-238). An admin has to be told that BEFORE the click.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const put = vi.fn()
const post = vi.fn()
const del = vi.fn()

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: (...args: unknown[]) => put(...args),
    post: (...args: unknown[]) => post(...args),
    delete: (...args: unknown[]) => del(...args),
    patch: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: class extends Error {},
}))

vi.mock('vue-router', async () => {
  const actual = await vi.importActual<typeof import('vue-router')>('vue-router')

  return { ...actual, useRoute: () => ({ query: {} }), useRouter: () => ({ push: vi.fn(), replace: vi.fn() }) }
})

import UserManagementView from '../UserManagementView.vue'
import { useAuthStore } from '@/stores/auth'
import { useActiveCompanyStore } from '@/stores/activeCompany'

const ALL = { update: true, deactivate: true, restore: true, move_company: true }

function makeUser(over: Partial<Record<string, unknown>> = {}) {
  return {
    id: 7,
    name: 'สมชาย ใจดี',
    // TASK-246 — the editable halves. `name` is the server's joined display
    // string; the edit form writes these two, never a split of that one.
    first_name: 'สมชาย',
    last_name: 'ใจดี',
    phone: null,
    email: 'somchai@example.com',
    role: 'agent',
    company: { id: 4, name: 'ไทยประกันชีวิต' },
    is_active: true,
    is_team_leader: false,
    last_login_at: '2026-09-04T02:00:00Z',
    created_at: '2026-01-01T00:00:00Z',
    permissions: { ...ALL },
    ...over,
  }
}

/**
 * The company list the store will load. It matters more than it looks:
 * activeCompany.loadCompanies() DROPS a selected id that is not in this list
 * (that is how a stale persisted company is cleaned up), so a stub that
 * answered /companies with anything else would silently reset the scope and
 * hide exactly the controls under test.
 */
const COMPANIES = [
  { id: 4, name: 'ไทยประกันชีวิต', slug: 'tli' },
  { id: 5, name: 'AIA', slug: 'aia' },
]

function mockUsers(users: unknown[]) {
  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith('/companies')) return { data: COMPANIES }

    return { data: users }
  })
}

async function mountView() {
  const wrapper = mount(UserManagementView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div />' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        ConfirmDialog: {
          name: 'ConfirmDialog',
          props: ['show', 'title', 'body', 'confirmLabel', 'variant'],
          template: '<div v-if="show" data-test="confirm"><p>{{ title }}</p><p>{{ body }}</p><button data-test="confirm-yes" @click="$emit(\'confirm\')">ok</button></div>',
        },
        RouterLink: { props: ['to'], template: '<a><slot /></a>' },
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

const btn = (wrapper: Wrapper, test: string) => wrapper.find(`[data-test="${test}"]`)

beforeEach(() => {
  get.mockReset()
  put.mockReset()
  post.mockReset()
  del.mockReset()
  put.mockResolvedValue({ data: {} })
  post.mockResolvedValue({ data: {} })
  del.mockResolvedValue(undefined)
  mockUsers([makeUser()])

  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never
  useActiveCompanyStore().setCompany(null)
})

describe('UserManagementView — the buttons come from the server', () => {
  it('asks the API for the permissions and the last login', async () => {
    // Without both flags the screen would have to guess at the first and
    // invent the second.
    await mountView()

    const path = get.mock.calls[0]![0] as string
    expect(path).toContain('with_permissions=1')
    expect(path).toContain('with_last_login=1')
  })

  it('hides an action the server says this viewer may not take', async () => {
    // Defect 1. The row is visible; the button is not.
    mockUsers([makeUser({ permissions: { ...ALL, deactivate: false } })])

    const wrapper = await mountView()

    expect(btn(wrapper, 'deactivate').exists()).toBe(false)
    expect(btn(wrapper, 'reset-password').exists()).toBe(true)
  })

  it('offers promotion when the server allows it', async () => {
    /*
     * Defect 2, and the reason this task exists: the API has allowed
     * agent → company_admin since UpdateUserRequest was written, and the only
     * form that could reach it hid the option. In practice "promote somebody"
     * meant editing the database by hand.
     */
    const wrapper = await mountView()

    expect(btn(wrapper, 'promote').exists()).toBe(true)
  })

  it('offers demotion on an admin, not promotion', async () => {
    mockUsers([makeUser({ role: 'company_admin' })])

    const wrapper = await mountView()

    expect(btn(wrapper, 'demote').exists()).toBe(true)
    expect(btn(wrapper, 'promote').exists()).toBe(false)
  })

  it('shows only the restore button on a closed account', async () => {
    // A deactivated user cannot be promoted or have a password set — those
    // buttons would be offering to configure an account that cannot log in.
    mockUsers([makeUser({ is_active: false })])

    const wrapper = await mountView()

    expect(btn(wrapper, 'restore').exists()).toBe(true)
    expect(btn(wrapper, 'deactivate').exists()).toBe(false)
    expect(btn(wrapper, 'promote').exists()).toBe(false)
    expect(btn(wrapper, 'reset-password').exists()).toBe(false)
  })
})

describe('UserManagementView — saying what an action costs', () => {
  it('warns that a promotion signs the person out, before the click', async () => {
    /*
     * Defect 3. TASK-238 revokes every token on a rights change — correct,
     * and invisible: the promoted person is simply logged out mid-task and
     * nobody can explain why.
     */
    const wrapper = await mountView()
    await btn(wrapper, 'promote').trigger('click')

    const dialog = wrapper.find('[data-test="confirm"]')
    expect(dialog.exists()).toBe(true)
    expect(dialog.text()).toContain('ถอนการเข้าใช้งาน')
    // …and nothing has been sent yet.
    expect(put).not.toHaveBeenCalled()
  })

  it('sends the role change only after it is confirmed', async () => {
    const wrapper = await mountView()
    await btn(wrapper, 'promote').trigger('click')
    await btn(wrapper, 'confirm-yes').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/users/7', { role: 'company_admin' })
  })

  it('warns that deactivation is immediate but reversible', async () => {
    // Both halves matter: an admin who thinks it is destructive will avoid
    // using it, and one who thinks it is gentle will use it on the wrong row.
    const wrapper = await mountView()
    await btn(wrapper, 'deactivate').trigger('click')

    const dialog = wrapper.find('[data-test="confirm"]')
    expect(dialog.text()).toContain('เข้าระบบไม่ได้ทันที')
    expect(dialog.text()).toContain('กู้คืนบัญชีได้ภายหลัง')
    expect(del).not.toHaveBeenCalled()
  })

  it('says a reset revokes the old sessions, on the dialog that does it', async () => {
    const wrapper = await mountView()
    await btn(wrapper, 'reset-password').trigger('click')

    expect(wrapper.find('[data-test="reset-dialog"]').text()).toContain('ถอนทันที')
  })

  it('refuses a password the server would refuse, without a round trip', async () => {
    // Not a substitute for the server rule — a first answer that does not
    // cost the admin a failed request and a re-typed password.
    const wrapper = await mountView()
    await btn(wrapper, 'reset-password').trigger('click')
    await btn(wrapper, 'new-password').setValue('short')
    await btn(wrapper, 'submit-reset').trigger('click')
    await flushPromises()

    expect(post).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="reset-dialog"]').text()).toContain('อย่างน้อย 8 ตัว')
  })

  it('never echoes the new password back after saving it', async () => {
    // It is a credential in transit to a human, not a value to leave on a
    // screen somebody may walk away from.
    const wrapper = await mountView()
    await btn(wrapper, 'reset-password').trigger('click')
    await btn(wrapper, 'new-password').setValue('Correct8Horse')
    await btn(wrapper, 'submit-reset').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/users/7/reset-password', { password: 'Correct8Horse' })
    expect(wrapper.text()).not.toContain('Correct8Horse')
  })
})

describe('UserManagementView — what the list says', () => {
  it('spells out that nobody has a recorded login rather than printing a dash', async () => {
    /*
     * Null means "no login recorded since auditing began (2026-08-21)", which
     * is narrower than "never signed in". A dash invites the wider reading,
     * and somebody eventually closes an account over it.
     */
    mockUsers([makeUser({ last_login_at: null })])

    const wrapper = await mountView()

    expect(wrapper.find('tbody').text()).toContain('ไม่พบบันทึกการเข้าระบบ')
  })

  it('explains why no Super Admin is in the list', async () => {
    // Otherwise an empty admin column reads as "this platform has no
    // administrator", which is alarming and false.
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('ไม่แสดง Super Admin')
    expect(wrapper.text()).toContain('admin:create-super')
  })

  it('filters by role at the API, not in the browser', async () => {
    // /users paginates. Filtering the page in the browser would answer "the
    // admins who happen to be on page 1 of everybody".
    const wrapper = await mountView()
    await wrapper.find('[data-test="role-filter"]').setValue('company_admin')
    await flushPromises()

    expect(get.mock.calls[get.mock.calls.length - 1]![0] as string).toContain('role=company_admin')
  })

  it('shows closed accounts by default, unlike the agent roster', async () => {
    // "Who can get into this system" includes the accounts somebody closed —
    // a deactivated admin must not be invisible on the screen that accounts
    // for admins.
    await mountView()

    expect(get.mock.calls[0]![0] as string).toContain('include_inactive=1')
  })

  it('marks your own row so an admin knows which one they are', async () => {
    mockUsers([makeUser({ id: 1, name: 'ผู้ดูแลระบบ', permissions: { ...ALL, deactivate: false } })])

    const wrapper = await mountView()

    expect(wrapper.find('tbody').text()).toContain('(คุณ)')
    expect(btn(wrapper, 'deactivate').exists()).toBe(false)
  })
})

/**
 * TASK-246 — the three things this screen could not do.
 *
 * It could change a role, close an account and reset a password, but it could
 * not CREATE an account, could not fix a typo in the address somebody logs in
 * with, and never read the `move_company` permission it had been receiving
 * since the day it was built. A page called "จัดการผู้ใช้ระบบ" that cannot add
 * a user sends the admin to the agent roster (which hides the ผู้ดูแลบริษัท
 * option) or to the database by hand.
 */
describe('UserManagementView — creating an account', () => {
  it('sends the typed password and the scoped company', async () => {
    /*
     * StoreUserRequest REQUIRES company_id from a Super Admin — they belong to
     * no company, so there is nothing to infer — and the scoped company is the
     * only honest answer available.
     */
    useActiveCompanyStore().setCompany(4)

    const wrapper = await mountView()
    await btn(wrapper, 'open-create').trigger('click')
    await btn(wrapper, 'create-first-name').setValue('อารีย์')
    await btn(wrapper, 'create-last-name').setValue('ทองดี')
    await btn(wrapper, 'create-email').setValue('aree@example.com')
    await btn(wrapper, 'create-password').setValue('Str0ngPass')
    await btn(wrapper, 'submit-create').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/users', {
      first_name: 'อารีย์',
      last_name: 'ทองดี',
      email: 'aree@example.com',
      password: 'Str0ngPass',
      role: 'company_admin',
      company_id: 4,
    })
  })

  it('refuses to guess a company in ทุกบริษัท mode', async () => {
    // The alternative is picking one for them, which files a person under a
    // tenant nobody chose.
    const wrapper = await mountView()
    await btn(wrapper, 'open-create').trigger('click')

    expect(btn(wrapper, 'create-needs-company').exists()).toBe(true)
    expect((btn(wrapper, 'submit-create').element as HTMLButtonElement).disabled).toBe(true)
  })

  it('does not repeat the new password back on screen', async () => {
    /*
     * The admin typed it a second ago; printing it into a success banner is
     * how a live credential ends up in a screenshot. The banner says to hand
     * it over, not what it is.
     */
    useActiveCompanyStore().setCompany(4)
    post.mockResolvedValue({ data: { ...makeUser(), name: 'อารีย์ ทองดี', role: 'company_admin' } })

    const wrapper = await mountView()
    await btn(wrapper, 'open-create').trigger('click')
    await btn(wrapper, 'create-first-name').setValue('อารีย์')
    await btn(wrapper, 'create-last-name').setValue('ทองดี')
    await btn(wrapper, 'create-email').setValue('aree@example.com')
    await btn(wrapper, 'create-password').setValue('Str0ngPass')
    await btn(wrapper, 'submit-create').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('สร้างบัญชี อารีย์ ทองดี')
    expect(wrapper.text()).not.toContain('Str0ngPass')
  })

  it('rejects a short password before sending it', async () => {
    useActiveCompanyStore().setCompany(4)

    const wrapper = await mountView()
    await btn(wrapper, 'open-create').trigger('click')
    await btn(wrapper, 'create-password').setValue('short')
    await btn(wrapper, 'submit-create').trigger('click')
    await flushPromises()

    expect(post).not.toHaveBeenCalled()
    expect(btn(wrapper, 'create-error').exists()).toBe(true)
  })
})

describe('UserManagementView — editing an account', () => {
  it('sends the two name halves rather than a split of the display name', async () => {
    const wrapper = await mountView()
    await btn(wrapper, 'edit-user').trigger('click')
    await btn(wrapper, 'edit-email').setValue('somchai.new@example.com')
    await btn(wrapper, 'submit-edit').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/users/7', {
      first_name: 'สมชาย',
      last_name: 'ใจดี',
      email: 'somchai.new@example.com',
      phone: null,
    })
  })

  it('sends null for a phone left blank, not an empty string', async () => {
    // '' fails the string rule server-side; null is the value the column
    // actually holds for "no phone".
    const wrapper = await mountView()
    await btn(wrapper, 'edit-user').trigger('click')
    await btn(wrapper, 'edit-phone').setValue('   ')
    await btn(wrapper, 'submit-edit').trigger('click')
    await flushPromises()

    expect(put.mock.calls[0]![1]).toMatchObject({ phone: null })
  })

  it('is hidden when the server says this row is not the viewer\'s to change', async () => {
    mockUsers([makeUser({ permissions: { ...ALL, update: false } })])

    const wrapper = await mountView()

    expect(btn(wrapper, 'edit-user').exists()).toBe(false)
  })
})

describe('UserManagementView — moving an account to another company', () => {
  it('offers it only when the server says so', async () => {
    mockUsers([makeUser({ permissions: { ...ALL, move_company: false } })])

    const wrapper = await mountView()

    expect(btn(wrapper, 'move-company').exists()).toBe(false)
  })

  it('never offers the company the person is already in', async () => {
    const wrapper = await mountView()
    await btn(wrapper, 'move-company').trigger('click')
    await flushPromises()

    const options = btn(wrapper, 'move-target').findAll('option').map((o) => o.text())
    expect(options).toContain('AIA')
    expect(options).not.toContain('ไทยประกันชีวิต')
  })

  it('says what changes before the move, then sends it', async () => {
    const wrapper = await mountView()
    await btn(wrapper, 'move-company').trigger('click')
    await flushPromises()

    // The account's whole tenant changes; what already happened does not.
    expect(wrapper.text()).toContain('ยังอยู่กับบริษัทเดิม')

    await btn(wrapper, 'move-target').setValue(5)
    await btn(wrapper, 'submit-move').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/users/7/move-company', { company_id: 5 })
  })
})
