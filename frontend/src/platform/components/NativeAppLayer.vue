<script setup lang="ts">
/**
 * NativeAppLayer — MOB-27 / MOB-29 / MOB-31 (2026-10-02).
 *
 * The screens only the iOS/Android app has, drawn over everything else:
 *
 *   1. "please update" (MOB-31)  — blocking; the installed build is below
 *      the server's minimum. Nothing else matters until it is updated.
 *   2. the lock screen (MOB-29)   — blocking; biometric / passcode unlock.
 *   3. "no internet" (MOB-27)     — blocking while offline, clears itself.
 *   4. "a new version is out"     — a dismissible banner, once per version.
 *
 * ── WHY IT IS NOT IN App.vue ──
 *
 * App.vue renders this through an injected component that only main.ts
 * provides inside the app (platform/index.ts NATIVE_LAYER_KEY). In a browser
 * nothing is provided, nothing renders, and this file is not even part of
 * the web bundle.
 *
 * Every overlay is opaque and sits above the portal's own modals (z-[1000]
 * ConfirmDialog, z-[1100] AttachmentLightbox) — a lock screen with a client
 * drawer showing through it would not be a lock.
 *
 * Copy falls back to Thai inline: these can appear before the dictionary has
 * loaded (offline at launch is the obvious case), and a raw "app.offline_title"
 * key on a blocking screen would be worse than no translation.
 */
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from '@/composables/useI18n'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'
import AppLogo from '@/design-system/components/AppLogo.vue'
import Icon from '@/design-system/components/Icon.vue'
import { networkState, recheckNetwork } from '../network'
import { clearLock, lockState, unlock } from '../biometric'
import { dismissUpdateBanner, updateState } from '../versionPolicy'
import { openWithSystem } from '../browser'

const { td } = useI18n()
const auth = useAuthStore()
const theme = useThemeStore()
const router = useRouter()

// ── Lock (MOB-29) ─────────────────────────────────────────────────────────
// Only meaningful while somebody is signed in: a session that ended under
// the lock (401, sign-out elsewhere) leaves nothing to protect.
const showLock = computed(() => lockState.active && auth.isAuthenticated)

function unlockTexts() {
  return {
    reason: td('app.lock_reason', 'ยืนยันตัวตนเพื่อเปิดแอป'),
    cancel: td('common.cancel', 'ยกเลิก'),
    title: td('app.lock_title', 'แอปถูกล็อกอยู่'),
  }
}

function tryUnlock(): void {
  void unlock(unlockTexts())
}

/*
 * Ask straight away when the lock goes up, rather than making the agent tap
 * "unlock" first. The short delay lets the launch splash finish hiding, so
 * the system prompt does not appear over a logo.
 */
watch(
  showLock,
  (shown) => {
    if (shown) window.setTimeout(tryUnlock, 300)
  },
  { immediate: true },
)

const lockMessage = computed(() => {
  switch (lockState.lastResult) {
    case 'cancelled':
      return td('app.lock_cancelled', 'ยกเลิกการยืนยันตัวตนแล้ว — กดปลดล็อกเพื่อลองใหม่')
    case 'unavailable':
      return td(
        'app.lock_unavailable',
        'ตอนนี้เครื่องนี้ยืนยันตัวตนด้วย Face ID / ลายนิ้วมือ หรือรหัสผ่านเครื่องไม่ได้ — ออกจากระบบแล้วเข้าสู่ระบบด้วยรหัสผ่านแทน',
      )
    case 'failed':
      return td('app.lock_failed', 'ยืนยันตัวตนไม่สำเร็จ — กรุณาลองใหม่')
    default:
      return ''
  }
})

const loggingOut = ref(false)

/** Same order as ProfileSettingsView's sign-out: revoke, then leave. */
async function logoutFromLock(): Promise<void> {
  if (loggingOut.value) return
  loggingOut.value = true
  const target = theme.loginRouteLocation()
  try {
    await auth.logout()
  } finally {
    clearLock()
    loggingOut.value = false
    void router.push(target)
  }
}

// ── Offline (MOB-27) ──────────────────────────────────────────────────────
function retryNetwork(): void {
  void recheckNetwork()
}

// ── Update (MOB-31) ───────────────────────────────────────────────────────
function openStore(): void {
  if (updateState.storeUrl) openWithSystem(updateState.storeUrl)
}
</script>

