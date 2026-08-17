import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

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
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
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
