<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useAuthStore } from '@/stores/auth'
import {
  listCategories,
  createCategory,
  updateCategory,
  deleteCategory,
  listManagedProducts,
  deleteProduct,
  hardDeleteProduct,
  downloadCatalogExport,
  previewCatalogImport,
  applyCatalogImport,
  type CatalogImportResult,
  type ManagedStatusFilter,
  getProductFees,
  setProductFees,
} from '@/api/catalog'
import type { Category, Product } from '@/api/types'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'

const auth = useAuthStore()
const canManageStock = computed(() => auth.user?.role === 'agen' || auth.user?.role === 'admin')
/** R-04 / §E: Admin has Create + Read + Update only — no Delete. Only super_admin/agen may delete. */
const canDeleteProduct = computed(() => ['super_admin', 'agen'].includes(auth.user?.role ?? ''))

const loading = ref(false)
const errorMessage = ref('')
const categories = ref<Category[]>([])
const products = ref<Product[]>([])
// REM-05: four fixed choices; default "Semua". "all" is a filter value, not a DB status.
const statusFilter = ref<ManagedStatusFilter>('all')
const statusOptions: { value: ManagedStatusFilter; label: string }[] = [
  { value: 'all', label: 'Semua' },
  { value: 'active', label: 'Aktif' },
  { value: 'draft', label: 'Draft' },
  { value: 'inactive', label: 'Nonaktif' },
]

function primaryImageUrl(product: Product): string | null {
  return product.images.find((i) => i.is_primary)?.url ?? product.images[0]?.url ?? null
}

function totalStock(product: Product): number {
  if (product.has_variations) {
    return product.variations.reduce((sum, v) => sum + (v.stock?.available ?? 0), 0)
  }
  return product.stock?.available ?? 0
}

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [cats, prods] = await Promise.all([listCategories(), listManagedProducts({ status: statusFilter.value, per_page: 100 })])
    categories.value = cats
    products.value = prods.products
  } catch {
    errorMessage.value = 'Gagal memuat data produk.'
  } finally {
    loading.value = false
  }
}

// Category quick-add + REM-06 hierarchy editor
const newCategoryName = ref('')
const newCategoryParentId = ref<number | null>(null)
const categoryError = ref('')

/** REM-06: tree-ordered rows (parents first, children indented) for display and selects. */
interface CategoryRow {
  category: Category
  depth: number
}
const categoryRows = computed<CategoryRow[]>(() => {
  const byParent = new Map<number | null, Category[]>()
  for (const c of categories.value) {
    const list = byParent.get(c.parent_id) ?? []
    list.push(c)
    byParent.set(c.parent_id, list)
  }
  const rows: CategoryRow[] = []
  const walk = (parent: number | null, depth: number) => {
    for (const c of (byParent.get(parent) ?? []).sort((a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name))) {
      rows.push({ category: c, depth })
      walk(c.id, depth + 1)
    }
  }
  walk(null, 0)
  // Cycle safety for legacy data: append any row stranded by an existing loop.
  const seen = new Set(rows.map((r) => r.category.id))
  for (const c of categories.value) if (!seen.has(c.id)) rows.push({ category: c, depth: 0 })
  return rows
})

/** REM-06: ids below a category — used to keep its own subtree out of parent options. */
function descendantIds(categoryId: number): Set<number> {
  const byParent = new Map<number | null, Category[]>()
  for (const c of categories.value) {
    const list = byParent.get(c.parent_id) ?? []
    list.push(c)
    byParent.set(c.parent_id, list)
  }
  const out = new Set<number>()
  const queue = [categoryId]
  while (queue.length) {
    const current = queue.shift()!
    for (const child of byParent.get(current) ?? []) {
      if (!out.has(child.id)) {
        out.add(child.id)
        queue.push(child.id)
      }
    }
  }
  return out
}

function indentLabel(depth: number): string {
  return depth ? `${'— '.repeat(depth)}` : ''
}

/**
 * Valid parents for a category: everything except itself and its own subtree
 * (client-side anti-cycle). The server remains authoritative for concurrent edits.
 */
function parentOptions(category: Category): { value: number | ''; label: string }[] {
  const forbidden = descendantIds(category.id)
  const options: { value: number | ''; label: string }[] = [{ value: '', label: 'Tanpa induk (utama)' }]
  for (const row of categoryRows.value) {
    if (row.category.id === category.id || forbidden.has(row.category.id)) continue
    options.push({ value: row.category.id, label: `${indentLabel(row.depth)}${row.category.name}` })
  }
  return options
}

