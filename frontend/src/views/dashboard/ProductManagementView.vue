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
  listProducts,
  deleteProduct,
  getProductFees,
  setProductFees,
} from '@/api/catalog'
import type { Category, Product } from '@/api/types'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'

const auth = useAuthStore()
const canManageStock = computed(() => auth.user?.role === 'agen' || auth.user?.role === 'admin')

const loading = ref(false)
const errorMessage = ref('')
const categories = ref<Category[]>([])
const products = ref<Product[]>([])

function primaryImageUrl(product: Product): string | null {
  return product.images.find((i) => i.is_primary)?.url ?? product.images[0]?.url ?? null
}

function totalStock(product: Product): number {
  if (product.has_variations) {
    return product.variations.reduce((sum, v) => sum + (v.agent_available_quantity ?? 0), 0)
  }
  return product.agent_available_quantity ?? 0
}

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [cats, prods] = await Promise.all([listCategories(), listProducts({ per_page: 100 })])
    categories.value = cats
    products.value = prods.products
  } catch {
    errorMessage.value = 'Gagal memuat data produk.'
  } finally {
    loading.value = false
  }
}

// Category quick-add
const newCategoryName = ref('')
async function submitNewCategory() {
  if (!newCategoryName.value.trim()) return
  try {
    await createCategory({ name: newCategoryName.value.trim() })
    newCategoryName.value = ''
    categories.value = await listCategories()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menambah kategori.'
  }
}

async function renameCategory(category: Category) {
  const newName = prompt('Nama kategori baru:', category.name)
  if (!newName || !newName.trim() || newName.trim() === category.name) return
  try {
    await updateCategory(category.id, { name: newName.trim() })
    categories.value = await listCategories()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal mengubah kategori.'
  }
}

async function removeCategory(category: Category) {
  if (!confirm(`Hapus kategori "${category.name}"?`)) return
  try {
    await deleteCategory(category.id)
    categories.value = await listCategories()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menghapus kategori.'
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

const statusLabel: Record<string, string> = { draft: 'Draf', active: 'Aktif', inactive: 'Nonaktif' }

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
      <div class="mb-3 flex flex-wrap gap-2">
        <span
          v-for="cat in categories"
          :key="cat.id"
          class="flex items-center gap-1.5 rounded-full bg-stone-100 py-1 pl-3 pr-1.5 text-xs text-stone-600 dark:bg-stone-800 dark:text-stone-300"
        >
          {{ cat.name }}
          <button type="button" class="text-stone-400 hover:text-brand-600" title="Ubah nama" @click="renameCategory(cat)">
            <AppIcon name="menu" :size="11" />
          </button>
          <button type="button" class="text-stone-400 hover:text-red-600" title="Hapus" @click="removeCategory(cat)">
            <AppIcon name="trash" :size="11" />
          </button>
        </span>
        <span v-if="categories.length === 0" class="text-xs text-stone-400 dark:text-stone-500">Belum ada kategori.</span>
      </div>
      <div class="flex gap-2">
        <input
          v-model="newCategoryName"
          type="text"
          placeholder="Nama kategori baru"
          class="w-64 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
        />
        <button type="button" class="rounded-lg bg-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-300 dark:bg-stone-700 dark:text-stone-200" @click="submitNewCategory">
          Tambah
        </button>
      </div>
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
                  type="button"
                  class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-100 dark:bg-red-950 dark:text-red-300"
                  @click="remove(product)"
                >
                  Hapus
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
