/**
 * TASK-241 — "which user did what", asked from the screen.
 *
 * The backend half (TASK-240) added `?actor_user_id=` to /audit-logs. This
 * file is about the four ways the FRONT half of that question goes wrong,
 * each of which looks fine on screen:
 *
 *  1. FILTERING IN THE BROWSER. The obvious implementation filters
 *     `auditRows` with an Array.filter and renders the result. It looks
 *     right on a demo database and lies on a real one: this table paginates
 *     at 15, so "everything ผู้ใช้ #7 did" would mean "the rows of ผู้ใช้ #7
 *     that happen to be on page 1 of everybody's". Every assertion below
 *     that matters checks the REQUEST, not the DOM.
 *
 *  2. THE FILTER NOT SURVIVING THE URL. The question is usually asked from
 *     somewhere else (the agent roster's "ดูกิจกรรมของผู้ใช้นี้") and the
 *     answer is usually shown to somebody else. State that lives only in the
 *     component can be neither linked to nor sent.
 *
 *  3. THE NAME RIDING ALONG IN THE URL. A person's name in a query string
 *     ends up in browser history and in the Referer header of everything the
 *     next page loads. §6/PDPA — the id travels, the name does not.
 *
 *  4. A NARROWED TABLE THAT DOES NOT SAY IT IS NARROWED. Arriving on a
 *     filtered screen is visually identical to a quiet day across the whole
 *     platform, and "no wrongdoing found" is the worst possible thing for
 *     this screen to imply by accident.
 *
 * BR-6 — that a Company Admin sees only their own people in the dropdown —
 * is enforced by TenantScope on /users and covered by
 * AuditLogActorFilterTest server-side. What this layer can be held to is
 * that it asks the scoped endpoint, and that is asserted.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const download = vi.fn()
const replace = vi.fn()
const query: Record<string, string> = {}

const { FakeApiError } = vi.hoisted(() => ({
  FakeApiError: class extends Error {
    constructor(
      public status: number,
      public body: unknown,
    ) {
      // The real ApiError lifts Laravel's own message out of the body — the
      // 422 from the export says by HOW MUCH the range is too wide, and a
      // stub that drops it would let a screen that swallows it pass.
      super(
        (body as { message?: string } | null)?.message ?? `API error ${status}`,
      )
    }
  },
}))

vi.mock('@/api/client', () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    put: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    postForm: vi.fn(),
    download: (...args: unknown[]) => download(...args),
  },
  ApiError: FakeApiError,
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query }),
  useRouter: () => ({ replace, push: vi.fn() }),
}))

import PolicyReportView from '../PolicyReportView.vue'
import { useActiveCompanyStore } from '@/stores/activeCompany'
import { useAuthStore } from '@/stores/auth'

const ACTORS = [
  { id: 7, name: 'เกรียงยศ โอหุยหะนภา' },
  { id: 9, name: 'สมชาย ใจดี' },
]

const AUDIT_ROW = {
  id: 1,
  company_id: 4,
  actor_name: 'เกรียงยศ โอหุยหะนภา',
  action: 'user.role_changed',
  auditable_type: 'App\\Models\\User',
  auditable_id: 9,
  old_values: { role: 'agent' },
  new_values: { role: 'company_admin' },
  ip_address: '203.0.113.9',
  created_at: '2026-09-05T03:00:00Z',
}

const COMPANIES = [{ id: 4, name: 'ไทยประกันชีวิต', slug: 'thailife' }]

function mockApi() {
  get.mockImplementation(async (path: string) => {
    if (path.startsWith('/users')) return { data: ACTORS, meta: { last_page: 1 } }
    /*
     * NOT a detail. loadCompanies() drops a selected company that is not in
     * the list it gets back (a company deleted, or no longer visible to this
     * actor), so a stub that answers /companies with anything else silently
     * resets the header scope to "ทุกบริษัท" mid-test — and every assertion
     * about company_id then fails for a reason that has nothing to do with
     * the code under test.
     */
    if (path.startsWith('/companies')) return { data: COMPANIES }

    return {
      data: [AUDIT_ROW],
      meta: { current_page: 1, last_page: 1, total: 1, per_page: 15 },
    }
  })
}

