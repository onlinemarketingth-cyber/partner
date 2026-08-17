<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import AppLogo from '@/design-system/components/AppLogo.vue'
import BottomNav from '@/design-system/components/BottomNav.vue'
import Icon from '@/design-system/components/Icon.vue'
import NotificationBell from '@/design-system/components/NotificationBell.vue'
import ToastHost from '@/design-system/components/ToastHost.vue'
import { useAuthStore } from '@/stores/auth'
import { usePageHeaderStore } from '@/stores/pageHeader'
import { useThemeStore } from '@/stores/theme'
import { initials } from '@/utils/initials'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const theme = useThemeStore()

// TASK-086 / ADR-021 — written by the mounted view's HeroHeader.
const pageHeader = usePageHeaderStore()

/**
 * Same contract HeroHeader's own back button had: a bare page name or a
 * full path, falling back to router.back() when neither is given.
 */
function goBack() {
  const target = pageHeader.backPage
  if (!target) {
    router.back()

    return
  }

  void router.push(target.startsWith('/') ? target : `/${target}`)
}
// Login (and any other `meta.public` marketing-style screen) renders full-bleed,
// no app chrome — the router guard already keeps authenticated users out of it.
const showChrome = computed(() => !route.meta.public)

/**
 * TASK-167 §5 — THE THIRD CHROME STATE.
 *
 * `showChrome` was the only switch, so a screen was either public (no
 * chrome, full-bleed background) or a nav tab. The Academy content screens
 * are neither: authenticated, top bar and background intact, but no bottom
 * nav — they are a place you are IN, not a tab you switch to, and a tab bar
 * offering four ways out of a video is noise.
 *
 * A separate flag rather than `meta.public`, deliberately: `public` also
 * drives the full-bleed background above, so reusing it would unthemed
 * these screens (ADR-018).
 */
const showBottomNav = computed(() => showChrome.value && !route.meta.hideBottomNav)

/*
 * The app background, applied here once behind everything rather than
 * per-view. Every view's <main> gave up its own opaque background for this
 * (see git history), so this shows through at the edges; content cards stay
 * bg-surface-card/95 (opaque) so text is readable over whatever it is.
 *
 * TASK-160 (human, 2026-08-11: "ตัดระบบการ setting สีส่วนตัวออกจาก profile
 * setup ให้ยึดสีจากระบบเท่านั้น") — THE PERSONAL BACKGROUND IS GONE.
 *
 * This used to read:
 *     const personal = resolveBackgroundStyle(auth.user?.background)
 *     if (Object.keys(personal).length) return personal
 *     return theme.companyBackgroundStyle
 * i.e. TASK-055/ADR-018's rule that the personal choice always beat the
 * company's. That rule is withdrawn. A per-user gradient sat on top of a
 * white-labelled tenant's brand and could not be governed by the company
 * that owns the tenant — an agent could pick anything, and the screen an
 * agent shows a customer stopped being the company's screen.
 *
 * `users.background` is deliberately NOT dropped: existing rows are simply
 * no longer read, so nothing is destroyed and the decision is reversible by
 * restoring three lines. See TASK-160's note about the write endpoints.
 */
const backgroundStyle = computed(() => theme.companyBackgroundStyle)

// Per-company app name override for the wordmark (falls back to built-in).
// TASK-121 — the app name is now resolved inside AppLogo, so this local
// copy is gone. Kept as a comment rather than silently deleted because the
// old `:label="appName !== 'Sync Vision Agent' ? appName : undefined"`
// ternary appeared here and in LoginView, and a future reader looking for
// where the company name comes from should be pointed at AppLogo.vue.
</script>

