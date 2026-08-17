import type { UserBackground } from '@/stores/auth'

/**
 * Resolves a user's saved background preference (gradient or uploaded
 * image) into an inline style object for the App shell's background
 * layer. Ported from frontend/src/utils/userBackground.ts (ADR-003 —
 * no shared package between the two frontends yet).
 */
export function resolveBackgroundStyle(background: UserBackground | null | undefined): Record<string, string> {
  if (!background) return {}
  if (background.type === 'gradient' && background.config) {
    const { color1, color2, angle } = background.config
    return { backgroundImage: `linear-gradient(${angle}deg, ${color1}, ${color2})` }
  }
  if (background.type === 'image' && background.image_url) {
    return { backgroundImage: `url(${background.image_url})`, backgroundSize: 'cover', backgroundPosition: 'center' }
  }
  return {}
}
