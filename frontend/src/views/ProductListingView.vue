<script setup lang="ts">
import { ApiError } from '@/api/client'
import StockSourceSelector from '@/components/shop/StockSourceSelector.vue'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'
import { createLatestRequestGuard } from '@/utils/latestRequest'
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import ShopLayout from '@/layouts/ShopLayout.vue'
import ProductCard from '@/components/shop/ProductCard.vue'
import CatalogCategoryTree from '@/components/shop/CatalogCategoryTree.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { listCategories, listProducts } from '@/api/catalog'
import { formatRupiah } from '@/utils/format'
import { buildCategoryTree, categoryPath } from '@/utils/categoryTree'
import type { Category, PaginationMeta, Product } from '@/api/types'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const auth = useAuthStore()
const cart = useCartStore()
const stockSource = computed(() => auth.user?.role === 'sales-kurir-sub' ? cart.stockSourceFor(auth.user.id) : undefined)
const loadGuard = createLatestRequestGuard()
const products = ref<Product[]>([])
const categories = ref<Category[]>([])
/** REM-06: the real parent→child hierarchy (any depth); nothing here ever re-parents or promotes a category. */
const tree = computed(() => buildCategoryTree(categories.value))
const meta = ref<PaginationMeta | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)
const filtersOpen = ref(false)

const SORT_OPTIONS = computed(() => [
  { value: 'newest', label: t('productList.sortNewest') },
  { value: 'price_asc', label: t('productList.sortPriceAsc') },
  { value: 'price_desc', label: t('productList.sortPriceDesc') },
] as const)

const filters = reactive({
  search: (route.query.search as string) ?? '',
  category: (route.query.category as string) ?? '',
  sort: (route.query.sort as string) ?? 'newest',
  minPrice: (route.query.min_price as string) ?? '',
  maxPrice: (route.query.max_price as string) ?? '',
  page: Number(route.query.page ?? 1),
})

/** Root → … → active category, from the actual parent chain (drives breadcrumb, title and which branches stay open). */
const activePath = computed(() => categoryPath(tree.value, filters.category))
const activeCategory = computed(() => activePath.value[activePath.value.length - 1])
const expanded = reactive(new Set<number>())
function toggleBranch(id: number) {
  if (expanded.has(id)) expanded.delete(id)
  else expanded.add(id)
}
// The active category and all its ancestors are always open, so the current place is visible in the tree.
watch(activePath, (path) => path.forEach((c) => expanded.add(c.id)), { immediate: true })

const GRID = 'grid grid-cols-2 gap-2.5 sm:grid-cols-3 sm:gap-3.5 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 xl:gap-4'


type ActiveFilter = { key: 'search' | 'category' | 'min' | 'max'; label: string }
const activeFilters = computed<ActiveFilter[]>(() => {
  const list: ActiveFilter[] = []
  if (filters.search) list.push({ key: 'search', label: `“${filters.search}”` })
  if (filters.category) list.push({ key: 'category', label: activeCategory.value?.name ?? filters.category })
  if (filters.minPrice) list.push({ key: 'min', label: t('productList.chipMin', { value: formatRupiah(filters.minPrice) }) })
  if (filters.maxPrice) list.push({ key: 'max', label: t('productList.chipMax', { value: formatRupiah(filters.maxPrice) }) })
  return list
})
function clearFilter(key: ActiveFilter['key']) {
  if (key === 'search') filters.search = ''
  if (key === 'category') filters.category = ''
  if (key === 'min') filters.minPrice = ''
  if (key === 'max') filters.maxPrice = ''
  applyFilters()
}
function resetFilters() {
  filters.search = ''
  filters.category = ''
  filters.minPrice = ''
  filters.maxPrice = ''
  applyFilters()
}
function pickCategory(slug: string) {
  filters.category = slug
  applyFilters()
}

async function fetchProducts() {
  const token = loadGuard.begin()
  loading.value = true
  loadError.value = null
  try {
    const result = await listProducts({
      stock_source: stockSource.value,
      search: filters.search || undefined,
      category: filters.category || undefined,
      sort: filters.sort as 'newest' | 'price_asc' | 'price_desc',
      min_price: filters.minPrice ? Number(filters.minPrice) : undefined,
      max_price: filters.maxPrice ? Number(filters.maxPrice) : undefined,
      page: filters.page,
      per_page: 12,
    })
    if (!loadGuard.isCurrent(token)) return
    products.value = result.products
    meta.value = result.meta
  } catch (e) {
    if (loadGuard.isCurrent(token)) {
      products.value = []
      meta.value = null
      loadError.value = e instanceof ApiError ? e.message : 'Gagal memuat ketersediaan stok.'
    }
  } finally {
    if (loadGuard.isCurrent(token)) loading.value = false
  }
}

