<script setup lang="ts">
/**
 * RowActions — one prominent action, everything else behind a ⋯ menu.
 *
 * 2026-09-18 (human, on a screenshot of four buttons in one row: "ตอนนี้ดูยาก
 * ไปหน่อยเรื่องปุ่ม เพราะสีมันไม่ชัดเจน … ลองใช้มาตรฐาน Apple ดู", then
 * choosing the ⋯ menu for the whole app).
 *
 * ── WHAT WAS ACTUALLY WRONG ──
 *
 * Not the colours. Every colour in that row already passed WCAG AA on white
 * (rose-600 4.7:1, emerald-700 5.5:1, brand-600 13.9:1). The problem was that
 * all four buttons wore `.btn-secondary` — a 2px navy border — so the border
 * was louder than the coloured label inside it, and "ลบ" (hard to undo) drew
 * the eye exactly as much as "แก้ไข" (done every day).
 *
 * Copying Apple's system colours would have made it WORSE, not better:
 * systemGreen is 2.2:1 on white and systemRed 3.6:1, both below the 4.5:1
 * floor for text. What is worth taking from Apple is the HIERARCHY — one
 * prominent action per context, secondary actions in a subdued container,
 * destructive ones set apart and red — not the hex values.
 *
 * ── THE RULE THIS COMPONENT ENFORCES ──
 *
 *   • At most ONE filled button per row. It is the thing the admin came to
 *     do; if a row has no such action, pass none and only ⋯ renders.
 *   • Everything else lives in the menu, in the order the admin thinks about
 *     it, with destructive items last and behind a separator.
 *   • A destructive item is red AND last AND separated — never red alone.
 *     Colour is not the only carrier of meaning (it is also the one a
 *     colour-blind admin does not receive).
 *
 * ── WHY THE MENU IS TELEPORTED ──
 *
 * Several of these rows live inside `overflow-x-auto` tables. An absolutely
 * positioned dropdown inside one is CLIPPED by the scroll container — it
 * would simply be invisible below the row, which is the kind of bug that
 * reads as "the menu button does nothing". So the panel is teleported to
 * <body> and positioned from the trigger's own bounding box, and it closes
 * on scroll rather than trying to follow the trigger around.
 *
 * Usage:
 *   <RowActions
 *     :primary="{ label: 'อนุมัติ', icon: 'check', test: 'approve' }"
 *     :items="[
 *       { label: 'แก้ไขข้อมูล', icon: 'pencil', test: 'edit', onSelect: () => openEdit(row) },
 *       { label: 'ลบ', icon: 'trash', test: 'remove', destructive: true,
 *         disabled: !canRemove, disabledReason: 'มีลูกค้า 3 ราย', onSelect: () => ask(row) },
 *     ]"
 *   />
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import Icon from './Icon.vue'

export interface RowAction {
  label: string
  icon?: string
  /** `data-test` for this item, so a spec can find it by the name it always had. */
  test?: string
  /** Red, last, behind a separator. Never red on its own — see the docblock. */
  destructive?: boolean
  disabled?: boolean
  /** Shown under a disabled item. The reason a control is dead belongs next
   *  to it, not in a tooltip nobody can open on a touch screen (CLAUDE.md §7). */
  disabledReason?: string
  onSelect?: () => void
}

export interface RowPrimaryAction {
  label: string
  icon?: string
  test?: string
  disabled?: boolean
  busyLabel?: string
  busy?: boolean
  /** 'primary' = brand navy (the default), 'positive' = the green used for
   *  approvals. Deliberately only two: a third would be a decoration. */
  tone?: 'primary' | 'positive'
  onSelect?: () => void
}

const props = withDefaults(defineProps<{
  primary?: RowPrimaryAction | null
  items?: RowAction[]
  /** `data-test` for the ⋯ trigger itself. */
  menuTest?: string
  /** Screen-reader name for the trigger; every row's menu needs its own. */
  menuLabel?: string
}>(), {
  primary: null,
  items: () => [],
  menuTest: 'row-menu',
  menuLabel: 'ตัวเลือกเพิ่มเติม',
})

const open = ref(false)
const trigger = ref<HTMLElement | null>(null)
const panel = ref<HTMLElement | null>(null)
const pos = ref({ top: 0, left: 0 })

/** Destructive items sink to the bottom, whatever order the caller passed
 *  them in — the separator above them has to mean something. */
const ordinary = computed(() => props.items.filter((i) => !i.destructive))
const destructive = computed(() => props.items.filter((i) => i.destructive))
const hasMenu = computed(() => props.items.length > 0)

const PANEL_WIDTH = 224

function place(): void {
  const el = trigger.value
  if (!el) return

  const r = el.getBoundingClientRect()
  // Right-aligned to the trigger, and nudged back inside the viewport on a
  // narrow screen rather than hanging off the edge.
  const left = Math.max(8, Math.min(r.right - PANEL_WIDTH, window.innerWidth - PANEL_WIDTH - 8))
  pos.value = { top: r.bottom + 6, left }
}

function close(): void {
  open.value = false
}

function toggle(): void {
  if (open.value) {
    close()

    return
  }
  place()
  open.value = true
}

function choose(item: RowAction): void {
  if (item.disabled) return
  close()
  item.onSelect?.()
}

function onDocumentPointer(e: PointerEvent): void {
  const t = e.target as Node
  if (trigger.value?.contains(t) || panel.value?.contains(t)) return
  close()
}

function onKey(e: KeyboardEvent): void {
  if (e.key === 'Escape') {
    close()
    trigger.value?.focus()
  }
}

