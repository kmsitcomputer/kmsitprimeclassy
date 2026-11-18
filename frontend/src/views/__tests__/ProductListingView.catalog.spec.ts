import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { i18n } from '@/i18n'
import { buildCategoryTree, categoryPath } from '@/utils/categoryTree'

const { listCategories, listProducts } = vi.hoisted(() => ({ listCategories: vi.fn(), listProducts: vi.fn() }))
vi.mock('@/api/catalog', () => ({ listCategories, listProducts }))
vi.mock('@/layouts/ShopLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/components/shop/StockSourceSelector.vue', () => ({ default: { template: '<div />' } }))
import ProductListingView from '../ProductListingView.vue'

const c = (id: number, name: string, parent_id: number | null, sort_order = 0) => ({ id, name, slug: name.toLowerCase().replace(/ /g, '-'), parent_id, is_active: true, sort_order })
// The Owner-confirmed structure: Cake and Cookies are separate roots; Yummy Class belongs to Cookies. One extra level proves depth is dynamic.
const CATS = [c(1, 'Cake', null), c(2, 'Cookies', null, 1), c(3, 'Yummy Class', 2), c(4, 'Kastangel Premium', 3)]

async function open(query = '') {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }, { path: '/products', name: 'products', component: { template: '<div />' } }] })
  await router.push('/products' + query)
  const w = mount(ProductListingView, { attachTo: document.body, global: { plugins: [createPinia(), router, i18n], stubs: { ProductCard: { template: '<div data-testid="card" />' }, AppIcon: true } } })
  await flushPromises()
  return { w, router }
}
const ids = (w: ReturnType<typeof mount>, scope = '[data-testid="category-sidebar"]') => w.findAll(`${scope} li[data-category-id]`).map((li) => `${li.attributes('data-category-id')}@${li.attributes('data-depth')}`)

beforeEach(() => {
  localStorage.clear()
  document.body.innerHTML = ''
  listCategories.mockResolvedValue(CATS)
  listProducts.mockResolvedValue({ products: [{ id: 1 }, { id: 2 }], meta: { total: 7, current_page: 1, last_page: 1 } })
})

describe('categoryPath', () => {
  it('returns the real root→target chain and nothing for unknown slugs', () => {
    const tree = buildCategoryTree(CATS)
    expect(categoryPath(tree, 'kastangel-premium').map((x) => x.name)).toEqual(['Cookies', 'Yummy Class', 'Kastangel Premium'])
    expect(categoryPath(tree, 'cake').map((x) => x.name)).toEqual(['Cake'])
    expect(categoryPath(tree, 'nope')).toEqual([])
    expect(categoryPath(tree, '')).toEqual([])
  })
})

describe('catalog category tree', () => {
  it('shows Cake and Cookies as roots, collapsed, with Yummy Class under Cookies only', async () => {
    const { w } = await open()
    expect(ids(w)).toEqual(['1@0', '2@0']) // collapsed: children not rendered
    await w.find('[data-category-id="2"] [data-testid="category-toggle"]').trigger('click')
    expect(ids(w)).toEqual(['1@0', '2@0', '3@1'])
    expect(w.find('[data-category-id="1"] [data-category-id="3"]').exists()).toBe(false) // never under Cake
    expect(w.find('[data-category-id="2"] > div [data-category-id="3"]').exists()).toBe(true)
  })

  it('opens the active category and every ancestor, highlights it, and supports a third level', async () => {
    const { w } = await open('?category=kastangel-premium')
    expect(ids(w)).toEqual(['1@0', '2@0', '3@1', '4@2'])
    const active = w.find('[data-category-id="4"] [data-testid="category-select"]')
    expect(active.attributes('aria-current')).toBe('true')
    expect(w.findAll('[data-testid="category-sidebar"] [aria-current="true"]')).toHaveLength(1)
    expect(w.find('[data-category-id="2"] [data-testid="category-toggle"]').attributes('aria-expanded')).toBe('true')
  })

  it('toggle buttons are real buttons with state and a name; collapsing hides the branch', async () => {
    const { w } = await open('?category=cookies')
    const toggle = w.find('[data-category-id="2"] [data-testid="category-toggle"]')
    expect(toggle.element.tagName).toBe('BUTTON')
    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(toggle.attributes('aria-label')).toContain('Cookies')
    expect(toggle.attributes('aria-controls')).toBe('cat-children-2')
    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(w.find('[data-category-id="3"]').exists()).toBe(false)
  })

  it('selecting a category filters through the real query (slug) and reloads products', async () => {
    const { w, router } = await open()
    await w.find('[data-category-id="2"] [data-testid="category-select"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query.category).toBe('cookies')
    expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ category: 'cookies' }))
    expect(w.find('[data-testid="catalog-title"]').text()).toBe('Cookies')
  })
})

