<script setup lang="ts">
/**
 * TopNavigation — app shell top bar for Sync Vision Agent.
 *
 * Visual language ported from the reference UI (sticky, white/90
 * backdrop-blur, icon nav with hover-reveal labels, font-size pill,
 * TH/EN toggle, avatar). The menu items, logo, and navigation
 * mechanism are our own — see AppLogo.vue and the design notes in
 * TASK-001 / the ag-lead chat thread for why (different product,
 * different domain, no Vue Router in the reference app).
 *
 * Avatar + logout wired to the Pinia auth store (src/stores/auth.ts).
 *
 * ADR-003 (2026-07-08): Admin moved out to a separate Vue app
 * (/frontend-admin) — no more `admin` nav item routed inside this SPA.
 * company_admin/super_admin users instead get an external link to the
 * admin app (VITE_ADMIN_APP_URL).
 */
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useFontSize } from '@/composables/useFontSize'
import { useI18n } from '@/composables/useI18n'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import Icon from './Icon.vue'
import AppLogo from './AppLogo.vue'
import NotificationBell from './NotificationBell.vue'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const themeStore = useThemeStore()
const { fontSize, setFontSize } = useFontSize()
const { lang, t, setLang } = useI18n()

const avatarInitial = computed(() => authStore.user?.name?.trim().charAt(0).toUpperCase() || '?')

async function handleLogout() {
    userMenuOpen.value = false
    // TASK-064 — carry ?company=<slug> back to /login so the login page
    // stays branded after logout instead of falling back to the neutral
    // default (an in-SPA route push never re-runs the boot-time
    // loadPublic() that would otherwise pick this up from localStorage).
    const target = themeStore.loginRouteLocation()
    // Guarded so a network hiccup on /logout still returns the UI to a sane
    // state — same pattern as ProfileSettingsView.vue's handleLogout(). The
    // store's own logout() already nulls user.value in a finally, but without
    // this wrapper a thrown error here would skip the redirect entirely.
    try {
        await authStore.logout()
    } finally {
        router.push(target)
    }
}

const fontSizeOptions = [
    { v: 'small', label: 'A', size: '11px' },
    { v: 'medium', label: 'A', size: '13px' },
    { v: 'large', label: 'A', size: '15px' },
] as const

function toggleLang() {
    setLang(lang.value === 'TH' ? 'EN' : 'TH')
}

interface NavItem {
    name: string
    icon: string
    label: { th: string; en: string }
}

const navItems: NavItem[] = [
    { name: 'home', icon: 'home', label: { th: 'My Day', en: 'My Day' } },
    { name: 'clients', icon: 'users', label: { th: 'ลูกค้า', en: 'Clients' } },
    { name: 'referrals', icon: 'user_plus', label: { th: 'SWS Referral', en: 'SWS Referral' } },
    { name: 'pipeline', icon: 'pipeline', label: { th: 'Pipeline', en: 'Pipeline' } },
    { name: 'academy', icon: 'book', label: { th: 'Academy', en: 'Academy' } },
    { name: 'commission', icon: 'money', label: { th: 'ค่าแนะนำ', en: 'Commission' } },
    { name: 'leaderboard', icon: 'trophy', label: { th: 'Leaderboard', en: 'Leaderboard' } },
    // ADR-011 Section 4 (TASK-033)
    { name: 'affiliate-links', icon: 'link', label: { th: 'ลิงก์พันธมิตร', en: 'Affiliate Links' } },
    // TASK-056 Sprint P3 — Product browse + share ("ส่วน Product").
    { name: 'products', icon: 'box', label: { th: 'สินค้า', en: 'Products' } },
]

const activeName = computed(() => route.name as string)

const isAdminRole = computed(() =>
    authStore.user?.role === 'company_admin' || authStore.user?.role === 'super_admin',
)
const adminAppUrl = (import.meta.env.VITE_ADMIN_APP_URL as string | undefined) ?? 'http://admin.localhost:5179'

const userMenuOpen = ref(false)
function toggleUserMenu() {
    userMenuOpen.value = !userMenuOpen.value
}
</script>