/** Every path the component asked for, in order. */
const requestedPaths = (): string[] => get.mock.calls.map((c) => c[0] as string)
const auditPaths = (): string[] => requestedPaths().filter((p) => p.startsWith('/audit-logs'))
/**
 * The request the table is currently showing. (No Array#at — see tsconfig's
 * lib target.) Throws rather than returning undefined: every caller below is
 * about the SHAPE of that request, and `expect(undefined).not.toContain(...)`
 * would pass for a screen that made no request at all.
 */
function lastAuditPath(): string {
  const paths = auditPaths()
  if (!paths.length) throw new Error('the view made no /audit-logs request')

  return paths[paths.length - 1]!
}
const lastCallOf = (fn: typeof replace) => fn.mock.calls[fn.mock.calls.length - 1]

async function mountView(routeQuery: Record<string, string> = {}) {
  for (const key of Object.keys(query)) delete query[key]
  Object.assign(query, routeQuery)

  const wrapper = mount(PolicyReportView, {
    global: {
      stubs: {
        HeroHeader: { template: '<div><slot name="tabs" /></div>' },
        EmptyState: true,
        Icon: true,
        LoadingSkeleton: true,
        DateRangeFilter: true,
        PlatformScopeBadge: true,
      },
    },
  })
  await flushPromises()

  return wrapper
}

type Wrapper = Awaited<ReturnType<typeof mountView>>

const actorSelect = (wrapper: Wrapper) => wrapper.find('[data-test="actor-filter"]')

async function chooseActor(wrapper: Wrapper, id: number) {
  await actorSelect(wrapper).setValue(String(id))
  await wrapper.findAll('button').find((b) => b.text() === 'กรอง')!.trigger('click')
  await flushPromises()
}

beforeEach(() => {
  get.mockReset()
  download.mockReset()
  download.mockResolvedValue(undefined)
  replace.mockReset()
  mockApi()

  const auth = useAuthStore()
  auth.user = { id: 1, name: 'ผู้ดูแลระบบ', role: 'super_admin' } as never

  const activeCompany = useActiveCompanyStore()
  activeCompany.companies = COMPANIES as never
  activeCompany.setCompany(4)
})

describe('PolicyReportView — filtering the trail by person', () => {
  it('sends the chosen person to the API rather than sifting the page in the browser', async () => {
    // Defect 1. This table paginates; a client-side filter answers a
    // different question than the one asked and cannot be told apart from
    // the right answer by looking.
    const wrapper = await mountView()
    expect(lastAuditPath()).not.toContain('actor_user_id')

    await chooseActor(wrapper, 7)

    expect(lastAuditPath()).toContain('actor_user_id=7')
  })

  it('goes back to page 1 when the filter changes', async () => {
    // Page 4 of everybody is not page 4 of one person, and the row count
    // under a new filter is usually smaller than the page you were on.
    const wrapper = await mountView()

    await chooseActor(wrapper, 7)

    expect(lastAuditPath()).toContain('page=1')
  })

  it('keeps the other filters instead of replacing them', async () => {
    // Two filters that silently cancel each other are worse than one.
    const wrapper = await mountView()
    await wrapper.find('input[placeholder^="เช่น"]').setValue('user.role_changed')

    await chooseActor(wrapper, 7)

    const last = lastAuditPath()
    expect(last).toContain('actor_user_id=7')
    expect(last).toContain('action=user.role_changed')
  })

  it('still narrows by the company in the header', async () => {
    // ADR-038 — the header scope and the actor filter are different
    // questions and both have to reach the query.
    const wrapper = await mountView()

    await chooseActor(wrapper, 7)

    expect(lastAuditPath()).toContain('company_id=4')
  })
})

