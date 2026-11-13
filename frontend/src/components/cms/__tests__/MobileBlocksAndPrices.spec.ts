import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n } from '@/i18n'
import CtaBlock from '../blocks/CtaBlock.vue'
import ProductBlock from '../blocks/ProductBlock.vue'
import ProductCard from '@/components/shop/ProductCard.vue'

const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/p/:slug', name: 'product-detail', component: { template: '<div />' } }] })
const global = () => ({ plugins: [i18n, router, createPinia()] })

beforeEach(() => setActivePinia(createPinia()))

describe('CTA — compact premium on mobile, unchanged from sm up', () => {
  const content = { heading: 'Unduh aplikasinya', button_label: 'Download Sekarang', button_url: 'https://example.com/app' }

  it('the button is content-sized (never full width) with a 44px touch target and a 14px label', () => {
    const w = mount(CtaBlock, { props: { content } })
    const cls = w.find('a').classes()
    expect(cls).not.toContain('w-full')
    expect(cls).toContain('w-fit')
    expect(cls).toContain('min-h-11')
    expect(cls).toContain('text-sm')
    expect(cls).toContain('font-semibold')
    expect(cls).toContain('rounded-full')
    expect(cls).toContain('px-5')
    expect(cls).toContain('py-2.5')
  })

  it('mobile block padding is 20/24px and the title-to-button gap 16px; sm+ values are the previous ones', () => {
    const w = mount(CtaBlock, { props: { content } })
    const section = w.find('section').classes()
    expect(section).toEqual(expect.arrayContaining(['px-5', 'py-6', 'sm:px-10', 'sm:py-12', 'lg:px-14']))
    expect(w.find('section > div:last-child').classes()).toEqual(expect.arrayContaining(['gap-4', 'sm:gap-6']))
    const a = w.find('a').classes()
    expect(a).toEqual(expect.arrayContaining(['sm:w-auto', 'sm:px-7', 'sm:py-3', 'sm:text-base']))
  })

  it('keeps label, url, description, theme and alignment from the CMS and invents nothing', () => {
    const w = mount(CtaBlock, { props: { content: { ...content, description: 'Belanja lebih cepat', theme: 'brand', align: 'center' } } })
    expect(w.find('a').text()).toBe('Download Sekarang')
    expect(w.find('a').attributes('href')).toBe('https://example.com/app')
    expect(w.find('p').text()).toBe('Belanja lebih cepat')
    expect(w.find('section').classes().join(' ')).toContain('to-brand-800')
    expect(w.find('section > div:last-child').classes()).toContain('text-center')
    expect(mount(CtaBlock, { props: { content } }).text()).not.toMatch(/aplikasi prime/i)
    expect(mount(CtaBlock, { props: { content } }).find('p').exists()).toBe(false)
  })
})

describe('Product price on the homepage block — "mulai —" regression', () => {
  const base = { id: 1, slug: 'kastangel', name: 'Kastangel', image_url: undefined }
  const price = (w: ReturnType<typeof mount>) => w.find('[data-testid="product-price"]').text()

  it('a variation product shows "mulai" and its cheapest active variation price', () => {
    const w = mount(ProductBlock, { props: { content: { products: [{ ...base, has_variations: true, base_price: undefined, starting_price: '55000.00' }] } }, global: global() })
    expect(price(w)).toMatch(/^mulai\s*Rp\s?55\.000/)
    expect(price(w)).not.toContain('—')
  })

  it('a simple product shows its base price, with or without the new field', () => {
    const withField = mount(ProductBlock, { props: { content: { products: [{ ...base, has_variations: false, base_price: 100000, starting_price: '100000.00' }] } }, global: global() })
    expect(price(withField)).toMatch(/Rp\s?100\.000/)
    expect(price(withField)).not.toContain('mulai')
    const legacy = mount(ProductBlock, { props: { content: { products: [{ ...base, has_variations: false, base_price: 100000 }] } }, global: global() })
    expect(price(legacy)).toMatch(/Rp\s?100\.000/)
  })

  it('no valid price means no amount at all, never a made-up one', () => {
    for (const starting_price of [null, '', 'abc'] as const) {
      const w = mount(ProductBlock, { props: { content: { products: [{ ...base, has_variations: true, base_price: undefined, starting_price }] } }, global: global() })
      expect(price(w)).toBe('—')
    }
  })

  it('cards are two columns on mobile, three on tablet, four from lg, with the compact gaps', () => {
    const w = mount(ProductBlock, { props: { content: { products: [{ ...base, has_variations: false, base_price: 1000 }] } }, global: global() })
    expect(w.find('section > div').classes()).toEqual(expect.arrayContaining(['grid-cols-2', 'sm:grid-cols-3', 'lg:grid-cols-4', 'gap-2.5', 'sm:gap-3.5', 'lg:gap-4']))
    expect(w.find('section > div').classes()).not.toContain('md:grid-cols-4')
    expect(w.find('h3').classes()).toEqual(expect.arrayContaining(['line-clamp-2', 'min-h-10'])) // two-line name area keeps rows aligned
    expect(w.find('.aspect-square').exists()).toBe(true)
  })
})

describe('ProductCard (catalog) price', () => {
  const variation = (price: string, extra: Record<string, unknown> = {}) => ({ id: Math.random(), sku: 's', label: 'v', price, weight_grams: 1, is_active: true, ...extra })
  const product = (extra: Record<string, unknown>) => ({ id: 1, name: 'Kastangel', slug: 'kastangel', has_variations: true, base_price: null, images: [], variations: [], ...extra }) as never
  const priceOf = (p: never) => mount(ProductCard, { props: { product: p }, global: global() }).find('[data-testid="product-price"]').text()

  it('shows the cheapest active variation as "mulai"', () => {
    expect(priceOf(product({ variations: [variation('110000.00'), variation('95000.00'), variation('55000.00')] }))).toMatch(/^mulai\s*Rp\s?55\.000/)
  })

  it('ignores an inactive variation even when it is the cheapest', () => {
    expect(priceOf(product({ variations: [variation('10000.00', { is_active: false }), variation('70000.00')] }))).toMatch(/Rp\s?70\.000/)
  })

  it('no valid price, or a payload without variations, renders a dash instead of crashing or inventing a number', () => {
    expect(priceOf(product({ variations: [] }))).toBe('—')
    expect(priceOf(product({ variations: [variation('x')] }))).toBe('—')
    expect(priceOf(product({ variations: undefined }))).toBe('—')
  })

  it('a simple product shows its base price; the card keeps a 1:1 photo area with or without an image', () => {
    const simple = mount(ProductCard, { props: { product: product({ has_variations: false, base_price: '100000.00' }) }, global: global() })
    expect(simple.find('[data-testid="product-price"]').text()).toMatch(/Rp\s?100\.000/)
    expect(simple.find('.aspect-square').exists()).toBe(true) // placeholder state (no image uploaded) keeps the same box
    const withImage = mount(ProductCard, { props: { product: product({ has_variations: false, base_price: '1', images: [{ id: 1, url: '/a.jpg', is_primary: true }] }) }, global: global() })
    expect(withImage.find('.aspect-square img').attributes('src')).toBe('/a.jpg')
  })
})