<template>
  <!-- Background layer. When the app chrome is shown (authenticated mobile
       shell) the personal/company background is CONSTRAINED to the app
       column width (max-w-md, centred) so it can't bleed across the whole
       desktop viewport around the phone column — the body's own background
       fills the margins. Public pages (login) stay full-bleed for branding.

       TASK-159 §4.1 — the class was `bg-surface-chip`, i.e. main.css's
       fixed slate-100, so a company that set brand colours but never
       picked a background TYPE got themed ink on unthemed paper
       (`companyBackgroundStyle` returns {} in that case, so nothing
       covered the slate). `bg-surface-app` is the token that exists for
       exactly this surface: theme.ts derives it from the company's
       background and falls back to its CARD colour, so a dark-carded
       tenant now gets a dark page instead of slate-100. The inline
       `backgroundStyle` (personal background winning over the company
       image/gradient) is unchanged and still layers ON TOP of it, and the
       showChrome width constraint below is untouched. -->
  <div
    class="fixed -z-10 bg-surface-app"
    :class="showChrome ? 'inset-y-0 left-1/2 -translate-x-1/2 w-full max-w-md' : 'inset-0'"
    :style="backgroundStyle"
  ></div>

  <!-- Public pages (login/register/verify/affiliate) render full-bleed.
       Transition removed here too — same reason as the authenticated
       RouterView below; login→register must never be able to blank out. -->
  <RouterView v-if="!showChrome" />

  <!-- Authenticated mobile-app shell: slim top bar + app column + bottom nav. -->
  <template v-else>
    <header
      class="sticky top-0 z-40 backdrop-blur border-b border-slate-200"
      :style="{ background: 'var(--nav-bg)', color: 'var(--nav-text)' }"
    >
      <!-- TASK-086 / ADR-021 — this row used to show the same logo on every
           screen while each page repeated its own title 68px lower down.
           It is now a real navigation bar: back + page title + page action,
           which is what bought the in-page header its 15% budget. The logo
           still shows on any screen that publishes no title (Home). -->
      <div class="mx-auto w-full max-w-md px-4 h-14 flex items-center gap-2">
        <button
          v-if="pageHeader.backPage"
          type="button"
          @click="goBack"
          class="shrink-0 -ml-2 w-11 h-11 flex items-center justify-center rounded-full active:bg-black/10"
          :aria-label="pageHeader.backLabel || 'ย้อนกลับ'"
        >
          <Icon name="chevron_left" :size="22" />
        </button>

        <h1 v-if="pageHeader.active && pageHeader.title" class="min-w-0 flex-1 truncate text-base font-bold">
          {{ pageHeader.title }}
        </h1>

        <!-- TASK-121 — the uploaded-logo lookup moved INTO AppLogo, so the
             four public pages that used to miss it get it for free. -->
        <RouterLink v-else to="/" class="flex items-center">
          <AppLogo mode="wordmark" context="nav" :height="28" />
        </RouterLink>

        <div class="ml-auto flex items-center gap-1">
          <!-- Teleport target for the mounted view's HeroHeader #actions
               slot. Empty (and zero-width) on screens with no action. -->
          <div id="page-header-action" class="flex items-center"></div>
          <NotificationBell />
          <!-- TASK-087 — Apple HIG: 44x44pt minimum tap target. The avatar
               was a bare 32x32 link, the smallest target in the app and
               the one closest to the screen's right edge. The circle is
               still 32px; the LINK around it is now 44x44 with the extra
               area transparent, so nothing looks bigger — it just stops
               being a miss. -->
          <RouterLink to="/profile" class="shrink-0 w-11 h-11 flex items-center justify-center" aria-label="โปรไฟล์">
            <img
              v-if="auth.user?.avatar_url"
              :src="auth.user.avatar_url"
              alt=""
              class="w-8 h-8 rounded-full object-cover border border-line-card"
            />
            <span
              v-else
              class="w-8 h-8 rounded-full bg-brand-100 text-brand-700 text-xs font-bold flex items-center justify-center"
            >
              {{ initials(auth.user?.name ?? '') }}
            </span>
          </RouterLink>
        </div>
      </div>
    </header>

    <!-- Bug fix (2026-08-03, human-reported: "ตอนนี้ผมกดทุกปุ่มไม่มีอะไร
         แสดงออกมาเลย" — every tab rendered an empty page).

         The route-level <Transition name="page-fade" mode="out-in"> that
         used to wrap this RouterView is GONE. Its leave transition stopped
         completing once the views grew (TASK-079 Phase 4 gave all 13 views
         a HeroHeader root and a nested content-fade <Transition>), so the
         `transitionend` Vue waits on never fired. That produced both halves
         of the bug, and confirmed the diagnosis:
           - with mode="out-in": the outgoing view never signalled "done", so
             the incoming one was never mounted — <main> held nothing but
             Vue's empty placeholder comment. This is what the human saw.
           - with the mode removed: the outgoing views were never removed
             either, so they piled up (measured live: 1→2→3→4→5 children
             stacking as you tabbed through the bottom nav).

         A decorative 150ms page fade is not worth a class of bug that can
         blank the entire app, so the transition is removed rather than
         re-tuned. Views now mount directly. The per-view `content-fade`
         (skeleton→content) transitions are unaffected and still run — they
         are what actually smooths the perceived load. `.page-fade-*` is
         kept in main.css, unused, deliberately: see the note there before
         reintroducing any transition at this level.
         pb-24 clears the fixed BottomNav — and drops to pb-6 on the screens
         that hide it, or they would end in 96px of nothing. -->
    <main class="mx-auto w-full max-w-md min-h-screen" :class="showBottomNav ? 'pb-24' : 'pb-6'">
      <RouterView />
    </main>

    <BottomNav v-if="showBottomNav" />
  </template>

  <!-- TASK-079 Phase 2 — mounted once, outside the showChrome branch, so
       public pages (login/register/payment) get toasts too. It Teleports
       to <body> itself, so its position in this tree doesn't matter. -->
  <ToastHost />
</template>
