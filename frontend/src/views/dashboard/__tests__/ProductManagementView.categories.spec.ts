import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { ApiError } from '@/api/client'
const { listCategories, createCategory, updateCategory } = vi.hoisted(() => ({ listCategories: vi.fn(), createCategory: vi.fn(), updateCategory: vi.fn() }))
vi.mock('@/api/catalog', () => ({
  listCategories, createCategory, updateCategory, deleteCategory: vi.fn(),
  listManagedProducts: vi.fn().mockResolvedValue({ products: [] }),
  deleteProduct: vi.fn(), hardDeleteProduct: vi.fn(), downloadCatalogExport: vi.fn(),
  previewCatalogImport: vi.fn(), applyCatalogImport: vi.fn(),
  getProductFees: vi.fn(), setProductFees: vi.fn(),
}))
vi.mock('@/layouts/DashboardLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
import ProductManagementView from '../ProductManagementView.vue'

const cat = (id: number, name: string, parent_id: number | null) => ({ id, name, slug: name.toLowerCase(), parent_id, is_active: true, sort_order: 0 })
let tree: ReturnType<typeof cat>[]
async function setup() {
  const pinia = createPinia()
  const auth = useAuthStore(pinia)
  auth.user = { id: 1, role: (globalThis as { __role?: string }).__role ?? 'super_admin' } as typeof auth.user
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/list', name: 'product-management', component: { template: '<div />' } },
      { path: '/new', name: 'product-create', component: { template: '<div />' } },
      { path: '/opnames', name: 'warehouse-opnames', component: { template: '<div />' } },
    ],
  })
  await router.push('/list')
  const w = mount(ProductManagementView, { global: { plugins: [pinia, router], stubs: { AppIcon: true } } })
  await flushPromises()
  return w
}

beforeEach(() => {
  vi.clearAllMocks()
  // Kue > (Birthday > Standar, Wedding)
  tree = [cat(1, 'Kue', null), cat(2, 'Birthday', 1), cat(3, 'Wedding', 1), cat(4, 'Standar', 2)]
  listCategories.mockImplementation(async () => [...tree])
})

