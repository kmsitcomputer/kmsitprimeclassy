<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { approveProposal, listProposals, proposeFulfillment, rejectProposal, type FulfillmentProposal } from '@/api/proposals'
import { listStockRequests, type StockRequest } from '@/api/stockRequests'
import { useAuthStore } from '@/stores/auth'
import { skuLabel } from '@/utils/format'

const auth = useAuthStore()
const isAdmin = computed(() => auth.user?.role === 'admin')

const requests = ref<StockRequest[]>([])
const requestMeta = ref({ current_page: 1, last_page: 1, total: 0 })
const requestSearch = ref('')
const requestStatus = ref('')
const quantities = ref<Record<number, number>>({})
const proposals = ref<FulfillmentProposal[]>([])
const proposalMeta = ref({ current_page: 1, last_page: 1, total: 0 })
const proposalStatus = ref<'pending' | 'approved' | 'rejected' | ''>('pending')
const rejectReason = ref<Record<number, string>>({})
const error = ref('')
const success = ref('')
const submitting = ref(false)

function itemName(item: { product_name?: string | null; product?: { name: string } | null; product_variation_id?: number | null; product_id?: number | null }): string {
  return item.product_name ?? item.product?.name ?? (item.product_variation_id != null ? `Varian #${item.product_variation_id}` : `Produk #${item.product_id}`)
}

function proposalStatusLabel(status: string): string {
  return status === 'pending' ? 'Menunggu' : status === 'approved' ? 'Disetujui' : status === 'rejected' ? 'Ditolak' : status
}

function proposalStatusClass(status: string): string {
  return status === 'pending'
    ? 'bg-amber-100 text-amber-800'
    : status === 'approved'
      ? 'bg-emerald-100 text-emerald-800'
      : 'bg-red-100 text-red-700'
}

async function loadRequests() {
  const { requests: rows, meta } = await listStockRequests({
    ...(requestStatus.value ? { status: requestStatus.value } : {}),
    ...(requestSearch.value.trim() ? { search: requestSearch.value.trim() } : {}),
    page: requestMeta.value.current_page,
  })
  requests.value = rows
  requestMeta.value = meta
}

async function loadProposals(reset = false) {
  if (reset) proposalMeta.value.current_page = 1
  const { proposals: rows, meta } = await listProposals({
    ...(proposalStatus.value ? { status: proposalStatus.value } : {}),
    ...(!isAdmin.value ? { scope: 'mine' as const } : {}),
    page: proposalMeta.value.current_page,
  })
  proposals.value = rows
  proposalMeta.value = meta
}

async function submitProposal(request: StockRequest) {
  if (submitting.value) return
  error.value = ''; success.value = ''
  const items = request.items
    .filter((item) => (quantities.value[item.id] ?? 0) > 0)
    .map((item) => ({ item_id: item.id, quantity: Math.min(quantities.value[item.id] ?? 0, item.remaining_qty) }))
  if (!items.length) {
    error.value = 'Isi jumlah usulan untuk minimal satu item.'
    return
  }
  submitting.value = true
  try {
    await proposeFulfillment(request.id, items)
    for (const item of request.items) delete quantities.value[item.id]
    success.value = 'Usulan fulfillment dikirim. Menunggu persetujuan Admin — stok belum berubah.'
    await Promise.all([loadRequests(), loadProposals(true)])
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Gagal mengirim usulan.'
  } finally {
    submitting.value = false
  }
}