<template>
    <div class="sticky top-0 z-50 font-sans select-none">
        <nav class="bg-white/90 backdrop-blur-xl border-b border-slate-200 px-4 sm:px-6 py-2 shadow-sm">
            <div class="w-full max-w-none mx-auto flex items-center justify-between gap-4">
                <!-- Left: logo + nav -->
                <div class="flex items-center gap-6 min-w-0">
                    <RouterLink to="/" class="shrink-0 transition-transform hover:scale-105">
                        <AppLogo mode="wordmark" :height="32" />
                    </RouterLink>

                    <div class="hidden lg:flex items-center gap-1 overflow-x-auto no-scrollbar">
                        <RouterLink
                            v-for="item in navItems"
                            :key="item.name"
                            :to="{ name: item.name }"
                            class="group relative flex items-center p-2 rounded-xl transition-all duration-300 border border-transparent shrink-0"
                            :class="activeName === item.name
                                ? 'bg-brand-50/60 text-brand-600 font-bold'
                                : 'text-ink-card-muted hover:text-ink-brand hover:bg-surface-chip'"
                        >
                            <div class="w-6 h-6 shrink-0 flex items-center justify-center">
                                <Icon :name="item.icon" :size="20" />
                            </div>
                            <span
                                class="whitespace-nowrap ml-2 overflow-hidden text-sm tracking-wide opacity-0 max-w-0 group-hover:max-w-xs group-hover:opacity-100 transition-all duration-300"
                                :class="{ 'opacity-100 max-w-xs': activeName === item.name }"
                            >
                                {{ t('nav_' + item.name, item.label.th, item.label.en) }}
                            </span>
                        </RouterLink>
                    </div>
                </div>

                <!-- Right: search / admin link / notifications / font size / lang / avatar -->
                <div class="flex items-center gap-3 shrink-0">
                    <button
                        type="button"
                        class="w-10 h-10 flex items-center justify-center rounded-full transition-all text-ink-card-muted hover:bg-surface-chip hover:text-ink-brand"
                        :title="t('search', 'ค้นหา', 'Search')"
                    >
                        <Icon name="search" :size="18" />
                    </button>

                    <!-- ADR-003: Admin lives in a separate app now — this is an
                         external link out, not a Vue Router route. -->
                    <a
                        v-if="isAdminRole"
                        :href="adminAppUrl"
                        target="_blank"
                        rel="noopener"
                        class="hidden sm:flex items-center gap-1.5 px-3 py-2 rounded-xl text-ink-card-muted hover:text-ink-brand hover:bg-surface-chip transition-all text-sm font-bold"
                        :title="t('admin_console', 'ระบบ Admin', 'Admin console')"
                    >
                        <Icon name="settings" :size="18" />
                        <span class="hidden lg:inline">{{ t('admin_console', 'Admin', 'Admin') }}</span>
                    </a>

                    <NotificationBell />

                    <div class="hidden md:flex items-center gap-0.5 bg-surface-chip rounded-full border border-line-card p-0.5">
                        <button
                            v-for="opt in fontSizeOptions"
                            :key="opt.v"
                            type="button"
                            @click="setFontSize(opt.v)"
                            :style="{ fontSize: opt.size, lineHeight: 1 }"
                            :class="[
                                'px-2.5 py-1.5 rounded-full font-bold transition-all min-w-[28px]',
                                fontSize === opt.v ? 'bg-brand-600 text-ink-primary shadow' : 'text-ink-card-muted hover:text-brand-600',
                            ]"
                        >
                            {{ opt.label }}
                        </button>
                    </div>

                    <button
                        type="button"
                        @click="toggleLang"
                        class="relative w-16 h-8 bg-surface-chip rounded-full border border-line-card cursor-pointer flex items-center px-1"
                    >
                        <div
                            class="absolute top-1 bottom-1 w-7 bg-surface-card rounded-full shadow flex items-center justify-center transition-all duration-300"
                            :class="lang === 'TH' ? 'translate-x-0' : 'translate-x-8'"
                        >
                            <span class="text-[11px] font-black text-ink-brand">{{ lang }}</span>
                        </div>
                    </button>

                    <div class="relative">
                        <button
                            type="button"
                            @click="toggleUserMenu"
                            class="cursor-pointer relative transform transition-transform hover:scale-110 w-9 h-9 rounded-full overflow-hidden bg-brand-600 text-ink-primary flex items-center justify-center font-bold text-sm border border-white shadow"
                        >
                            <img v-if="authStore.user?.avatar_url" :src="authStore.user.avatar_url" :alt="authStore.user.name" class="w-full h-full object-cover" />
                            <span v-else>{{ avatarInitial }}</span>
                        </button>
                        <div
                            v-if="userMenuOpen"
                            class="absolute right-0 mt-2 w-52 rounded-xl bg-surface-card border border-line-card shadow-2xl overflow-hidden z-50"
                        >
                            <div v-if="authStore.user" class="px-3 py-2 border-b border-line-card-subtle">
                                <p class="text-sm font-bold text-ink-card truncate">{{ authStore.user.name }}</p>
                                <p class="text-xs text-ink-card-subtle truncate">{{ authStore.user.email }}</p>
                            </div>
                            <RouterLink
                                :to="{ name: 'profile' }"
                                @click="userMenuOpen = false"
                                class="block w-full text-left px-3 py-2 text-xs text-ink-card-muted hover:bg-surface-chip hover:text-ink-brand font-bold"
                            >
                                {{ t('profile', 'โปรไฟล์ของฉัน', 'My Profile') }}
                            </RouterLink>
                            <button
                                type="button"
                                @click="handleLogout"
                                class="w-full text-left px-3 py-2 text-xs text-ink-card-muted hover:bg-surface-chip hover:text-ink-danger font-bold border-t border-line-card-subtle"
                            >
                                {{ t('logout', 'ออกจากระบบ', 'Logout') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </nav>
    </div>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar {
    display: none;
}
.no-scrollbar {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
</style>