/*
 * Listeners exist only while the menu is open — a page with fifty rows would
 * otherwise carry fifty scroll listeners for menus nobody has opened.
 *
 * `scroll` with capture:true so it fires for an inner scroll container too,
 * and it CLOSES rather than repositioning: a panel that chases its row while
 * the table scrolls sideways is more distracting than one that goes away.
 */
watch(open, async (isOpen) => {
  if (isOpen) {
    document.addEventListener('pointerdown', onDocumentPointer, true)
    document.addEventListener('keydown', onKey)
    window.addEventListener('scroll', close, true)
    window.addEventListener('resize', close)
    await nextTick()
    panel.value?.querySelector<HTMLElement>('[data-menuitem]:not([disabled])')?.focus()

    return
  }

  document.removeEventListener('pointerdown', onDocumentPointer, true)
  document.removeEventListener('keydown', onKey)
  window.removeEventListener('scroll', close, true)
  window.removeEventListener('resize', close)
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointer, true)
  document.removeEventListener('keydown', onKey)
  window.removeEventListener('scroll', close, true)
  window.removeEventListener('resize', close)
})

/** Roving focus, so the menu is usable without a mouse. */
function move(e: KeyboardEvent, delta: number): void {
  e.preventDefault()
  const all = Array.from(panel.value?.querySelectorAll<HTMLElement>('[data-menuitem]:not([disabled])') ?? [])
  if (all.length === 0) return

  const here = all.indexOf(document.activeElement as HTMLElement)
  all[(here + delta + all.length) % all.length]?.focus()
}
</script>

<template>
  <div class="flex items-center gap-2 shrink-0">
    <!-- At most one. A second filled button is the thing this component
         exists to prevent, so there is no slot for one. -->
    <button
      v-if="primary"
      type="button"
      class="inline-flex items-center gap-1.5 h-9 px-4 rounded-[9px] text-[13px] font-bold text-white
             whitespace-nowrap transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
      :class="primary.tone === 'positive' ? 'bg-emerald-700 hover:bg-emerald-800' : 'bg-brand-600 hover:bg-brand-700'"
      :data-test="primary.test"
      :disabled="primary.disabled || primary.busy"
      @click="primary.onSelect?.()"
    >
      <Icon v-if="primary.icon" :name="primary.icon" :size="14" />
      {{ primary.busy ? (primary.busyLabel ?? primary.label) : primary.label }}
    </button>

    <!-- Subdued container, hairline border: the Apple "bordered" weight, not
         the 2px navy outline that used to shout over its own label. -->
    <button
      v-if="hasMenu"
      ref="trigger"
      type="button"
      class="inline-flex items-center justify-center h-9 w-9 rounded-[9px] bg-slate-100 border border-slate-200
             text-slate-700 hover:bg-slate-200 transition-colors focus:outline-none focus-visible:ring-2
             focus-visible:ring-brand-400"
      :class="open ? 'bg-slate-200' : ''"
      :data-test="menuTest"
      :aria-label="menuLabel"
      :aria-expanded="open"
      aria-haspopup="menu"
      @click="toggle"
    >
      <Icon name="dots" :size="18" />
    </button>

    <Teleport to="body">
      <div
        v-if="open"
        ref="panel"
        role="menu"
        data-test="row-menu-panel"
        class="fixed z-[1100] rounded-xl bg-white border border-slate-200 shadow-xl p-1.5"
        :style="{ top: `${pos.top}px`, left: `${pos.left}px`, width: `${PANEL_WIDTH}px` }"
        style="font-family: Kanit, sans-serif;"
        @keydown.down="move($event, 1)"
        @keydown.up="move($event, -1)"
      >
        <button
          v-for="item in ordinary"
          :key="item.label"
          type="button"
          role="menuitem"
          data-menuitem
          class="w-full flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-[13.5px] text-left
                 text-slate-700 hover:bg-slate-100 focus:bg-slate-100 focus:outline-none
                 disabled:text-slate-300 disabled:hover:bg-transparent disabled:cursor-not-allowed"
          :data-test="item.test"
          :disabled="item.disabled"
          @click="choose(item)"
        >
          <Icon v-if="item.icon" :name="item.icon" :size="15" class="shrink-0" />
          <span class="truncate">{{ item.label }}</span>
        </button>

        <template v-if="destructive.length">
          <!-- The separator is load-bearing: it is what stops a destructive
               item being one careless keystroke below an everyday one. -->
          <div v-if="ordinary.length" class="h-px bg-slate-200 my-1.5 mx-2" data-test="row-menu-separator"></div>
          <template v-for="item in destructive" :key="item.label">
            <button
              type="button"
              role="menuitem"
              data-menuitem
              class="w-full flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-[13.5px] text-left
                     text-[#C81E1E] hover:bg-rose-50 focus:bg-rose-50 focus:outline-none
                     disabled:text-slate-300 disabled:hover:bg-transparent disabled:cursor-not-allowed"
              :data-test="item.test"
              :disabled="item.disabled"
              @click="choose(item)"
            >
              <Icon v-if="item.icon" :name="item.icon" :size="15" class="shrink-0" />
              <span class="truncate">{{ item.label }}</span>
            </button>
            <!-- Why it is dead, next to it — never only in a `title`, which
                 never opens on touch (CLAUDE.md §7's own objection). -->
            <p
              v-if="item.disabled && item.disabledReason"
              class="px-2.5 pb-2 pt-0.5 text-[11.5px] leading-snug text-slate-400"
              :data-test="item.test ? `${item.test}-reason` : undefined"
            >
              {{ item.disabledReason }}
            </p>
          </template>
        </template>
      </div>
    </Teleport>
  </div>
</template>
