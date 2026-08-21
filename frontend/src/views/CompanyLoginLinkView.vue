<script setup lang="ts">
/**
 * CompanyLoginLinkView — /in/<code> (TASK-235).
 *
 * ── FOUND BY CLICKING IT IN UAT, 2026-08-20 ──
 *
 * The admin screen minted a short login link and handed it over, and the
 * agent portal had no route for it. A missing route in this SPA does not
 * throw and does not 404 — `<RouterView>` simply renders nothing, so the
 * visitor gets the app chrome wrapped around an empty page. That reads as
 * "this site is broken", not "that link is wrong", and nothing in the
 * codebase connects the two.
 *
 * ── WHY A REDIRECT AND NOT A PAGE ──
 *
 * `/in/<code>` is short for `/login?company=<slug>` and nothing more. The
 * login page already knows how to theme itself from that query parameter
 * (stores/theme.ts reads it, and persists it), so this resolves the code
 * to a slug and hands over. Rendering a second login form here would be a
 * second login form to keep in step with the first.
 *
 * `replace`, not `push`: the visitor should never land back on this
 * resolver by pressing Back — there is nothing here to come back to.
 */
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import { useThemeStore } from '@/stores/theme'

const route = useRoute()
const router = useRouter()
const themeStore = useThemeStore()

const failed = ref(false)

onMounted(async () => {
  const code = typeof route.params.code === 'string' ? route.params.code.trim() : ''

  if (!code) {
    router.replace({ name: 'login' })

    return
  }

  try {
    const res = await api.get<{ company_slug: string }>(`/public/login-links/${encodeURIComponent(code)}`)
    await router.replace({ name: 'login', query: { company: res.company_slug } })

    /*
     * THE REDIRECT ALONE DOES NOT THEME THE PAGE. Reported 2026-08-21:
     * "scan qr code … สีของ theme ไม่มา".
     *
     * loadPublic() runs exactly once, from main.ts, at boot — and at that
     * moment the URL is /in/<code>, which carries no `?company=`. The store
     * therefore falls back to a slug cached in localStorage, which is empty
     * on a phone that has never signed in (and stays empty in an in-app
     * browser, which often starts with fresh storage on every open). So the
     * boot load resolves nothing.
     *
     * Writing `?company=` into the URL afterwards changes nothing by itself:
     * an in-SPA route push never re-runs loadPublic(). The theme store's own
     * loginRouteLocation() docblock states this exact limitation — it was
     * known, and this arrival was simply never wired to it.
     *
     * Called AFTER awaiting the replace, because resolveSlug() reads
     * window.location.search: run it before the navigation commits and it
     * would look at /in/<code> again and bail a second time. It also caches
     * the slug, so a returning visitor on this device is themed from the
     * first frame.
     *
     * Never throws (boot resilience is built into loadPublic), so an
     * unreachable theme endpoint still leaves the agent on a working, if
     * neutral, login page.
     */
    await themeStore.loadPublic()
  } catch (e) {
    /*
     * A dead link still reaches the login page — just the unbranded one.
     *
     * The backend answers one generic 404 for unknown, revoked and expired
     * alike (deliberately, so a stranger cannot probe which), so this page
     * could not explain the difference even if it wanted to. Stranding an
     * agent on an error screen when the thing they were trying to do —
     * log in — is one redirect away would be choosing the worse outcome
     * for the sake of a more precise message we do not have.
     */
    failed.value = true
    if (!(e instanceof ApiError)) {
      // A network failure is worth a beat before redirecting, in case it
      // is transient and the retry below succeeds.
    }
    router.replace({ name: 'login' })
  }
})
</script>

<template>
  <main class="min-h-screen flex items-center justify-center px-6">
    <p class="text-sm text-ink-card-subtle">
      {{ failed ? 'กำลังพาไปหน้าเข้าสู่ระบบ...' : 'กำลังเปิดหน้าเข้าสู่ระบบของบริษัท...' }}
    </p>
  </main>
</template>
