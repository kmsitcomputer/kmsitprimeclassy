<script setup lang="ts">
import { ApiError } from '@/api/client'
import StockSourceSelector from '@/components/shop/StockSourceSelector.vue'
import { createLatestRequestGuard } from '@/utils/latestRequest'
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import ShopLayout from '@/layouts/ShopLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import StockBadge from '@/components/ui/StockBadge.vue'
import ProductCard from '@/components/shop/ProductCard.vue'
import { getProduct, listRelatedProducts } from '@/api/catalog'
import type { Product, ProductImage, ProductVariation } from '@/api/types'
import { formatRupiah } from '@/utils/format'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'
import { useWishlistStore } from '@/stores/wishlist'

const props = defineProps<{ slug: string }>()
const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const cart = useCartStore()
const wishlist = useWishlistStore()

const stockSource = computed(() => auth.user?.role === 'sales-kurir-sub' ? cart.stockSourceFor(auth.user.id) : undefined)
const loadGuard = createLatestRequestGuard()
const product = ref<Product | null>(null)
const loading = ref(true)
const notFound = ref(false)
const loadError = ref<string | null>(null)
const activeImageIndex = ref(0)
const selectedVariation = ref<ProductVariation | null>(null)
const quantity = ref(1)
const addedMessage = ref(false)

const relatedProducts = ref<Product[]>([])
const relatedLoading = ref(false)
const relatedGuard = createLatestRequestGuard()

async function load() {
  const token = loadGuard.begin()
  loading.value = true
  notFound.value = false
  loadError.value = null
  relatedGuard.invalidate()
  relatedProducts.value = []
  try {
    const loaded = await getProduct(props.slug, stockSource.value)
    if (!loadGuard.isCurrent(token)) return
    product.value = loaded
    selectedVariation.value = loaded.has_variations ? (loaded.variations.find((v) => v.is_active) ?? null) : null
    activeImageIndex.value = 0
    quantity.value = 1
    loadRelatedProducts(loaded.id)
  } catch (e) {
    if (loadGuard.isCurrent(token)) {
      notFound.value = e instanceof ApiError && e.status === 404
      loadError.value = e instanceof ApiError ? e.message : 'Gagal memuat produk dan ketersediaan stok.'
      product.value = null
    }
  } finally {
    if (loadGuard.isCurrent(token)) loading.value = false
  }
}

// Secondary content: a failure here must never take down the product detail
// page itself, so it's isolated in its own try/catch and its own loading flag.
async function loadRelatedProducts(productId: number) {
  const token = relatedGuard.begin()
  relatedLoading.value = true
  try {
    const result = await listRelatedProducts(productId, 8, stockSource.value)
    if (relatedGuard.isCurrent(token)) relatedProducts.value = result
  } catch {
    if (relatedGuard.isCurrent(token)) relatedProducts.value = []
  } finally {
    if (relatedGuard.isCurrent(token)) relatedLoading.value = false
  }
}

onMounted(load)
watch(() => [props.slug, stockSource.value], load)

const activeImage = computed(() => galleryImages.value[activeImageIndex.value] ?? null)

/**
 * REM-02: the gallery follows the selected variation. A variation owns its own
 * images (product_variation_id === variation.id); the parent gallery
 * (product_variation_id === null) is the fallback when the selected variation has
 * no images of its own. Before any selection the full list is shown, matching the
 * previous behaviour. No image row is ever mutated — only the visible subset.
 */
const galleryImages = computed<ProductImage[]>(() => {
  const p = product.value
  if (!p?.images?.length) return []
  const variation = selectedVariation.value
  if (!variation) return p.images
  const own = p.images.filter((i) => i.product_variation_id === variation.id)
  if (own.length) return own
  return p.images.filter((i) => i.product_variation_id === null)
})

/**
 * REM-02 gallery → variation: one representative thumbnail (primary, else first) for every OTHER
 * active variation that owns images. Clicking it selects THAT variation — an image belongs to exactly
 * one variation (product_variation_id), so the mapping is unambiguous and never fabricates a
 * variation. Variations without their own images are not represented (nothing to click).
 */
const variantThumbs = computed(() => {
  const p = product.value
  if (!p?.has_variations || !p.images?.length) return []
  const out: { variation: ProductVariation; image: ProductImage }[] = []
  for (const variation of p.variations ?? []) {
    if (!variation.is_active || variation.id === selectedVariation.value?.id) continue
    const own = p.images.filter((i) => i.product_variation_id === variation.id)
    const image = own.find((i) => i.is_primary) ?? own[0]
    if (image) out.push({ variation, image })
  }
  return out
})

function selectVariation(variation: ProductVariation) {
  if (!variation.is_active) return
  selectedVariation.value = variation
  quantity.value = 1
}

const primaryIndex = (images: ProductImage[]) => Math.max(0, images.findIndex((i) => i.is_primary))

