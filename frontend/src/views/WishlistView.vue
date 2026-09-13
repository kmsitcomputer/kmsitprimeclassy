<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ShopLayout from '@/layouts/ShopLayout.vue'
import ProductCard from '@/components/shop/ProductCard.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useWishlistStore } from '@/stores/wishlist'
import { getProduct } from '@/api/catalog'
import type { Product } from '@/api/types'

const { t } = useI18n()
const wishlist = useWishlistStore()
const products = ref<Product[]>([])
const loading = ref(true)

async function loadAll() {
  loading.value = true
  const results = await Promise.all(
    wishlist.items.map((item) => getProduct(item.slug).catch(() => null)),
  )
  products.value = results.filter((p: Product | null): p is Product => p !== null)
  loading.value = false
}

onMounted(loadAll)
watch(() => wishlist.items.length, loadAll)
</script>

<template>
  <ShopLayout>
    <h1 class="mb-4 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">{{ t('wishlist.title') }}</h1>

    <div v-if="loading" class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
      <div v-for="i in 4" :key="i" class="aspect-[3/4.2] animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
    </div>
    <div v-else-if="!products.length" class="flex flex-col items-center gap-3 rounded-2xl bg-stone-100 py-16 text-center dark:bg-stone-900">
      <AppIcon name="heart" :size="40" class="text-stone-300 dark:text-stone-700" />
      <p class="text-sm text-stone-500 dark:text-stone-400">{{ t('wishlist.empty') }}</p>
      <RouterLink :to="{ name: 'products' }" class="text-sm font-medium text-brand-600 dark:text-brand-400">{{ t('wishlist.explore') }}</RouterLink>
    </div>
    <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
      <ProductCard v-for="product in products" :key="product.id" :product="product" />
    </div>
  </ShopLayout>
</template>
