import { fileURLToPath, URL } from 'node:url'

import { defineConfig, type Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'

/**
 * MOB-25 (2026-10-02) — `viewport-fit=cover`, for the iOS/Android app build
 * ONLY (`vite build --mode app`, i.e. `npm run build:app`).
 *
 * It lets the app draw edge to edge — under the status bar and the home
 * indicator — and is what makes `env(safe-area-inset-*)` report real values
 * there, which App.vue / BottomNav pad by.
 *
 * Not in the web build, on purpose: in mobile Safari the same tag also makes
 * those insets non-zero, which would add ~34px under the web BottomNav and
 * change the browser portal's layout. The owner's condition for the app is
 * that the web portal stays exactly as it is.
 */
function appViewportFit(): Plugin {
  let mode = ''

  return {
    name: 'app-viewport-fit',
    configResolved(config) {
      mode = config.mode
    },
    transformIndexHtml(html) {
      if (mode !== 'app') return html

      return html.replace(
        '<meta name="viewport" content="width=device-width, initial-scale=1.0">',
        '<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">',
      )
    },
  }
}

// https://vite.dev/config/
export default defineConfig({
  // `vite-plugin-vue-devtools` was removed here (2026-08-03, human
  // request). It injected a floating toolbar pill — a Vue logo + an
  // inspector crosshair — fixed to the bottom-centre of every dev page,
  // which on this mobile-width app sat directly on top of the BottomNav
  // and covered the middle tab ("ขาย"). It only ever rendered in dev, so
  // nothing about the production build changes; the package is still in
  // devDependencies if it's ever wanted back.
  plugins: [
    vue(),
    appViewportFit(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      // MOB-28 — the push plugin's optional `firebase` peer is not installed;
      // its never-run web half is pointed at a stub. See the stub's header.
      'firebase/messaging': fileURLToPath(
        new URL('./src/platform/stubs/firebase-messaging.ts', import.meta.url),
      ),
    },
  },
  server: {
    // Pinned at 5178 (human's choice) with strictPort so this app
    // never silently auto-hops to another port when its port is taken
    // — that's what broke the Sanctum CSRF/cookie handshake earlier
    // (backend's SANCTUM_STATEFUL_DOMAINS only knew about the port we
    // told it about). Fails loudly instead, which is easier to
    // diagnose. Moved off the Vite default 5173 because the human's
    // machine already runs a different, unrelated project on 5173.
    port: 5178,
    strictPort: true,
    // Bug fix (2026-08-02) — this app is now visited at
    // http://agent.localhost:5178 (not bare "localhost") so its Sanctum
    // session cookie stops colliding with the Admin app's (see
    // backend/.env's SESSION_DOMAIN comment). Vite validates the
    // incoming Host header against allowedHosts as a DNS-rebinding
    // guard; explicitly allow the new hostname rather than disabling
    // the check.
    allowedHosts: ['agent.localhost'],
  },
})
