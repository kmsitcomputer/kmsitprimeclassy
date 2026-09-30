<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import {
  createSubStockRequest,
  listSubStockRequests,
  mySubStock,
  subStockAction,
  type SubStockRequest,
  type SubStockRequestItem,
  type SubStockRow,
} from '@/api/subStock'
import { skuLabel } from '@/utils/format'

const subLocation = ref<{ id: number; code: string; name: string } | null>(null)
const stocks = ref<SubStockRow[]>([])
const requests = ref<SubStockRequest[]>([])
const requestMeta = ref({ current_page: 1, last_page: 1, total: 0 })

const direction = ref<'replenish' | 'return'>('replenish')
const note = ref('')
const draftQuantities = ref<Record<string, number>>({})
const error = ref('')
const success = ref('')
const submitting = ref(false)

function itemName(item: SubStockRequestItem | SubStockRow): string {
  return item.variation?.sku
    ? `${item.product?.name ?? 'Produk'} — ${item.variation.sku}`
    : (item.product?.name ?? (item.product_variation_id != null ? `Varian #${item.product_variation_id}` : `Produk #${item.product_id}`))
}

function rowKey(row: SubStockRow): string {
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

async function loadStock() {
  const data = await mySubStock()
  subLocation.value = data.sub_location
  stocks.value = data.stocks
}

async function loadRequests() {
  const { requests: rows, meta } = await listSubStockRequests({ page: requestMeta.value.current_page })
  requests.value = rows
  requestMeta.value = meta
}

async function submitRequest() {
  if (submitting.value) return
  error.value = ''; success.value = ''
  const items = stocks.value
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
      <div v-if="!stocks.length" class="text-sm text-stone-400">Belum ada stok tercatat di lokasi Sub ini.</div>
      <div v-for="row in stocks" :key="rowKey(row)" class="mb-2 flex flex-wrap items-center gap-3 rounded-lg bg-stone-50 p-2 text-sm">
        <span class="min-w-0 flex-1 basis-48">
          <span class="font-medium">{{ itemName(row) }}</span>
          <span class="block text-xs text-stone-400">{{ skuLabel(row.product?.sku ?? row.variation?.sku ?? null) }}</span>
        </span>
        <span class="rounded bg-white px-2 py-1 text-xs">Fisik: <strong>{{ row.physical }}</strong></span>
        <span class="rounded bg-white px-2 py-1 text-xs">Reservasi: <strong>{{ row.reserved }}</strong></span>
        <span class="rounded bg-white px-2 py-1 text-xs">Sellable: <strong>{{ row.sellable }}</strong></span>
        <input v-model.number="draftQuantities[rowKey(row)]" type="number" min="0" class="w-24 rounded-lg border border-stone-200 p-2" placeholder="Jml" :disabled="submitting" />
      </div>
      <div class="mt-3 flex flex-wrap items-center gap-2">
        <select v-model="direction" class="rounded-lg border border-stone-200 px-3 py-2 text-sm">
          <option value="replenish">Isi ulang (Transit → Sub)</option>
          <option value="return">Retur (Sub → Transit)</option>
        </select>
        <input v-model="note" type="text" placeholder="Catatan (opsional)" class="min-w-0 flex-1 rounded-lg border border-stone-200 px-3 py-2 text-sm" />
        <button type="button" :disabled="submitting || !stocks.length" class="rounded-xl bg-brand-600 px-4 py-2 font-semibold text-white disabled:opacity-40" @click="submitRequest">Kirim Permintaan</button>
      </div>
    </section>

    <!-- ================= Own requests ================= -->
    <section class="rounded-xl border border-stone-200 bg-white p-4">
      <h2 class="mb-3 font-display text-xl font-semibold">Permintaan Saya</h2>
      <div v-if="!requests.length" class="text-sm text-stone-400">Belum ada permintaan.</div>
      <ul class="grid gap-3">
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
      <div v-if="requestMeta.last_page > 1" class="mt-3 flex items-center gap-2 text-sm">
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="requestMeta.current_page <= 1" @click="onPage(-1)">Prev</button>
        <span>Halaman {{ requestMeta.current_page }} / {{ requestMeta.last_page }} ({{ requestMeta.total }})</span>
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="requestMeta.current_page >= requestMeta.last_page" @click="onPage(1)">Next</button>
      </div>
    </section>
  </DashboardLayout>
</template>
