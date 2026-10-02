<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { decideFulfillmentChange, getDiprosesOrder, listDiprosesOrders, listFulfillmentChangeProposals, proposeFulfillmentChange, type FulfillmentChangeProposal } from '@/api/fulfillmentChanges'
import type { DeliveryGroup, Order, OrderItem, PaginationMeta } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { formatDate } from '@/utils/format'

type ProposalMode = 'quantity' | 'date' | 'both'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const isGudang = computed(() => auth.user?.role === 'gudang')
const orders = ref<Order[]>([])
const selectedOrder = ref<Order | null>(null)
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
const proposalModes = ref<Record<number, ProposalMode | null>>({})
const error = ref('')
const success = ref('')

async function load() {
  if (isGudang.value) {
    const orderId = Number(route.params.orderId)
    if (Number.isInteger(orderId) && orderId > 0) {
      selectedOrder.value = await getDiprosesOrder(orderId)
      orders.value = []
      return
    }
    selectedOrder.value = null
    const result = await listDiprosesOrders(orderPage.value)
    orders.value = result.orders
    orderMeta.value = result.meta
  } else {
    const result = await listFulfillmentChangeProposals(proposalStatus.value, proposalPage.value)
    proposals.value = result.proposals
    proposalMeta.value = result.meta
  }
}

function itemsForGroup(order: Order, group: DeliveryGroup): OrderItem[] {
  const itemIds = new Set(group.item_ids)
  return order.items.filter((item) => itemIds.has(item.id))
}

watch(() => route.params.orderId, load)

async function filterProposals() {
  proposalPage.value = 1
  await load()
}

async function submit(order: Order, item: Order['items'][number]) {
  error.value = ''; success.value = ''
  try {
    const mode = proposalModes.value[item.id] ?? 'both'
    const payload: { fulfilled_quantity?: number; requested_delivery_date?: string | null; reason?: string } = {
      reason: reasons.value[item.id]?.trim() || undefined,
    }
    if (mode === 'quantity' || mode === 'both') payload.fulfilled_quantity = quantity.value[item.id] ?? item.fulfilled_quantity
    if (mode === 'date' || mode === 'both') payload.requested_delivery_date = dates.value[item.id] || item.requested_delivery_date
    await proposeFulfillmentChange(order.id, item.id, payload)
    proposalModes.value[item.id] = null
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
      <button v-if="selectedOrder" type="button" class="justify-self-start text-sm text-stone-600 underline" @click="router.push({ name: 'warehouse-fulfillment-changes' })">Kembali ke daftar order</button>
      <article v-for="order in selectedOrder ? [selectedOrder] : orders" :key="order.id" class="rounded-xl border border-stone-200 bg-white p-4">
        <div class="mb-3 flex flex-wrap justify-between gap-2">
          <button v-if="!selectedOrder" type="button" class="font-semibold underline" @click="router.push({ name: 'warehouse-order-detail', params: { orderId: order.id } })">{{ order.order_no }}</button>
          <strong v-else>{{ order.order_no }}</strong>
          <span class="text-sm text-stone-500">{{ order.recipient_name }}</span>
        </div>
        <div v-if="selectedOrder" class="mb-3 grid gap-1 text-sm text-stone-600 sm:grid-cols-2">
          <span>{{ order.recipient_phone }}</span>
          <span class="sm:col-span-2">{{ order.address }}</span>
        </div>
        <section v-for="group in order.delivery_groups ?? []" :key="`${order.id}-${group.delivery_date ?? 'none'}`" class="border-t border-stone-100 pt-3">
          <div class="mb-2 flex flex-wrap justify-between gap-2 text-sm">
            <strong>{{ group.delivery_date ? formatDate(group.delivery_date) : 'Tanpa tanggal' }}</strong>
            <span class="text-stone-500">{{ (group.delivery_methods ?? []).join(' · ') }}</span>
          </div>
          <p v-if="group.courier_names?.length" class="mb-2 text-xs text-stone-500">Kurir: {{ group.courier_names.join(', ') }}</p>
          <div v-for="item in itemsForGroup(order, group)" :key="item.id" class="mb-3 grid gap-2 border-t border-stone-100 pt-3 md:grid-cols-[1fr_9rem_12rem_auto] md:items-end">
            <div>
              <div class="font-medium">{{ item.product_name }}<span v-if="item.variation_label"> · {{ item.variation_label }}</span><span v-if="item.sku" class="ml-1 text-xs text-stone-400">{{ item.sku }}</span></div>
              <div class="text-xs text-stone-500">Pemenuhan: {{ item.fulfilled_quantity }} / {{ item.original_quantity }} order · {{ item.requested_delivery_date ? formatDate(item.requested_delivery_date) : 'Tanpa tanggal' }}</div>
            </div>
            <template v-if="selectedOrder">
              <div v-if="!proposalModes[item.id]" class="flex flex-wrap gap-2 md:col-span-3">
                <button type="button" class="text-sm font-medium text-stone-700 underline" @click="proposalModes[item.id] = 'quantity'">Ubah Jumlah Pemenuhan</button>
                <button type="button" class="text-sm font-medium text-stone-700 underline" @click="proposalModes[item.id] = 'date'">Ubah Tanggal Kirim</button>
                <button type="button" class="text-sm font-medium text-stone-700 underline" @click="proposalModes[item.id] = 'both'">Ajukan Keduanya</button>
              </div>
              <template v-else>
                <label v-if="proposalModes[item.id] !== 'date'" class="text-xs text-stone-500">Jumlah<input v-model.number="quantity[item.id]" :placeholder="String(item.fulfilled_quantity)" type="number" min="0" :max="item.original_quantity" class="mt-1 w-full rounded border border-stone-200 px-2 py-1 text-sm" /></label>
                <label v-if="proposalModes[item.id] !== 'quantity'" class="text-xs text-stone-500">Tanggal kirim<input v-model="dates[item.id]" :placeholder="item.requested_delivery_date || 'YYYY-MM-DD'" type="date" class="mt-1 w-full rounded border border-stone-200 px-2 py-1 text-sm" /></label>
                <button type="button" class="rounded bg-stone-800 px-3 py-2 text-sm text-white" @click="submit(order, item)">Ajukan Perubahan</button>
                <button type="button" class="rounded px-3 py-2 text-sm text-stone-600" @click="proposalModes[item.id] = null">Batal</button>
              </template>
            </template>
          </div>
        </section>
        <p v-if="selectedOrder && !(selectedOrder.delivery_groups ?? []).length" class="text-sm text-stone-400">Semua item order ini telah diproses.</p>
      </article>
      <p v-if="!selectedOrder && !orders.length" class="text-sm text-stone-400">Tidak ada order diproses.</p>
      <div v-if="!selectedOrder && orderMeta && orderMeta.last_page > 1" class="flex items-center justify-end gap-3 text-sm">
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