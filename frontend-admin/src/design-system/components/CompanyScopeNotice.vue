<script setup lang="ts">
/**
 * CompanyScopeNotice — TASK-208 / ADR-038.
 *
 * One shared "you are in ทุกบริษัท mode, pick a company in the header first"
 * panel, so every screen says it in the same words and points at the same
 * control. Renders nothing at all when a company IS selected (and for a
 * Company Admin, who never has this state).
 *
 * `action` lets a screen name what is blocked ("แก้ไขตั้งค่าวิดีโอ",
 * "เพิ่มแบรนด์") — vague blockers are what made the old per-screen pickers
 * confusing in the first place.
 */
import { computed } from 'vue'
import Icon from './Icon.vue'
import { useI18n } from '@/composables/useI18n'
import { useActiveCompanyStore } from '@/stores/activeCompany'

// The action is passed in ALREADY TRANSLATED by the calling screen
// (td('reward.scope_action') and friends), because only that screen knows
// what it does. Empty means "use the generic wording".
const props = withDefaults(defineProps<{ action?: string }>(), { action: '' })

const { td } = useI18n()
const actionLabel = computed(() => props.action || td('scope.default_action'))

const store = useActiveCompanyStore()
</script>

<template>
  <div
    v-if="store.requiresCompanyPick"
    class="mt-4 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 flex items-start gap-3"
  >
    <Icon name="alert" :size="18" class="text-amber-600 shrink-0 mt-0.5" />
    <div class="text-xs text-amber-800 leading-relaxed">
      <!-- {action} is a SLOT, not a suffix: Thai puts the negation after the
           verb ("...จัดการของรางวัลไม่ได้") and English puts it before
           ("you cannot manage rewards here"), so the two halves cannot be
           concatenated the way this used to. -->
      <p class="font-bold">{{ td('scope.title', '', { action: actionLabel }) }}</p>
      <p class="mt-0.5">
        {{ td('scope.help_1') }}
        <span class="font-bold">“{{ td('scope.help_all_companies') }}”</span>
        {{ td('scope.help_2') }}
      </p>
    </div>
  </div>
</template>
