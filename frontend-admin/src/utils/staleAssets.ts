/**
 * 2026-09-10 (human: "ปิดหน้า admin ค้างไว้ ... กดปุ่มทำงานอะไรไม่ได้
 * ต้องกดปุ่ม refresh ถึงกลับมาทำงานได้").
 *
 * ── THE TAB THAT WAS OPEN DURING A DEPLOY ──
 *
 * Every route in this console is a lazy `import()`, and Vite names each built
 * chunk with a content hash. `scripts/deploy.sh` rsyncs with `--delete`, so
 * the moment a new build lands the OLD chunk filenames stop existing on the
 * server.
 *
 * A tab that was already open is still holding the old `index.html` in memory,
 * and it will keep asking for the filenames that HTML named. Every one of them
 * now 404s. Vue Router treats a failed dynamic import as a failed navigation:
 * the URL does not change, the screen does not change, nothing is logged where
 * anybody would look — clicking the menu simply does nothing. Reloading fetches
 * the new HTML with the new names and everything works again, which is exactly
 * the shape of the report.
 *
 * Nobody is signed out by this, which is why it does not look like a session
 * problem and why refreshing "fixes" it without a login.
 *
 * ── WHY RELOADING IS THE RIGHT ANSWER AND NOT A WORKAROUND ──
 *
 * There is nothing to retry. The file the running app wants is gone from the
 * server on purpose, and the only version of this app that can work is the one
 * that ships with the new index.html. So the app fetches it — the same thing
 * the person would do by hand, at the moment we can already prove it is
 * needed, instead of leaving them to guess.
 */

/** How long to refuse a second reload. Long enough that a broken deploy cannot
 *  put the browser in a reload loop; short enough to be invisible otherwise. */
const RELOAD_COOLDOWN_MS = 15_000

const RELOAD_MARK_KEY = 'admin.staleAssetReloadAt'

/**
 * Is this the browser telling us a JS/CSS chunk could not be fetched?
 *
 * Matched on the message because that is all any of these give us: the browser
 * reports a failed module fetch as a plain TypeError, and each engine words it
 * differently. Deliberately narrow — a general "reload on any error" would
 * paper over real bugs by hiding them behind a refresh.
 */
export function isStaleAssetError(error: unknown): boolean {
  const message = error instanceof Error ? error.message : String(error ?? '')

  return /failed to fetch dynamically imported module/i.test(message)
    || /error loading dynamically imported module/i.test(message)
    || /importing a module script failed/i.test(message)
    || /unable to preload/i.test(message)
}

/**
 * Reload onto `target`, at most once per cooldown.
 *
 * The guard is the important half. If the new build is itself broken — a chunk
 * that 404s on a fresh load — an unguarded reload would spin the browser
 * forever and take the error message with it every time. One reload either
 * fixes it or leaves the failure on screen where somebody can read it.
 */
export function reloadForStaleAssets(target?: string): boolean {
  const now = Date.now()

  try {
    const last = Number(window.sessionStorage.getItem(RELOAD_MARK_KEY) ?? '0')
    if (Number.isFinite(last) && now - last < RELOAD_COOLDOWN_MS) return false

    window.sessionStorage.setItem(RELOAD_MARK_KEY, String(now))
  } catch {
    // Private mode, or storage disabled. One reload is still better than a
    // console whose menu does nothing, so this falls through rather than
    // refusing — the cooldown is a safety net, not a precondition.
  }

  window.location.assign(target ?? `${window.location.pathname}${window.location.search}`)

  return true
}

/**
 * Wire both signals the browser gives us.
 *
 *   • `router.onError` — the navigation that failed, and the only one that
 *     knows WHERE the person was trying to go. Reloading onto that path means
 *     they land on the screen they clicked rather than back where they were.
 *   • `vite:preloadError` — fired when a preloaded chunk fails before any
 *     navigation is involved. Default-prevented first: without that Vite
 *     rethrows it as an unhandled rejection, which helps nobody once we have
 *     already decided what to do about it.
 */
export function installStaleAssetRecovery(router: {
  onError: (handler: (error: unknown, to: { fullPath: string }) => void) => void
}): void {
  router.onError((error, to) => {
    if (isStaleAssetError(error)) reloadForStaleAssets(to.fullPath)
  })

  window.addEventListener('vite:preloadError', (event) => {
    event.preventDefault()
    reloadForStaleAssets()
  })
}