describe('catalog header, filters and sort', () => {
  it('breadcrumb follows the parent chain and the count is the API total (no invented numbers)', async () => {
    const { w } = await open('?category=yummy-class')
    const crumbs = w.findAll('[data-testid="catalog-breadcrumb"] li').map((li) => li.text()).filter((t) => t !== '›')
    expect(crumbs).toEqual(['Beranda', 'Semua Produk', 'Cookies', 'Yummy Class'])
    expect(w.find('[data-testid="catalog-breadcrumb"] [aria-current="page"]').text()).toBe('Yummy Class')
    expect(w.find('[data-testid="catalog-title"]').text()).toBe('Yummy Class')
    expect(w.find('[data-testid="catalog-count"]').text()).toBe('7 produk ditemukan')
  })

  it('without a category the title is "Semua Produk" and no filter chips are shown', async () => {
    const { w } = await open()
    expect(w.find('[data-testid="catalog-title"]').text()).toBe('Semua Produk')
    expect(w.find('[data-testid="active-filters"]').exists()).toBe(false)
  })

  it('active filters show as chips; one chip clears only itself; reset clears them all', async () => {
    const { w, router } = await open('?category=cookies&min_price=10000&max_price=90000')
    const chips = w.findAll('[data-testid="active-filters"] [data-filter]').map((x) => x.attributes('data-filter'))
    expect(chips).toEqual(['category', 'min', 'max'])
    await w.find('[data-filter="min"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({ category: 'cookies', max_price: '90000' })
    await w.find('[data-testid="reset-filters"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({})
    expect(w.find('[data-testid="active-filters"]').exists()).toBe(false)
  })

  it('sorting is wired to the API', async () => {
    const { w } = await open()
    await w.find('[data-testid="catalog-sort"]').setValue('price_asc')
    await flushPromises()
    expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ sort: 'price_asc' }))
  })

  it('price filter reaches the API as numbers', async () => {
    const { w } = await open('?min_price=5000&max_price=20000')
    expect(listProducts).toHaveBeenLastCalledWith(expect.objectContaining({ min_price: 5000, max_price: 20000 }))
    expect(w.findAll('[data-testid="catalog-grid"] [data-testid="card"]')).toHaveLength(2)
  })

  it('empty result offers a reset when filters are active; errors are announced', async () => {
    listProducts.mockResolvedValueOnce({ products: [], meta: { total: 0, current_page: 1, last_page: 1 } })
    const { w } = await open('?category=cookies')
    expect(w.find('[data-testid="catalog-empty"]').exists()).toBe(true)
    expect(w.find('[data-testid="catalog-empty"] button').exists()).toBe(true)
    listProducts.mockRejectedValueOnce(new Error('x'))
    const { w: w2 } = await open()
    expect(w2.find('[role="alert"]').exists()).toBe(true)
  })

  it('mobile: the filter button opens a drawer with the same tree; its controls are reachable and it closes', async () => {
    const { w } = await open('?category=cookies')
    await w.find('[data-testid="filters-open"]').trigger('click')
    const drawer = document.body.querySelector('[data-testid="filters-drawer"]')!
    expect(drawer.getAttribute('role')).toBe('dialog')
    expect(drawer.querySelectorAll('li[data-category-id]').length).toBe(3) // Cake, Cookies, Yummy Class (Cookies open)
    ;(drawer.querySelector('button[aria-label="Tutup"]') as HTMLButtonElement).click()
    await flushPromises()
    expect(document.body.querySelector('[data-testid="filters-drawer"]')).toBeNull()
  })
})