async function approve(id: number) {
  error.value = ''; success.value = ''
  try {
    await approveProposal(id)
    success.value = 'Proposal disetujui. Transit berkurang, Shipping bertambah, Reservasi dilepas.'
    await Promise.all([loadRequests(), loadProposals()])
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal menyetujui proposal.' }
}

async function reject(id: number) {
  error.value = ''; success.value = ''
  try {
    const reason = (rejectReason.value[id] ?? '').trim()
    if (!reason) throw new Error('Isi alasan penolakan.')
    await rejectProposal(id, reason)
    success.value = 'Proposal ditolak.'
    delete rejectReason.value[id]
    await loadProposals()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal menolak proposal.' }
}

function onRequestSearch() {
  requestMeta.value.current_page = 1
  void loadRequests()
}

function onProposalPage(d: number) {
  proposalMeta.value.current_page = Math.max(1, proposalMeta.value.current_page + d)
  void loadProposals()
}

onMounted(async () => {
  await Promise.all([loadRequests(), loadProposals()])
})
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold">Stock Requests</h1>
    <p class="mb-5 text-sm text-stone-500">Gudang mengusulkan · Admin menyetujui · Fulfill hanya dari Transit fisik.</p>
    <p v-if="error" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>
    <p v-if="success" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-600">{{ success }}</p>

    <!-- ================= ADMIN: pending proposals ================= -->
    <section v-if="isAdmin" class="mb-8 rounded-xl border border-stone-200 bg-white p-4">
      <h2 class="mb-3 font-display text-xl font-semibold">Pending Fulfillment Proposals</h2>
      <div class="mb-3 flex max-w-xl gap-2">
        <select v-model="proposalStatus" class="rounded-lg border border-stone-200 px-3 py-2 text-sm" @change="loadProposals(true)">
          <option value="pending">Menunggu</option>
          <option value="approved">Disetujui</option>
          <option value="rejected">Ditolak</option>
          <option value="">Semua</option>
        </select>
      </div>
      <div v-if="!proposals.length" class="text-sm text-stone-400">Belum ada proposal.</div>
      <ul class="grid gap-3">
        <li v-for="proposal in proposals" :key="proposal.id" class="rounded-xl border border-stone-100 p-3">
          <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm">
            <span class="font-medium">{{ proposal.request?.order?.order_no ?? proposal.request?.request_number ?? `Request #${proposal.stock_request_id}` }}</span>
            <span class="text-xs text-stone-400">{{ proposal.requester?.name ?? '' }} · {{ proposal.created_at ?? '' }}</span>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="proposalStatusClass(proposal.status)">{{ proposalStatusLabel(proposal.status) }}</span>
          </div>
          <ul class="grid gap-2">
            <li v-for="line in proposal.items" :key="line.id" class="flex flex-wrap items-center gap-3 rounded-lg bg-stone-50 p-2 text-sm">
              <img v-if="line.request_item?.product_image_url" :src="line.request_item.product_image_url" :alt="itemName(line.request_item)" class="h-12 w-12 shrink-0 rounded-xl object-cover" />
              <div class="min-w-0 flex-1 basis-48">
                <div class="font-medium">{{ line.request_item ? itemName(line.request_item) : 'Item' }}</div>
                <div v-if="line.request_item?.variation_label" class="text-xs text-stone-500">Varian: {{ line.request_item.variation_label }}</div>
                <div class="truncate text-xs text-stone-400">{{ skuLabel(line.request_item?.sku ?? null) }}</div>
                <div class="mt-1 grid grid-cols-2 gap-1 text-xs sm:grid-cols-4">
                  <span class="rounded bg-white px-2 py-1">Diminta: <strong>{{ line.request_item?.requested_qty ?? '—' }}</strong></span>
                  <span class="rounded bg-white px-2 py-1">Terpenuhi: <strong>{{ line.request_item?.fulfilled_qty ?? '—' }}</strong></span>
                  <span class="rounded bg-white px-2 py-1">Sisa: <strong>{{ line.request_item?.remaining_qty ?? '—' }}</strong></span>
                  <span class="rounded bg-white px-2 py-1">Usulan: <strong>+{{ line.quantity }}</strong></span>
                </div>
                <div class="mt-1 grid grid-cols-3 gap-1 text-xs">
                  <span class="rounded bg-white px-2 py-1">Transit: <strong>{{ line.request_item?.current_stock?.transit ?? '—' }}</strong></span>
                  <span class="rounded bg-white px-2 py-1">Shipping: <strong>{{ line.request_item?.current_stock?.shipping ?? '—' }}</strong></span>
                  <span class="rounded bg-white px-2 py-1">Reserved: <strong>{{ line.request_item?.current_stock?.reserved ?? '—' }}</strong></span>
                </div>
              </div>
              <div v-if="proposal.status === 'pending'" class="flex shrink-0 flex-wrap items-center gap-2">
                <button type="button" class="rounded-lg bg-emerald-600 px-3 py-1 text-sm text-white" @click="approve(proposal.id)">Setujui</button>
                <input v-model="rejectReason[proposal.id]" placeholder="Alasan tolak" class="rounded-lg border border-stone-200 bg-white px-2 py-1 text-sm" />
                <button type="button" class="rounded-lg bg-red-600 px-3 py-1 text-sm text-white" @click="reject(proposal.id)">Tolak</button>
              </div>
            </li>
          </ul>
        </li>
      </ul>
      <div v-if="proposalMeta.last_page > 1" class="mt-3 flex items-center gap-2 text-sm">
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="proposalMeta.current_page <= 1" @click="onProposalPage(-1)">Prev</button>
        <span>Halaman {{ proposalMeta.current_page }} / {{ proposalMeta.last_page }} ({{ proposalMeta.total }})</span>
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="proposalMeta.current_page >= proposalMeta.last_page" @click="onProposalPage(1)">Next</button>
      </div>
    </section>

    <!-- ================= GUDANG: transaction requests ================= -->
    <section v-if="!isAdmin" class="mb-6">
      <div class="mb-4 flex max-w-2xl gap-2">
        <input v-model="requestSearch" type="text" placeholder="Cari order / produk / SKU..." class="min-w-0 flex-1 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm" @keyup.enter="onRequestSearch" />
        <select v-model="requestStatus" class="shrink-0 rounded-lg border border-stone-200 px-3 py-2 text-sm" @change="onRequestSearch">
          <option value="">Semua</option>
          <option value="pending">Pending</option>
          <option value="partial">Partial</option>
          <option value="fulfilled">Fulfilled</option>
        </select>
        <button type="button" class="shrink-0 rounded-lg bg-stone-800 px-4 py-2 text-sm text-white" @click="onRequestSearch">Cari</button>
      </div>
      <div v-if="!requests.length" class="text-sm text-stone-400">Belum ada stock request.</div>
      <div v-for="request in requests" :key="request.id" class="mb-4 rounded-xl border border-stone-200 bg-white p-4">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
          <strong>{{ request.order?.order_no ?? request.request_number }}</strong>
          <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs">{{ request.status }}</span>
        </div>
        <div v-for="item in request.items" :key="item.id" class="mb-2 flex flex-wrap items-center gap-3 text-sm">
          <img v-if="item.product_image_url" :src="item.product_image_url" :alt="itemName(item)" class="h-10 w-10 shrink-0 rounded-lg object-cover" />
          <span class="min-w-0 flex-1 basis-48">
            <span class="font-medium">{{ itemName(item) }}</span>
            <span v-if="item.variation_label"> — {{ item.variation_label }}</span>
            <span class="block text-xs text-stone-400">{{ skuLabel(item.sku ?? item.sku_snapshot) }} · diminta {{ item.requested_qty }} · terpenuhi {{ item.fulfilled_qty }} · sisa {{ item.remaining_qty }}</span>
            <span class="block text-xs text-stone-400">Transit {{ item.current_stock?.transit ?? '—' }} · Shipping {{ item.current_stock?.shipping ?? '—' }} · Reserved {{ item.current_stock?.reserved ?? '—' }}</span>
          </span>
          <input v-model.number="quantities[item.id]" class="w-24 min-w-0 rounded-lg border border-stone-200 p-2" type="number" min="0" :max="item.remaining_qty" :disabled="item.remaining_qty === 0 || submitting" />
        </div>
        <button v-if="request.status === 'pending' || request.status === 'partial'" type="button" :disabled="submitting" class="rounded-xl bg-brand-600 px-4 py-2 font-semibold text-white disabled:opacity-40" @click="submitProposal(request)">Ajukan ke Admin</button>
      </div>
      <div v-if="requestMeta.last_page > 1" class="mt-3 flex items-center gap-2 text-sm">
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="requestMeta.current_page <= 1" @click="requestMeta.current_page--; loadRequests()">Prev</button>
        <span>Halaman {{ requestMeta.current_page }} / {{ requestMeta.last_page }} ({{ requestMeta.total }})</span>
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="requestMeta.current_page >= requestMeta.last_page" @click="requestMeta.current_page++; loadRequests()">Next</button>
      </div>
    </section>

    <!-- ================= GUDANG: proposal history ================= -->
    <section v-if="!isAdmin" class="rounded-xl border border-stone-200 bg-white p-4">
      <h2 class="mb-3 font-semibold">Riwayat Usulan Saya</h2>
      <div v-if="!proposals.length" class="text-sm text-stone-400">Belum ada usulan.</div>
      <ul v-else class="grid gap-2">
        <li v-for="proposal in proposals" :key="proposal.id" class="rounded-xl border border-stone-100 p-2 text-sm">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="font-medium">{{ proposal.request?.order?.order_no ?? proposal.request?.request_number ?? '' }}</span>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="proposalStatusClass(proposal.status)">{{ proposalStatusLabel(proposal.status) }}</span>
          </div>
          <div v-for="line in proposal.items" :key="line.id" class="text-xs text-stone-500">
            {{ line.request_item ? itemName(line.request_item) : '' }} · +{{ line.quantity }}
            <span v-if="proposal.status === 'rejected' && proposal.rejection_reason"> · Alasan: {{ proposal.rejection_reason }}</span>
          </div>
        </li>
      </ul>
    </section>
  </DashboardLayout>
</template>
