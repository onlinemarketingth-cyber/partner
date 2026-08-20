<script setup lang="ts">
/**
 * TASK-218 (human decision, 2026-08-20, revised same day) — a Super Admin
 * who lands in the Agent Portal is told so in ONE LINE and sent straight
 * to the Admin app.
 *
 * r1 was a full explanation card (what the roles are, why the two apps
 * share a session, a logout button). The human's answer: "ไม่ต้องสรุป
 * แจ้งเตือนแบบสั้นๆ ครับ แล้ว Rediect ไปที่หน้า admin ได้เลย" — and they
 * are right. Nobody who lands here by accident wants a briefing; they
 * want to be where they meant to go. The explanation lives in
 * docs/tasks/TASK-218 where it belongs, not in the user's way.
 *
 * ═══ WHY ANYONE LANDS HERE AT ALL ═══
 *
 * The Admin app and this app share ONE login session in the browser. On
 * production both call the same API host (api.partner.syncvision.io), so
 * the Sanctum session cookie carries a parent-domain scope
 * (.partner.syncvision.io) and every subdomain reads it. Locally the same
 * collision was fixed on 2026-08-02 by giving each app its own API
 * hostname (agent.localhost:8010 vs admin.localhost:8010 — see each
 * frontend's .env); that fix was never carried over to production.
 *
 * The symptom this replaces: the agent dashboard rendered from the
 * ADMIN's identity — zero XP, no team, no orders — which reads as a
 * broken app rather than as "wrong door".
 *
 * NOT A SECURITY BOUNDARY, and must not be described as one. A
 * client-side route guard protects nothing; every endpoint is already
 * gated server-side by Policies and Abilities (CLAUDE.md §5). This
 * removes confusion, not a vulnerability.
 */
import { useRouter } from 'vue-router'
import { onMounted, onUnmounted } from 'vue'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()

// Same resolution as TopNavigation.vue's "Admin console" link — one
// convention for "where the admin app lives", not two that can drift.
const adminAppUrl = (import.meta.env.VITE_ADMIN_APP_URL as string | undefined) ?? 'http://admin.localhost:5179'

/**
 * Long enough to read six words, short enough not to feel like a stop.
 * Zero would work too, but a redirect with no visible cause is the thing
 * that makes people think they clicked something wrong.
 */
const REDIRECT_DELAY_MS = 1000

let timer: ReturnType<typeof setTimeout> | undefined

onMounted(() => {
  // The route is `public: true` (so the guard cannot bounce it into
  // itself), which means anyone can reach it by typing the URL. An
  // anonymous visitor, or an agent, must not be thrown at the admin app.
  if (!authStore.isAuthenticated) {
    void router.replace({ name: 'login' })

    return
  }

  if (authStore.user?.role !== 'super_admin') {
    void router.replace({ name: 'home' })

    return
  }

  // `replace`, not `assign`: this screen must not become a Back-button
  // trap that fires the redirect again the moment it is returned to.
  timer = setTimeout(() => window.location.replace(adminAppUrl), REDIRECT_DELAY_MS)
})

onUnmounted(() => {
  if (timer) clearTimeout(timer)
})
</script>

<template>
  <main
    class="min-h-screen flex flex-col items-center justify-center gap-3 px-6 text-center bg-slate-50"
    style="font-family: Kanit, sans-serif;"
  >
    <div class="w-8 h-8 rounded-full border-2 border-slate-200 border-t-brand-600 animate-spin" aria-hidden="true"></div>

    <p class="text-sm font-bold text-slate-700">
      บัญชี Super Admin — กำลังพาไปหน้า Admin
    </p>

    <!-- The escape hatch. A redirect that silently fails leaves a spinner
         spinning forever, and the user with no way out but the URL bar. -->
    <a :href="adminAppUrl" class="text-xs text-slate-400 underline hover:text-brand-600">
      ถ้าไม่ถูกพาไปอัตโนมัติ กดที่นี่
    </a>
  </main>
</template>