<template>
  <!-- 1. Forced update — above everything, including the lock. -->
  <div
    v-if="updateState.required"
    class="fixed inset-0 z-[2300] flex flex-col items-center justify-center gap-5 bg-surface-app px-6 pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] text-center"
    role="alertdialog"
    aria-modal="true"
    aria-labelledby="native-update-title"
  >
    <AppLogo mode="wordmark" :height="32" />
    <div
      class="w-full max-w-sm rounded-2xl border border-line-card bg-surface-card/95 p-6 shadow-xl"
    >
      <div
        class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-brand-50 text-brand-600"
      >
        <Icon name="rocket" :size="26" />
      </div>
      <h2 id="native-update-title" class="text-lg font-bold text-ink-card">
        {{ td('app.update_required_title', 'กรุณาอัปเดตแอป') }}
      </h2>
      <p class="mt-2 text-sm leading-relaxed text-ink-card-muted">
        {{
          td(
            'app.update_required_body',
            'แอปเวอร์ชันนี้ไม่รองรับแล้ว กรุณาอัปเดตเป็นเวอร์ชันล่าสุดเพื่อใช้งานต่อ',
          )
        }}
      </p>
      <button
        v-if="updateState.storeUrl"
        type="button"
        class="mt-5 inline-flex min-h-[44px] w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-ink-primary shadow-sm transition-all hover:bg-brand-700 active:scale-95"
        @click="openStore"
      >
        <Icon name="download" :size="16" />
        {{ td('app.update_open_store', 'ไปที่หน้าอัปเดต') }}
      </button>
      <p v-else class="mt-4 text-xs text-ink-card-subtle">
        {{ td('app.update_required_no_store', 'เปิด App Store หรือ Google Play แล้วอัปเดตแอปนี้') }}
      </p>
    </div>
  </div>

  <!-- 2. Biometric lock. -->
  <div
    v-else-if="showLock"
    class="fixed inset-0 z-[2200] flex flex-col items-center justify-center gap-5 bg-surface-app px-6 pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] text-center"
    role="dialog"
    aria-modal="true"
    aria-labelledby="native-lock-title"
  >
    <AppLogo mode="wordmark" :height="32" />
    <div
      class="w-full max-w-sm rounded-2xl border border-line-card bg-surface-card/95 p-6 shadow-xl"
    >
      <div
        class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-brand-50 text-brand-600"
      >
        <Icon name="shield_check" :size="26" />
      </div>
      <h2 id="native-lock-title" class="text-lg font-bold text-ink-card">
        {{ td('app.lock_title', 'แอปถูกล็อกอยู่') }}
      </h2>
      <p class="mt-2 text-sm leading-relaxed text-ink-card-muted">
        {{
          td(
            'app.lock_body',
            'ยืนยันตัวตนด้วย Face ID / ลายนิ้วมือ หรือรหัสผ่านเครื่องเพื่อใช้งานต่อ',
          )
        }}
      </p>
      <p v-if="lockMessage" class="mt-3 text-xs font-bold text-ink-danger" role="alert">
        {{ lockMessage }}
      </p>
      <button
        type="button"
        :disabled="lockState.authenticating"
        class="mt-5 inline-flex min-h-[44px] w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-ink-primary shadow-sm transition-all hover:bg-brand-700 active:scale-95 disabled:opacity-60"
        @click="tryUnlock"
      >
        <Icon name="key" :size="16" />
        {{ td('app.lock_unlock', 'ปลดล็อก') }}
      </button>
      <button
        type="button"
        :disabled="loggingOut"
        class="mt-2 inline-flex min-h-[44px] w-full items-center justify-center gap-2 rounded-xl border border-line-card bg-surface-danger px-4 py-2.5 text-sm font-bold text-ink-danger disabled:opacity-50"
        @click="logoutFromLock"
      >
        <Icon name="arrow_right" :size="16" />
        {{ td('nav.logout', 'ออกจากระบบ') }}
      </button>
    </div>
  </div>

  <!-- 3. Offline. -->
  <div
    v-else-if="networkState.offline"
    class="fixed inset-0 z-[2100] flex flex-col items-center justify-center gap-5 bg-surface-app px-6 pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] text-center"
    role="alertdialog"
    aria-modal="true"
    aria-labelledby="native-offline-title"
  >
    <div
      class="w-full max-w-sm rounded-2xl border border-line-card bg-surface-card/95 p-6 shadow-xl"
    >
      <div
        class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-surface-warning text-ink-warning"
      >
        <Icon name="globe" :size="26" />
      </div>
      <h2 id="native-offline-title" class="text-lg font-bold text-ink-card">
        {{ td('app.offline_title', 'ไม่มีการเชื่อมต่ออินเทอร์เน็ต') }}
      </h2>
      <p class="mt-2 text-sm leading-relaxed text-ink-card-muted">
        {{
          td(
            'app.offline_body',
            'ตรวจสอบ Wi-Fi หรือเน็ตมือถือ แล้วกดลองใหม่ — หน้านี้จะหายไปเองเมื่อกลับมาออนไลน์',
          )
        }}
      </p>
      <button
        type="button"
        :disabled="networkState.checking"
        class="mt-5 inline-flex min-h-[44px] w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-ink-primary shadow-sm transition-all hover:bg-brand-700 active:scale-95 disabled:opacity-60"
        @click="retryNetwork"
      >
        <Icon name="refresh" :size="16" />
        {{
          networkState.checking
            ? td('app.offline_checking', 'กำลังตรวจสอบ...')
            : td('common.retry', 'ลองใหม่')
        }}
      </button>
    </div>
  </div>

  <!-- 4. New version available — dismissible, once per version. -->
  <div
    v-if="updateState.available && !updateState.required"
    class="fixed inset-x-0 top-[calc(env(safe-area-inset-top)+0.5rem)] z-[1500] mx-auto w-[calc(100%-2rem)] max-w-md"
    role="status"
  >
    <div
      class="flex items-center gap-3 rounded-2xl border border-line-card bg-surface-card/95 px-4 py-3 shadow-xl backdrop-blur"
    >
      <Icon name="rocket" :size="18" class="shrink-0 text-ink-brand" />
      <p class="min-w-0 flex-1 text-sm font-bold text-ink-card">
        {{
          td('app.update_available', 'มีแอปเวอร์ชันใหม่ ({version})', {
            version: updateState.available,
          })
        }}
      </p>
      <button
        v-if="updateState.storeUrl"
        type="button"
        class="min-h-[44px] shrink-0 rounded-xl bg-brand-600 px-3 text-xs font-bold text-ink-primary active:scale-95"
        @click="openStore"
      >
        {{ td('app.update_now', 'อัปเดต') }}
      </button>
      <button
        type="button"
        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-ink-card-subtle active:scale-90"
        :aria-label="td('common.close', 'ปิด')"
        @click="dismissUpdateBanner"
      >
        <Icon name="x" :size="16" />
      </button>
    </div>
  </div>
</template>
