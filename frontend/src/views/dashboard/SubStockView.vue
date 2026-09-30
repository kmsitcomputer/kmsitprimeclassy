<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import {
  createSubStockRequest,
  listSubStockRequests,
  mySubStock,
  replenishmentTargets,
  subStockAction,
  type SubStockRequest,
  type SubStockRequestItem,
  type SubStockRow,
  type SubStockTarget,
} from '@/api/subStock'
import { ApiError } from '@/api/client'
import { skuLabel } from '@/utils/format'

const subLocation = ref<{ id: number; code: string; name: string } | null>(null)
const stocks = ref<SubStockRow[]>([])
const targets = ref<SubStockTarget[]>([])
const requests = ref<SubStockRequest[]>([])
// loading | error | ready (successful-empty is ready + zero rows). `noLocation` is the backend 404 for a Sub without an active location.
const stockState = ref<'loading' | 'error' | 'ready'>('loading')
const stockError = ref('')
const noLocation = ref(false)
const requestsState = ref<'loading' | 'error' | 'ready'>('loading')
const requestsError = ref('')
const requestMeta = ref({ current_page: 1, last_page: 1, total: 0 })

const direction = ref<'replenish' | 'return'>('replenish')
const note = ref('')
const draftQuantities = ref<Record<string, number>>({})
watch(direction, () => { draftQuantities.value = {} })
const error = ref('')
const success = ref('')
const submitting = ref(false)

function itemName(item: SubStockRequestItem | SubStockRow | SubStockTarget): string {
  return item.variation?.sku
    ? `${item.product?.name ?? 'Produk'} — ${item.variation.sku}`
    : (item.product?.name ?? (item.product_variation_id != null ? `Varian #${item.product_variation_id}` : `Produk #${item.product_id}`))
}

function rowKey(row: SubStockRow | SubStockTarget): string {
  return row.product_variation_id != null ? `v:${row.product_variation_id}` : `p:${row.product_id}`
}

function statusLabel(status: string): string {
  return {
    requested: 'Menunggu Admin', approved: 'Disetujui — menunggu Gudang', rejected: 'Ditolak', cancelled: 'Dibatalkan',
    executed: 'Diproses Gudang', received: 'Diterima',
  }[status] ?? status
}

function statusClass(status: string): string {
  return {
    requested: 'bg-amber-100 text-amber-800', approved: 'bg-blue-100 text-blue-800', rejected: 'bg-red-100 text-red-700',
    cancelled: 'bg-stone-200 text-stone-600', executed: 'bg-indigo-100 text-indigo-800', received: 'bg-emerald-100 text-emerald-800',
  }[status] ?? 'bg-stone-100 text-stone-600'
}

// Return draws on the Sub's own stock; replenish draws on Agent Transit targets (no existing Sub row required).
const draftRows = computed<(SubStockRow | SubStockTarget)[]>(() => (direction.value === 'replenish' ? targets.value : stocks.value))

async function loadStock() {
  stockState.value = 'loading'
  stockError.value = ''
  noLocation.value = false
  try {
    const [data, transitTargets] = await Promise.all([mySubStock(), replenishmentTargets()])
    subLocation.value = data.sub_location
    stocks.value = data.stocks
    targets.value = transitTargets
    stockState.value = 'ready'
  } catch (e) {
    subLocation.value = null
    stocks.value = []
    targets.value = []
    stockState.value = 'error'
    noLocation.value = e instanceof ApiError && e.status === 404
    stockError.value = e instanceof Error ? e.message : 'Gagal memuat stok Sub.'
  }
}

async function loadRequests() {
  requestsState.value = 'loading'
  requestsError.value = ''
  try {
    const { requests: rows, meta } = await listSubStockRequests({ page: requestMeta.value.current_page })
    requests.value = rows
    requestMeta.value = meta
    requestsState.value = 'ready'
  } catch (e) {
    requests.value = []
    requestsState.value = 'error'
    requestsError.value = e instanceof Error ? e.message : 'Gagal memuat permintaan.'
  }
}

