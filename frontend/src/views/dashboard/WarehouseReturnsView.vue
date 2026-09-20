<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { inspectReturn, listReturns, type ReturnRequestRecord } from '@/api/returns'
import { skuLabel } from '@/utils/format'

const returns = ref<ReturnRequestRecord[]>([]); const good = ref<Record<number, number>>({}); const damaged = ref<Record<number, number>>({}); const error = ref('')
async function load() { returns.value = (await listReturns()).returns }
async function inspect(itemId: number, received: number) { try { await inspectReturn(itemId, { received_quantity: received, good_quantity: good.value[itemId] ?? 0, damaged_quantity: damaged.value[itemId] ?? 0 }); await load() } catch { error.value = 'Inspection gagal. Pastikan jumlah good + damaged sesuai jumlah diterima.' } }
onMounted(load)
</script>
<template><DashboardLayout><h1 class="mb-1 font-display text-2xl font-semibold">Warehouse Returns</h1><p class="mb-5 text-sm text-stone-500">Receive → inspect → restock only good units. Damaged units remain non-sellable and auditable.</p><p v-if="error" class="mb-3 text-sm text-red-600">{{ error }}</p><div v-for="request in returns" :key="request.id" class="mb-4 rounded-xl border bg-white p-4"><div class="mb-2">Return #{{ request.id }} · {{ request.status }}</div><div v-for="item in request.items" :key="item.id" class="mb-2 flex items-center gap-2 text-sm"><span class="flex-1"><span class="font-medium">{{ item.product_name }}</span><span v-if="item.variation_label"> — {{ item.variation_label }}</span><span class="block text-xs text-stone-400">{{ skuLabel(item.sku) }} · Qty {{ item.quantity_returned }} · {{ item.condition_status ?? 'pending' }}</span></span><input v-model.number="good[item.id]" class="w-20 border p-1" type="number" min="0" placeholder="Good" /><input v-model.number="damaged[item.id]" class="w-20 border p-1" type="number" min="0" placeholder="Damaged" /><button v-if="item.status === 'approved'" class="rounded bg-brand-600 px-3 py-1 text-white" @click="inspect(item.id, item.quantity_returned)">Inspect</button></div></div></DashboardLayout></template>