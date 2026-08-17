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

app.mount('#app')
