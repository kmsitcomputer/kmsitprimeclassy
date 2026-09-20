<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import WarehouseProductPicker from '@/components/ui/WarehouseProductPicker.vue'
import { approveOpname, countOpname, createOpname, listOpnames, submitOpname, type StockOpname, type StockOpnameItem } from '@/api/opnames'
import { useAuthStore } from '@/stores/auth'
import { skuLabel } from '@/utils/format'

const auth = useAuthStore(); const opnames = ref<StockOpname[]>([])
// DS-PBR-001: human-readable picker; IDs stay internal to the API contract.
const productId = ref<number | null>(null); const variationId = ref<number | null>(null); const count = ref(0); const error = ref('')
const expandedId = ref<number | null>(null)

/* Product-oriented identity (PBR-003) — name/variant/SKU first, IDs secondary. */
function itemName(item: StockOpnameItem): string {
  return item.product?.name ?? (item.product_variation_id != null ? `Varian #${item.product_variation_id}` : `Produk #${item.product_id}`)
}
function itemSku(item: StockOpnameItem): string | null {
  return item.variation?.sku ?? item.product?.sku ?? null
}
function toggleItems(id: number) {
  expandedId.value = expandedId.value === id ? null : id
}
async function load() { opnames.value = await listOpnames() }
async function create() { try { if ((!productId.value && !variationId.value) || (productId.value && variationId.value)) throw new Error(); const opname = await createOpname({ opname_type: 'physical_opname', stock_type: 'transit', items: [{ product_id: productId.value ?? undefined, product_variation_id: variationId.value ?? undefined }] }); const item = opname.items[0]; if (!item) throw new Error(); await countOpname(opname.id, [{ item_id: item.id, counted_quantity: count.value }]); await submitOpname(opname.id); await load() } catch { error.value = 'Gagal membuat atau submit opname.' } }
async function approve(id: number) { try { await approveOpname(id); await load() } catch { error.value = 'Approval gagal, mungkin snapshot sudah stale.' } }
onMounted(load)
</script>
<template><DashboardLayout><h1 class="mb-1 font-display text-2xl font-semibold">Stock Opname</h1><p class="mb-5 text-sm text-stone-500">Physical truth is approved by Admin. Factory Plan uses a separate reconciliation type.</p><form v-if="auth.user?.role === 'gudang'" class="mb-5 grid max-w-xl gap-2" @submit.prevent="create"><WarehouseProductPicker v-model:product-id="productId" v-model:variation-id="variationId" /><input v-model.number="count" type="number" min="0" placeholder="Counted quantity" required /><button class="rounded bg-brand-600 px-3 py-2 text-white">Submit Opname</button></form><p v-if="error" class="mb-3 text-sm text-red-600">{{ error }}</p><div v-for="opname in opnames" :key="opname.id" class="mb-2 rounded border"><div class="flex items-center justify-between gap-2 p-3"><span>{{ opname.opname_number }} · {{ opname.opname_type }} · {{ opname.status }}</span><span class="flex gap-2"><button type="button" class="rounded bg-stone-100 px-3 py-1 text-sm" @click="toggleItems(opname.id)">{{ expandedId === opname.id ? 'Sembunyi' : `Items (${opname.items.length})` }}</button><button v-if="auth.user?.role === 'admin' && opname.status === 'submitted'" class="rounded bg-brand-600 px-3 py-1 text-white" @click="approve(opname.id)">Approve</button></span></div><ul v-if="expandedId === opname.id" class="space-y-1 border-t bg-stone-50 p-3 text-sm"><li v-for="item in opname.items" :key="item.id" class="flex items-center justify-between gap-2"><span><span class="font-medium">{{ itemName(item) }}</span><span v-if="item.variation?.label"> — {{ item.variation.label }}</span><span class="block text-xs text-stone-400">{{ skuLabel(itemSku(item)) }} · sistem {{ item.system_quantity }} · hitung {{ item.counted_quantity ?? '-' }}</span></span></li></ul></div></DashboardLayout></template>