function syncQuery() {
  router.replace({
    query: {
      ...(filters.search ? { search: filters.search } : {}),
      ...(filters.category ? { category: filters.category } : {}),
      ...(filters.sort !== 'newest' ? { sort: filters.sort } : {}),
      ...(filters.minPrice ? { min_price: filters.minPrice } : {}),
      ...(filters.maxPrice ? { max_price: filters.maxPrice } : {}),
      ...(filters.page > 1 ? { page: String(filters.page) } : {}),
    },
  })
}

function applyFilters() {
  filters.page = 1
  filtersOpen.value = false
  syncQuery()
}

function goToPage(page: number) {
  filters.page = page
  syncQuery()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

watch(
  () => route.query,
  (query) => {
    filters.search = (query.search as string) ?? ''
    filters.category = (query.category as string) ?? ''
    filters.sort = (query.sort as string) ?? 'newest'
    filters.minPrice = (query.min_price as string) ?? ''
    filters.maxPrice = (query.max_price as string) ?? ''
    filters.page = Number(query.page ?? 1)
    fetchProducts()
  },
)

watch(stockSource, () => { filters.page = 1; void fetchProducts() })

onMounted(async () => {
  categories.value = await listCategories()
  await fetchProducts()
})
</script>

<template>
  <ShopLayout>
    <StockSourceSelector />

    <!-- Catalog header: breadcrumb, active category title, real product count, sort, active filters -->
    <header class="mb-5 rounded-2xl border border-brand-100 bg-gradient-to-br from-brand-50 to-white p-4 sm:p-5 dark:border-brand-900/60 dark:from-brand-900/30 dark:to-stone-950" data-testid="catalog-header">
      <nav :aria-label="t('productList.breadcrumb')" class="mb-2 text-xs text-stone-500 dark:text-stone-400" data-testid="catalog-breadcrumb">
        <ol class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
          <li><RouterLink to="/" class="hover:text-brand-700 hover:underline dark:hover:text-brand-300">{{ t('productList.home') }}</RouterLink></li>
          <li aria-hidden="true">›</li>
          <li v-if="!activePath.length" aria-current="page" class="font-medium text-stone-700 dark:text-stone-200">{{ t('productList.allProducts') }}</li>
          <li v-else><RouterLink :to="{ name: 'products' }" aria-current-value="false" class="hover:text-brand-700 hover:underline dark:hover:text-brand-300">{{ t('productList.allProducts') }}</RouterLink></li>
          <template v-for="(c, i) in activePath" :key="c.id">
            <li aria-hidden="true">›</li>
            <li v-if="i < activePath.length - 1"><RouterLink :to="{ name: 'products', query: { category: c.slug } }" aria-current-value="false" class="hover:text-brand-700 hover:underline dark:hover:text-brand-300">{{ c.name }}</RouterLink></li>
            <li v-else aria-current="page" class="font-medium text-stone-700 dark:text-stone-200">{{ c.name }}</li>
          </template>
        </ol>
      </nav>

      <div class="flex items-end justify-between gap-3">
        <div class="min-w-0">
          <h1 class="break-words font-display text-2xl font-semibold text-brand-800 sm:text-3xl dark:text-brand-100" data-testid="catalog-title">
            {{ filters.search ? t('productList.searchResultsFor', { query: filters.search }) : (activeCategory?.name ?? t('productList.allProducts')) }}
          </h1>
          <p v-if="meta" class="mt-1 text-sm text-stone-600 dark:text-stone-300" data-testid="catalog-count">{{ t('productList.productsFound', { count: meta.total }) }}</p>
        </div>
        <div class="flex flex-none items-center gap-2">
          <label class="hidden items-center gap-2 text-sm text-stone-600 md:flex dark:text-stone-300">
            <span>{{ t('productList.sortBy') }}</span>
            <select
              v-model="filters.sort"
              class="rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm text-stone-800 dark:border-stone-600 dark:bg-stone-900 dark:text-stone-100"
              data-testid="catalog-sort"
              @change="applyFilters"
            >
              <option v-for="opt in SORT_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
          </label>
          <button
            type="button"
            class="flex min-h-10 items-center gap-1.5 rounded-full border border-brand-300 bg-white px-4 text-sm font-medium text-brand-700 md:hidden dark:border-brand-700 dark:bg-stone-900 dark:text-brand-200"
            data-testid="filters-open"
            @click="filtersOpen = true"
          >
            <AppIcon name="filter" :size="16" />
            {{ t('productList.filter') }}
          </button>
        </div>
      </div>

      <div v-if="activeFilters.length" class="mt-3 flex flex-wrap items-center gap-2" data-testid="active-filters">
        <span class="text-xs font-medium text-stone-500 dark:text-stone-400">{{ t('productList.activeFilters') }}</span>
        <button
          v-for="f in activeFilters"
          :key="f.key"
          type="button"
          class="inline-flex min-h-8 items-center gap-1 rounded-full border border-brand-200 bg-white px-3 text-xs font-medium text-brand-800 dark:border-brand-800 dark:bg-stone-900 dark:text-brand-100"
          :aria-label="t('productList.removeFilter', { name: f.label })"
          :data-filter="f.key"
          @click="clearFilter(f.key)"
        >
          {{ f.label }}
          <AppIcon name="close" :size="12" />
        </button>
        <button type="button" class="min-h-8 px-2 text-xs font-semibold text-brand-700 underline dark:text-brand-300" data-testid="reset-filters" @click="resetFilters">
          {{ t('productList.resetFilters') }}
        </button>
      </div>
    </header>

    <div class="grid grid-cols-1 gap-6 md:grid-cols-[240px_minmax(0,1fr)]">
      <!-- Desktop sidebar: category tree + price -->
      <aside class="hidden md:block">
        <div class="sticky top-20 space-y-6 rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
          <nav :aria-label="t('productList.category')" data-testid="category-sidebar">
            <h2 class="mb-2 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('productList.category') }}</h2>
            <button
              type="button"
              class="mb-0.5 flex min-h-9 w-full items-center rounded-lg px-3 text-left text-sm"
              :class="!filters.category ? 'bg-brand-50 font-semibold text-brand-700 dark:bg-brand-900/40 dark:text-brand-200' : 'text-stone-700 hover:bg-stone-100 dark:text-stone-200 dark:hover:bg-stone-800'"
              :aria-current="!filters.category ? 'true' : undefined"
              data-testid="category-all"
              @click="pickCategory('')"
            >
              {{ t('productList.allCategories') }}
            </button>
            <CatalogCategoryTree :nodes="tree" :active-slug="filters.category" :expanded="expanded" @select="pickCategory" @toggle="toggleBranch" />
          </nav>

          <div>
            <h2 class="mb-2 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('productList.priceRange') }}</h2>
            <div class="flex items-center gap-2">
              <input
                v-model="filters.minPrice"
                type="number"
                min="0"
                inputmode="numeric"
                :placeholder="t('productList.min')"
                :aria-label="t('productList.min')"
                class="w-full min-w-0 rounded-lg border border-stone-300 bg-white px-2 py-2 text-sm dark:border-stone-600 dark:bg-stone-950"
              />
              <span class="text-stone-400">–</span>
              <input
                v-model="filters.maxPrice"
                type="number"
                min="0"
                inputmode="numeric"
                :placeholder="t('productList.max')"
                :aria-label="t('productList.max')"
                class="w-full min-w-0 rounded-lg border border-stone-300 bg-white px-2 py-2 text-sm dark:border-stone-600 dark:bg-stone-950"
              />
            </div>
            <AppButton size="sm" variant="secondary" class="mt-2 w-full" @click="applyFilters">{{ t('common.apply') }}</AppButton>
          </div>
        </div>
      </aside>

      <div class="min-w-0">
        <div v-if="loading" :class="GRID" data-testid="catalog-loading">
          <div v-for="i in 8" :key="i" class="aspect-[3/4.2] animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
        </div>
        <p v-else-if="loadError" role="alert" class="rounded-xl bg-red-50 p-4 text-sm text-red-700 dark:bg-red-950 dark:text-red-200">{{ loadError }}</p>
        <div v-else-if="!products.length" class="rounded-2xl border border-dashed border-stone-300 p-8 text-center dark:border-stone-700" data-testid="catalog-empty">
          <p class="text-sm text-stone-600 dark:text-stone-300">{{ t('productList.noResults') }}</p>
          <button v-if="activeFilters.length" type="button" class="mt-3 min-h-10 rounded-full bg-brand-600 px-5 text-sm font-semibold text-white" @click="resetFilters">{{ t('productList.resetFilters') }}</button>
        </div>
        <template v-else>
          <div :class="GRID" data-testid="catalog-grid">
            <ProductCard v-for="product in products" :key="product.id" :product="product" />
          </div>

          <div v-if="meta && meta.last_page > 1" class="mt-6 flex items-center justify-center gap-2">
            <button
              type="button"
              class="grid h-10 w-10 place-items-center rounded-full border border-stone-300 disabled:opacity-40 dark:border-stone-600"
              :aria-label="t('common.previous')"
              :disabled="meta.current_page <= 1"
              @click="goToPage(meta.current_page - 1)"
            >
              <AppIcon name="chevron-left" :size="16" />
            </button>
            <span class="text-sm text-stone-600 dark:text-stone-300">{{ meta.current_page }} / {{ meta.last_page }}</span>
            <button
              type="button"
              class="grid h-10 w-10 place-items-center rounded-full border border-stone-300 disabled:opacity-40 dark:border-stone-600"
              :aria-label="t('common.next')"
              :disabled="meta.current_page >= meta.last_page"
              @click="goToPage(meta.current_page + 1)"
            >
              <AppIcon name="chevron-right" :size="16" />
            </button>
          </div>
        </template>
      </div>
    </div>

    <!-- Mobile filter drawer -->
    <Teleport to="body">
      <div v-if="filtersOpen" class="fixed inset-0 z-40 md:hidden" role="dialog" aria-modal="true" :aria-label="t('productList.filterAndSort')" data-testid="filters-drawer">
        <div class="absolute inset-0 bg-black/50" @click="filtersOpen = false" />
        <div class="absolute inset-x-0 bottom-0 max-h-[88vh] overflow-y-auto rounded-t-3xl bg-white p-5 dark:bg-stone-900">
          <div class="mb-4 flex items-center justify-between">
            <h2 class="font-display text-lg font-semibold text-stone-800 dark:text-stone-100">{{ t('productList.filterAndSort') }}</h2>
            <button type="button" class="grid h-10 w-10 place-items-center rounded-full" :aria-label="t('common.close')" @click="filtersOpen = false"><AppIcon name="close" :size="20" /></button>
          </div>

          <div class="space-y-5">
            <div>
              <h3 class="mb-2 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('productList.sortBy') }}</h3>
              <select v-model="filters.sort" class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2.5 text-sm dark:border-stone-600 dark:bg-stone-950">
                <option v-for="opt in SORT_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </select>
            </div>

            <nav :aria-label="t('productList.category')">
              <h3 class="mb-2 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('productList.category') }}</h3>
              <button
                type="button"
                class="mb-0.5 flex min-h-10 w-full items-center rounded-lg px-3 text-left text-sm"
                :class="!filters.category ? 'bg-brand-50 font-semibold text-brand-700 dark:bg-brand-900/40 dark:text-brand-200' : 'text-stone-700 dark:text-stone-200'"
                :aria-current="!filters.category ? 'true' : undefined"
                @click="filters.category = ''"
              >
                {{ t('productList.allCategories') }}
              </button>
              <CatalogCategoryTree :nodes="tree" :active-slug="filters.category" :expanded="expanded" @select="filters.category = $event" @toggle="toggleBranch" />
            </nav>

            <div>
              <h3 class="mb-2 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('productList.priceRange') }}</h3>
              <div class="flex items-center gap-2">
                <input v-model="filters.minPrice" type="number" min="0" inputmode="numeric" :placeholder="t('productList.min')" :aria-label="t('productList.min')" class="w-full min-w-0 rounded-lg border border-stone-300 bg-white px-2 py-2.5 text-sm dark:border-stone-600 dark:bg-stone-950" />
                <span class="text-stone-400">–</span>
                <input v-model="filters.maxPrice" type="number" min="0" inputmode="numeric" :placeholder="t('productList.max')" :aria-label="t('productList.max')" class="w-full min-w-0 rounded-lg border border-stone-300 bg-white px-2 py-2.5 text-sm dark:border-stone-600 dark:bg-stone-950" />
              </div>
            </div>

            <div class="flex gap-2">
              <button v-if="activeFilters.length" type="button" class="min-h-11 flex-1 rounded-full border border-stone-300 px-4 text-sm font-medium text-stone-700 dark:border-stone-600 dark:text-stone-200" @click="resetFilters">{{ t('productList.resetFilters') }}</button>
              <AppButton class="flex-1" @click="applyFilters">{{ t('productList.applyFilter') }}</AppButton>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </ShopLayout>
</template>
