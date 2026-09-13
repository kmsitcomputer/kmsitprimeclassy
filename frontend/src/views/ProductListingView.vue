<script setup lang="ts">
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import ShopLayout from '@/layouts/ShopLayout.vue'
import ProductCard from '@/components/shop/ProductCard.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { listCategories, listProducts } from '@/api/catalog'
import type { Category, PaginationMeta, Product } from '@/api/types'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const products = ref<Product[]>([])
const categories = ref<Category[]>([])
const meta = ref<PaginationMeta | null>(null)
const loading = ref(true)
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

async function fetchProducts() {
  loading.value = true
  try {
    const result = await listProducts({
      search: filters.search || undefined,
      category: filters.category || undefined,
      sort: filters.sort as 'newest' | 'price_asc' | 'price_desc',
      min_price: filters.minPrice ? Number(filters.minPrice) : undefined,
      max_price: filters.maxPrice ? Number(filters.maxPrice) : undefined,
      page: filters.page,
      per_page: 12,
    })
    products.value = result.products
    meta.value = result.meta
  } finally {
    loading.value = false
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

onMounted(async () => {
  categories.value = await listCategories()
  await fetchProducts()
})
</script>

<template>
  <ShopLayout>
    <div class="mb-4 flex items-center justify-between gap-3">
      <div>
        <h1 class="font-display text-xl font-semibold text-stone-800 dark:text-stone-100">
          {{ filters.search ? t('productList.searchResultsFor', { query: filters.search }) : t('productList.allProducts') }}
        </h1>
        <p v-if="meta" class="text-sm text-stone-500 dark:text-stone-400">{{ t('productList.productsFound', { count: meta.total }) }}</p>
      </div>
      <button
        type="button"
        class="flex items-center gap-1.5 rounded-full border border-stone-200 px-3.5 py-2 text-sm font-medium text-stone-700 md:hidden dark:border-stone-700 dark:text-stone-200"
        @click="filtersOpen = true"
      >
        <AppIcon name="filter" :size="16" />
        {{ t('productList.filter') }}
      </button>
    </div>

    <div class="grid grid-cols-1 gap-6 md:grid-cols-[220px_1fr]">
      <!-- Desktop sidebar filters -->
      <aside class="hidden md:block">
        <div class="sticky top-20 space-y-6">
          <div>
            <h3 class="mb-2 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('productList.sortBy') }}</h3>
            <select
              v-model="filters.sort"
              class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-900"
              @change="applyFilters"
            >
              <option v-for="opt in SORT_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
          </div>

          <div>
            <h3 class="mb-2 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('productList.category') }}</h3>
            <div class="space-y-1">
              <button
                type="button"
                class="block w-full rounded-lg px-2 py-1.5 text-left text-sm"
                :class="!filters.category ? 'bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-300' : 'text-stone-600 hover:bg-stone-100 dark:text-stone-300 dark:hover:bg-stone-800'"
                @click="filters.category = ''; applyFilters()"
              >
                {{ t('productList.allCategories') }}
              </button>
              <button
                v-for="cat in categories"
                :key="cat.id"
                type="button"
                class="block w-full rounded-lg px-2 py-1.5 text-left text-sm"
                :class="filters.category === cat.slug ? 'bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-300' : 'text-stone-600 hover:bg-stone-100 dark:text-stone-300 dark:hover:bg-stone-800'"
                @click="filters.category = cat.slug; applyFilters()"
              >
                {{ cat.name }}
              </button>
            </div>
          </div>

          <div>
            <h3 class="mb-2 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('productList.priceRange') }}</h3>
            <div class="flex items-center gap-2">
              <input
                v-model="filters.minPrice"
                type="number"
                :placeholder="t('productList.min')"
                class="w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-900"
              />
              <span class="text-stone-400">–</span>
              <input
                v-model="filters.maxPrice"
                type="number"
                :placeholder="t('productList.max')"
                class="w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-900"
              />
            </div>
            <AppButton size="sm" variant="secondary" class="mt-2 w-full" @click="applyFilters">{{ t('common.apply') }}</AppButton>
          </div>
        </div>
      </aside>

      <div>
        <div v-if="loading" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <div v-for="i in 9" :key="i" class="aspect-[3/4.2] animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
        </div>
        <p v-else-if="!products.length" class="rounded-xl bg-stone-100 p-8 text-center text-sm text-stone-500 dark:bg-stone-900">
          {{ t('productList.noResults') }}
        </p>
        <template v-else>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <ProductCard v-for="product in products" :key="product.id" :product="product" />
          </div>

          <div v-if="meta && meta.last_page > 1" class="mt-6 flex items-center justify-center gap-2">
            <button
              type="button"
              class="grid h-9 w-9 place-items-center rounded-full border border-stone-200 disabled:opacity-40 dark:border-stone-700"
              :disabled="meta.current_page <= 1"
              @click="goToPage(meta.current_page - 1)"
            >
              <AppIcon name="chevron-left" :size="16" />
            </button>
            <span class="text-sm text-stone-600 dark:text-stone-300">{{ meta.current_page }} / {{ meta.last_page }}</span>
            <button
              type="button"
              class="grid h-9 w-9 place-items-center rounded-full border border-stone-200 disabled:opacity-40 dark:border-stone-700"
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
      <div v-if="filtersOpen" class="fixed inset-0 z-40 md:hidden">
        <div class="absolute inset-0 bg-black/40" @click="filtersOpen = false" />
        <div class="absolute inset-x-0 bottom-0 max-h-[85vh] overflow-y-auto rounded-t-3xl bg-white p-5 dark:bg-stone-900">
          <div class="mb-4 flex items-center justify-between">
            <h3 class="font-display text-lg font-semibold text-stone-800 dark:text-stone-100">{{ t('productList.filterAndSort') }}</h3>
            <button type="button" @click="filtersOpen = false"><AppIcon name="close" :size="20" /></button>
          </div>

          <div class="space-y-5">
            <div>
              <h4 class="mb-2 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('productList.sortBy') }}</h4>
              <select
                v-model="filters.sort"
                class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-900"
              >
                <option v-for="opt in SORT_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
              </select>
            </div>

            <div>
              <h4 class="mb-2 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('productList.category') }}</h4>
              <div class="flex flex-wrap gap-2">
                <button
                  type="button"
                  class="rounded-full border px-3 py-1.5 text-sm"
                  :class="!filters.category ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-300' : 'border-stone-200 text-stone-600 dark:border-stone-700 dark:text-stone-300'"
                  @click="filters.category = ''"
                >
                  {{ t('productList.all') }}
                </button>
                <button
                  v-for="cat in categories"
                  :key="cat.id"
                  type="button"
                  class="rounded-full border px-3 py-1.5 text-sm"
                  :class="filters.category === cat.slug ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-300' : 'border-stone-200 text-stone-600 dark:border-stone-700 dark:text-stone-300'"
                  @click="filters.category = cat.slug"
                >
                  {{ cat.name }}
                </button>
              </div>
            </div>

            <div>
              <h4 class="mb-2 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('productList.priceRange') }}</h4>
              <div class="flex items-center gap-2">
                <input
                  v-model="filters.minPrice"
                  type="number"
                  :placeholder="t('productList.min')"
                  class="w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-900"
                />
                <span class="text-stone-400">–</span>
                <input
                  v-model="filters.maxPrice"
                  type="number"
                  :placeholder="t('productList.max')"
                  class="w-full rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-900"
                />
              </div>
            </div>

            <AppButton block @click="applyFilters">{{ t('productList.applyFilter') }}</AppButton>
          </div>
        </div>
      </div>
    </Teleport>
  </ShopLayout>
</template>
