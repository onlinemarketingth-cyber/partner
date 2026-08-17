<script setup lang="ts">
import { useRoute } from 'vue-router'
import Icon from '@/design-system/components/Icon.vue'

const route = useRoute()
const title = (route.meta.navLabel as string) ?? 'หน้านี้'
</script>

<template>
  <main class="min-h-screen px-4 py-6 lg:px-8">
    <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-line-card bg-surface-card/95 py-24 text-center">
      <Icon name="clock" :size="28" class="text-ink-card-subtle" />
      <h1 class="text-lg font-bold text-ink-card">{{ title }}</h1>
      <p class="text-sm text-ink-card-subtle">
        ยังไม่มี — รอ task spec + endpoint จาก ag-dev ก่อนถึงจะสร้างหน้านี้จริง
      </p>
    </div>
  </main>
</template>