// Reset the viewer whenever the visible set changes (variation picked/replaced or
// product reloaded) so a stale index can never point into another gallery; the
// primary image of the new gallery is shown first.
watch(
  () => galleryImages.value.map((i) => i.id).join(','),
  () => {
    activeImageIndex.value = primaryIndex(galleryImages.value)
  },
)

const unitPrice = computed(() => {
  if (!product.value) return 0
  if (product.value.has_variations) return selectedVariation.value ? parseFloat(selectedVariation.value.price) : 0
  return product.value.base_price ? parseFloat(product.value.base_price) : 0
})

/**
 * Price shown to everyone, including anonymous visitors. A product with several variations shows its
 * range until a variation is picked (never "Rp 0"); a single variation shows its own price; a picked
 * variation shows its actual price. Checkout re-validates the real price server-side.
 */
const priceLabel = computed(() => {
  const p = product.value
  if (!p) return ''
  if (!p.has_variations || selectedVariation.value) return formatRupiah(unitPrice.value)
  const prices = (p.variations ?? []).map((v) => parseFloat(v.price)).filter((n) => !Number.isNaN(n))
  if (!prices.length) return '—'
  const lo = Math.min(...prices)
  const hi = Math.max(...prices)
  return lo === hi ? formatRupiah(lo) : `${formatRupiah(lo)} – ${formatRupiah(hi)}`
})

const stockQuantity = computed<number | undefined>(() => {
  if (!product.value) return undefined
  return product.value.has_variations ? selectedVariation.value?.stock?.available : product.value.stock?.available
})

const canAddToCart = computed(() => {
  if (!auth.isLoggedIn) return false
  if (loading.value || !product.value) return false
  if (product.value.has_variations && !selectedVariation.value) return false
  return (stockQuantity.value ?? 0) > 0
})

function changeQuantity(delta: number) {
  const next = quantity.value + delta
  const max = stockQuantity.value ?? 99
  quantity.value = Math.min(Math.max(1, next), Math.max(1, max))
}

function addToCart() {
  if (!product.value || !canAddToCart.value) return
  // REM-02: the cart line thumbnail follows the same variation gallery contract
  // as the detail viewer (variation images first, parent gallery fallback).
  const image = galleryImages.value.find((i) => i.is_primary) ?? galleryImages.value[0]

  cart.add(
    {
      key: `${product.value.id}:${selectedVariation.value?.id ?? 'base'}`,
      productId: product.value.id,
      variationId: selectedVariation.value?.id ?? null,
      productSlug: product.value.slug,
      name: product.value.name,
      variationLabel: selectedVariation.value?.label ?? null,
      unitPrice: unitPrice.value,
      image: image?.url ?? null,
      stockLimit: stockQuantity.value ?? null,
    },
    quantity.value,
  )

  addedMessage.value = true
  setTimeout(() => (addedMessage.value = false), 2000)
}

function goToLoginToBuy() {
  router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
}
</script>

