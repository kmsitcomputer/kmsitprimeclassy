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
beforeEach(() => {
  localStorage.clear() // the wishlist persists client-side; every test starts empty
  setActivePinia(createPinia())
})

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

describe.each([
  ['ProductBlock (homepage)', homeCard],
  ['ProductCard (catalog / wishlist / related)', catalogCard],
])('%s: shared wishlist, action and fallback', (_name, card) => {
  it('shows the wishlist heart and a footer action that is only a link affordance (never an add-to-cart control)', () => {
    const a = card()
    expect(a.find('.pc-card__wish').exists()).toBe(true)
    expect(a.find('.pc-card__wish').attributes('aria-pressed')).toBe('false')
    const cta = a.find('[data-testid=product-card-cta]')
    expect(cta.exists()).toBe(true)
    expect(cta.element.tagName).toBe('SPAN')
    expect(cta.attributes('aria-hidden')).toBe('true')
    // the only buttons on the card are the wishlist toggle; nothing can reach the cart without opening the product page
    expect(a.findAll('button')).toHaveLength(1)
    expect(a.attributes('href')).toBe('/products/kastangel')
  })

  it('toggling the wishlist does not navigate and flips the pressed state', async () => {
    const a = card()
    await a.find('.pc-card__wish').trigger('click')
    expect(a.find('.pc-card__wish').attributes('aria-pressed')).toBe('true')
    expect(a.find('.pc-card__wish').classes()).toContain('pc-card__wish--on')
  })
})

describe('shared card: image, stock and price are data driven', () => {
  const mountCard = (product: object) => mount(ProductCard, { props: { product: product as never }, global: global() })
  const base = { id: 7, name: 'Nastar', slug: 'nastar', has_variations: false, base_price: '45000.00', images: [{ id: 1, url: '/x.jpg', is_primary: true }], variations: [] }

  it('falls back to the placeholder when the image fails to load', async () => {
    const w = mountCard(base)
    expect(w.find('img').exists()).toBe(true)
    await w.find('img').trigger('error')
    expect(w.find('img').exists()).toBe(false)
    expect(w.find('.pc-card__placeholder').exists()).toBe(true)
  })

  it('uses the placeholder when there is no image, and shows the real price (no "mulai" for a simple product)', () => {
    const w = mountCard({ ...base, images: [] })
    expect(w.find('.pc-card__placeholder').exists()).toBe(true)
    expect(w.find('[data-testid=product-price]').text()).toMatch(/^Rp\s?45\.000/)
  })

  it('catalog card keeps its stock slot; the homepage card, which has no stock data, shows none', () => {
    expect(mountCard(base).find('.pc-card__stock').exists()).toBe(true)
    expect(homeCard().find('.pc-card__stock').exists()).toBe(false)
  })
})

describe('product-card.css — the approved tokens', () => {
  it('holds the out-of-stock label to one compact line so rows keep one height', () => {
    const b = css.slice(css.indexOf(".pc-card__stock[data-testid='stock-out-label'] {"))
    expect(b).toMatch(/white-space:\s*nowrap/)
    expect(b).toMatch(/font-size:\s*12px/)
  })

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
    }).toEqual({ card: '#fffcf8', image: '#eadbcb', footer: '#f2e5d7', border: '#c5a48b', divider: '#d9c4b1', name: '#4a1a26', price: '#8e354d' })
    expect(token(b, 'image')).not.toBe(token(b, 'footer'))
  })

  it('dark mode: deep maroon card, maroon surfaces, visible divider, warm-white name, soft pink price', () => {
    const b = block('.dark .pc-card')
    expect({
      card: token(b, 'card'), image: token(b, 'image'), footer: token(b, 'footer'), border: token(b, 'border'),
      divider: token(b, 'divider'), name: token(b, 'name'), price: token(b, 'price'),
    }).toEqual({ card: '#2a1018', image: '#3d1b26', footer: '#34151e', border: '#6b2c3c', divider: '#5a2433', name: '#fbf3ee', price: '#f7a8be' })
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
