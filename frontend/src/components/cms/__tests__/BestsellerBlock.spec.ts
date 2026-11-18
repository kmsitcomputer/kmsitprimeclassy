import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { i18n } from '@/i18n'
import HomepageBlockRenderer from '../HomepageBlockRenderer.vue'

describe('REM-07 CMS bestseller rendering', () => {
  const global = { plugins: [i18n, createPinia()], stubs: { RouterLink: { template: '<a><slot /></a>' } } }
  it('hides an empty ranking including its heading', () => {
    const w = mount(HomepageBlockRenderer, { props: { block: { id: 1, type: 'bestseller', content: { heading: 'Terlaris', products: [] } } }, global })
    expect(w.find('section').exists()).toBe(false)
    expect(w.text()).not.toContain('Terlaris')
  })
  it('keeps server ranking order and normal product cards', () => {
    const w = mount(HomepageBlockRenderer, { props: { block: { id: 1, type: 'bestseller', content: { heading: 'Terlaris', products: [{ id: 8, slug: 'b', name: 'Best', starting_price: '2000' }, { id: 2, slug: 'a', name: 'Second', starting_price: '1000' }] } } }, global })
    expect(w.findAll('h3').map(x => x.text())).toEqual(['Best', 'Second'])
    expect(w.findAll('[data-testid="product-price"]')).toHaveLength(2)
  })
})