async function submitRequest() {
  if (submitting.value) return
  error.value = ''; success.value = ''
  const items = draftRows.value
    .filter((row) => (draftQuantities.value[rowKey(row)] ?? 0) > 0)
    .map((row) => ({
      product_id: row.product_variation_id ? undefined : (row.product_id ?? undefined),
      product_variation_id: row.product_variation_id ?? undefined,
      quantity: draftQuantities.value[rowKey(row)] ?? 0,
    }))
  if (!items.length) {
    error.value = 'Isi jumlah untuk minimal satu produk.'
    return
  }
  submitting.value = true
  try {
    await createSubStockRequest(direction.value, items, crypto.randomUUID(), note.value.trim() || undefined)
    draftQuantities.value = {}
    note.value = ''
    success.value = direction.value === 'replenish' ? 'Permintaan pengisian stok dikirim. Menunggu persetujuan Admin.' : 'Permintaan retur dikirim. Menunggu persetujuan Admin.'
    requestMeta.value.current_page = 1
    await loadRequests()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Gagal mengirim permintaan.'
  } finally {
    submitting.value = false
  }
}

async function cancelRequest(id: number) {
  error.value = ''; success.value = ''
  try {
    await subStockAction(id, 'cancel')
    success.value = 'Permintaan dibatalkan.'
    await loadRequests()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal membatalkan permintaan.' }
}

async function receiveRequest(id: number) {
  error.value = ''; success.value = ''
  try {
    await subStockAction(id, 'receive')
    success.value = 'Penerimaan dikonfirmasi.'
    await Promise.all([loadRequests(), loadStock()])
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal mengonfirmasi penerimaan.' }
}

function onPage(d: number) {
  requestMeta.value.current_page = Math.max(1, requestMeta.value.current_page + d)
  void loadRequests()
}

onMounted(async () => {
  await Promise.all([loadStock(), loadRequests()])
})
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold">Stok Sub Saya</h1>
    <p v-if="subLocation" class="mb-5 text-sm text-stone-500">{{ subLocation.name }} ({{ subLocation.code }})</p>
    <p v-if="error" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>
    <p v-if="success" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-600">{{ success }}</p>

    <!-- ================= Own Sub stock ================= -->
    <section class="mb-8 rounded-xl border border-stone-200 bg-white p-4">
      <h2 class="mb-3 font-display text-xl font-semibold">Stok Fisik Sub</h2>
      <div v-if="stockState === 'loading'" class="text-sm text-stone-400">Memuat stok Sub...</div>
      <div v-else-if="stockState === 'error'" class="rounded-lg border p-3 text-sm" :class="noLocation ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-red-200 bg-red-50 text-red-600'">
        {{ stockError }}
        <span v-if="noLocation"> Hubungi Agen/Admin untuk menetapkan Sub Location Anda.</span>
        <button v-else type="button" class="ml-2 underline" @click="loadStock">Coba lagi</button>
      </div>
      <template v-else>
        <div v-if="!stocks.length" class="mb-3 text-sm text-stone-400">Belum ada stok tercatat di lokasi Sub ini.</div>
        <div v-for="row in stocks" :key="rowKey(row)" class="mb-2 flex flex-wrap items-center gap-3 rounded-lg bg-stone-50 p-2 text-sm">
          <span class="min-w-0 flex-1 basis-48">
            <span class="font-medium">{{ itemName(row) }}</span>
            <span class="block text-xs text-stone-400">{{ skuLabel(row.product?.sku ?? row.variation?.sku ?? null) }}</span>
          </span>
          <span class="rounded bg-white px-2 py-1 text-xs">Fisik: <strong>{{ row.physical }}</strong></span>
          <span class="rounded bg-white px-2 py-1 text-xs">Reservasi: <strong>{{ row.reserved }}</strong></span>
          <span class="rounded bg-white px-2 py-1 text-xs">Sellable: <strong>{{ row.sellable }}</strong></span>
        </div>

        <h3 class="mb-2 mt-4 text-sm font-semibold">{{ direction === 'replenish' ? 'Pilih produk yang diminta (ketersediaan Transit dicek Gudang saat eksekusi)' : 'Pilih produk dari stok Sub Anda' }}</h3>
        <div v-if="!draftRows.length" class="mb-2 text-sm text-stone-400">{{ direction === 'replenish' ? 'Belum ada produk aktif yang dapat diminta.' : 'Tidak ada stok Sub yang dapat diretur.' }}</div>
        <div v-for="row in draftRows" :key="'d-' + rowKey(row)" class="mb-2 flex flex-wrap items-center gap-3 rounded-lg bg-stone-50 p-2 text-sm">
          <span class="min-w-0 flex-1 basis-48">
            <span class="font-medium">{{ itemName(row) }}</span>
            <span class="block text-xs text-stone-400">{{ skuLabel(row.product?.sku ?? row.variation?.sku ?? null) }}</span>
          </span>
          <span v-if="'current_transit' in row" class="rounded bg-white px-2 py-1 text-xs">Transit saat ini: <strong>{{ row.current_transit }}</strong></span>
          <span v-else class="rounded bg-white px-2 py-1 text-xs">Sellable: <strong>{{ row.sellable }}</strong></span>
          <input v-model.number="draftQuantities[rowKey(row)]" type="number" min="0" class="w-24 rounded-lg border border-stone-200 p-2" placeholder="Jml" :disabled="submitting" />
        </div>
      </template>
      <div v-if="stockState === 'ready'" class="mt-3 flex flex-wrap items-center gap-2">
        <select v-model="direction" class="rounded-lg border border-stone-200 px-3 py-2 text-sm">
          <option value="replenish">Isi ulang (Transit → Sub)</option>
          <option value="return">Retur (Sub → Transit)</option>
        </select>
        <input v-model="note" type="text" placeholder="Catatan (opsional)" class="min-w-0 flex-1 rounded-lg border border-stone-200 px-3 py-2 text-sm" />
        <button type="button" :disabled="submitting || !draftRows.length" class="rounded-xl bg-brand-600 px-4 py-2 font-semibold text-white disabled:opacity-40" @click="submitRequest">Kirim Permintaan</button>
      </div>
    </section>

    <!-- ================= Own requests ================= -->
    <section class="rounded-xl border border-stone-200 bg-white p-4">
      <h2 class="mb-3 font-display text-xl font-semibold">Permintaan Saya</h2>
      <div v-if="requestsState === 'loading'" class="text-sm text-stone-400">Memuat permintaan...</div>
      <div v-else-if="requestsState === 'error'" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-600">
        {{ requestsError }}
        <button type="button" class="ml-2 underline" @click="loadRequests">Coba lagi</button>
      </div>
      <div v-else-if="!requests.length" class="text-sm text-stone-400">Belum ada permintaan.</div>
      <ul v-else class="grid gap-3">
        <li v-for="request in requests" :key="request.id" class="rounded-xl border border-stone-100 p-3">
          <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm">
            <span class="font-medium">{{ request.request_number }}</span>
            <span class="text-xs text-stone-400">{{ request.direction === 'replenish' ? 'Isi ulang' : 'Retur' }}</span>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(request.status)">{{ statusLabel(request.status) }}</span>
          </div>
          <ul class="grid gap-1 text-xs text-stone-500">
            <li v-for="item in request.items" :key="item.id">{{ itemName(item) }} · {{ item.quantity }}</li>
          </ul>
          <p v-if="request.status === 'rejected' && request.rejection_reason" class="mt-1 text-xs text-red-600">Alasan: {{ request.rejection_reason }}</p>
          <div class="mt-2 flex gap-2">
            <button v-if="request.status === 'requested'" type="button" class="rounded-lg bg-stone-200 px-3 py-1 text-xs" @click="cancelRequest(request.id)">Batalkan</button>
            <button v-if="request.status === 'executed' && request.direction === 'replenish'" type="button" class="rounded-lg bg-emerald-600 px-3 py-1 text-xs text-white" @click="receiveRequest(request.id)">Konfirmasi Terima</button>
          </div>
        </li>
      </ul>
      <div v-if="requestsState === 'ready' && requestMeta.last_page > 1" class="mt-3 flex items-center gap-2 text-sm">
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="requestMeta.current_page <= 1" @click="onPage(-1)">Prev</button>
        <span>Halaman {{ requestMeta.current_page }} / {{ requestMeta.last_page }} ({{ requestMeta.total }})</span>
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="requestMeta.current_page >= requestMeta.last_page" @click="onPage(1)">Next</button>
      </div>
    </section>
  </DashboardLayout>
</template>
