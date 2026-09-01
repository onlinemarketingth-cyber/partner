<script setup lang="ts">
/**
 * AuthenticatedMedia — renders an <img> or <video> whose source is a
 * Sanctum-protected stream/thumbnail URL (ADR-007: product media,
 * Academy module video). Wraps useAuthenticatedMedia() in its own
 * component instance so it can be used freely inside v-for loops
 * (composables with onUnmounted must live in their own component
 * setup, not a parent's loop body). Ported verbatim from
 * frontend-admin/src/design-system/components/AuthenticatedMedia.vue.
 */
import { computed, toRef } from 'vue'
import { useAuthenticatedMedia } from '@/composables/useAuthenticatedMedia'
import Icon from './Icon.vue'
import { useI18n } from '@/composables/useI18n'

const { td } = useI18n()
const props = withDefaults(
  defineProps<{
    src: string | null
    type?: 'image' | 'video'
    controls?: boolean
    class?: string
  }>(),
  { type: 'image', controls: true, class: '' },
)

const sourceRef = toRef(props, 'src')
const { objectUrl, loading, error, retry } = useAuthenticatedMedia(sourceRef)
const showPlaceholder = computed(() => !props.src || loading.value || error.value)
</script>

<template>
  <!-- TASK-224 — the ERROR placeholder is a button, the other two are
       not. A failed media fetch used to be a dead red triangle that
       stayed until the component happened to remount; auto-retry now
       covers a blip, and this covers everything it deliberately does
       not (a 404 the admin has since re-uploaded, a 403 a re-login
       cleared). `type="button"` matters — this is rendered inside
       product forms, and a bare <button> would submit them. -->
  <button
    v-if="showPlaceholder && error"
    type="button"
    :title="`${error} — ${td('ui.retry_hint')}`"
    :class="['flex flex-col items-center justify-center gap-1 bg-surface-chip text-ink-card-subtle', props.class]"
    @click.stop.prevent="retry"
  >
    <Icon name="refresh" :size="20" class="text-rose-300" />
    <span class="text-[10px] font-bold">{{ td('ui.retry') }}</span>
  </button>
  <div v-else-if="showPlaceholder" :class="['flex items-center justify-center bg-surface-chip text-ink-card-subtle', props.class]">
    <Icon v-if="loading" name="clock" :size="20" class="animate-pulse" />
    <Icon v-else :name="type === 'video' ? 'play' : 'image'" :size="20" />
  </div>
  <video v-else-if="type === 'video'" :src="objectUrl!" :controls="controls" :class="props.class" />
  <img v-else :src="objectUrl!" :class="props.class" />
</template>
