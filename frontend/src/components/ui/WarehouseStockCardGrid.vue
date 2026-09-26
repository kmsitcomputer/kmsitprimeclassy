<script setup lang="ts">
import type { StockCard } from '@/api/warehouse'
import { skuLabel } from '@/utils/format'

defineProps<{ cards: StockCard[] }>()
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
      <dl class="mt-2 grid grid-cols-2 gap-1 text-sm">
        <div><dt class="text-xs text-stone-400">Transit</dt><dd>{{ card.transit }}</dd></div>
        <div><dt class="text-xs text-stone-400">Plan Pabrik</dt><dd>{{ card.factory_plan }}</dd></div>
        <div><dt class="text-xs text-stone-400">Reserved</dt><dd>{{ card.reserved }}</dd></div>
        <div><dt class="text-xs text-stone-400">Stok Jual</dt><dd class="font-semibold">{{ card.sellable }}</dd></div>
      </dl>
    </article>
  </div>
</template>
