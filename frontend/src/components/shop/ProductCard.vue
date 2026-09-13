<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { Product } from '@/api/types'
import { formatRupiah } from '@/utils/format'
import { useAuthStore } from '@/stores/auth'
import { useWishlistStore } from '@/stores/wishlist'
import AppIcon from '@/components/ui/AppIcon.vue'
import StockBadge from '@/components/ui/StockBadge.vue'

const props = defineProps<{ product: Product }>()
const { t } = useI18n()
const auth = useAuthStore()
const wishlist = useWishlistStore()

const primaryImage = computed(() => props.product.images?.find((i) => i.is_primary) ?? props.product.images?.[0])

const displayPrice = computed(() => {
  if (!props.product.has_variations) return props.product.base_price
  const prices = props.product.variations.map((v) => parseFloat(v.price)).filter((n) => !Number.isNaN(n))
  return prices.length ? String(Math.min(...prices)) : null
})

const stockQuantity = computed(() => {
  if (props.product.has_variations) {
    const totals = props.product.variations.map((v) => v.agent_available_quantity).filter((n) => n !== undefined) as number[]
    return totals.length ? totals.reduce((a, b) => a + b, 0) : undefined
  }
  return props.product.agent_available_quantity
})
</script>

<template>
  <RouterLink
    :to="{ name: 'product-detail', params: { slug: product.slug } }"
    class="group relative flex flex-col overflow-hidden rounded-2xl border border-stone-200 bg-white transition hover:shadow-md dark:border-stone-800 dark:bg-stone-900"
  >
    <button
      type="button"
      class="absolute right-2 top-2 z-10 grid h-8 w-8 place-items-center rounded-full bg-white/90 text-stone-500 shadow-sm backdrop-blur transition hover:text-brand-600 dark:bg-stone-900/90 dark:text-stone-400"
      :aria-label="wishlist.has(product.id) ? t('product.removeFromWishlist') : t('product.addToWishlist')"
      @click.prevent.stop="wishlist.toggle({ productId: product.id, slug: product.slug })"
    >
      <AppIcon :name="wishlist.has(product.id) ? 'heart-filled' : 'heart'" :size="16" :class="wishlist.has(product.id) && 'text-brand-600'" />
    </button>

    <div class="aspect-square w-full overflow-hidden bg-stone-100 dark:bg-stone-800">
      <img
        v-if="primaryImage"
        :src="primaryImage.url"
        :alt="product.name"
        class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
        loading="lazy"
      />
      <div v-else class="grid h-full w-full place-items-center text-stone-300 dark:text-stone-700">
        <AppIcon name="box" :size="40" />
      </div>
    </div>

    <div class="flex flex-1 flex-col gap-1.5 p-3">
      <h3 class="line-clamp-2 text-sm font-medium text-stone-800 dark:text-stone-100">{{ product.name }}</h3>
      <p class="font-display text-base font-semibold text-brand-700 dark:text-brand-300">
        <template v-if="product.has_variations">{{ t('cms.startingFrom') }}</template>{{ displayPrice ? formatRupiah(displayPrice) : '—' }}
      </p>
      <StockBadge :quantity="stockQuantity" :logged-in="auth.isLoggedIn" />
    </div>
  </RouterLink>
</template>
