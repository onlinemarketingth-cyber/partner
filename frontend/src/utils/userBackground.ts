import type { UserBackground } from '@/stores/auth'

/**
 * Resolves a user's background preference into an inline CSS style
 * object. Shared by App.vue (applies it behind the whole shell) and
 * ProfileSettingsView (live preview) so the two can never drift.
 * Falls back to an empty object (the existing bg-slate-50 Tailwind
 * class on <main>/App.vue takes over) when no preference is set —
 * never invents a default gradient/image.
 */
export function resolveBackgroundStyle(background: UserBackground | null | undefined): Record<string, string> {
  if (!background) return {}

  if (background.type === 'gradient' && background.config) {
    const { color1, color2, angle } = background.config
    return { backgroundImage: `linear-gradient(${angle}deg, ${color1}, ${color2})` }
  }

  if (background.type === 'image' && background.image_url) {
    return {
      backgroundImage: `url(${background.image_url})`,
      backgroundSize: 'cover',
      backgroundPosition: 'center',
    }
  }

  return {}
}
