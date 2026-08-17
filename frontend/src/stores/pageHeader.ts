import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * pageHeader — TASK-086 / ADR-021 (2026-08-03, human requirement: "ตำแหน่ง
 * นี้ในทุกหน้าไม่ให้เกินความสูงของหน้าจอ 15% รวม padding").
 *
 * WHY A STORE AND NOT PROPS
 * The measurement that drove this: the in-page header was two stacked
 * rows — 68px (icon + title + action) + 69px (tabs or search/filter) =
 * 137px. The budget is 15% of the viewport: 127px on a 844px phone and
 * only 100px on an iPhone SE. Two rows cannot fit, and trimming padding
 * recovers ~24px at most — so one row had to leave the page body
 * entirely.
 *
 * It goes up into the app top bar, which already exists (57px, always on
 * screen) and until now showed the same logo on every screen — pure
 * duplication. That is also the standard native navigation bar: back +
 * title + action.
 *
 * The bar lives in App.vue and the title is owned by whichever view is
 * mounted, i.e. data has to travel UP the tree. This store is that
 * channel. The alternative — route meta — was rejected because several
 * titles are dynamic (a client's name, a product name) and route meta is
 * static; and prop-drilling through <RouterView> would mean touching all
 * 13 views.
 *
 * HeroHeader writes here on mount and clears on unmount, so no view file
 * changes: every screen already passes `icon` / `title` / `back-page` to
 * HeroHeader, and those same props now feed the bar.
 */
export const usePageHeaderStore = defineStore('pageHeader', () => {
    /**
     * False when no HeroHeader is mounted (e.g. Home, whose hero block the
     * human had removed). App.vue falls back to the logo in that case, so
     * the bar is never left blank.
     */
    const active = ref(false)

    const icon = ref<string | null>(null)
    const title = ref<string | null>(null)
    const backPage = ref<string | null>(null)
    const backLabel = ref<string | null>(null)

    function set(payload: {
        icon?: string | null
        title: string
        backPage?: string | null
        backLabel?: string | null
    }) {
        active.value = true
        icon.value = payload.icon ?? null
        title.value = payload.title
        backPage.value = payload.backPage ?? null
        backLabel.value = payload.backLabel ?? null
    }

    /**
     * Guarded by `owner`: Vue mounts the incoming view BEFORE unmounting
     * the outgoing one, so a naive clear() in the old view's onUnmounted
     * would wipe the new view's title and leave the bar showing the logo.
     * Only the component that last wrote may clear.
     */
    let owner: symbol | null = null

    function claim(token: symbol, payload: Parameters<typeof set>[0]) {
        owner = token
        set(payload)
    }

    function release(token: symbol) {
        if (owner !== token) {
            return
        }

        owner = null
        active.value = false
        icon.value = null
        title.value = null
        backPage.value = null
        backLabel.value = null
    }

    return { active, icon, title, backPage, backLabel, set, claim, release }
})