describe('PolicyReportView — the filter in the URL', () => {
  it('writes the choice into the address bar so the answer can be linked to', async () => {
    // Defect 2.
    const wrapper = await mountView()

    await chooseActor(wrapper, 7)

    expect(replace).toHaveBeenCalledWith({ query: expect.objectContaining({ actor: '7' }) })
  })

  it('puts the id in the URL and never the name', async () => {
    /*
     * Defect 3, and the reason this is a test rather than a code comment:
     * `?actor=เกรียงยศ...` would read BETTER to a developer looking at the
     * address bar, which is exactly how it gets written. It leaks a person's
     * name into history and into the Referer of every asset the next page
     * loads.
     */
    const wrapper = await mountView()

    await chooseActor(wrapper, 7)

    const written = JSON.stringify(replace.mock.calls)
    expect(written).toContain('"actor":"7"')
    expect(written).not.toContain('เกรียงยศ')
  })

  it('replaces rather than pushes, so Back leaves the page instead of walking the filters', async () => {
    const wrapper = await mountView()

    await chooseActor(wrapper, 7)
    await chooseActor(wrapper, 9)

    expect(replace).toHaveBeenCalledTimes(2)
  })

  it('opens already filtered when arrived at with ?actor=', async () => {
    /*
     * The "ดูกิจกรรมของผู้ใช้นี้" jump from the agent roster. The FIRST audit
     * request has to carry the filter: loading everything and then narrowing
     * shows one flash of the whole platform's trail — rows about people the
     * viewer opened this page with no intention of reading.
     */
    await mountView({ actor: '7' })

    expect(auditPaths()[0]).toContain('actor_user_id=7')
    expect(auditPaths().some((p) => !p.includes('actor_user_id'))).toBe(false)
  })

  it('ignores a nonsense ?actor= instead of asking the API about it', async () => {
    // A hand-edited or truncated URL. '' means everybody; it must never
    // become NaN in a query string.
    await mountView({ actor: 'ฉันไม่ใช่ตัวเลข' })

    expect(auditPaths()[0]).not.toContain('actor_user_id')
    expect(auditPaths()[0]).not.toContain('NaN')
  })
})

describe('PolicyReportView — saying that the table is narrowed', () => {
  it('names the person the table is limited to', async () => {
    // Defect 4.
    const wrapper = await mountView({ actor: '7' })

    expect(wrapper.text()).toContain('กำลังดูเฉพาะกิจกรรมของ')
    expect(wrapper.text()).toContain('เกรียงยศ โอหุยหะนภา')
  })

  it('says nothing when no filter is applied', async () => {
    const wrapper = await mountView()

    expect(wrapper.text()).not.toContain('กำลังดูเฉพาะกิจกรรมของ')
  })

  it('still says it is narrowed when the name cannot be resolved', async () => {
    /*
     * The dropdown lists the CURRENT company's people; a deep link can name
     * somebody who is not in it (another company, or a roster that failed to
     * load). Falling back to no chip at all would silently turn "one
     * person's trail" into what looks like the whole platform's.
     */
    const wrapper = await mountView({ actor: '4242' })

    expect(wrapper.text()).toContain('ผู้ใช้ #4242')
  })

  it('clears back to everybody, in the table and in the URL together', async () => {
    const wrapper = await mountView({ actor: '7' })

    await wrapper.findAll('button').find((b) => b.text() === 'ดูของทุกคน')!.trigger('click')
    await flushPromises()

    expect(lastAuditPath()).not.toContain('actor_user_id')
    // A URL left carrying ?actor= after the table stopped honouring it is a
    // link that lies to whoever it is sent to.
    expect(JSON.stringify(lastCallOf(replace))).not.toContain('actor')
  })
})

describe('PolicyReportView — the list of people to choose from', () => {
  it('reads the whole roster, including deactivated accounts', async () => {
    /*
     * /users paginates at 15 with no ?per_page, so a bare GET offers the
     * alphabetically-first fifteen and quietly makes everybody else
     * unaskable-about. include_inactive because a closed account is exactly
     * the one somebody comes here to ask about.
     */
    await mountView()

    const users = requestedPaths().filter((p) => p.startsWith('/users'))
    expect(users.length).toBeGreaterThan(0)
    expect(users[0]).toContain('include_inactive=1')
  })

  it('asks for the header company\'s people, and asks once', async () => {
    /*
     * fetchAllPages applies scopedPath itself. Wrapping the path in
     * scopedPath as well sends company_id TWICE — PHP takes the last, so it
     * works right up until the two values differ.
     */
    await mountView()

    const users = requestedPaths().filter((p) => p.startsWith('/users'))
    expect(users[0]).toContain('company_id=4')
    expect(users[0]!.match(/company_id=/g)).toHaveLength(1)
  })

  it('keeps the trail on screen when the roster will not load', async () => {
    // The dropdown is a convenience. The log is why anybody opened the page,
    // and it must not go down with a lookup.
    get.mockImplementation(async (path: string) => {
      if (path.startsWith('/users')) throw new Error('boom')

      return { data: [AUDIT_ROW], meta: { current_page: 1, last_page: 1, total: 1, per_page: 15 } }
    })

    const wrapper = await mountView()

    expect(wrapper.text()).toContain('เปลี่ยนบทบาท')
    expect(actorSelect(wrapper).exists()).toBe(true)
  })
})

