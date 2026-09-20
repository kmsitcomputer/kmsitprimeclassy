<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { fulfillStockRequest, listStockRequests, type StockRequest, type StockRequestItem } from '@/api/stockRequests'
import { skuLabel } from '@/utils/format'

const requests = ref<StockRequest[]>([]); const error = ref(''); const quantities = ref<Record<number, number>>({})

/* Product-oriented identity (PBR-003) — name/variant/SKU first, IDs secondary. */
function itemName(item: StockRequestItem): string {
  return item.product_name ?? item.product?.name ?? (item.product_variation_id != null ? `Varian #${item.product_variation_id}` : `Produk #${item.product_id}`)
}
function itemSku(item: StockRequestItem): string | null {
  return item.sku ?? item.sku_snapshot ?? item.variation?.sku ?? item.product?.sku ?? null
}
async function load() { requests.value = await listStockRequests() }
async function fulfill(request: StockRequest) { try { const items = request.items.filter((item) => (quantities.value[item.id] ?? 0) > 0).map((item) => ({ item_id: item.id, quantity: quantities.value[item.id] ?? 0 })); if (!items.length) throw new Error(); await fulfillStockRequest(request.id, `ui-${request.id}-${Date.now()}`, items); await load() } catch { error.value = 'Fulfillment gagal. Periksa Transit dan sisa request.' } }
onMounted(load)
</script>
<template><DashboardLayout><h1 class="mb-1 font-display text-2xl font-semibold">Stock Requests</h1><p class="mb-5 text-sm text-stone-500">Fulfill hanya dari stok fisik Transit. Factory Plan bukan stok fisik.</p><p v-if="error" class="mb-3 text-sm text-red-600">{{ error }}</p><div v-for="request in requests" :key="request.id" class="mb-4 rounded-xl border bg-white p-4"><div class="mb-3 flex justify-between"><strong>{{ request.request_number }}</strong><span>{{ request.status }}</span></div><div v-for="item in request.items" :key="item.id" class="mb-2 flex items-center gap-3 text-sm"><img v-if="item.product_image_url" :src="item.product_image_url" :alt="itemName(item)" class="h-10 w-10 shrink-0 rounded-lg object-cover" /><span class="flex-1"><span class="font-medium">{{ itemName(item) }}</span><span v-if="item.variation_label ?? item.variation?.label"> — {{ item.variation_label ?? item.variation?.label }}</span><span v-else-if="!item.product_name && !item.product"> · Tanpa Varian</span><span class="block text-xs text-stone-400">{{ skuLabel(itemSku(item)) }} · requested {{ item.requested_qty }} · fulfilled {{ item.fulfilled_qty }} · remaining {{ item.remaining_qty }}</span></span><input v-model.number="quantities[item.id]" class="w-24 border p-1" type="number" min="0" :max="item.remaining_qty" :disabled="item.remaining_qty === 0" /></div><button v-if="request.status === 'pending' || request.status === 'partial'" class="rounded bg-brand-600 px-3 py-2 text-white" @click="fulfill(request)">Fulfill</button></div></DashboardLayout></template>