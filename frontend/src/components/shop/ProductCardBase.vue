<script setup lang="ts">
/**
 * THE product card markup. ProductCard (catalog / wishlist / related) and ProductBlock (homepage "Produk Kami") are thin
 * adapters that map their own payload onto these props, so every surface renders exactly the same card.
 * Nothing here knows a price, stock or product rule: it only displays what its caller resolved.
 *
 * The footer action is the card's own link affordance (it opens the product page, where required variations are chosen
 * before anything can reach the cart). It is deliberately NOT a second interactive control and never adds to the cart.
 */
import { ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { formatRupiah } from '@/utils/format'
import { useAuthStore } from '@/stores/auth'
import { useWishlistStore } from '@/stores/wishlist'
import AppIcon from '@/components/ui/AppIcon.vue'
import StockBadge from '@/components/ui/StockBadge.vue'
import '@/assets/product-card.css'

const props = defineProps<{
  id: number
  slug: string
  name: string
  imageUrl?: string | null
  /** Already resolved by the caller: base price, or the cheapest active variation. null = no valid price. */
  price: string | number | null
  hasVariations: boolean
  /** Stock is shown only when the caller knows it (catalog). The homepage payload carries none, so it passes false. */
  showStock: boolean
  stockQuantity?: number
}>()

const { t } = useI18n()
const auth = useAuthStore()
const wishlist = useWishlistStore()

// A broken image URL must degrade to the same placeholder as a missing one, never to a broken-image glyph.
const imageFailed = ref(false)
watch(() => props.imageUrl, () => { imageFailed.value = false })
</script>

<template>
  <RouterLink :to="{ name: 'product-detail', params: { slug } }" class="pc-card group">
    <button
      type="button"
      class="pc-card__wish"
      :class="wishlist.has(id) && 'pc-card__wish--on'"
      :aria-label="wishlist.has(id) ? t('product.removeFromWishlist') : t('product.addToWishlist')"
      :aria-pressed="wishlist.has(id)"
      @click.prevent.stop="wishlist.toggle({ productId: id, slug })"
    >
      <AppIcon :name="wishlist.has(id) ? 'heart-filled' : 'heart'" :size="16" />
    </button>

    <div class="pc-card__image aspect-square w-full">
      <img v-if="imageUrl && !imageFailed" :src="imageUrl" :alt="name" class="h-full w-full object-cover" loading="lazy" @error="imageFailed = true" />
      <div v-else class="pc-card__placeholder grid h-full w-full place-items-center"><AppIcon name="box" :size="40" /></div>
    </div>

    <div class="pc-card__footer p-2.5 sm:p-3 lg:p-4">
      <h3 class="pc-card__name line-clamp-2 min-h-10 text-[13px] font-medium leading-5 sm:text-sm lg:text-[15px]">{{ name }}</h3>
      <div class="pc-card__divider my-1.5 sm:my-2" data-testid="product-divider" aria-hidden="true" />
      <p class="pc-card__price font-display text-sm font-semibold sm:text-[15px] lg:text-base" data-testid="product-price">
        <template v-if="price !== null"><template v-if="hasVariations">{{ t('cms.startingFrom') }}</template>{{ formatRupiah(price) }}</template><template v-else>—</template>
      </p>
      <div class="pc-card__bottom">
        <StockBadge v-if="showStock" class="pc-card__stock" :quantity="stockQuantity" :logged-in="auth.isLoggedIn" />
        <span v-else />
        <span class="pc-card__cta" data-testid="product-card-cta" aria-hidden="true">
          <AppIcon name="cart" :size="16" />
        </span>
      </div>
    </div>
  </RouterLink>
</template>
