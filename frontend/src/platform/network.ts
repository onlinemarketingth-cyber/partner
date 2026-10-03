/**
 * MOB-27 (2026-10-02) — "no internet" inside the app.
 *
 * In a browser, losing the connection is the browser's problem: it shows its
 * own offline page on the next navigation. The app's pages are bundled, so
 * nothing like that ever appears — every screen still opens, and then every
 * list fails to load with a different red message. An agent on a train sees
 * a broken app, not a missing signal.
 *
 * So the app watches the connection (@capacitor/network) and, while there is
 * none, covers the screen with ONE plain Thai notice and a retry button
 * (NativeAppLayer.vue). It goes away by itself when the connection returns.
 *
 * In a browser nothing is installed and `offline` stays false for ever —
 * the web portal is unchanged.
 */
import { reactive } from 'vue'
import { isNativeApp } from './index'

export const networkState = reactive({ offline: false, checking: false })

async function plugin() {
  const { Network } = await import('@capacitor/network')

  return Network
}

/** App only: read the current status, then follow every change. */
export async function installNetworkWatcher(): Promise<void> {
  if (!isNativeApp()) return
  try {
    const network = await plugin()
    await network.addListener('networkStatusChange', (status) => {
      networkState.offline = !status.connected
    })
    const status = await network.getStatus()
    networkState.offline = !status.connected
  } catch {
    // Without the plugin the app behaves like the web portal: no notice.
  }
}

/** The notice's retry button. Resolves to true when the app is back online. */
export async function recheckNetwork(): Promise<boolean> {
  if (!isNativeApp()) return true

  networkState.checking = true
  try {
    const status = await (await plugin()).getStatus()
    networkState.offline = !status.connected
  } catch {
    // Leave the notice as it is; the change listener still clears it.
  } finally {
    networkState.checking = false
  }

  return !networkState.offline
}
