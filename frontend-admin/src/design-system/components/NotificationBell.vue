<script setup lang="ts">
/**
 * NotificationBell — visual-only stub (ported verbatim from
 * frontend/src/design-system/components/NotificationBell.vue — ADR-003
 * duplicated design-system convention).
 *
 * TODO: CONFIRM (integration) — wire to a real /api/v1/notifications
 * endpoint once ag-dev builds one. Deliberately trimmed from the
 * medical-saas reference component: no axios calls, no mark-all-read, no
 * live polling. `unreadCount` is a prop so a real implementation can slot
 * in later without changing this component's public API.
 */
import { ref } from 'vue'
import Icon from './Icon.vue'

withDefaults(defineProps<{ unreadCount?: number }>(), { unreadCount: 0 })

const open = ref(false)
function toggle() {
    open.value = !open.value
}
</script>

<template>
    <div class="relative">
        <button
            type="button"
            @click="toggle"
            class="relative inline-flex items-center justify-center w-10 h-10 rounded-xl hover:bg-slate-100 transition-colors"
            :class="{ 'bg-slate-100': open }"
            title="การแจ้งเตือน"
        >
            <Icon name="bell" :size="20" :class="open ? 'text-slate-900' : 'text-slate-600'" />
            <span
                v-if="unreadCount > 0"
                class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-rose-500 text-white text-[10px] font-bold flex items-center justify-center shadow-md"
            >
                {{ unreadCount > 99 ? '99+' : unreadCount }}
            </span>
        </button>

        <div
            v-if="open"
            class="absolute right-0 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-2xl bg-white border border-slate-200 shadow-2xl overflow-hidden z-50"
        >
            <div class="px-5 py-3.5 border-b border-slate-100 flex items-center gap-2">
                <Icon name="bell" :size="16" class="text-slate-600" />
                <h3 class="font-bold text-slate-800 text-sm">การแจ้งเตือน</h3>
            </div>
            <div class="px-5 py-10 text-center text-slate-400 text-sm">
                ยังไม่มีการแจ้งเตือน
            </div>
        </div>
    </div>
</template>
