/**
 * TASK-055 follow-up — split Thai / Latin font catalog for the Admin
 * theme-settings font picker. Every family here is a Google Font, loaded
 * on demand for the live preview and applied per-script by the Agent
 * Portal (Latin-first / Thai-second per-glyph stack).
 *
 * Presentational data only — no business logic (CLAUDE.md §7).
 */

export type FontScript = 'thai' | 'latin'

export type FontCategory =
  | 'modern'
  | 'professional'
  | 'elegant'
  | 'cute'
  | 'fancy'
  | 'loud'
  | 'futuristic'
  | 'vintage'
  | 'handwritten'
  | 'active'
  | 'calm'
  | 'playful'
  | 'sophisticated'
  | 'rugged'
  | 'excited'

export interface FontItem {
  name: string
  script: FontScript
  categories: FontCategory[]
}

export const FONT_CATALOG: FontItem[] = [
  // ── Thai ────────────────────────────────────────────────────────────────
  { name: 'Kanit', script: 'thai', categories: ['modern', 'professional', 'loud'] },
  { name: 'Prompt', script: 'thai', categories: ['modern', 'professional', 'calm'] },
  { name: 'Sarabun', script: 'thai', categories: ['professional', 'calm'] },
  { name: 'Noto Sans Thai', script: 'thai', categories: ['professional', 'modern'] },
  { name: 'Mitr', script: 'thai', categories: ['modern', 'active'] },
  { name: 'Bai Jamjuree', script: 'thai', categories: ['modern', 'futuristic'] },
  { name: 'Chakra Petch', script: 'thai', categories: ['futuristic', 'loud'] },
  { name: 'IBM Plex Sans Thai', script: 'thai', categories: ['professional', 'modern'] },
  { name: 'K2D', script: 'thai', categories: ['modern', 'playful'] },
  { name: 'Krub', script: 'thai', categories: ['professional', 'calm'] },
  { name: 'Athiti', script: 'thai', categories: ['modern', 'calm'] },
  { name: 'Fahkwang', script: 'thai', categories: ['modern', 'professional'] },
  { name: 'Thasadith', script: 'thai', categories: ['calm', 'professional'] },
  { name: 'Sriracha', script: 'thai', categories: ['handwritten', 'cute', 'playful'] },
  { name: 'Charm', script: 'thai', categories: ['elegant', 'cute'] },
  { name: 'Charmonman', script: 'thai', categories: ['handwritten', 'fancy'] },
  { name: 'Mali', script: 'thai', categories: ['cute', 'playful', 'calm'] },
  { name: 'Pridi', script: 'thai', categories: ['elegant', 'sophisticated'] },
  { name: 'Trirong', script: 'thai', categories: ['elegant', 'sophisticated', 'vintage'] },
  { name: 'Taviraj', script: 'thai', categories: ['elegant', 'sophisticated'] },
  { name: 'Maitree', script: 'thai', categories: ['professional', 'calm'] },
  { name: 'Niramit', script: 'thai', categories: ['professional', 'elegant'] },
  { name: 'Chonburi', script: 'thai', categories: ['loud', 'vintage', 'elegant'] },

  // ── Latin ───────────────────────────────────────────────────────────────
  { name: 'Inter', script: 'latin', categories: ['modern', 'professional'] },
  { name: 'Roboto', script: 'latin', categories: ['modern', 'professional'] },
  { name: 'Poppins', script: 'latin', categories: ['modern', 'playful'] },
  { name: 'Montserrat', script: 'latin', categories: ['modern', 'professional'] },
  { name: 'Open Sans', script: 'latin', categories: ['professional', 'calm'] },
  { name: 'Lato', script: 'latin', categories: ['professional', 'calm'] },
  { name: 'Source Sans 3', script: 'latin', categories: ['professional', 'modern'] },
  { name: 'Nunito', script: 'latin', categories: ['calm', 'cute'] },
  { name: 'Mulish', script: 'latin', categories: ['calm', 'professional'] },
  { name: 'Work Sans', script: 'latin', categories: ['modern', 'professional'] },
  { name: 'Playfair Display', script: 'latin', categories: ['elegant', 'sophisticated', 'vintage'] },
  { name: 'Cormorant', script: 'latin', categories: ['elegant', 'sophisticated'] },
  { name: 'EB Garamond', script: 'latin', categories: ['elegant', 'sophisticated', 'vintage'] },
  { name: 'Marcellus', script: 'latin', categories: ['elegant', 'sophisticated'] },
  { name: 'Cinzel', script: 'latin', categories: ['vintage', 'elegant', 'sophisticated'] },
  { name: 'Comfortaa', script: 'latin', categories: ['cute', 'calm', 'playful'] },
  { name: 'Baloo 2', script: 'latin', categories: ['cute', 'playful'] },
  { name: 'Quicksand', script: 'latin', categories: ['cute', 'calm', 'modern'] },
  { name: 'Fredoka', script: 'latin', categories: ['playful', 'cute'] },
  { name: 'Lobster', script: 'latin', categories: ['fancy', 'playful'] },
  { name: 'Pacifico', script: 'latin', categories: ['fancy', 'handwritten'] },
  { name: 'Dancing Script', script: 'latin', categories: ['handwritten', 'fancy', 'elegant'] },
  { name: 'Caveat', script: 'latin', categories: ['handwritten', 'active'] },
  { name: 'Satisfy', script: 'latin', categories: ['handwritten', 'fancy'] },
  { name: 'Anton', script: 'latin', categories: ['loud', 'active'] },
  { name: 'Bebas Neue', script: 'latin', categories: ['loud', 'active', 'modern'] },
  { name: 'Archivo Black', script: 'latin', categories: ['loud', 'modern'] },
  { name: 'Oswald', script: 'latin', categories: ['active', 'professional', 'rugged'] },
  { name: 'Teko', script: 'latin', categories: ['active', 'futuristic'] },
  { name: 'Staatliches', script: 'latin', categories: ['rugged', 'loud', 'vintage'] },
  { name: 'Audiowide', script: 'latin', categories: ['futuristic', 'excited'] },
  { name: 'Orbitron', script: 'latin', categories: ['futuristic', 'excited'] },
  { name: 'Rajdhani', script: 'latin', categories: ['futuristic', 'modern'] },
  { name: 'Exo 2', script: 'latin', categories: ['futuristic', 'modern'] },
  { name: 'Righteous', script: 'latin', categories: ['excited', 'playful', 'loud'] },
  { name: 'Bungee', script: 'latin', categories: ['excited', 'loud', 'playful'] },
  { name: 'Abril Fatface', script: 'latin', categories: ['vintage', 'elegant', 'fancy'] },
]

export const CATEGORY_LABELS: { key: FontCategory | 'all'; label: string }[] = [
  { key: 'all', label: 'ทั้งหมด' },
  { key: 'modern', label: 'Modern' },
  { key: 'professional', label: 'Professional' },
  { key: 'elegant', label: 'Elegant' },
  { key: 'cute', label: 'Cute' },
  { key: 'fancy', label: 'Fancy / Art' },
  { key: 'loud', label: 'Loud / Bold' },
  { key: 'futuristic', label: 'Futuristic' },
  { key: 'vintage', label: 'Vintage' },
  { key: 'handwritten', label: 'Handwritten' },
  { key: 'active', label: 'Active' },
  { key: 'calm', label: 'Calm' },
  { key: 'playful', label: 'Playful' },
  { key: 'sophisticated', label: 'Sophisticated' },
  { key: 'rugged', label: 'Rugged' },
  { key: 'excited', label: 'Excited' },
]
