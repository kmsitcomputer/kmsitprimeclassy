<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { Product } from '@/api/types'
import { formatRupiah } from '@/utils/format'
import { useAuthStore } from '@/stores/auth'
import { useWishlistStore } from '@/stores/wishlist'
import AppIcon from '@/components/ui/AppIcon.vue'
import '@/assets/product-card.css'
import StockBadge from '@/components/ui/StockBadge.vue'

const props = defineProps<{ product: Product }>()
const { t } = useI18n()
const auth = useAuthStore()
const wishlist = useWishlistStore()

const primaryImage = computed(() => props.product.images?.find((i) => i.is_primary) ?? props.product.images?.[0])

const displayPrice = computed(() => {
  if (!props.product.has_variations) return props.product.base_price
  // Same rule as the API's starting price: active variations only (the API already filters, this keeps a stale payload honest).
  const prices = (props.product.variations ?? [])
    .filter((v) => v.is_active !== false)
    .map((v) => parseFloat(v.price))
    .filter((n) => !Number.isNaN(n))
  return prices.length ? String(Math.min(...prices)) : null
})

const stockQuantity = computed(() => {
  if (props.product.has_variations) {
    const totals = (props.product.variations ?? []).map((v) => v.stock?.available).filter((n) => n !== undefined) as number[]
    return totals.length ? totals.reduce((a, b) => a + b, 0) : undefined
  }
  return props.product.stock?.available
})
</script>

<template>
  <RouterLink
    :to="{ name: 'product-detail', params: { slug: product.slug } }"
    class="pc-card group"
  >
    <button
      type="button"
      class="absolute right-2 top-2 z-10 grid h-8 w-8 place-items-center rounded-full bg-white/90 text-stone-500 shadow-sm backdrop-blur transition hover:text-brand-600 dark:bg-stone-900/90 dark:text-stone-400"
      :aria-label="wishlist.has(product.id) ? t('product.removeFromWishlist') : t('product.addToWishlist')"
      @click.prevent.stop="wishlist.toggle({ productId: product.id, slug: product.slug })"
    >
      <AppIcon :name="wishlist.has(product.id) ? 'heart-filled' : 'heart'" :size="16" :class="wishlist.has(product.id) && 'text-brand-600'" />
    </button>

    <div class="pc-card__image aspect-square w-full">
      <img
        v-if="primaryImage"
        :src="primaryImage.url"
        :alt="product.name"
        class="h-full w-full object-cover"
        loading="lazy"
      />
      <div v-else class="pc-card__placeholder grid h-full w-full place-items-center">
        <AppIcon name="box" :size="40" />
      </div>
    </div>

    <div class="pc-card__footer p-2.5 sm:p-3 lg:p-4">
      <h3 class="pc-card__name line-clamp-2 min-h-10 text-[13px] font-medium leading-5 sm:text-sm lg:text-[15px]">{{ product.name }}</h3>
      <div class="pc-card__divider my-1.5 sm:my-2" data-testid="product-divider" aria-hidden="true" />
      <p class="pc-card__price font-display text-sm font-semibold sm:text-[15px] lg:text-base" data-testid="product-price">
        <template v-if="displayPrice"><template v-if="product.has_variations">{{ t('cms.startingFrom') }}</template>{{ formatRupiah(displayPrice) }}</template><template v-else>—</template>
      </p>
      <StockBadge class="pc-card__stock mt-1.5 self-start" :quantity="stockQuantity" :logged-in="auth.isLoggedIn" />
    </div>
  </RouterLink>
</template>
