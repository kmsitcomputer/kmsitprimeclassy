import type { HomepageBlock } from '@/api/cms'
import { safeHref } from '@/utils/safeUrl'

export interface PlatformConfig { enabled?: boolean; url?: string }
export interface PlatformItem { key: string; href: string; label: string }

/** Owner-approved fixed order of the single Social Media & Marketplace card. */
export const PLATFORM_ORDER = ['instagram', 'tiktok', 'facebook', 'tokopedia', 'shopee'] as const
export const PLATFORM_LABEL: Record<string, string> = { instagram: 'Instagram', tiktok: 'TikTok', facebook: 'Facebook', tokopedia: 'Tokopedia', shopee: 'Shopee' }

/** Only platforms that are ENABLED in the CMS and carry a safe link become icons (no broken/unsafe links). */
export function platformItems(platforms: Record<string, PlatformConfig> | undefined): PlatformItem[] {
  const out: PlatformItem[] = []
  for (const key of PLATFORM_ORDER) {
    const cfg = platforms?.[key]
    const href = cfg?.enabled ? safeHref(cfg.url) : undefined
    if (href) out.push({ key, href, label: PLATFORM_LABEL[key]! })
  }
  return out
}

export const SOCIAL_MARKETPLACE_TYPE = 'social_marketplace'

/**
 * The CMS keeps two block kinds (social_media, marketplace_links) — the existing source of truth,
 * editable as before. The storefront shows them as ONE card: it appears where the first of the two sits,
 * the second is absorbed, platforms follow the approved order, and heading/description come from the first
 * block that has them. No new configuration, no stored data is changed.
 */
export function mergeSocialMarketplaceBlocks(blocks: HomepageBlock[]): HomepageBlock[] {
  const isPart = (b: HomepageBlock) => b.type === 'social_media' || b.type === 'marketplace_links'
  const parts = blocks.filter(isPart)
  if (parts.length === 0) return blocks
  const first = <K extends 'heading' | 'description'>(k: K) => parts.map((p) => p.content[k]).find((v) => typeof v === 'string' && v.trim() !== '')
  const platforms: Record<string, PlatformConfig> = {}
  for (const part of parts) Object.assign(platforms, part.content.platforms ?? {})
  const merged: HomepageBlock = {
    id: parts[0]!.id,
    type: SOCIAL_MARKETPLACE_TYPE,
    content: { heading: first('heading'), description: first('description'), platforms },
  }
  let placed = false
  const out: HomepageBlock[] = []
  for (const b of blocks) {
    if (!isPart(b)) out.push(b)
    else if (!placed) {
      out.push(merged)
      placed = true
    }
  }
  return out
}
