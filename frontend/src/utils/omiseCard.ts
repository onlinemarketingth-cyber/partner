/**
 * Omise's own card form, loaded on demand.
 *
 * ── WHY OMISE'S FORM AND NOT OURS ──
 *
 * OmiseCard renders Omise's hosted fields in an iframe on omise.co. The card
 * number is typed into a document this application does not own and cannot
 * read, so it never enters our DOM, our JavaScript, our error reports or our
 * analytics — only a one-time token comes back out.
 *
 * The alternative (our own <input>s, tokenised via Omise.createToken) also
 * keeps the card off our server, and it is what most integration guides
 * show. It is not what this does, because "off the server" and "out of the
 * page" are different promises: with our own inputs the number is in our
 * JS heap, and every future script we add to this page — a tag manager, a
 * session recorder, a bug reporter — inherits access to it. That is a
 * decision made once, here, rather than re-litigated by whoever adds the
 * next script.
 *
 * ── LOADED ONLY WHEN A CARD IS ACTUALLY OFFERED ──
 *
 * The script tag is injected on the first openCardForm() call, not at import
 * and not on page load. Most orders on this platform are paid by bank
 * transfer and never see a card form; those customers should not fetch a
 * third-party script, and the pay page must keep working with no network
 * path to Omise at all.
 *
 * ── WHAT HAPPENS WHEN IT FAILS ──
 *
 * Every failure rejects with a Thai message the customer can act on. There
 * is no silent fallback: a card form that quietly did nothing would leave
 * somebody clicking a button, and the bank-transfer instructions are still
 * on the same page for them to use instead.
 */

const SCRIPT_URL = 'https://cdn.omise.co/omise.js'
const SCRIPT_ID = 'omise-js'
const LOAD_TIMEOUT_MS = 15000

interface OmiseCardGlobal {
  configure(options: { publicKey: string; currency?: string; frameLabel?: string }): void
  open(options: {
    amount: number
    currency: string
    defaultPaymentMethod?: string
    frameDescription?: string
    onCreateTokenSuccess(token: string): void
    onFormClosed(): void
  }): void
}

declare global {
  interface Window {
    OmiseCard?: OmiseCardGlobal
  }
}

/**
 * One in-flight load shared by every caller.
 *
 * Without this, a customer who clicks the pay button twice while the script
 * is still downloading gets two <script> tags and two OmiseCard globals
 * racing to configure themselves with the same key.
 */
let loading: Promise<OmiseCardGlobal> | null = null

function loadOmiseJs(): Promise<OmiseCardGlobal> {
  if (window.OmiseCard) return Promise.resolve(window.OmiseCard)
  if (loading) return loading

  loading = new Promise<OmiseCardGlobal>((resolve, reject) => {
    const fail = (message: string) => {
      // Cleared so a later attempt can retry — a customer whose first load
      // failed on a flaky connection must not be stuck with a permanently
      // rejected promise for the rest of their session.
      loading = null
      reject(new Error(message))
    }

    const timer = window.setTimeout(
      () => fail('เชื่อมต่อระบบชำระเงินไม่สำเร็จ กรุณาลองใหม่ หรือชำระผ่านการโอนเงิน'),
      LOAD_TIMEOUT_MS,
    )

    const settle = () => {
      window.clearTimeout(timer)
      if (window.OmiseCard) {
        resolve(window.OmiseCard)
      } else {
        fail('โหลดระบบชำระเงินไม่สำเร็จ กรุณาลองใหม่ หรือชำระผ่านการโอนเงิน')
      }
    }

    // Re-use a tag an earlier attempt already injected rather than adding a
    // second one.
    const existing = document.getElementById(SCRIPT_ID) as HTMLScriptElement | null
    const script = existing ?? document.createElement('script')

    script.addEventListener('load', settle, { once: true })
    script.addEventListener(
      'error',
      () => {
        window.clearTimeout(timer)
        // The tag is removed so the next attempt starts clean; a failed
        // script element never fires load again.
        script.remove()
        fail('เชื่อมต่อระบบชำระเงินไม่สำเร็จ กรุณาลองใหม่ หรือชำระผ่านการโอนเงิน')
      },
      { once: true },
    )

    if (!existing) {
      script.id = SCRIPT_ID
      script.src = SCRIPT_URL
      script.async = true
      document.head.appendChild(script)
    }
  })

  return loading
}

export interface CardFormOptions {
  /** The pkey_ from the server. NEVER a secret key — that one never leaves it. */
  publicKey: string
  /** BR-3 — satang, straight through. Omise's unit for THB is satang too. */
  amountSatang: number
  /** Shown in Omise's own form so the customer can see what they are paying for. */
  description?: string
  /** The company's name, shown as the form's title. */
  merchantLabel?: string
}

/**
 * Open Omise's card form and resolve with a one-time token.
 *
 * Resolves with `null` when the customer CLOSED the form — a deliberate
 * choice, not an error. Somebody who changed their mind about paying by card
 * has not failed at anything, and showing them a red message would say they
 * had.
 */
export function openCardForm(options: CardFormOptions): Promise<string | null> {
  return loadOmiseJs().then(
    (omiseCard) =>
      new Promise<string | null>((resolve) => {
        omiseCard.configure({
          publicKey: options.publicKey,
          currency: 'THB',
          frameLabel: options.merchantLabel ?? '',
        })

        omiseCard.open({
          // No ×100 anywhere. Omise counts THB in satang and BR-3 stores
          // satang, so the correct conversion is none — and the absence of
          // one is easier to verify than the correctness of one.
          amount: options.amountSatang,
          currency: 'THB',
          defaultPaymentMethod: 'credit_card',
          frameDescription: options.description ?? '',
          onCreateTokenSuccess: (token: string) => resolve(token),
          /*
           * OmiseCard fires this on EVERY close, including the one that
           * follows a success — so this resolve(null) usually runs after the
           * token has already been resolved above. That is safe, and safe by
           * the language rather than by anything written here: a Promise
           * settles once and ignores every later attempt.
           *
           * An earlier draft had a `settled` flag guarding this line.
           * Mutation testing removed the flag and every test still passed,
           * which is the correct result — the flag protected nothing, while
           * reading as though it did. Deleted rather than kept with a test
           * that cannot fail.
           */
          onFormClosed: () => resolve(null),
        })
      }),
  )
}

/** Test seam — lets a spec reset the module's one-load-at-a-time state. */
export function __resetOmiseLoaderForTests(): void {
  loading = null
  document.getElementById(SCRIPT_ID)?.remove()
  delete window.OmiseCard
}
