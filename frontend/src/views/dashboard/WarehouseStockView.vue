<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { adjustFactoryPlan, getWarehouseSetting, listWarehouseStock, receiveTransit, setWarehouseSetting, type WarehouseRow } from '@/api/warehouse'
import { useAuthStore } from '@/stores/auth'

const rows = ref<WarehouseRow[]>([])
const auth = useAuthStore()
const planEnabled = ref(false)
const kind = ref<'transit' | 'factory_plan'>('transit')
const productId = ref<number>()
const variationId = ref<number>()
const quantity = ref(0)
const reference = ref('')
const error = ref('')
const success = ref('')

async function load() {
  rows.value = await listWarehouseStock(kind.value)
  planEnabled.value = (await getWarehouseSetting()).factory_plan_enabled
}
async function submit() {
  error.value = ''; success.value = ''
  try {
    if ((!productId.value && !variationId.value) || (productId.value && variationId.value) || !quantity.value || !reference.value.trim()) throw new Error('Isi tepat satu target, jumlah, dan referensi.')
    if (kind.value === 'transit') await receiveTransit({ product_id: productId.value, variation_id: variationId.value, quantity: quantity.value, reference: reference.value.trim() })
    else await adjustFactoryPlan({ product_id: productId.value, variation_id: variationId.value, delta: quantity.value, reference: reference.value.trim() })
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
      <input v-model.number="productId" type="number" min="1" placeholder="Product ID (atau kosong)" />
      <input v-model.number="variationId" type="number" min="1" placeholder="Variation ID (atau kosong)" />
      <input v-model.number="quantity" type="number" :min="kind === 'transit' ? 1 : -999999" required :disabled="kind === 'factory_plan' && !planEnabled" />
      <input v-model="reference" placeholder="Referensi dokumen" required />
      <button class="rounded-lg bg-brand-600 px-4 py-2 text-white" :disabled="kind === 'factory_plan' && !planEnabled">Simpan</button>
      <p v-if="error" class="text-sm text-red-600">{{ error }}</p><p v-if="success" class="text-sm text-emerald-600">{{ success }}</p>
    </form>
    <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white"><table class="w-full text-sm"><thead><tr><th class="p-3 text-left">Target</th><th class="p-3 text-right">Quantity</th></tr></thead><tbody><tr v-for="row in rows" :key="row.id"><td class="p-3">{{ row.product_id ?? row.product_variation_id }}</td><td class="p-3 text-right">{{ row.quantity }}</td></tr></tbody></table></div>
  </DashboardLayout>
</template>