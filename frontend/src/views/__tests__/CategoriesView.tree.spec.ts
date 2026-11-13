import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { i18n } from '@/i18n'

const listCategories = vi.hoisted(() => vi.fn())
vi.mock('@/api/catalog', () => ({ listCategories }))
vi.mock('@/layouts/ShopLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
import CategoriesView from '../CategoriesView.vue'

const c = (id: number, name: string, parent_id: number | null, sort_order = 0) => ({ id, name, slug: name.toLowerCase().replace(/ /g, '-'), parent_id, is_active: true, sort_order })

describe('storefront category page (REM-06)', () => {
  it('renders parents as top-level groups with children and grandchildren nested inside them', async () => {
    listCategories.mockResolvedValue([c(3, 'Nastar', 2), c(1, 'Roti', null, 1), c(2, 'Kue Kering', 4), c(4, 'Kue', null, 0), c(5, 'Roti Tawar', 1)])
    const w = mount(CategoriesView, { global: { plugins: [i18n], stubs: { RouterLink: { template: '<a><slot /></a>' }, AppIcon: true } } })
    await flushPromises()
    const roots = w.findAll('[data-testid="category-root"]')
    expect(roots).toHaveLength(2)
    expect(roots[0]!.text()).toContain('Kue')
    // Kue → Kue Kering → Nastar : strictly nested
    const kue = roots[0]!
    expect(kue.find('[data-category-id="4"] [data-category-id="2"] [data-category-id="3"]').exists()).toBe(true)
    expect(kue.find('[data-category-id="3"]').attributes('data-depth')).toBe('2')
    // a child never appears as a top-level root
    expect(w.findAll('[data-testid="category-root"] > ul > li[data-depth="0"]')).toHaveLength(2)
    expect(roots[1]!.find('[data-category-id="5"]').exists()).toBe(true)
    // each category rendered exactly once
    expect(w.findAll('[data-category-id]')).toHaveLength(5)
  })

  it('shows the empty state when there is nothing to show', async () => {
    listCategories.mockResolvedValue([])
    const w = mount(CategoriesView, { global: { plugins: [i18n], stubs: { RouterLink: true, AppIcon: true } } })
    await flushPromises()
    expect(w.find('[data-testid="category-tree"]').exists()).toBe(false)
  })
})
