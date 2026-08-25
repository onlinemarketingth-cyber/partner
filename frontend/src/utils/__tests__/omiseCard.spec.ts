import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { openCardForm, __resetOmiseLoaderForTests } from '../omiseCard'

/**
 * The card form's loader, and the ways a third-party script fails.
 *
 * ── WHY THE FAILURES ARE THE POINT ──
 *
 * The happy path is one function call into somebody else's SDK; there is
 * almost nothing of ours in it to get wrong. What IS ours is everything
 * around it: a customer on hotel wi-fi whose script never arrives, one who
 * double-taps the pay button, one who opens the form and changes their mind.
 * Each of those has a specific right answer, and each would otherwise show
 * up as a button that does nothing.
 *
 * ── WHAT THIS CANNOT TELL US ──
 *
 * OmiseCard is a stub here. These cases prove we call it correctly and
 * handle what it hands back; they cannot prove Omise's real form behaves as
 * the stub does. Only the test-mode transaction in Phase 5 can.
 */
describe('openCardForm', () => {
  let appended: HTMLScriptElement | null = null

  beforeEach(() => {
    vi.useFakeTimers()
    __resetOmiseLoaderForTests()
    appended = null

    // Capture the injected <script> so a test can decide whether it
    // "loads" or "fails", the way a network would.
    vi.spyOn(document.head, 'appendChild').mockImplementation(((node: Node) => {
      appended = node as HTMLScriptElement
      return node
    }) as typeof document.head.appendChild)
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
    __resetOmiseLoaderForTests()
  })

  /** Pretend the CDN answered and defined the global. */
  function scriptLoads(onOpen?: (options: Record<string, unknown>) => void) {
    ;(window as unknown as { OmiseCard: unknown }).OmiseCard = {
      configure: vi.fn(),
      open: vi.fn((options: Record<string, unknown>) => onOpen?.(options)),
    }
    appended?.dispatchEvent(new Event('load'))
  }

  it('resolves with the token the form produced', async () => {
    const promise = openCardForm({ publicKey: 'pkey_test_1', amountSatang: 890000 })
    scriptLoads((options) => {
      ;(options.onCreateTokenSuccess as (t: string) => void)('tokn_test_1')
    })

    await expect(promise).resolves.toBe('tokn_test_1')
  })

  it('passes satang through with no conversion', async () => {
    // A ×100 error on a payment form is the most expensive bug available in
    // this codebase, and the protection is that there is NO conversion. The
    // absence has to be asserted or it will not stay absent.
    let seen: Record<string, unknown> | null = null
    const promise = openCardForm({ publicKey: 'pkey_test_1', amountSatang: 890000 })
    scriptLoads((options) => {
      seen = options
      ;(options.onCreateTokenSuccess as (t: string) => void)('tokn_test_1')
    })
    await promise

    expect(seen!.amount).toBe(890000)
    expect(seen!.currency).toBe('THB')
  })

  it('resolves null when the customer closes the form without paying', async () => {
    // A cancellation is not an error. Somebody who changed their mind has
    // not failed at anything, and a red message would tell them they had.
    const promise = openCardForm({ publicKey: 'pkey_test_1', amountSatang: 100 })
    scriptLoads((options) => {
      ;(options.onFormClosed as () => void)()
    })

    await expect(promise).resolves.toBeNull()
  })

  it('keeps the token when the form closes immediately after producing one', async () => {
    // OmiseCard fires onFormClosed on EVERY close, including the one that
    // follows a success — so the null path runs after the token path in the
    // ordinary case. This pins the OUTCOME (the customer's payment is not
    // discarded), not any particular mechanism: it holds because a Promise
    // settles once, which is why the flag that used to guard it was deleted.
    const promise = openCardForm({ publicKey: 'pkey_test_1', amountSatang: 100 })
    scriptLoads((options) => {
      ;(options.onCreateTokenSuccess as (t: string) => void)('tokn_ok')
      ;(options.onFormClosed as () => void)()
    })

    await expect(promise).resolves.toBe('tokn_ok')
  })

  it('rejects with an actionable Thai message when the script cannot be fetched', async () => {
    // Hotel wi-fi, a corporate proxy, an ad blocker. The customer must be
    // told to use the bank transfer that is still on the same page, not left
    // pressing a button that does nothing.
    const promise = openCardForm({ publicKey: 'pkey_test_1', amountSatang: 100 })
    appended?.dispatchEvent(new Event('error'))

    await expect(promise).rejects.toThrow(/โอนเงิน/)
  })

  it('rejects rather than hanging when the script never answers', async () => {
    const promise = openCardForm({ publicKey: 'pkey_test_1', amountSatang: 100 })
    const assertion = expect(promise).rejects.toThrow(/ลองใหม่/)
    await vi.advanceTimersByTimeAsync(20000)
    await assertion
  })

  it('rejects when the script loads but defines no global', async () => {
    // A 200 that is not the SDK — a captive portal's login page, a CDN
    // error page. `load` fires, and without this check the next line would
    // read `.configure` off undefined.
    const promise = openCardForm({ publicKey: 'pkey_test_1', amountSatang: 100 })
    appended?.dispatchEvent(new Event('load'))

    await expect(promise).rejects.toThrow(/โหลดระบบชำระเงินไม่สำเร็จ/)
  })

  it('injects the script once however many times it is called', async () => {
    // A customer double-tapping the pay button must not get two <script>
    // tags racing to configure the same global.
    const first = openCardForm({ publicKey: 'pkey_test_1', amountSatang: 100 })
    const second = openCardForm({ publicKey: 'pkey_test_1', amountSatang: 100 })

    expect(document.head.appendChild).toHaveBeenCalledTimes(1)

    scriptLoads((options) => {
      ;(options.onCreateTokenSuccess as (t: string) => void)('tokn_ok')
    })

    await expect(first).resolves.toBe('tokn_ok')
    await expect(second).resolves.toBe('tokn_ok')
  })

  it('lets a customer retry after a failed load', async () => {
    // The in-flight promise is cleared on failure. Without that, one flaky
    // moment would leave the pay button permanently broken for the rest of
    // the session — the customer's only recourse being a page reload nobody
    // tells them to do.
    const first = openCardForm({ publicKey: 'pkey_test_1', amountSatang: 100 })
    appended?.dispatchEvent(new Event('error'))
    await expect(first).rejects.toThrow()

    const second = openCardForm({ publicKey: 'pkey_test_1', amountSatang: 100 })
    expect(document.head.appendChild).toHaveBeenCalledTimes(2)

    scriptLoads((options) => {
      ;(options.onCreateTokenSuccess as (t: string) => void)('tokn_retry')
    })
    await expect(second).resolves.toBe('tokn_retry')
  })
})