<template>
  <ShopLayout>
    <StockSourceSelector />
    <div v-if="loading" class="grid grid-cols-1 gap-6 md:grid-cols-2">
      <div class="aspect-square animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
      <div class="space-y-3">
        <div class="h-6 w-3/4 animate-pulse rounded bg-stone-100 dark:bg-stone-800" />
        <div class="h-4 w-1/2 animate-pulse rounded bg-stone-100 dark:bg-stone-800" />
      </div>
    </div>

    <p v-else-if="loadError && !notFound" class="rounded-lg bg-red-50 p-4 text-sm text-red-700">{{ loadError }}</p>
    <div v-else-if="notFound" class="py-16 text-center">
      <p class="text-stone-500 dark:text-stone-400">{{ t('product.notFound') }}</p>
      <RouterLink :to="{ name: 'products' }" class="mt-2 inline-block text-sm font-medium text-brand-600 dark:text-brand-400">
        {{ t('product.backToCatalog') }}
      </RouterLink>
    </div>

    <div v-else-if="product" class="grid grid-cols-1 gap-8 md:grid-cols-2">
      <div>
        <div class="aspect-square overflow-hidden rounded-2xl bg-stone-100 dark:bg-stone-800">
          <img
            v-if="activeImage"
            :src="activeImage.url"
            :alt="product.name"
            class="h-full w-full object-cover"
          />
          <div v-else class="grid h-full w-full place-items-center text-stone-300 dark:text-stone-700">
            <AppIcon name="box" :size="56" />
          </div>
        </div>
        <div v-if="galleryImages.length > 1 || variantThumbs.length" class="mt-3 flex gap-2 overflow-x-auto" data-testid="gallery-strip">
          <template v-if="galleryImages.length > 1">
            <button
              v-for="(img, idx) in galleryImages"
              :key="img.id"
              type="button"
              class="h-16 w-16 shrink-0 overflow-hidden rounded-lg border-2"
              :class="idx === activeImageIndex ? 'border-brand-500' : 'border-transparent'"
              data-testid="gallery-thumb"
              @click="activeImageIndex = idx"
            >
              <img :src="img.url" :alt="product.name" class="h-full w-full object-cover" />
            </button>
          </template>
          <template v-if="variantThumbs.length">
            <span class="mx-1 w-px shrink-0 self-stretch bg-stone-200 dark:bg-stone-700" aria-hidden="true"></span>
            <button
              v-for="thumb in variantThumbs"
              :key="`variant-${thumb.variation.id}`"
              type="button"
              class="h-16 w-16 shrink-0 overflow-hidden rounded-lg border-2 border-transparent hover:border-brand-300"
              :aria-label="`Pilih varian ${thumb.variation.label}`"
              :title="thumb.variation.label"
              :data-testid="`variant-thumb-${thumb.variation.id}`"
              @click="selectVariation(thumb.variation)"
            >
              <img :src="thumb.image.url" :alt="thumb.variation.label" class="h-full w-full object-cover" />
            </button>
          </template>
        </div>
      </div>

      <div>
        <p v-if="product.category" class="text-xs font-medium uppercase tracking-wide text-brand-600 dark:text-brand-400">
          {{ product.category.name }}
        </p>
        <h1 class="mt-1 font-display text-2xl font-semibold text-stone-900 dark:text-stone-50">{{ product.name }}</h1>
        <p class="mt-2 font-display text-2xl font-semibold text-brand-700 dark:text-brand-300">{{ priceLabel }}</p>

        <div class="mt-3">
          <StockBadge :quantity="stockQuantity" :logged-in="auth.isLoggedIn" />
        </div>

        <div v-if="product.has_variations" class="mt-5">
          <h3 class="mb-2 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('product.chooseVariation') }}</h3>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="variation in product.variations"
              :key="variation.id"
              type="button"
              :disabled="!variation.is_active"
              :data-testid="`variation-${variation.id}`"
              :aria-pressed="selectedVariation?.id === variation.id"
              class="rounded-xl border px-3.5 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-40"
              :class="
                selectedVariation?.id === variation.id
                  ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-300'
                  : 'border-stone-200 text-stone-600 hover:border-brand-300 dark:border-stone-700 dark:text-stone-300'
              "
              @click="selectVariation(variation)"
            >
              {{ variation.label }}
            </button>
          </div>
        </div>

        <div v-if="auth.isLoggedIn" class="mt-5 flex items-center gap-3">
          <span class="text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('product.quantity') }}</span>
          <div class="flex items-center rounded-full border border-stone-200 dark:border-stone-700">
            <button type="button" class="grid h-9 w-9 place-items-center text-stone-500" @click="changeQuantity(-1)">
              <AppIcon name="minus" :size="15" />
            </button>
            <span class="w-8 text-center text-sm font-medium">{{ quantity }}</span>
            <button type="button" class="grid h-9 w-9 place-items-center text-stone-500" @click="changeQuantity(1)">
              <AppIcon name="plus" :size="15" />
            </button>
          </div>
        </div>

        <div class="mt-6 flex gap-3">
          <button
            type="button"
            class="grid h-12 w-12 shrink-0 place-items-center rounded-xl border border-stone-200 text-stone-500 hover:text-brand-600 dark:border-stone-700"
            @click="wishlist.toggle({ productId: product.id, slug: product.slug })"
          >
            <AppIcon :name="wishlist.has(product.id) ? 'heart-filled' : 'heart'" :size="20" :class="wishlist.has(product.id) && 'text-brand-600'" />
          </button>

          <AppButton v-if="!auth.isLoggedIn" size="lg" block @click="goToLoginToBuy">
            <AppIcon name="lock" :size="16" /> {{ t('product.loginToBuy') }}
          </AppButton>
          <AppButton v-else size="lg" block :disabled="!canAddToCart" @click="addToCart">
            {{ addedMessage ? t('product.addedToCart') : t('product.addToCart') }}
          </AppButton>
        </div>

        <div
          v-if="product.description"
          class="cms-content mt-6 text-sm leading-relaxed text-stone-600 dark:text-stone-300"
          v-html="product.description"
        />
      </div>

      <div v-if="relatedLoading || relatedProducts.length" class="col-span-full mt-4">
        <h2 class="mb-3 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">
          {{ t('product.relatedProducts') }}
        </h2>
        <div v-if="relatedLoading" class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 sm:gap-3.5 lg:grid-cols-4 lg:gap-4">
          <div v-for="i in 4" :key="i" class="aspect-[3/4.2] animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
        </div>
        <div v-else class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 sm:gap-3.5 lg:grid-cols-4 lg:gap-4">
          <ProductCard v-for="related in relatedProducts" :key="related.id" :product="related" />
        </div>
      </div>
    </div>
  </ShopLayout>
</template>
