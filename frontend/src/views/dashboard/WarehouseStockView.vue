<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import WarehouseProductPicker from '@/components/ui/WarehouseProductPicker.vue'
import { adjustFactoryPlan, getWarehouseSetting, listWarehouseStock, receiveTransit, setWarehouseSetting, type WarehouseRow } from '@/api/warehouse'
import { useAuthStore } from '@/stores/auth'
import { skuLabel } from '@/utils/format'

const rows = ref<WarehouseRow[]>([])
const auth = useAuthStore()
const planEnabled = ref(false)
const kind = ref<'transit' | 'factory_plan'>('transit')
const search = ref('')
// DS-PBR-001: staff pick a human-readable product/variant in the picker;
// the raw DB IDs below stay internal, submitted to the existing API contract.
const productId = ref<number | null>(null)
const variationId = ref<number | null>(null)
const quantity = ref(0)
const reference = ref('')
const error = ref('')
const success = ref('')

/* Product-oriented identity (PBR-003) — name/variant/SKU first, IDs secondary. */
function productName(row: WarehouseRow): string {
  return row.product_name ?? row.product?.name ?? (row.product_variation_id != null ? `Varian #${row.product_variation_id}` : `Produk #${row.product_id}`)
}
function variationLabel(row: WarehouseRow): string | null {
  return row.variation_label ?? row.variation?.label ?? null
}
function sku(row: WarehouseRow): string | null {
  return row.sku ?? row.variation?.sku ?? row.product?.sku ?? null
}

async function load() {
  rows.value = await listWarehouseStock(kind.value, search.value.trim() || undefined)
  planEnabled.value = (await getWarehouseSetting()).factory_plan_enabled
}
async function submit() {
  error.value = ''; success.value = ''
  try {
    if ((!productId.value && !variationId.value) || (productId.value && variationId.value) || !quantity.value || !reference.value.trim()) throw new Error('Pilih produk/varian, isi jumlah, dan referensi.')
    if (kind.value === 'transit') await receiveTransit({ product_id: productId.value ?? undefined, variation_id: variationId.value ?? undefined, quantity: quantity.value, reference: reference.value.trim() })
    else await adjustFactoryPlan({ product_id: productId.value ?? undefined, variation_id: variationId.value ?? undefined, delta: quantity.value, reference: reference.value.trim() })
    success.value = 'Perubahan berhasil disimpan.'; await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal menyimpan perubahan.' }
}
async function togglePlan() {
  try { planEnabled.value = (await setWarehouseSetting(!planEnabled.value)).factory_plan_enabled; await load() } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal mengubah status Plan Pabrik.' }
}
onMounted(load)
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold">Warehouse Stock</h1>
    <div class="mb-5 flex items-center gap-3 text-sm text-stone-500"><span>Plan Pabrik: {{ planEnabled ? 'ACTIVE' : 'INACTIVE' }}</span><button v-if="auth.user?.role === 'admin'" type="button" class="rounded-lg bg-stone-200 px-3 py-1" @click="togglePlan">Toggle</button></div>
    <form class="mb-6 grid max-w-xl gap-3 rounded-xl border border-stone-200 bg-white p-4" @submit.prevent="submit">
      <select v-model="kind" @change="load"><option value="transit">Stok Transit</option><option value="factory_plan">Plan Pabrik</option></select>
      <WarehouseProductPicker v-model:product-id="productId" v-model:variation-id="variationId" />
      <input v-model.number="quantity" type="number" :min="kind === 'transit' ? 1 : -999999" required :disabled="kind === 'factory_plan' && !planEnabled" />
      <input v-model="reference" placeholder="Referensi dokumen" required />
      <button class="rounded-lg bg-brand-600 px-4 py-2 text-white" :disabled="kind === 'factory_plan' && !planEnabled">Simpan</button>
      <p v-if="error" class="text-sm text-red-600">{{ error }}</p><p v-if="success" class="text-sm text-emerald-600">{{ success }}</p>
    </form>
    <div class="mb-5 flex max-w-xl gap-2">
      <input v-model="search" type="text" placeholder="Cari nama produk / SKU..." class="flex-1 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm" @keyup.enter="load" />
      <button type="button" class="rounded-lg bg-stone-800 px-4 py-2 text-sm text-white" @click="load">Cari</button>
    </div>
    <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white"><table class="w-full text-sm"><thead><tr><th class="p-3 text-left">Produk</th><th class="p-3 text-right">Quantity</th></tr></thead><tbody><tr v-for="row in rows" :key="row.id"><td class="p-3"><div class="flex items-center gap-3"><img v-if="row.product_image_url" :src="row.product_image_url" :alt="productName(row)" class="h-10 w-10 shrink-0 rounded-lg object-cover" /><div><div class="font-medium text-stone-700">{{ productName(row) }}</div><div v-if="variationLabel(row)" class="text-xs text-stone-500">Varian: {{ variationLabel(row) }}</div><div class="text-xs text-stone-400">{{ skuLabel(sku(row)) }}</div></div></div></td><td class="p-3 text-right">{{ row.quantity }}</td></tr></tbody></table></div>
  </DashboardLayout>
</template>