async function submitNewCategory() {
  // double-click / Enter-spam guard: one create in flight at a time
  if (categorySaving.value || !newCategoryName.value.trim()) return
  categorySaving.value = true
  categoryError.value = ''
  try {
    await createCategory({ name: newCategoryName.value.trim(), parent_id: newCategoryParentId.value })
    newCategoryName.value = ''
    newCategoryParentId.value = null
    categories.value = await listCategories()
  } catch (e) {
    // duplicate / validation / authorization messages come from the server (nothing is hidden)
    // Field messages are already Indonesian and specific (e.g. duplicate name); drop the generic English envelope text.
    const details = e instanceof ApiError ? Object.values(e.errors ?? {}).flat().filter(Boolean) : []
    categoryError.value = e instanceof ApiError ? (details.length ? details.join(' ') : formatApiError(e)) : 'Gagal menambah kategori.'
    try {
      categories.value = await listCategories() // show what actually exists now (e.g. the twin created by another user)
    } catch {
      /* keep the current list */
    }
  } finally {
    categorySaving.value = false
  }
}

async function changeParent(category: Category, parentId: string) {
  const next = parentId === '' ? null : Number(parentId)
  if (next === category.parent_id) return
  try {
    await updateCategory(category.id, { parent_id: next })
    categoryError.value = ''
    categories.value = await listCategories()
  } catch (e) {
    // Server-side validation (cycle under concurrency, missing parent) is authoritative.
    categoryError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memindahkan kategori.'
    categories.value = await listCategories()
  }
}

async function renameCategory(category: Category) {
  const newName = prompt('Nama kategori baru:', category.name)
  if (!newName || !newName.trim() || newName.trim() === category.name) return
  try {
    await updateCategory(category.id, { name: newName.trim() })
    categoryError.value = ''
    categories.value = await listCategories()
  } catch (e) {
    categoryError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal mengubah kategori.'
  }
}

async function removeCategory(category: Category) {
  if (!confirm(`Hapus kategori "${category.name}"?`)) return
  try {
    await deleteCategory(category.id)
    categoryError.value = ''
    categories.value = await listCategories()
  } catch (e) {
    categoryError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menghapus kategori.'
  }
}

