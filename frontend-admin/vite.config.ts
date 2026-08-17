import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig({
  // `vite-plugin-vue-devtools` was removed here (2026-08-03, human
  // request) — same call as the Agent Portal's vite.config.ts. It
  // injected a floating Vue-logo + inspector toolbar pinned to the
  // bottom-centre of every dev page. Dev-only, so the production build
  // is unaffected; the package remains in devDependencies.
  plugins: [
    vue(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    // Fixed at 5179 (human's choice, distinct from the Agent Portal's
    // 5178) so it doesn't fight the other app for a port — ADR-003.
    // strictPort means Vite fails loudly instead of silently picking a
    // different port that the backend's SANCTUM_STATEFUL_DOMAINS
    // doesn't know about (see the port-hopping issue this caused on
    // the agent app). Moved off the Vite default 5273 alongside the
    // Agent Portal's move off 5173, since the human's machine already
    // runs a different, unrelated project on 5173.
    port: 5179,
    strictPort: true,
    // Bug fix (2026-08-02) — this app is now visited at
    // http://admin.localhost:5179 (not bare "localhost") so its Sanctum
    // session cookie stops colliding with the Agent Portal's (see
    // backend/.env's SESSION_DOMAIN comment). Vite validates the
    // incoming Host header against allowedHosts as a DNS-rebinding
    // guard; explicitly allow the new hostname rather than disabling
    // the check.
    allowedHosts: ['admin.localhost'],
  },
})
