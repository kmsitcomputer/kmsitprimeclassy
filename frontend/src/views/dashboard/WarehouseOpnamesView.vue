<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { approveOpname, countOpname, createOpname, listOpnames, submitOpname, type StockOpname } from '@/api/opnames'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore(); const opnames = ref<StockOpname[]>([]); const productId = ref<number>(); const count = ref(0); const error = ref('')
async function load() { opnames.value = await listOpnames() }
async function create() { try { if (!productId.value) throw new Error(); const opname = await createOpname({ opname_type: 'physical_opname', stock_type: 'transit', items: [{ product_id: productId.value }] }); const item = opname.items[0]; if (!item) throw new Error(); await countOpname(opname.id, [{ item_id: item.id, counted_quantity: count.value }]); await submitOpname(opname.id); await load() } catch { error.value = 'Gagal membuat atau submit opname.' } }
async function approve(id: number) { try { await approveOpname(id); await load() } catch { error.value = 'Approval gagal, mungkin snapshot sudah stale.' } }
onMounted(load)
</script>
<template><DashboardLayout><h1 class="mb-1 font-display text-2xl font-semibold">Stock Opname</h1><p class="mb-5 text-sm text-stone-500">Physical truth is approved by Admin. Factory Plan uses a separate reconciliation type.</p><form v-if="auth.user?.role === 'gudang'" class="mb-5 flex gap-2" @submit.prevent="create"><input v-model.number="productId" type="number" min="1" placeholder="Product ID" required /><input v-model.number="count" type="number" min="0" placeholder="Counted quantity" required /><button class="rounded bg-brand-600 px-3 py-2 text-white">Submit Opname</button></form><p v-if="error" class="mb-3 text-sm text-red-600">{{ error }}</p><div v-for="opname in opnames" :key="opname.id" class="mb-2 flex items-center justify-between rounded border p-3"><span>{{ opname.opname_number }} · {{ opname.opname_type }} · {{ opname.status }}</span><button v-if="auth.user?.role === 'admin' && opname.status === 'submitted'" class="rounded bg-brand-600 px-3 py-1 text-white" @click="approve(opname.id)">Approve</button></div></DashboardLayout></template>