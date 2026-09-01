<script setup lang="ts">
import { useI18n } from '@/composables/useI18n'
const { td } = useI18n()

/**
 * The Super Admin chooser — "which of the two apps did you mean?"
 *
 * ── HISTORY, BECAUSE THIS SCREEN HAS CHANGED SHAPE TWICE ──
 *
 * r1 (TASK-218) was a full explanation card: what the roles are, why the two
 * apps share a session, a logout button.
 *
 * r2 (same day) cut it to one line and an automatic redirect to the Admin
 * app — "ไม่ต้องสรุป แจ้งเตือนแบบสั้นๆ ครับ แล้ว Rediect ไปที่หน้า admin ได้เลย".
 * Right about the briefing; wrong in one respect that only showed up in use.
 *
 * r3 (this, human request 2026-08-21) — ASK instead of decide.
 *
 * The automatic redirect assumed every Super Admin who lands here arrived by
 * accident. Some did. But a platform owner opening the Agent Portal
 * ON PURPOSE — to see what agents actually see, to check a screen after a
 * change, to reproduce something an agent reported — had no way through at
 * all. The app decided on their behalf and was sometimes wrong, with no
 * recourse but the URL bar, and even that bounced them straight back.
 *
 * So the screen now states the situation in plain language and offers both
 * doors. The confusion TASK-218 fixed is still fixed: nobody arrives at an
 * empty agent dashboard WITHOUT having been told first why it is empty.
 * That sentence is the entire load-bearing part of this screen — see the
 * caption under the second button.
 *
 * ── NOT A SECURITY BOUNDARY, and must never be described as one ──
 *
 * A client-side route guard protects nothing. Every endpoint is gated
 * server-side by Policies and Abilities (CLAUDE.md §5), and a Super Admin
 * legitimately outranks all of them. This screen removes confusion, not a
 * vulnerability, and letting them through costs nothing in access terms —
 * they could always have called the same API directly.
 */
import { useRouter } from 'vue-router'
import { onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { rememberStayInAgentPortal } from '@/utils/portalChoice'
import Icon from '@/design-system/components/Icon.vue'

const router = useRouter()
const authStore = useAuthStore()

// Same resolution as TopNavigation.vue's "Admin console" link — one
// convention for "where the admin app lives", not two that can drift.
const adminAppUrl = (import.meta.env.VITE_ADMIN_APP_URL as string | undefined) ?? 'http://admin.localhost:5179'

onMounted(() => {
  // The route is `public: true` (so the guard cannot bounce it into itself),
  // which means anyone can reach it by typing the URL. An anonymous visitor,
  // or an agent, must never be shown a chooser that is not theirs.
  if (!authStore.isAuthenticated) {
    void router.replace({ name: 'login' })

    return
  }

  if (authStore.user?.role !== 'super_admin') {
    void router.replace({ name: 'home' })
  }
})

function goToAgentPortal(): void {
  // Recorded BEFORE navigating: the guard runs on the very next navigation
  // and would send them straight back here otherwise.
  rememberStayInAgentPortal()
  void router.replace({ name: 'home' })
}
</script>

<template>
  <main
    class="min-h-screen flex flex-col items-center justify-center gap-6 px-6 py-10 bg-slate-50"
    style="font-family: Kanit, sans-serif;"
  >
    <div class="text-center">
      <p class="text-lg font-bold text-slate-800">{{ td('sa.signed_in_admin') }}</p>
      <p class="text-sm text-slate-500 mt-1">{{ td('sa.choose_view') }}</p>
    </div>

    <div class="w-full max-w-sm flex flex-col gap-3">
      <!-- Admin first, and visually primary: it is what the great majority
           of people who see this screen actually wanted. -->
      <a
        :href="adminAppUrl"
        class="flex items-start gap-3 p-4 rounded-2xl bg-brand-600 text-white shadow-sm hover:bg-brand-700 transition text-left"
      >
        <Icon name="settings" :size="20" class="shrink-0 mt-0.5" />
        <span>
          <span class="block font-bold">{{ td('sa.admin_view') }}</span>
          <span class="block text-xs text-white/80 mt-0.5">
            {{ td('sa.admin_view_help') }}
          </span>
        </span>
      </a>

      <button
        type="button"
        class="flex items-start gap-3 p-4 rounded-2xl bg-white border border-slate-200 shadow-sm hover:border-brand-300 transition text-left"
        @click="goToAgentPortal"
      >
        <Icon name="user" :size="20" class="shrink-0 mt-0.5 text-slate-500" />
        <span>
          <span class="block font-bold text-slate-800">{{ td('sa.member_view') }}</span>
          <!--
            THE SENTENCE THIS WHOLE SCREEN EXISTS FOR.

            An admin's agent dashboard is empty because an admin has no
            sales of their own — not because anything is broken. Said
            BEFORE they click, it is a description. Discovered afterwards,
            it is a bug report. TASK-218 was raised over exactly that.
          -->
          <span class="block text-xs text-slate-500 mt-0.5">
            {{ td('sa.member_view_help') }}
          </span>
        </span>
      </button>
    </div>

    <p class="text-xs text-slate-400 text-center max-w-sm">
      {{ td('sa.change_mind') }}
    </p>
  </main>
</template>
