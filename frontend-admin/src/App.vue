<script setup lang="ts">
import { computed } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import AdminNavigation from '@/design-system/components/AdminNavigation.vue'
// 2026-09-11 (owner): "หากยังไม่ได้มีการ setup ค่าคอม ให้แจ้งเตือนในทุกหน้า".
// Mounted in the shell rather than per view for the literal reason in that
// sentence, and mounted ONCE so "every page" costs one request per session
// rather than one per navigation — the component guards its own visibility.
import CommissionReadinessBanner from '@/design-system/components/CommissionReadinessBanner.vue'
import { useAuthStore } from '@/stores/auth'
import { resolveBackgroundStyle } from '@/utils/userBackground'

const route = useRoute()
const auth = useAuthStore()
const showChrome = computed(() => !route.meta.public)

// Personal background preference (avatar/background feature) — same
// pattern as frontend/src/App.vue (ADR-003: duplicated, not shared).
const backgroundStyle = computed(() => resolveBackgroundStyle(auth.user?.background))
</script>

<template>
  <div class="fixed inset-0 -z-10 bg-slate-50" :style="backgroundStyle"></div>
  <AdminNavigation v-if="showChrome" />
  <!-- Above every page, below the nav: the warning has to be the first thing
       under the chrome, or it is something you scroll past. `showChrome` keeps
       it off the login screen; the component itself also refuses to render
       before /me has answered and for any role the endpoint would 403. -->
  <CommissionReadinessBanner v-if="showChrome" />
  <!-- Bug fix (2026-08-01, human report: "sub-menu ต้องกด refresh ก่อน
       ถึงจะขึ้นถูก"): wrapping the RouterView-resolved async component in
       <Transition> (any mode, even with no mode attribute at all) breaks
       subsequent SPA navigations in this app — Vue Router's internal
       currentRoute state updates correctly, but the mounted component
       instance does not swap, leaving stale content on screen until a
       hard reload. Verified empirically via live browser testing
       (synthetic clicks, real trusted clicks, fresh tabs, long waits —
       all reproduced the same stuck state with <Transition>, all fixed
       immediately once it was removed). :key="r.path" is kept so each
       route still gets a clean remount; the page-fade animation is
       sacrificed until a Transition-safe alternative is designed. -->
  <RouterView v-slot="{ Component, route: r }">
    <component :is="Component" :key="r.path" />
  </RouterView>
</template>