describe('PolicyReportView — reading the rows', () => {
  it('translates the new account and login actions instead of printing their keys', async () => {
    // TASK-237/238/240 added around twenty actions. An unmapped one still
    // renders (raw), so the failure mode is a screen of dotted identifiers
    // rather than missing rows — cosmetic, invisible to a smoke test, and
    // exactly what a human notices first.
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('เปลี่ยนบทบาท')
    expect(wrapper.text()).not.toContain('user.role_changed')
  })

  it('warns that reads are not recorded, because absence of a row is not absence of an event', async () => {
    /*
     * The most dangerous way to read this screen is as a complete record of
     * what happened. It holds CHANGES and logins; general viewing is not
     * recorded, and someone concluding "he never opened it" from an empty
     * trail would be wrong.
     */
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('ยังไม่เก็บการ “เปิดดู” ข้อมูลทั่วไป')
  })
})

describe('PolicyReportView — taking the trail away as a file', () => {
  const exportButton = (wrapper: Wrapper) => wrapper.find('[data-test="export-audit-csv"]')

  /** The path handed to api.download — asserted on, so its absence must fail. */
  function downloadedPath(): string {
    const call = download.mock.calls[0]
    if (!call) throw new Error('the view asked for no download')

    return call[0] as string
  }

  it('exports what is on screen, not everything', async () => {
    /*
     * The file is handed to somebody who was not in the room. If it answers
     * a WIDER question than the table it was taken from — every company, or
     * every person — the discrepancy is discovered by the auditor holding
     * it, and by then it is a document that says something nobody checked.
     */
    const wrapper = await mountView({ actor: '7' })
    await wrapper.find('input[placeholder^="เช่น"]').setValue('user.role_changed')

    await exportButton(wrapper).trigger('click')
    await flushPromises()

    const path = downloadedPath()
    expect(path).toContain('/audit-logs/export')
    expect(path).toContain('actor_user_id=7')
    expect(path).toContain('action=user.role_changed')
    expect(path).toContain('company_id=4')
  })

  it('asks for no page, because a file is not a page of a table', async () => {
    const wrapper = await mountView()

    await exportButton(wrapper).trigger('click')
    await flushPromises()

    expect(downloadedPath()).not.toContain('page=')
  })

  it('goes through the authenticated download, never a bare link', async () => {
    // Section 5 rule 6 — an <a href> carries no session cookie and no XSRF
    // token, so the server could not record who took the copy even if it
    // wanted to. The whole point of TASK-242 is that it can.
    const wrapper = await mountView()

    await exportButton(wrapper).trigger('click')
    await flushPromises()

    expect(download).toHaveBeenCalledTimes(1)
  })

  it('shows the server\'s reason when the range is too wide', async () => {
    /*
     * The refusal is actionable ONLY if the number survives: "ส่งออกไม่สำเร็จ"
     * tells an admin to try again, and they will — with the same dates.
     */
    download.mockRejectedValue(
      new FakeApiError(422, { message: 'ช่วงเวลาที่เลือกกว้าง 2192 วัน — ส่งออกได้ครั้งละไม่เกิน 366 วัน กรุณาแบ่งช่วงเวลา' }),
    )

    const wrapper = await mountView()
    await exportButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('366')
    expect(wrapper.text()).toContain('2192')
  })

  it('says on screen what the file will contain before it is asked for', async () => {
    // The two rules a person has to know BEFORE clicking: the window is
    // capped, and the export is itself recorded. Told afterwards, the first
    // is an error message and the second is a surprise.
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('ไม่เกิน 1 ปี')
    expect(wrapper.text()).toContain('บันทึกทุกครั้งที่มีการส่งออก')
  })

  it('translates its own action in the table instead of printing the key', async () => {
    get.mockImplementation(async (path: string) => {
      if (path.startsWith('/users')) return { data: ACTORS, meta: { last_page: 1 } }
      if (path.startsWith('/companies')) return { data: COMPANIES }

      return {
        data: [{ ...AUDIT_ROW, action: 'audit_log.exported' }],
        meta: { current_page: 1, last_page: 1, total: 1, per_page: 15 },
      }
    })

    const wrapper = await mountView()

    expect(wrapper.text()).toContain('ส่งออกบันทึกการตรวจสอบเป็นไฟล์ CSV')
    expect(wrapper.text()).not.toContain('audit_log.exported')
  })
})
