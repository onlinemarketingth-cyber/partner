/**
 * TASK-080 (2026-08-03) — announcements can now be rendered TWO ways at
 * once: the existing auto-popup modal AND an inline banner carousel on
 * selected pages. The backend added three fields to
 * GET /api/v1/announcements (AnnouncementResource):
 *
 *   show_as_modal  — boolean, default true  (may auto-pop / be a modal)
 *   show_as_banner — boolean, default false (render in a page carousel)
 *   banner_pages   — string[] | null        (which pages the banner shows on)
 *
 * This module owns the ONE banner predicate, shared by HomeView,
 * ProductBrowseView and AnnouncementsListView — the rule below is subtle
 * enough that three hand-copied `.filter()` calls would drift apart.
 *
 * IMPORTANT — everything about WHO may see an announcement (company
 * scope, published/expiry window, cert-tier audience targeting) is
 * already decided server-side; the endpoint only ever returns rows this
 * agent is allowed to see. Nothing here re-implements or second-guesses
 * that — this is purely a "where on screen does it render" filter.
 */
import type { AnnouncementModalItem } from '@/design-system/components/AnnouncementModal.vue'

/** Mirrors App\Enums\AnnouncementBannerPage on the backend. */
export type AnnouncementBannerPage = 'home' | 'products' | 'announcements'

/**
 * The `/announcements` payload = what AnnouncementModal already renders,
 * plus TASK-080's three display flags. Intersected (not redeclared) so
 * the modal stays the single source of truth for the shared shape — a
 * banner-aware announcement is still a valid `AnnouncementModalItem` and
 * can be handed straight to <AnnouncementModal>.
 */
export type BannerAwareAnnouncement = AnnouncementModalItem & {
  show_as_modal: boolean
  show_as_banner: boolean
  banner_pages: AnnouncementBannerPage[] | null
}

/**
 * Announcements to render as inline banners on `page`.
 *
 * `banner_pages === null` means ALL pages — this is deliberate on the
 * backend (see the migration comment): an admin who flips show_as_banner
 * on but never picks pages has half-configured the announcement, and the
 * useful failure mode there is "it shows everywhere", not "it silently
 * vanishes and the admin thinks the feature is broken". Same treatment
 * for an empty array, which is what an admin gets by un-ticking every
 * page — still not an instruction to hide it.
 */
export function bannerAnnouncementsForPage<T extends BannerAwareAnnouncement>(
  announcements: T[],
  page: AnnouncementBannerPage,
): T[] {
  return announcements.filter(
    (a) => a.show_as_banner === true && (!a.banner_pages?.length || a.banner_pages.includes(page)),
  )
}
