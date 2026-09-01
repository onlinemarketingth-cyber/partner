<script setup lang="ts">
/**
 * BottomNav — mobile app-shell bottom tab bar (TASK-053 Phase 3).
 *
 * Fixed to the viewport bottom with iOS safe-area padding. The bar spans
 * full width but its inner content is constrained to the app column
 * (max-w-md) so it lines up with the top bar + content.
 *
 * TASK-098 / ADR-023 (2026-08-04) — colours come from tokens now, but
 * this file changed the LEAST on purpose. Nav chrome already had a
 * correct surface/on-surface pair long before ADR-023: `--nav-bg` /
 * `--nav-text` / `--nav-active`, applied inline so the card-level CSS in
 * main.css cannot reach it. Those inline styles stay exactly as they
 * are. The only conversion is the top hairline, which was a hardcoded
 * `border-line-card` — invisible against a dark `--nav-bg` — and is now
 * `border-line-card`.
 *
 * (ADR-023 §2.4 is the standing warning against the opposite move:
 * `--nav-text` is the ink for `--nav-bg` and must NOT be borrowed as a
 * label colour on brand-coloured buttons elsewhere.)
 */
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import Icon from './Icon.vue'
import { useThemeStore } from '@/stores/theme'
import { useI18n } from '@/composables/useI18n'

const { td } = useI18n()
const route = useRoute()
const theme = useThemeStore()

// TASK-055 / ADR-018 — curated per-company label overrides (theme.label).
// TASK-057 — same pattern for icons (theme.icon): a company can swap any
// of these icons for another from Icon.vue's set via the Admin theme
// screen. Both fall back to the built-in Thai defaults / icon names when
// no theme override is set.
//
// TASK-079 Phase 1 (2026-08-03, UX audit) — the third slot used to be
// โปรไฟล์, which was redundant: App.vue's top bar already links to
// /profile from the avatar (App.vue :77-90), so profile access is
// unchanged, it just isn't spending a scarce bottom-tab slot any more.
// `nav_profile` stays a valid theme.label/icon key for companies that
// already set it — it is simply no longer read HERE (ProfileSettingsView
// still reads it for its own page title).
//
// TASK-169 Phase 4b (2026-08-12, human: "ผมต้องการรวม UI เป็นหน้าเดียว
// คือ ลูกค้า และ เมนูขาย เอา Product มาแทน") — that slot now holds
// สินค้า → /products. ขาย → /referrals is gone because the SWS Referral
// log was MERGED INTO ลูกค้า, not removed: a client's deals live in that
// client's drawer, and /referrals redirects there (router/index.ts).
// /products is a finished screen that had never had a nav slot at all.
//
// THE KEY IS `nav_products`, NOT `nav_sales` (ag-lead ruling, TASK-169
// §5.1). Reusing `nav_sales` would make a company that had renamed "ขาย"
// through the Admin theme screen suddenly see that name on สินค้า — a
// tenant's own words silently relabelling a different destination.
// `nav_sales` follows `nav_profile`: still a valid key, no longer read
// anywhere, never recycled for a different tab.
/*
 * Sprint TZI18N-2 — the FALLBACK is translated, the theme override is not.
 *
 * theme.label(key, fallback) lets a company rename a tab for its own people
 * ("ลูกค้า" -> "สมาชิก", say). That override is a deliberate per-tenant
 * choice and must keep winning, so it is untouched here — only the default
 * that shows when a company has NOT renamed the tab now follows the
 * language switch. Translating the override too would silently discard a
 * setting an admin chose on purpose.
 */
const items = computed(() => [
  { to: '/', icon: theme.icon('nav_home', 'home'), label: theme.label('nav_home', td('nav.home')) },
  { to: '/clients', icon: theme.icon('nav_clients', 'users'), label: theme.label('nav_clients', td('nav.clients')) },
  // The products tab and HomeView's own quick-link for /products must not
  // name one screen two different things — both read this same key.
  { to: '/products', icon: theme.icon('nav_products', 'box'), label: theme.label('nav_products', td('nav.products')) },
  { to: '/academy', icon: theme.icon('nav_academy', 'brain'), label: theme.label('nav_academy', td('nav.academy')) },
  { to: '/commission', icon: theme.icon('nav_commission', 'money'), label: theme.label('nav_commission', td('nav.commission')) },
])

function isActive(to: string): boolean {
  if (to === '/') return route.path === '/'
  return route.path === to || route.path.startsWith(to + '/')
}

const activeMap = computed(() => items.value.map((i) => isActive(i.to)))
</script>

<template>
  <nav
    class="fixed bottom-0 inset-x-0 z-40 backdrop-blur border-t border-line-card pb-[env(safe-area-inset-bottom)]"
    :style="{ background: 'var(--nav-bg)' }"
  >
    <div class="mx-auto w-full max-w-md flex items-stretch justify-around h-16">
      <!-- TASK-079 Phase 3 — `active:` press feedback added: the app had
           no touch-press state anywhere, so tapping a tab felt dead on a
           phone (`hover:` never fires on touch). Label bumped 10px→11px
           for legibility (Thai glyphs lose detail below 11px). -->
      <RouterLink
        v-for="(item, i) in items"
        :key="item.to"
        :to="item.to"
        class="flex-1 flex flex-col items-center justify-center gap-0.5 transition-all active:scale-90"
        :style="activeMap[i] ? { color: 'var(--nav-active)' } : { color: 'var(--nav-text)', opacity: 0.6 }"
      >
        <Icon :name="item.icon" :size="22" />
        <span class="text-[11px] font-bold leading-tight">{{ item.label }}</span>
      </RouterLink>
    </div>
  </nav>
</template>
