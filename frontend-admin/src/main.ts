import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import VueApexCharts from 'vue3-apexcharts'

import App from './App.vue'
import router from './router'
import { setUnauthorizedHandler } from './api/client'
import { useAuthStore } from './stores/auth'

const app = createApp(App)

app.use(createPinia())
app.use(router)
// TASK-052 / ADR-015 — global <apexchart> for the chart-based Agent
// Dashboard (first chart dependency in the repo).
app.use(VueApexCharts)

// Bug fix — see api/client.ts's comment on setUnauthorizedHandler: a
// session that expires mid-use now clears the stale client-side user
// and sends the person back to login instead of leaving them stuck on
// a page repeatedly showing raw "(401)" errors.
setUnauthorizedHandler(() => {
  const authStore = useAuthStore()
  authStore.user = null
  if (router.currentRoute.value.name !== 'login') {
    router.push({ name: 'login' })
  }
})

/*
 * 2026-09-09 (human: "ตอนนี้ session หมดอายุ ไม่เด้งไปที่ login").
 *
 * The handler above is REACTIVE: it only learns the session died when a
 * request fails, so a tab left open overnight sits there showing yesterday's
 * page until somebody clicks something — and what they usually click is Save,
 * which is the worst moment to find out.
 *
 * Coming back to the tab is exactly the moment to check, and it costs one
 * request at the one instant a person is about to act. fetchUser() clears the
 * user itself on failure, and the router guard sends a signed-out visitor to
 * the login page, so there is nothing to duplicate here.
 *
 * Guarded on being signed in already, so this never fires on the login screen
 * itself — which would turn a mis-typed password into a page that reloads
 * under the person's hands.
 */
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState !== 'visible') return

  const authStore = useAuthStore()
  if (!authStore.isAuthenticated) return

  void authStore.fetchUser().then(() => {
    if (!authStore.user && router.currentRoute.value.name !== 'login') {
      router.push({ name: 'login' })
    }
  })
})

app.mount('#app')
