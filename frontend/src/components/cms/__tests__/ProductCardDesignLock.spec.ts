import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n } from '@/i18n'
import ProductBlock from '../blocks/ProductBlock.vue'
import ProductCard from '@/components/shop/ProductCard.vue'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

// Read the stylesheet itself: this is the single place the design lock lives.
const css = readFileSync(resolve(process.cwd(), 'src/assets/product-card.css'), 'utf8')

const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/products/:slug', name: 'product-detail', component: { template: '<div />' } }] })
const global = () => ({ plugins: [i18n, router, createPinia()] })
beforeEach(() => setActivePinia(createPinia()))

const homeProduct = { id: 1, slug: 'kastangel', name: 'Kastangel', has_variations: true, base_price: undefined, starting_price: '55000.00', image_url: undefined }
const catalogProduct = { id: 1, name: 'Kastangel', slug: 'kastangel', has_variations: true, base_price: null, images: [], variations: [{ id: 1, sku: 's', label: 'v', price: '55000.00', weight_grams: 1, is_active: true }] }

const homeCard = () => mount(ProductBlock, { props: { content: { products: [homeProduct] } }, global: global() }).find('a')
const catalogCard = () => mount(ProductCard, { props: { product: catalogProduct as never }, global: global() }).find('a')

describe.each([
  ['ProductBlock (homepage)', homeCard],
  ['ProductCard (catalog / wishlist / related)', catalogCard],
])('%s follows the Two-Tone Luxury anatomy', (_name, card) => {
  it('is one card with a 1:1 image surface and a separate footer surface', () => {
    const a = card()
    expect(a.classes()).toContain('pc-card')
    expect(a.find('.pc-card__image').classes()).toContain('aspect-square')
    expect(a.find('.pc-card__footer').exists()).toBe(true)
    expect(a.find('.pc-card__image').element.nextElementSibling).toBe(a.find('.pc-card__footer').element)
  })

  it('has a divider between the product name and the price, in that order', () => {
    const footer = card().find('.pc-card__footer').element
    const order = [...footer.children].map((e) => (e.matches('h3') ? 'name' : e.matches('[data-testid=product-divider]') ? 'divider' : e.matches('[data-testid=product-price]') ? 'price' : 'other'))
    expect(order.slice(0, 3)).toEqual(['name', 'divider', 'price'])
    expect(card().find('[data-testid=product-divider]').attributes('aria-hidden')).toBe('true') // decorative
  })

  it('keeps the two-line name slot, so the divider and price sit at the same height for short and long names', () => {
    const name = card().find('.pc-card__name')
    expect(name.classes()).toEqual(expect.arrayContaining(['line-clamp-2', 'min-h-10', 'leading-5']))
  })

  it('takes its colours only from the shared tokens (no per-theme utility classes on the card chrome)', () => {
    const a = card()
    const chrome = [a.element, a.find('.pc-card__image').element, a.find('.pc-card__footer').element, a.find('.pc-card__name').element, a.find('[data-testid=product-divider]').element, a.find('[data-testid=product-price]').element]
    for (const el of chrome) expect(el.className).not.toMatch(/(^|\s)(dark:)?(bg|border|text)-(white|stone|brand)-?\d*/)
    expect(a.html()).not.toContain('group-hover:scale') // the hover effect is the card lift, not a second zoom
  })

  it('still navigates to the product page and shows the price', () => {
    const a = card()
    expect(a.attributes('href')).toBe('/products/kastangel')
    expect(a.find('[data-testid=product-price]').text()).toMatch(/^mulai\s*Rp\s?55\.000/)
  })

  it('has no nested interactive control that could interfere with the card link, except the existing wishlist heart on the catalog card', () => {
    const buttons = card().findAll('button')
    expect(buttons.length === 0 || buttons.every((b) => /wishlist/i.test(b.attributes('aria-label') ?? ''))).toBe(true)
  })
})

describe('product-card.css — the approved tokens', () => {
  const block = (selector: string) => {
    const start = css.indexOf(selector + ' {')
    expect(start).toBeGreaterThanOrEqual(0)
    return css.slice(start, css.indexOf('}', start))
  }
  const token = (b: string, name: string) => new RegExp(`--pc-${name}:\\s*(#[0-9a-f]{6})`, 'i').exec(b)?.[1]?.toLowerCase()

  it('light mode: champagne border, cream card, two distinct surfaces, burgundy price', () => {
    const b = block('.pc-card')
    expect({
      card: token(b, 'card'), image: token(b, 'image'), footer: token(b, 'footer'), border: token(b, 'border'),
      divider: token(b, 'divider'), name: token(b, 'name'), price: token(b, 'price'),
    }).toEqual({ card: '#fffcf8', image: '#eadbcb', footer: '#f2e5d7', border: '#c5a48b', divider: '#d9c4b1', name: '#392729', price: '#8e354d' })
    expect(token(b, 'image')).not.toBe(token(b, 'footer'))
  })

  it('dark mode: charcoal card, plum surfaces, visible divider, white name, soft rose price', () => {
    const b = block('.dark .pc-card')
    expect({
      card: token(b, 'card'), image: token(b, 'image'), footer: token(b, 'footer'), border: token(b, 'border'),
      divider: token(b, 'divider'), name: token(b, 'name'), price: token(b, 'price'),
    }).toEqual({ card: '#241d20', image: '#34292d', footer: '#2d2327', border: '#5c424b', divider: '#5c424b', name: '#ffffff', price: '#f49ab2' })
    expect(token(b, 'image')).not.toBe(token(b, 'footer'))
  })

  it('structure is theme-independent: only custom properties differ in the dark block', () => {
    const dark = block('.dark .pc-card')
    const declarations = dark.split('{')[1]!.split(';').map((d) => d.trim()).filter(Boolean)
    expect(declarations.every((d) => d.startsWith('--pc-'))).toBe(true)
  })

  it('a 1px border, 16px radius, clipped corners and a layered resting shadow', () => {
    const b = block('.pc-card')
    expect(b).toMatch(/border:\s*1px solid var\(--pc-border\)/)
    expect(b).toMatch(/border-radius:\s*16px/)
    expect(b).toMatch(/overflow:\s*hidden/)
    expect(b).toMatch(/--pc-shadow:[^;]*,[^;]*;/) // two layers
    expect(b).toMatch(/box-shadow:\s*var\(--pc-shadow\)/)
    expect(block('.pc-card__divider')).toMatch(/height:\s*1px/)
  })

  it('hover lifts 2px only on real pointers, never moves under reduced motion, and keeps a visible focus ring', () => {
    expect(css).toMatch(/@media \(hover: hover\) and \(pointer: fine\)\s*\{\s*\.pc-card:hover\s*\{[^}]*translateY\(-2px\)/)
    expect(css).toMatch(/@media \(prefers-reduced-motion: reduce\)[\s\S]*\.pc-card:hover\s*\{\s*transform:\s*none/)
    expect(css).toMatch(/\.pc-card:focus-visible\s*\{\s*outline:\s*2px solid var\(--pc-price\)/)
  })
})
