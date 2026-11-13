/** LOCK-07: ONE white card, one centered title + description, one row of five circular icons (IG, TikTok, FB, Tokopedia, Shopee). */
import { describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

import type { HomepageBlock } from '@/api/cms'
import SocialMarketplaceBlock from '../blocks/SocialMarketplaceBlock.vue'
import SocialMediaBlock from '../blocks/SocialMediaBlock.vue'
import MarketplaceLinksBlock from '../blocks/MarketplaceLinksBlock.vue'
import HomepageBlockRenderer from '../HomepageBlockRenderer.vue'
import { mergeSocialMarketplaceBlocks, platformItems } from '@/utils/socialMarketplace'

const social = { instagram: { enabled: true, url: 'https://instagram.com/prime' }, tiktok: { enabled: true, url: 'https://tiktok.com/@prime' }, facebook: { enabled: true, url: 'https://facebook.com/prime' } }
const market = { tokopedia: { enabled: true, url: 'https://tokopedia.com/prime' }, shopee: { enabled: true, url: 'https://shopee.co.id/prime' } }
const blocks = (): HomepageBlock[] => [
  { id: 1, type: 'hero', content: { heading: 'Hero' } },
  { id: 2, type: 'social_media', content: { heading: 'Ikuti Kami', description: 'Sosial & toko online', platforms: social } },
  { id: 3, type: 'text', content: { body: 'x' } },
  { id: 4, type: 'marketplace_links', content: { heading: 'Belanja di Marketplace', description: 'lain', platforms: market } },
]

describe('merge (CMS stays the source of truth)', () => {
  it('collapses both CMS blocks into one card at the first block position, absorbing the second', () => {
    const out = mergeSocialMarketplaceBlocks(blocks())
    expect(out.map((b) => b.type)).toEqual(['hero', 'social_marketplace', 'text'])
    expect(out[1]!.content.heading).toBe('Ikuti Kami') // one heading, never two
    expect(out[1]!.content.description).toBe('Sosial & toko online')
  })

  it('falls back to the other block title when the first has none; works with only one block kind', () => {
    const b = blocks()
    b[1]!.content.heading = ''
    expect(mergeSocialMarketplaceBlocks(b)[1]!.content.heading).toBe('Belanja di Marketplace')
    const onlyMarket = mergeSocialMarketplaceBlocks([blocks()[3]!])
    expect(onlyMarket).toHaveLength(1)
    expect(onlyMarket[0]!.type).toBe('social_marketplace')
  })

  it('leaves pages without these blocks untouched (same array)', () => {
    const none: HomepageBlock[] = [{ id: 1, type: 'hero', content: {} }]
    expect(mergeSocialMarketplaceBlocks(none)).toBe(none)
  })

  it('platform order is fixed: Instagram, TikTok, Facebook, Tokopedia, Shopee — regardless of CMS key order', () => {
    const shuffled = { shopee: market.shopee, facebook: social.facebook, tokopedia: market.tokopedia, instagram: social.instagram, tiktok: social.tiktok }
    expect(platformItems(shuffled).map((i) => i.key)).toEqual(['instagram', 'tiktok', 'facebook', 'tokopedia', 'shopee'])
  })
})

describe('card rendering', () => {
  const merged = () => mergeSocialMarketplaceBlocks(blocks())[1]!
  const mountCard = () => mount(SocialMarketplaceBlock, { props: { content: merged().content } })

  it('is exactly one white rounded card with a centered title, a centered description and ONE icon row of five', () => {
    const w = mountCard()
    expect(w.findAll('section')).toHaveLength(1)
    const card = w.get('[data-testid="social-marketplace-card"] > div')
    expect(card.classes()).toEqual(expect.arrayContaining(['bg-white', 'rounded-2xl', 'text-center', 'border']))
    expect(w.findAll('h2')).toHaveLength(1)
    expect(w.findAll('[data-testid="smc-description"]')).toHaveLength(1)
    expect(w.findAll('[data-testid="smc-icons"]')).toHaveLength(1)
    expect(w.findAll('[data-testid="smc-icons"] a').map((a) => a.attributes('data-platform'))).toEqual(['instagram', 'tiktok', 'facebook', 'tokopedia', 'shopee'])
    // title/description come BEFORE the icon row (first row = text, second row = icons), never side by side
    const html = w.html()
    expect(html.indexOf('smc-title')).toBeLessThan(html.indexOf('smc-description'))
    expect(html.indexOf('smc-description')).toBeLessThan(html.indexOf('smc-icons'))
    expect(w.get('[data-testid="smc-icons"]').classes()).toEqual(expect.arrayContaining(['flex', 'justify-center', 'flex-nowrap']))
  })

  it('icons are small circles (40px, 48px from sm) — no brown rectangular tiles, no visible text labels', () => {
    const w = mountCard()
    for (const a of w.findAll('[data-testid="smc-icons"] a')) {
      expect(a.classes()).toEqual(expect.arrayContaining(['rounded-full', 'h-10', 'w-10', 'sm:h-12', 'sm:w-12', 'bg-white']))
      expect(a.classes().join(' ')).not.toMatch(/brand-9|stone-9|from-|gradient|rounded-2xl/)
      expect(a.text()).toBe('')
      expect(a.find('svg, img').exists()).toBe(true)
    }
    expect(w.html()).not.toMatch(/bg-gradient|to-brand-900/)
  })

  it('links are safe, accessible and come from the CMS (disabled / unsafe / url-less platforms never render)', () => {
    const content = { ...merged().content, platforms: { ...social, tiktok: { enabled: false, url: 'https://tiktok.com/x' }, facebook: { enabled: true, url: 'javascript:alert(1)' }, tokopedia: { enabled: true, url: '' }, shopee: market.shopee } }
    const w = mount(SocialMarketplaceBlock, { props: { content } })
    const links = w.findAll('a')
    expect(links.map((l) => l.attributes('href'))).toEqual(['https://instagram.com/prime', 'https://shopee.co.id/prime'])
    for (const l of links) {
      expect(l.attributes('target')).toBe('_blank')
      expect(l.attributes('rel')).toBe('noopener noreferrer')
      expect(l.attributes('aria-label')).toBeTruthy()
    }
    expect(links.map((l) => l.attributes('aria-label'))).toEqual(['Instagram', 'Shopee'])
  })

  it('renders nothing when no platform is enabled', () => {
    expect(mount(SocialMarketplaceBlock, { props: { content: { heading: 'x', platforms: { instagram: { enabled: false } } } } }).find('section').exists()).toBe(false)
  })

  it('does not inject CMS text as HTML (no script injection)', () => {
    const w = mount(SocialMarketplaceBlock, { props: { content: { heading: '<img src=x onerror=alert(1)>', description: '<script>alert(1)</script>', platforms: social } } })
    expect(w.find('img[src="x"]').exists()).toBe(false)
    expect(w.find('script').exists()).toBe(false)
    expect(w.text()).toContain('<img src=x onerror=alert(1)>')
  })

  it('the renderer maps the merged type, and CMS single-block previews use the same card', async () => {
    const w = mount(HomepageBlockRenderer, { props: { block: merged() } })
    await flushPromises()
    expect(w.find('[data-testid="social-marketplace-card"]').exists()).toBe(true)
    expect(mount(SocialMediaBlock, { props: { content: { platforms: social } } }).findAll('[data-testid="smc-icons"] a')).toHaveLength(3)
    expect(mount(MarketplaceLinksBlock, { props: { content: { platforms: market } } }).findAll('[data-testid="smc-icons"] a')).toHaveLength(2)
  })
})