describe('REM-06 category hierarchy editor', () => {
  it('renders the tree in hierarchy order with the current parent selected per row', async () => {
    const w = await setup()
    // Depth-first pre-order: Standar is nested under Birthday before Wedding appears.
    const names = w.findAll('span.rounded-full').map((s) => s.text().replace(/\s+/g, ''))
    expect(names).toEqual(['Kue', 'Birthday', 'Standar', 'Wedding'])
    // Parent chips are indented; each row shows its stored parent.
    expect((w.find('select[aria-label="Induk kategori Kue"]').element as HTMLSelectElement).value).toBe('')
    expect((w.find('select[aria-label="Induk kategori Wedding"]').element as HTMLSelectElement).value).toBe('1')
    expect((w.find('select[aria-label="Induk kategori Standar"]').element as HTMLSelectElement).value).toBe('2')
    const rows = w.findAll('div.flex.flex-wrap.items-center')
    expect(rows[1]!.attributes('style')).toContain('margin-left: 1.5rem')
    expect(rows[2]!.attributes('style')).toContain('margin-left: 3rem')
    expect(rows[3]!.attributes('style')).toContain('margin-left: 1.5rem')
  })

  it('excludes the category itself and its subtree from its parent options (anti-cycle)', async () => {
    const w = await setup()
    // Kue owns every other row; only "Tanpa induk" remains.
    const rootOptions = w.find('select[aria-label="Induk kategori Kue"]').findAll('option').map((o) => o.attributes('value'))
    expect(rootOptions).toEqual([''])
    // Wedding has no children: every other category is a valid parent.
    const weddingOptions = w.find('select[aria-label="Induk kategori Wedding"]').findAll('option').map((o) => o.attributes('value'))
    expect(weddingOptions).toEqual(['', '1', '2', '4'])
    // Subtree labels are indented so the choice is readable.
    expect(w.find('select[aria-label="Induk kategori Wedding"]').findAll('option').map((o) => o.text())).toContain('— — Standar')
  })

  it('reparents through the API and refreshes the tree', async () => {
    const w = await setup()
    updateCategory.mockResolvedValue({})
    await w.find('select[aria-label="Induk kategori Wedding"]').setValue('4')
    await flushPromises()
    expect(updateCategory).toHaveBeenCalledExactlyOnceWith(3, { parent_id: 4 })
    expect(listCategories).toHaveBeenCalledTimes(2)
  })

  it('keeps the row intact and surfaces the server validation message on failure', async () => {
    const w = await setup()
    updateCategory.mockRejectedValue(new ApiError('Induk tidak boleh berupa turunan kategori ini (siklus hierarki).', 422, null))
    await w.find('select[aria-label="Induk kategori Standar"]').setValue('4')
    await flushPromises()
    expect(w.text()).toContain('siklus hierarki')
    // The list is reloaded so the select shows the stored parent again.
    expect(listCategories).toHaveBeenCalledTimes(2)
    expect((w.find('select[aria-label="Induk kategori Standar"]').element as HTMLSelectElement).value).toBe('2')
  })

  it('creates a category under the selected parent and resets the form', async () => {
    const w = await setup()
    createCategory.mockResolvedValue({})
    await w.find('input[placeholder="Nama kategori baru"]').setValue('Mini')
    await w.find('select[aria-label="Induk kategori baru"]').setValue('2')
    await w.find('[data-testid="category-create-form"]').trigger('submit')
    await flushPromises()
    expect(createCategory).toHaveBeenCalledExactlyOnceWith({ name: 'Mini', parent_id: 2 })
    expect((w.find('input[placeholder="Nama kategori baru"]').element as HTMLInputElement).value).toBe('')
    expect((w.find('select[aria-label="Induk kategori baru"]').element as HTMLSelectElement).selectedIndex).toBe(0)
  })

  it('shows the API message when creating under an invalid parent fails', async () => {
    const w = await setup()
    createCategory.mockRejectedValue(new ApiError('Kategori induk tidak ditemukan.', 422, null))
    await w.find('input[placeholder="Nama kategori baru"]').setValue('Mini')
    await w.find('select[aria-label="Induk kategori baru"]').setValue('1')
    await w.find('[data-testid="category-create-form"]').trigger('submit')
    await flushPromises()
    expect(w.text()).toContain('Kategori induk tidak ditemukan')
    // The typed name is preserved for correction.
    expect((w.find('input[placeholder="Nama kategori baru"]').element as HTMLInputElement).value).toBe('Mini')
  })
})

