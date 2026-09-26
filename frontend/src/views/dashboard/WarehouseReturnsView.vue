<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { inspectReturn, listReturns, type ReturnRequestRecord } from '@/api/returns'
import { skuLabel } from '@/utils/format'

const returns = ref<ReturnRequestRecord[]>([])
const good = ref<Record<number, number>>({})
const damaged = ref<Record<number, number>>({})
const error = ref('')
const success = ref('')
const working = ref(false)

function dispositionLabel(item: { disposition_status?: string }): string {
  if (item.disposition_status === 'restocked') return 'Sudah dimasukkan Transit'
  if (item.disposition_status === 'damaged_confirmed') return 'Rusak terkonfirmasi'
  if (item.disposition_status === 'pending_disposition') return 'Menunggu persetujuan Admin'
  return 'Belum diinspeksi'
}

async function load() {
  returns.value = (await listReturns()).returns
}

async function inspect(itemId: number, received: number) {
  if (working.value) return
  error.value = ''; success.value = ''
  working.value = true
  try {
    await inspectReturn(itemId, { received_quantity: received, good_quantity: good.value[itemId] ?? 0, damaged_quantity: damaged.value[itemId] ?? 0 })
    success.value = 'Hasil inspeksi tercatat. Menunggu persetujuan akhir Admin — stok belum berubah.'
    await load()
  } catch {
    error.value = 'Inspection gagal. Pastikan jumlah good + damaged sesuai jumlah diterima.'
  } finally {
    working.value = false
  }
}

onMounted(load)
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold">Warehouse Returns</h1>
    <p class="mb-5 text-sm text-stone-500">Terima → inspeksi kondisi → Admin finalisasi. Stok Transit hanya bertambah setelah Admin menyetujui.</p>
    <p v-if="error" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>
    <p v-if="success" class="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-600">{{ success }}</p>
    <div v-for="request in returns" :key="request.id" class="mb-4 rounded-xl border border-stone-200 bg-white p-4">
      <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
        <span>Return #{{ request.id }} · Order {{ request.order_no }}</span>
        <span class="text-xs text-stone-400">{{ request.customer_name }} · {{ request.status }}</span>
      </div>
      <div v-for="item in request.items" :key="item.id" class="mb-2 flex flex-wrap items-center gap-2 text-sm">
        <span class="min-w-0 flex-1 basis-48">
          <span class="font-medium">{{ item.product_name }}</span>
          <span v-if="item.variation_label"> — {{ item.variation_label }}</span>
          <span class="block text-xs text-stone-400">{{ skuLabel(item.sku) }} · Qty {{ item.quantity_returned }} · {{ item.condition_status ?? 'pending' }} · {{ dispositionLabel(item) }}</span>
          <span v-if="item.good_quantity || item.damaged_quantity" class="block text-xs text-stone-400">Baik {{ item.good_quantity ?? 0 }} · Rusak {{ item.damaged_quantity ?? 0 }}</span>
        </span>
        <template v-if="item.status === 'approved' && !item.inspected_at">
          <input v-model.number="good[item.id]" class="w-20 min-w-0 rounded-lg border border-stone-200 p-2" type="number" min="0" placeholder="Good" />
          <input v-model.number="damaged[item.id]" class="w-20 min-w-0 rounded-lg border border-stone-200 p-2" type="number" min="0" placeholder="Damaged" />
          <button type="button" :disabled="working" class="shrink-0 rounded-xl bg-brand-600 px-3 py-2 text-white disabled:opacity-40" @click="inspect(item.id, item.quantity_returned)">Inspect</button>
        </template>
        <span v-else class="shrink-0 text-xs text-stone-400">{{ dispositionLabel(item) }}</span>
      </div>
    </div>
  </DashboardLayout>
</template>
