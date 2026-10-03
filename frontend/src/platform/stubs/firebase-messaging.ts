/**
 * MOB-28 (2026-10-02) — stand-in for the `firebase/messaging` web SDK.
 *
 * @capacitor-firebase/messaging ships a WEB implementation (dist/esm/web.js)
 * next to its native one, and that file imports `firebase/messaging` — the
 * plugin's OPTIONAL peer dependency, which this project deliberately does not
 * install: the web portal keeps its polling bell, and the full Firebase SDK
 * would be ~100 KB of nothing for it.
 *
 * The bundler still has to resolve that import to build the plugin's lazy
 * web chunk, so vite.config.ts points it here. The web half is never run:
 * a browser never loads the plugin at all (platform/push.ts returns before
 * the dynamic import), and inside the app Capacitor uses the native iOS /
 * Android implementation. If it ever were reached, every call reports "not
 * supported" instead of crashing.
 *
 * Remove the alias in vite.config.ts if web push is ever wanted — that is the
 * moment to install `firebase` for real.
 */
const NOT_INSTALLED =
  'firebase web SDK is not installed (see src/platform/stubs/firebase-messaging.ts)'

export function isSupported(): Promise<boolean> {
  return Promise.resolve(false)
}

export function getMessaging(): never {
  throw new Error(NOT_INSTALLED)
}

export function getToken(): Promise<never> {
  return Promise.reject(new Error(NOT_INSTALLED))
}

export function deleteToken(): Promise<never> {
  return Promise.reject(new Error(NOT_INSTALLED))
}

export function onMessage(): () => void {
  return () => {}
}
