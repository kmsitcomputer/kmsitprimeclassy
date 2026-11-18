<script setup lang="ts">
import { computed } from 'vue'
import type { Product } from '@/api/types'
import ProductCardBase from '@/components/shop/ProductCardBase.vue'

const props = defineProps<{ product: Product }>()

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
  <ProductCardBase
    :id="product.id"
    :slug="product.slug"
    :name="product.name"
    :image-url="primaryImage?.url"
    :price="displayPrice ?? null"
    :has-variations="!!product.has_variations"
    :show-stock="true"
    :stock-quantity="stockQuantity"
  />
</template>