// REM-01: import / export. Stock cells are final physical counts and only become stock after
// Gudang counts and Admin approves the generated opname; catalogue changes on those rows wait too.
const importFile = ref<File | null>(null)
const importResult = ref<CatalogImportResult | null>(null)
const importBusy = ref(false)
const importKey = ref('')
// One explicit stock context per import (never summed). Empty = the file carries no stock cells.
const importStockType = ref<'' | 'transit' | 'shipping'>('')
const importAgentId = ref<number | null>(null)
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')
/** Category writes are super_admin-only (ProductCategoryPolicy; categories are a GLOBAL taxonomy). Admin/Agen see the hierarchy read-only until the Owner decides the ownership model. */
const canManageCategories = isSuperAdmin
/** REM-06 (Owner 13 Nov 2026): Admin Agen and Agen may CREATE categories (parent or child) in the global taxonomy; edit/delete/reparent stay super_admin-only (enforced by the backend too). */
const canCreateCategory = computed(() => ['super_admin', 'admin', 'agen'].includes(auth.user?.role ?? ''))
const categorySaving = ref(false)
function importContext() {
  return {
    ...(importStockType.value ? { stock_type: importStockType.value } : {}),
    ...(isSuperAdmin.value && importAgentId.value ? { agent_id: importAgentId.value } : {}),
  }
}
const outcomeLabel: Record<string, string> = {
  catalog_created: 'Produk dibuat',
  catalog_updated: 'Katalog diperbarui',
  catalog_withheld_stock_pending: 'Menunggu hitung Gudang & persetujuan Admin (perubahan katalog ditahan)',
  stock_pending: 'Menunggu hitung Gudang & persetujuan Admin',
  stock_pending_replay: 'Sudah pernah diajukan (idempoten)',
  rejected: 'Ditolak',
}
const errorLabel: Record<string, string> = {
  idempotency_conflict: 'Kunci impor sama dengan data berbeda (409) — buat impor baru',
  stock_context_invalid: 'Konteks stok tidak valid (pilih jenis stok / Agen)',
  duplicate_sku_in_file: 'SKU ganda di dalam file',
  sku_owner_type_mismatch: 'SKU milik tipe lain (produk/varian)',
}
function pickImportFile(e: Event) {
  importFile.value = (e.target as HTMLInputElement).files?.[0] ?? null
  importResult.value = null
  importKey.value = ''
}
async function exportCatalog(format: 'csv' | 'xlsx') {
  try {
    const blob = await downloadCatalogExport(format, importContext())
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `katalog.${format}`
    a.click()
    URL.revokeObjectURL(url)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal mengekspor katalog.'
  }
}
async function runImport(apply: boolean) {
  if (!importFile.value) return
  importBusy.value = true
  errorMessage.value = ''
  try {
    if (apply) {
      // Same key for retries of the same file ⇒ no duplicate documents.
      importKey.value ||= `imp-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
      importResult.value = await applyCatalogImport(importFile.value, importKey.value, importContext())
      await load()
    } else {
      importResult.value = await previewCatalogImport(importFile.value, importContext())
    }
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memproses file impor.'
  } finally {
    importBusy.value = false
  }
}

async function removePermanently(product: Product) {
  if (!confirm(`HAPUS PERMANEN produk "${product.name}"? Tindakan ini tidak dapat dibatalkan.`)) return
  try {
    await hardDeleteProduct(product.id)
    await load()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menghapus permanen produk.'
  }
}

async function remove(product: Product) {
  if (!confirm(`Hapus produk "${product.name}"?`)) return
  try {
    await deleteProduct(product.id)
    await load()
  } catch {
    errorMessage.value = 'Gagal menghapus produk.'
  }
}

// Fees modal (simple, non-variation products only) — kept as a small popup
// since it's an auxiliary setting, not the main create/edit CRUD form.
const feeProduct = ref<Product | null>(null)
const feeForm = ref({ agent_fee: 0, sales_fee: 0, courier_fee: 0 })
const feeError = ref('')

async function openFees(product: Product) {
  feeProduct.value = product
  feeError.value = ''
  try {
    const fees = await getProductFees(product.id)
    feeForm.value = { agent_fee: fees.agent_fee, sales_fee: fees.sales_fee, courier_fee: fees.courier_fee ?? 0 }
  } catch {
    feeError.value = 'Gagal memuat fee produk ini.'
  }
}

async function submitFees() {
  if (!feeProduct.value) return
  try {
    await setProductFees(feeProduct.value.id, feeForm.value)
    feeProduct.value = null
  } catch {
    feeError.value = 'Gagal menyimpan fee.'
  }
}

const statusLabel: Record<string, string> = { draft: 'Draft', active: 'Aktif', inactive: 'Nonaktif' }

onMounted(load)
</script>

<template>
  <DashboardLayout>
    <div class="mb-5 flex items-center justify-between gap-2">
      <div>
        <h1 class="font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Produk & Kategori</h1>
        <p class="text-sm text-stone-500 dark:text-stone-400">Kelola katalog produk, kategori, dan fee.</p>
      </div>
      <RouterLink
        :to="{ name: 'product-create' }"
        class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
      >
        + Produk Baru
      </RouterLink>
    </div>

    <p v-if="errorMessage" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ errorMessage }}
    </p>

    <div class="mb-6 rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
      <h2 class="mb-3 text-sm font-semibold text-stone-700 dark:text-stone-200">Kategori</h2>
      <p v-if="categoryError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
        {{ categoryError }}
      </p>
      <div class="mb-3 space-y-1.5">
        <div
          v-for="row in categoryRows"
          :key="row.category.id"
          class="flex flex-wrap items-center gap-2"
          :style="{ marginLeft: `${row.depth * 1.5}rem` }"
        >
          <span
            class="flex items-center gap-1.5 rounded-full bg-stone-100 py-1 pl-3 pr-1.5 text-xs text-stone-600 dark:bg-stone-800 dark:text-stone-300"
          >
            {{ row.category.name }}
            <template v-if="canManageCategories">
              <button type="button" class="text-stone-400 hover:text-brand-600" title="Ubah nama" @click="renameCategory(row.category)">
                <AppIcon name="menu" :size="11" />
              </button>
              <button type="button" class="text-stone-400 hover:text-red-600" title="Hapus" @click="removeCategory(row.category)">
                <AppIcon name="trash" :size="11" />
              </button>
            </template>
            <span v-else class="pr-1.5"></span>
          </span>
          <select
            v-if="canManageCategories"
            :value="row.category.parent_id ?? ''"
            class="rounded-lg border border-stone-200 bg-white px-1.5 py-1 text-[11px] text-stone-500 dark:border-stone-700 dark:bg-stone-950 dark:text-stone-400"
            :aria-label="`Induk kategori ${row.category.name}`"
            @change="changeParent(row.category, ($event.target as HTMLSelectElement).value)"
          >
            <option v-for="opt in parentOptions(row.category)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>
        </div>
        <span v-if="categories.length === 0" class="text-xs text-stone-400 dark:text-stone-500">Belum ada kategori.</span>
      </div>
      <p v-if="!canManageCategories && canCreateCategory" class="mb-2 rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-600 dark:bg-stone-800 dark:text-stone-300" data-testid="category-createonly-note">
        Kategori bersifat global dan dipakai seluruh jaringan. Anda dapat menambah kategori utama atau subkategori; mengubah, memindahkan, atau menghapus kategori hanya dapat dilakukan Super Admin.
      </p>
      <p v-else-if="!canCreateCategory" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200" data-testid="category-readonly-note">
        Hierarki kategori hanya dapat dilihat.
      </p>
      <form v-if="canCreateCategory" class="flex flex-wrap gap-2" data-testid="category-create-form" @submit.prevent="submitNewCategory">
        <input
          v-model="newCategoryName"
          type="text"
          placeholder="Nama kategori baru"
          class="w-64 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
          maxlength="100"
          aria-label="Nama kategori baru"
        />
        <select
          v-model="newCategoryParentId"
          class="rounded-lg border border-stone-200 bg-white px-2 py-2 text-xs dark:border-stone-700 dark:bg-stone-950"
          aria-label="Induk kategori baru"
        >
          <option :value="null">Tanpa induk (utama)</option>
          <option v-for="row in categoryRows" :key="row.category.id" :value="row.category.id">{{ indentLabel(row.depth) }}{{ row.category.name }}</option>
        </select>
        <button type="submit" class="rounded-lg bg-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-300 disabled:opacity-60 dark:bg-stone-700 dark:text-stone-200" :disabled="categorySaving || !newCategoryName.trim()" data-testid="category-create-submit">
          {{ categorySaving ? 'Menyimpan…' : 'Tambah' }}
        </button>
      </form>
      <p v-if="canCreateCategory" class="mt-2 text-[11px] text-stone-400 dark:text-stone-500">
        Anak tampil menjorok di bawah induknya. Induk tidak boleh kategori itu sendiri atau turunannya (siklus ditolak server).
      </p>
    </div>

    <div class="mb-6 rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
      <h2 class="mb-3 text-sm font-semibold text-stone-700 dark:text-stone-200">Impor / Ekspor Katalog</h2>
      <div class="flex flex-wrap items-center gap-2 text-sm">
        <button type="button" class="rounded-lg bg-stone-100 px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200" @click="exportCatalog('csv')">Ekspor CSV</button>
        <button type="button" class="rounded-lg bg-stone-100 px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200" @click="exportCatalog('xlsx')">Ekspor XLSX</button>
        <select v-model="importStockType" class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-xs dark:border-stone-700 dark:bg-stone-950" aria-label="Konteks stok">
          <option value="">Tanpa stok</option>
          <option value="transit">Stok Transit</option>
          <option value="shipping">Stok Shipping</option>
        </select>
        <input v-if="isSuperAdmin" v-model.number="importAgentId" type="number" min="1" placeholder="ID Agen" class="w-24 rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-xs dark:border-stone-700 dark:bg-stone-950" />
        <input type="file" accept=".csv,.xlsx" class="text-xs" @change="pickImportFile" />
        <button type="button" :disabled="!importFile || importBusy" class="rounded-lg bg-stone-200 px-3 py-1.5 text-xs font-medium disabled:opacity-50 dark:bg-stone-700" @click="runImport(false)">Pratinjau</button>
        <button type="button" :disabled="!importFile || importBusy" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50" @click="runImport(true)">Terapkan</button>
      </div>
      <div v-if="importResult" class="mt-3 text-xs text-stone-600 dark:text-stone-300">
        <p class="mb-2">Valid {{ importResult.summary.valid }} · Tidak valid {{ importResult.summary.invalid }} · Dengan stok {{ importResult.summary.with_stock }}</p>
        <ul class="max-h-48 overflow-y-auto">
          <li v-for="r in importResult.rows" :key="r.line" :class="r.valid ? '' : 'text-red-600 dark:text-red-300'">
            Baris {{ r.line }} · {{ r.sku }} ·
            {{ r.valid ? (r.outcome ? (outcomeLabel[r.outcome] ?? r.outcome) : r.action) : r.errors.map((c) => errorLabel[c] ?? c).join(', ') }}
          </li>
        </ul>
        <p v-if="importResult.summary.with_stock" class="mt-2">
          Angka stok adalah target hitung fisik, bukan saldo. Gudang menghitung &amp; submit, Admin menyetujui di
          <RouterLink :to="{ name: 'warehouse-opnames' }" class="underline">Stock Opname</RouterLink>; perubahan katalog baris tersebut terbit saat disetujui.
        </p>
      </div>
    </div>

    <div class="mb-3 flex items-center gap-2 text-sm">
      <label for="product-status-filter" class="text-stone-500 dark:text-stone-400">Status</label>
      <select
        id="product-status-filter"
        v-model="statusFilter"
        class="rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
        @change="load"
      >
        <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
    </div>

    <p v-if="loading" class="text-sm text-stone-500 dark:text-stone-400">Memuat...</p>

    <div v-else class="overflow-x-auto rounded-2xl border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900">
      <table class="w-full text-sm">
        <thead class="border-b border-stone-200 text-left text-xs uppercase text-stone-400 dark:border-stone-800 dark:text-stone-500">
          <tr>
            <th class="px-4 py-3"></th>
            <th class="px-4 py-3">Nama</th>
            <th class="px-4 py-3">Kategori</th>
            <th class="px-4 py-3">Tipe</th>
            <th v-if="canManageStock" class="px-4 py-3 text-right">Stok</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="products.length === 0">
            <td :colspan="canManageStock ? 7 : 6" class="px-4 py-6 text-center text-stone-400 dark:text-stone-500">Tidak ada produk.</td>
          </tr>
          <tr v-for="product in products" :key="product.id" class="border-b border-stone-100 last:border-0 dark:border-stone-800">
            <td class="px-4 py-3">
              <img v-if="primaryImageUrl(product)" :src="primaryImageUrl(product)!" class="h-10 w-10 rounded-lg object-cover" />
              <div v-else class="h-10 w-10 rounded-lg bg-stone-100 dark:bg-stone-800"></div>
            </td>
            <td class="px-4 py-3">
              <div class="font-medium text-stone-700 dark:text-stone-200">{{ product.name }}</div>
              <div class="text-xs text-stone-400 dark:text-stone-500">SKU: {{ product.sku || '-' }}</div>
            </td>
            <td class="px-4 py-3 text-stone-500 dark:text-stone-400">{{ product.category?.name ?? '—' }}</td>
            <td class="px-4 py-3 text-stone-500 dark:text-stone-400">
              {{ product.has_variations ? `Varian (${product.variations.length})` : 'Sederhana' }}
            </td>
            <td v-if="canManageStock" class="px-4 py-3 text-right tabular-nums text-stone-600 dark:text-stone-300">{{ totalStock(product) }}</td>
            <td class="px-4 py-3 text-stone-500 dark:text-stone-400">{{ statusLabel[product.status] ?? product.status }}</td>
            <td class="px-4 py-3 text-right">
              <div class="flex justify-end gap-2">
                <button
                  v-if="!product.has_variations"
                  type="button"
                  class="rounded-lg bg-stone-100 px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200"
                  @click="openFees(product)"
                >
                  Fee
                </button>
                <RouterLink
                  :to="{ name: 'product-edit', params: { id: product.id } }"
                  class="rounded-lg bg-stone-100 px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200"
                >
                  Edit
                </RouterLink>
                <button
                  v-if="canDeleteProduct"
                  type="button"
                  class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-100 dark:bg-red-950 dark:text-red-300"
                  @click="remove(product)"
                >
                  Hapus
                </button>
                <button
                  v-if="canDeleteProduct"
                  type="button"
                  class="rounded-lg bg-red-100 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-200 dark:bg-red-900 dark:text-red-200"
                  title="Hapus permanen (hanya jika tidak direferensikan)"
                  @click="removePermanently(product)"
                >
                  Hapus Permanen
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Fees modal -->
    <div v-if="feeProduct" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="feeProduct = null">
      <div class="w-full max-w-sm rounded-2xl bg-white p-5 dark:bg-stone-900">
        <h2 class="mb-4 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">Fee — {{ feeProduct.name }}</h2>
        <p v-if="feeError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ feeError }}</p>
        <div class="space-y-3">
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Fee Agen
            <input v-model.number="feeForm.agent_fee" type="number" min="0" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Fee Sales
            <input v-model.number="feeForm.sales_fee" type="number" min="0" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Fee Kurir
            <input v-model.number="feeForm.courier_fee" type="number" min="0" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
        </div>
        <div class="mt-4 flex justify-end gap-2">
          <button type="button" class="rounded-lg px-3 py-2 text-sm text-stone-500 dark:text-stone-400" @click="feeProduct = null">Batal</button>
          <button type="button" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="submitFees">Simpan</button>
        </div>
      </div>
    </div>
  </DashboardLayout>
</template>
