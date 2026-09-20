<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import WarehouseProductPicker from '@/components/ui/WarehouseProductPicker.vue'
import { cancelTransfer, completeTransfer, createTransfer, listTransfers, type StockTransfer, type StockTransferItem } from '@/api/transfers'
import { listSubLocations, type SubLocation } from '@/api/warehouse'
import { skuLabel } from '@/utils/format'

const transfers = ref<StockTransfer[]>([])
const locations = ref<SubLocation[]>([]); const source = ref('transit'); const destination = ref('sub'); const sourceLocation = ref<number>(); const destinationLocation = ref<number>()
// DS-PBR-001: human-readable picker; IDs stay internal to the API contract.
const productId = ref<number | null>(null); const variationId = ref<number | null>(null); const quantity = ref(0); const reference = ref('')
const error = ref('')
const expandedId = ref<number | null>(null)

/* Product-oriented identity (PBR-003) — name/variant/SKU first, IDs secondary. */
function itemName(item: StockTransferItem): string {
  return item.product?.name ?? (item.product_variation_id != null ? `Varian #${item.product_variation_id}` : `Produk #${item.product_id}`)
}
function itemSku(item: StockTransferItem): string | null {
  return item.variation?.sku ?? item.product?.sku ?? null
}
function toggleItems(id: number) {
  expandedId.value = expandedId.value === id ? null : id
}
async function load() { try { transfers.value = (await listTransfers()).transfers } catch { error.value = 'Gagal memuat transfer.' } }
async function complete(id: number) { try { await completeTransfer(id); await load() } catch { error.value = 'Transfer tidak dapat diselesaikan.' } }
async function cancel(id: number) { try { await cancelTransfer(id); await load() } catch { error.value = 'Transfer tidak dapat dibatalkan.' } }
async function create() { try { if ((!productId.value && !variationId.value) || (productId.value && variationId.value) || quantity.value <= 0) throw new Error(); await createTransfer({ source_stock_type: source.value, source_sub_location_id: sourceLocation.value, destination_stock_type: destination.value, destination_sub_location_id: destinationLocation.value, reference: reference.value || undefined, items: [{ product_id: productId.value ?? undefined, product_variation_id: variationId.value ?? undefined, quantity: quantity.value }] }); await load() } catch { error.value = 'Transfer Sub tidak dapat dibuat.' } }
function print(handoverId?: number) { if (handoverId) window.open(`/api/v1/warehouse/handovers/${handoverId}/print`, '_blank', 'noopener') }
onMounted(async () => { await load(); locations.value = await listSubLocations() })
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold">Stock Transfer</h1>
    <p class="mb-5 text-sm text-stone-500">Transfer fisik dalam network Agent Anda.</p>
    <p v-if="error" class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
    <form class="mb-5 grid max-w-2xl gap-2 rounded-xl border p-4" @submit.prevent="create"><div class="flex gap-2"><select v-model="source"><option value="transit">Transit</option><option value="sub">Sub</option></select><select v-model="sourceLocation" :disabled="source !== 'sub'"><option :value="undefined">Main</option><option v-for="location in locations.filter((item) => item.is_active)" :key="location.id" :value="location.id">{{ location.code }}</option></select><span>→</span><select v-model="destination"><option value="transit">Transit</option><option value="sub">Sub</option></select><select v-model="destinationLocation" :disabled="destination !== 'sub'"><option :value="undefined">Main</option><option v-for="location in locations.filter((item) => item.is_active)" :key="location.id" :value="location.id">{{ location.code }}</option></select></div><WarehouseProductPicker v-model:product-id="productId" v-model:variation-id="variationId" /><input v-model.number="quantity" type="number" min="1" placeholder="Quantity" required /><input v-model="reference" placeholder="Reference" /><button class="rounded bg-brand-600 px-3 py-2 text-white">Create Transfer</button></form>
    <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white"><table class="w-full text-sm"><thead><tr><th class="p-3 text-left">Transfer</th><th class="p-3 text-left">Route</th><th class="p-3 text-left">Status</th><th class="p-3"></th></tr></thead><tbody><template v-for="transfer in transfers" :key="transfer.id"><tr class="border-t"><td class="p-3">{{ transfer.transfer_number }}</td><td class="p-3">{{ transfer.source_stock_type }} → {{ transfer.destination_stock_type }}</td><td class="p-3">{{ transfer.status }}</td><td class="p-3 text-right"><button type="button" class="mr-2 rounded bg-stone-100 px-3 py-1" @click="toggleItems(transfer.id)">{{ expandedId === transfer.id ? 'Sembunyi' : `Items (${transfer.items.length})` }}</button><button v-if="transfer.status === 'pending'" class="mr-2 rounded bg-brand-600 px-3 py-1 text-white" @click="complete(transfer.id)">Complete</button><button v-if="transfer.status === 'pending'" class="mr-2 rounded bg-stone-200 px-3 py-1" @click="cancel(transfer.id)">Cancel</button><button v-if="transfer.handover" class="rounded bg-stone-200 px-3 py-1" @click="print(transfer.handover.id)">Print Handover</button></td></tr><tr v-if="expandedId === transfer.id"><td colspan="4" class="bg-stone-50 p-3"><ul class="space-y-1 text-sm"><li v-for="(item, i) in transfer.items" :key="i" class="flex items-center justify-between gap-2"><span><span class="font-medium">{{ itemName(item) }}</span><span v-if="item.variation?.label"> — {{ item.variation.label }}</span><span class="block text-xs text-stone-400">{{ skuLabel(itemSku(item)) }}</span></span><span class="shrink-0 text-stone-500">× {{ item.quantity }}</span></li></ul></td></tr></template></tbody></table></div>
  </DashboardLayout>
</template>