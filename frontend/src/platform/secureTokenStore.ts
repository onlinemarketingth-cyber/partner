/**
 * MOB-01 (2026-10-02) — where the app keeps the sign-in token between
 * launches: the iOS Keychain / an Android Keystore-encrypted store.
 *
 * ── WHY NOT localStorage, WHICH THE WEB PORTAL USES ──
 *
 * Inside the app the page runs in a WebView, and iOS may clear a WebView's
 * localStorage when the phone is short of space. In a browser that costs a
 * refresh; in an app it signs the agent out for no reason they can see. The
 * Keychain is not cleared that way.
 *
 * ── WHAT THIS FILE DOES NOT CHANGE ──
 *
 * In a browser both functions do nothing: api/client.ts keeps its
 * localStorage path exactly as before, and the plugin is never even loaded
 * (dynamic import), so the web bundle a browser downloads does not grow.
 *
 * ── KNOWN BEHAVIOUR, NOT A BUG ──
 *
 * iOS does not delete Keychain items when an app is deleted, so a reinstall
 * can find an old token. The server gives tokens a 12h expiry and the first
 * request with a dead one returns 401, which purges it (api/client.ts
 * notifyIfUnauthorized) — the agent simply lands on the login screen.
 */
import { isNativeApp } from './index'

/** Same key name the web portal uses in localStorage (api/client.ts). */
const TOKEN_KEY = 'sva_token'

async function plugin() {
  const { SecureStorage } = await import('@aparajita/capacitor-secure-storage')

  return SecureStorage
}

/**
 * Writes run one after another, in the order they were asked for: a login
 * immediately followed by a logout must end with NO token stored, which two
 * independent promises could otherwise finish the wrong way round.
 */
let queue: Promise<unknown> = Promise.resolve()

/** The token saved by an earlier launch, or null. Never throws. */
export async function readNativeToken(): Promise<string | null> {
  if (!isNativeApp()) return null
  try {
    await queue
    const value = await (await plugin()).get(TOKEN_KEY)

    return typeof value === 'string' && value !== '' ? value : null
  } catch {
    // A failed read is "not signed in", never a crash at boot.
    return null
  }
}

/** Save (or, with null, forget) the token. Fire-and-forget; never throws. */
export function writeNativeToken(next: string | null): void {
  if (!isNativeApp()) return
  queue = queue
    .then(async () => {
      const store = await plugin()
      if (next) await store.set(TOKEN_KEY, next)
      else await store.remove(TOKEN_KEY)
    })
    .catch(() => {
      // The in-memory token in api/client.ts is still right for this launch;
      // the worst case is signing in again next time.
    })
}