describe('REM-06 global create-only for Admin Agen / Agen', () => {
  const asRole = async (role: string) => {
    ;(globalThis as { __role?: string }).__role = role
    tree = [cat(1, 'Kue', null), cat(2, 'Kering', 1)]
    listCategories.mockImplementation(async () => [...tree])
    const w = await setup()
    delete (globalThis as { __role?: string }).__role
    return w
  }
  for (const role of ['admin', 'agen']) {
    it(`${role}: can create a root and a child; sees the create-only note; has NO edit / delete / reparent controls`, async () => {
      const w = await asRole(role)
      expect(w.find('[data-testid="category-createonly-note"]').exists()).toBe(true)
      expect(w.find('[data-testid="category-readonly-note"]').exists()).toBe(false)
      expect(w.find('[data-testid="category-create-form"]').exists()).toBe(true)
      expect(w.find('select[aria-label="Induk kategori Kering"]').exists()).toBe(false)   // reparent
      expect(w.find('button[title="Ubah nama"]').exists()).toBe(false)                    // rename
      expect(w.find('button[title="Hapus"]').exists()).toBe(false)                        // delete

      createCategory.mockResolvedValue({})
      await w.find('input[placeholder="Nama kategori baru"]').setValue('  Minuman ')
      await w.find('[data-testid="category-create-form"]').trigger('submit')
      await flushPromises()
      expect(createCategory).toHaveBeenLastCalledWith({ name: 'Minuman', parent_id: null })

      await w.find('input[placeholder="Nama kategori baru"]').setValue('Kopi')
      await w.find('select[aria-label="Induk kategori baru"]').setValue(1)
      await w.find('[data-testid="category-create-form"]').trigger('submit')
      await flushPromises()
      expect(createCategory).toHaveBeenLastCalledWith({ name: 'Kopi', parent_id: 1 })
      expect(updateCategory).not.toHaveBeenCalled()
    })
  }

  it('prevents double submit: a second submit while the first is in flight sends nothing and the button is disabled', async () => {
    const w = await asRole('agen')
    let resolve!: () => void
    createCategory.mockImplementation(() => new Promise<void>((r) => { resolve = r }))
    await w.find('input[placeholder="Nama kategori baru"]').setValue('Minuman')
    await w.find('[data-testid="category-create-form"]').trigger('submit')
    await w.find('[data-testid="category-create-form"]').trigger('submit')
    await w.find('[data-testid="category-create-form"]').trigger('submit')
    expect(createCategory).toHaveBeenCalledTimes(1)
    expect(w.find('[data-testid="category-create-submit"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-testid="category-create-submit"]').text()).toContain('Menyimpan')
    resolve()
    await flushPromises()
    expect(w.find('[data-testid="category-create-submit"]').attributes('disabled')).toBeDefined() // name cleared → nothing to submit
    expect((w.find('input[placeholder="Nama kategori baru"]').element as HTMLInputElement).value).toBe('')
  })

  it('shows the server duplicate error clearly, keeps the typed name, re-enables the form and refreshes the list', async () => {
    const w = await asRole('agen')
    createCategory.mockRejectedValue(new ApiError('Validasi gagal', 422, { name: ['Kategori dengan nama yang sama sudah ada pada induk ini.'] }))
    await w.find('input[placeholder="Nama kategori baru"]').setValue('Kering')
    await w.find('[data-testid="category-create-form"]').trigger('submit')
    await flushPromises()
    expect(w.text()).toContain('Kategori dengan nama yang sama sudah ada pada induk ini.')
    expect((w.find('input[placeholder="Nama kategori baru"]').element as HTMLInputElement).value).toBe('Kering')
    expect(w.find('[data-testid="category-create-submit"]').attributes('disabled')).toBeUndefined()
    expect(listCategories.mock.calls.length).toBeGreaterThan(1)
  })

  it('a backend 403 is shown (UI gating is not the security boundary)', async () => {
    const w = await asRole('agen')
    createCategory.mockRejectedValue(new ApiError('Anda tidak memiliki izin.', 403, null))
    await w.find('input[placeholder="Nama kategori baru"]').setValue('X')
    await w.find('[data-testid="category-create-form"]').trigger('submit')
    await flushPromises()
    expect(w.text()).toContain('Anda tidak memiliki izin.')
  })

  it('a newly created category appears in the hierarchy immediately (list reloaded)', async () => {
    const w = await asRole('admin')
    createCategory.mockImplementation(async () => { tree = [...tree, cat(3, 'Camilan', null), cat(4, 'Keripik', 3)] })
    await w.find('input[placeholder="Nama kategori baru"]').setValue('Camilan')
    await w.find('[data-testid="category-create-form"]').trigger('submit')
    await flushPromises()
    expect(w.text()).toContain('Camilan')
    expect(w.text()).toContain('Keripik')
  })

  it('super_admin keeps edit / delete / reparent controls and no create-only note', async () => {
    const w = await asRole('super_admin')
    expect(w.find('[data-testid="category-createonly-note"]').exists()).toBe(false)
    expect(w.find('select[aria-label="Induk kategori Kering"]').exists()).toBe(true)
    expect(w.find('button[title="Hapus"]').exists()).toBe(true)
  })

  it('other roles see the tree read-only with no create form', async () => {
    const w = await asRole('keuangan')
    expect(w.find('[data-testid="category-readonly-note"]').exists()).toBe(true)
    expect(w.find('[data-testid="category-create-form"]').exists()).toBe(false)
  })
})
