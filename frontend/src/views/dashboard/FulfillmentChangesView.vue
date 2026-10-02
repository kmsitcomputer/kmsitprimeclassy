<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { decideFulfillmentChange, listDiprosesOrders, listFulfillmentChangeProposals, proposeFulfillmentChange, type FulfillmentChangeProposal } from '@/api/fulfillmentChanges'
import type { Order, PaginationMeta } from '@/api/types'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const isGudang = computed(() => auth.user?.role === 'gudang')
const orders = ref<Order[]>([])
const proposals = ref<FulfillmentChangeProposal[]>([])
const orderMeta = ref<PaginationMeta | null>(null)
const proposalMeta = ref<PaginationMeta | null>(null)
const orderPage = ref(1)
const proposalPage = ref(1)
const proposalStatus = ref<'all' | 'pending' | 'approved' | 'rejected'>('pending')
const quantity = ref<Record<number, number>>({})
const dates = ref<Record<number, string>>({})
const reasons = ref<Record<number, string>>({})
const rejectReasons = ref<Record<number, string>>({})
const error = ref('')
const success = ref('')

async function load() {
  if (isGudang.value) {
    const result = await listDiprosesOrders(orderPage.value)
    orders.value = result.orders
    orderMeta.value = result.meta
  } else {
    const result = await listFulfillmentChangeProposals(proposalStatus.value, proposalPage.value)
    proposals.value = result.proposals
    proposalMeta.value = result.meta
  }
}

async function filterProposals() {
  proposalPage.value = 1
  await load()
}

