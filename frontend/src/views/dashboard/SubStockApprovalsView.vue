<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { listSubStockRequests, subStockAction, type SubStockRequest, type SubStockRequestItem } from '@/api/subStock'
import { skuLabel } from '@/utils/format'

const requests = ref<SubStockRequest[]>([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const status = ref<'requested' | ''>('requested')
const rejectReason = ref<Record<number, string>>({})
const error = ref('')
const success = ref('')

function itemName(item: SubStockRequestItem): string {
  return item.variation?.sku
    ? `${item.product?.name ?? 'Produk'} — ${item.variation.sku}`
    : (item.product?.name ?? (item.product_variation_id != null ? `Varian #${item.product_variation_id}` : `Produk #${item.product_id}`))
}

async function load(reset = false) {
  if (reset) meta.value.current_page = 1
  const { requests: rows, meta: m } = await listSubStockRequests({ status: status.value || undefined, page: meta.value.current_page })
  requests.value = rows
  meta.value = m
}

async function approve(id: number) {
  error.value = ''; success.value = ''
  try {
    await subStockAction(id, 'approve')
    success.value = 'Permintaan disetujui.'
    await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal menyetujui permintaan.' }
}

async function reject(id: number) {
  error.value = ''; success.value = ''
  try {
    const reason = (rejectReason.value[id] ?? '').trim()
    if (!reason) throw new Error('Isi alasan penolakan.')
    await subStockAction(id, 'reject', { reason })
    delete rejectReason.value[id]
    success.value = 'Permintaan ditolak.'
    await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal menolak permintaan.' }
}

function onPage(d: number) {
  meta.value.current_page = Math.max(1, meta.value.current_page + d)
  void load()
}

onMounted(() => load())
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold">Persetujuan Stok Sub</h1>
    <p class="mb-5 text-sm text-stone-500">Sales-Kurir-Sub mengajukan · Admin menyetujui · Gudang mengeksekusi.</p>
    <p v-if="error" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>
    <p v-if="success" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-600">{{ success }}</p>

    <div class="mb-4 flex max-w-xl gap-2">
      <select v-model="status" class="rounded-lg border border-stone-200 px-3 py-2 text-sm" @change="load(true)">
        <option value="requested">Menunggu</option>
        <option value="approved">Disetujui</option>
        <option value="rejected">Ditolak</option>
        <option value="executed">Dieksekusi</option>
        <option value="received">Diterima</option>
        <option value="">Semua</option>
      </select>
    </div>

    <div v-if="!requests.length" class="text-sm text-stone-400">Tidak ada permintaan.</div>
    <ul class="grid gap-3">
      <li v-for="request in requests" :key="request.id" class="rounded-xl border border-stone-200 bg-white p-4">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm">
          <span class="font-medium">{{ request.request_number }}</span>
          <span class="text-xs text-stone-400">{{ request.subLocation?.name ?? '' }} · {{ request.requester?.name ?? '' }} · {{ request.direction === 'replenish' ? 'Isi ulang' : 'Retur' }}</span>
          <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs">{{ request.status }}</span>
        </div>
        <ul class="mb-2 grid gap-1 text-xs text-stone-500">
          <li v-for="item in request.items" :key="item.id">{{ itemName(item) }} · {{ skuLabel(item.product?.sku ?? item.variation?.sku ?? null) }} · {{ item.quantity }}</li>
        </ul>
        <p v-if="request.note" class="mb-2 text-xs text-stone-400">Catatan: {{ request.note }}</p>
        <div v-if="request.status === 'requested'" class="flex flex-wrap items-center gap-2">
          <button type="button" class="rounded-lg bg-emerald-600 px-3 py-1 text-sm text-white" @click="approve(request.id)">Setujui</button>
          <input v-model="rejectReason[request.id]" placeholder="Alasan tolak" class="rounded-lg border border-stone-200 bg-white px-2 py-1 text-sm" />
          <button type="button" class="rounded-lg bg-red-600 px-3 py-1 text-sm text-white" @click="reject(request.id)">Tolak</button>
        </div>
      </li>
    </ul>
    <div v-if="meta.last_page > 1" class="mt-3 flex items-center gap-2 text-sm">
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="meta.current_page <= 1" @click="onPage(-1)">Prev</button>
      <span>Halaman {{ meta.current_page }} / {{ meta.last_page }} ({{ meta.total }})</span>
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="meta.current_page >= meta.last_page" @click="onPage(1)">Next</button>
    </div>
  </DashboardLayout>
</template>
