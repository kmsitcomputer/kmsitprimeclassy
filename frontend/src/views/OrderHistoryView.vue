<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import ShopLayout from '@/layouts/ShopLayout.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { listOrders } from '@/api/orders'
import type { Order, PaginationMeta } from '@/api/types'
import { formatRupiah, formatDate, orderStatusLabel } from '@/utils/format'

const { t } = useI18n()
const orders = ref<Order[]>([])
const meta = ref<PaginationMeta | null>(null)
const loading = ref(true)

async function load(page = 1) {
  loading.value = true
  const result = await listOrders(page)
  orders.value = result.orders
  meta.value = result.meta
  loading.value = false
}

onMounted(() => load())

const STATUS_STYLES: Record<string, string> = {
  diterima: 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-400',
  diproses: 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-400',
  dikirim: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-400',
  terkirim: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400',
  dibatalkan: 'bg-stone-100 text-stone-500 dark:bg-stone-800 dark:text-stone-400',
  pengembalian: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-400',
  kembali: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-400',
}
</script>

<template>
  <ShopLayout>
    <h1 class="mb-4 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">{{ t('orders.historyTitle') }}</h1>

    <div v-if="loading" class="space-y-3">
      <div v-for="i in 3" :key="i" class="h-24 animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
    </div>
    <div v-else-if="!orders.length" class="flex flex-col items-center gap-3 rounded-2xl bg-stone-100 py-16 text-center dark:bg-stone-900">
      <AppIcon name="clock" :size="40" class="text-stone-300 dark:text-stone-700" />
      <p class="text-sm text-stone-500 dark:text-stone-400">{{ t('orders.empty') }}</p>
      <RouterLink :to="{ name: 'products' }" class="text-sm font-medium text-brand-600 dark:text-brand-400">{{ t('orders.startShopping') }}</RouterLink>
    </div>
    <ul v-else class="space-y-3">
      <li v-for="order in orders" :key="order.id">
        <RouterLink
          :to="{ name: 'order-detail', params: { id: order.id } }"
          class="block rounded-2xl border border-stone-200 bg-white p-4 hover:border-brand-300 dark:border-stone-800 dark:bg-stone-900"
        >
          <div class="flex items-center justify-between gap-2">
            <span class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ order.order_no }}</span>
            <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="STATUS_STYLES[order.status]">
              {{ orderStatusLabel(order.status) }}
            </span>
          </div>
          <p class="mt-1 text-xs text-stone-400">{{ formatDate(order.created_at) }}</p>
          <p v-if="order.konsumen || order.sales" class="mt-1 text-xs text-stone-500 dark:text-stone-400">
            <span v-if="order.konsumen">{{ order.konsumen.name }}</span>
            <span v-if="order.konsumen && order.sales"> &middot; </span>
            <span v-if="order.sales">Sales: {{ order.sales.name }}</span>
          </p>
          <div class="mt-2 flex items-center justify-between">
            <span class="text-xs text-stone-500 dark:text-stone-400">{{ t('orders.itemsCount', { count: order.items.length }) }}</span>
            <span class="font-display text-sm font-semibold text-brand-700 dark:text-brand-300">{{ formatRupiah(order.total_amount) }}</span>
          </div>
        </RouterLink>
      </li>
    </ul>

    <div v-if="meta && meta.last_page > 1" class="mt-6 flex items-center justify-center gap-2">
      <button type="button" class="grid h-9 w-9 place-items-center rounded-full border border-stone-200 disabled:opacity-40 dark:border-stone-700" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">
        <AppIcon name="chevron-left" :size="16" />
      </button>
      <span class="text-sm text-stone-600 dark:text-stone-300">{{ meta.current_page }} / {{ meta.last_page }}</span>
      <button type="button" class="grid h-9 w-9 place-items-center rounded-full border border-stone-200 disabled:opacity-40 dark:border-stone-700" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">
        <AppIcon name="chevron-right" :size="16" />
      </button>
    </div>
  </ShopLayout>
</template>
