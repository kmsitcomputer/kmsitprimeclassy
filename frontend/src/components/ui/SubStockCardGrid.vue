<script setup lang="ts">
import type { SubStockCard } from '@/api/warehouse'
import { skuLabel } from '@/utils/format'

defineProps<{ cards: SubStockCard[] }>()
</script>

<template>
  <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <article v-for="card in cards" :key="`${card.product_id ?? 'p'}-${card.product_variation_id ?? 'v'}`" class="min-w-0 rounded-xl border border-stone-200 bg-white p-3">
      <div class="flex items-center gap-3">
        <img v-if="card.product_image_url" :src="card.product_image_url" :alt="card.product_name" class="h-12 w-12 shrink-0 rounded-lg object-cover" />
        <div class="min-w-0">
          <div class="truncate font-medium text-stone-700">{{ card.product_name }}</div>
          <div v-if="card.variation_label" class="truncate text-xs text-stone-500">Varian: {{ card.variation_label }}</div>
          <div class="truncate text-xs text-stone-400">{{ skuLabel(card.sku) }}</div>
        </div>
      </div>
      <dl class="mt-2 text-sm">
        <div><dt class="text-xs text-stone-400">Stok Sub</dt><dd class="text-lg font-bold">{{ card.sub_stock }}</dd></div>
      </dl>
    </article>
  </div>
</template>
