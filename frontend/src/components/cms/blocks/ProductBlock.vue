<script setup lang="ts">
import ProductCardBase from '@/components/shop/ProductCardBase.vue'
import type { HomepageContentProduct } from '@/api/cms'

defineProps<{ content: { heading?: string; products?: HomepageContentProduct[] } }>()

// A variation product has no base price: its price is the cheapest active variation (`starting_price`).
// Older payloads without that field fall back to base_price. No valid price -> no amount at all.
function priceOf(product: HomepageContentProduct): string | number | null {
  const value = product.starting_price !== undefined ? product.starting_price : (product.base_price ?? null)
  return value === null || value === '' || Number.isNaN(Number(value)) ? null : value
}
</script>

<template>
  <section v-if="content.products?.length">
    <h2 v-if="content.heading" class="mb-3 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">
      {{ content.heading }}
    </h2>
    <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 sm:gap-3.5 lg:grid-cols-4 lg:gap-4">
      <ProductCardBase
        v-for="product in content.products"
        :id="product.id"
        :key="product.id"
        :slug="product.slug"
        :name="product.name"
        :image-url="product.image_url"
        :price="priceOf(product)"
        :has-variations="!!product.has_variations"
        :show-stock="false"
      />
    </div>
  </section>
</template>