async function submit(order: Order, item: Order['items'][number]) {
  error.value = ''; success.value = ''
  try {
    await proposeFulfillmentChange(order.id, item.id, {
      fulfilled_quantity: quantity.value[item.id] ?? item.fulfilled_quantity,
      requested_delivery_date: dates.value[item.id] || item.requested_delivery_date,
      reason: reasons.value[item.id]?.trim() || undefined,
    })
    success.value = 'Perubahan dikirim ke Admin untuk persetujuan.'
    await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal mengirim perubahan.' }
}

async function decide(proposal: FulfillmentChangeProposal, decision: 'approve' | 'reject') {
  error.value = ''; success.value = ''
  try {
    await decideFulfillmentChange(proposal.id, decision, rejectReasons.value[proposal.id]?.trim())
    success.value = decision === 'approve' ? 'Perubahan disetujui dan diterapkan.' : 'Perubahan ditolak; order tetap seperti semula.'
    await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Keputusan gagal.' }
}

onMounted(load)
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold">{{ isGudang ? 'Order Diproses' : 'Perubahan Fulfillment' }}</h1>
    <p class="mb-5 text-sm text-stone-500">{{ isGudang ? 'Ajukan jumlah pemenuhan dan tanggal kirim untuk ditinjau Admin.' : 'Tinjau perubahan operasional yang diajukan Gudang.' }}</p>
    <p v-if="error" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>
    <p v-if="success" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-600">{{ success }}</p>

    <section v-if="isGudang" class="grid gap-4">
      <article v-for="order in orders" :key="order.id" class="rounded-xl border border-stone-200 bg-white p-4">
        <div class="mb-3 flex flex-wrap justify-between gap-2"><strong>{{ order.order_no }}</strong><span class="text-sm text-stone-500">{{ order.recipient_name }}</span></div>
        <div v-for="item in order.items" :key="item.id" class="mb-3 grid gap-2 border-t border-stone-100 pt-3 md:grid-cols-[1fr_9rem_12rem_auto] md:items-end">
          <div><div class="font-medium">{{ item.product_name }}<span v-if="item.variation_label"> · {{ item.variation_label }}</span></div><div class="text-xs text-stone-500">Saat ini: {{ item.fulfilled_quantity }} · {{ item.requested_delivery_date || 'Tanpa tanggal' }}</div></div>
          <label class="text-xs text-stone-500">Jumlah<input v-model.number="quantity[item.id]" :placeholder="String(item.fulfilled_quantity)" type="number" min="0" :max="item.original_quantity" class="mt-1 w-full rounded border border-stone-200 px-2 py-1 text-sm" /></label>
          <label class="text-xs text-stone-500">Tanggal kirim<input v-model="dates[item.id]" :placeholder="item.requested_delivery_date || 'YYYY-MM-DD'" type="date" class="mt-1 w-full rounded border border-stone-200 px-2 py-1 text-sm" /></label>
          <button type="button" class="rounded bg-stone-800 px-3 py-2 text-sm text-white" @click="submit(order, item)">Ajukan</button>
        </div>
      </article>
      <p v-if="!orders.length" class="text-sm text-stone-400">Tidak ada order diproses.</p>
      <div v-if="orderMeta && orderMeta.last_page > 1" class="flex items-center justify-end gap-3 text-sm">
        <button type="button" :disabled="orderPage <= 1" class="disabled:opacity-40" @click="orderPage--; load()">Sebelumnya</button>
        <span>{{ orderMeta.current_page }} / {{ orderMeta.last_page }}</span>
        <button type="button" :disabled="orderPage >= orderMeta.last_page" class="disabled:opacity-40" @click="orderPage++; load()">Berikutnya</button>
      </div>
    </section>

    <section v-else class="grid gap-3">
      <label class="flex items-center gap-2 text-sm text-stone-600">Status
        <select v-model="proposalStatus" class="rounded border border-stone-200 bg-white px-2 py-1" @change="filterProposals">
          <option value="pending">Pending</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
          <option value="all">Semua</option>
        </select>
      </label>
      <article v-for="proposal in proposals" :key="proposal.id" class="rounded-xl border border-stone-200 bg-white p-4">
        <div class="flex flex-wrap justify-between gap-2"><strong>{{ proposal.order_no }}</strong><span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs">{{ proposal.status }}</span></div>
        <div class="mt-2 text-sm">{{ proposal.order_item?.product_name || `Order item #${proposal.order_item_id}` }}<span v-if="proposal.order_item?.variation_label"> · {{ proposal.order_item.variation_label }}</span><span v-if="proposal.order_item?.sku"> · {{ proposal.order_item.sku }}</span></div>
        <p v-if="proposal.reason" class="mt-1 text-sm text-stone-500">Alasan Gudang: {{ proposal.reason }}</p>
        <div class="mt-3 grid gap-2 text-sm md:grid-cols-2"><div>CURRENT: {{ proposal.current.fulfilled_quantity }} · {{ proposal.current.requested_delivery_date || 'Tanpa tanggal' }}</div><div>PROPOSED: {{ proposal.proposed.fulfilled_quantity }} · {{ proposal.proposed.requested_delivery_date || 'Tanpa tanggal' }}</div></div>
        <div class="mt-2 text-xs text-stone-500">Gudang: {{ proposal.proposer?.name || '-' }}<span v-if="proposal.decider"> · Diputuskan oleh {{ proposal.decider.name }}</span></div>
        <p v-if="proposal.decision_reason" class="mt-1 text-sm text-stone-600">Alasan keputusan: {{ proposal.decision_reason }}</p>
        <div class="mt-3 flex flex-wrap gap-2" v-if="proposal.status === 'pending'"><button type="button" class="rounded bg-emerald-600 px-3 py-2 text-sm text-white" @click="decide(proposal, 'approve')">Setujui</button><input v-model="rejectReasons[proposal.id]" placeholder="Alasan penolakan" class="rounded border border-stone-200 px-2 py-1 text-sm" /><button type="button" class="rounded bg-red-600 px-3 py-2 text-sm text-white" @click="decide(proposal, 'reject')">Tolak</button></div>
      </article>
      <p v-if="!proposals.length" class="text-sm text-stone-400">Tidak ada proposal.</p>
      <div v-if="proposalMeta && proposalMeta.last_page > 1" class="flex items-center justify-end gap-3 text-sm">
        <button type="button" :disabled="proposalPage <= 1" class="disabled:opacity-40" @click="proposalPage--; load()">Sebelumnya</button>
        <span>{{ proposalMeta.current_page }} / {{ proposalMeta.last_page }}</span>
        <button type="button" :disabled="proposalPage >= proposalMeta.last_page" class="disabled:opacity-40" @click="proposalPage++; load()">Berikutnya</button>
      </div>
    </section>
  </DashboardLayout>
</template>