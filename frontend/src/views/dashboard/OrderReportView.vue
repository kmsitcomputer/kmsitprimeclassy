<script setup lang="ts">
import { ref, computed } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AgentPicker from '@/components/ui/AgentPicker.vue'
import { getOrdersReport, downloadReportXlsx, type TransactionReportRow } from '@/api/reports'
import { formatRupiah } from '@/utils/format'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

const items = ref<TransactionReportRow[]>([])
const loading = ref(true)
const currentPage = ref(1)
const lastPage = ref(1)
const filters = ref({
  from: '',
  to: '',
  delivery_date_from: '',
  delivery_date_to: '',
  item_status: '',
  order_status: '',
  agent_id: undefined as number | undefined,
})

function buildFilters() {
  return {
    from: filters.value.from || undefined,
    to: filters.value.to || undefined,
    delivery_date_from: filters.value.delivery_date_from || undefined,
    delivery_date_to: filters.value.delivery_date_to || undefined,
    item_status: filters.value.item_status || undefined,
    order_status: filters.value.order_status || undefined,
    agent_id: filters.value.agent_id,
  }
}

async function load(page = 1) {
  loading.value = true
  const result = await getOrdersReport({ ...buildFilters(), page })
  items.value = result.items
  currentPage.value = result.currentPage
  lastPage.value = result.lastPage
  loading.value = false
}

load()

function download() {
  downloadReportXlsx('transactions', buildFilters())
}
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Laporan Order</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
      Semua transaksi (per item order) di jaringan Anda, termasuk sales, korsal, dan kurir yang menangani.
    </p>

    <div class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Order Dari
        <input v-model="filters.from" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Order Sampai
        <input v-model="filters.to" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Kirim Dari
        <input v-model="filters.delivery_date_from" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Kirim Sampai
        <input v-model="filters.delivery_date_to" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Status Item
        <select v-model="filters.item_status" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950">
          <option value="">Semua</option>
          <option value="diterima">Diterima</option>
          <option value="diproses">Diproses</option>
          <option value="dikirim">Dikirim</option>
          <option value="terkirim">Terkirim</option>
          <option value="pengembalian">Pengembalian</option>
          <option value="kembali">Kembali</option>
          <option value="dibatalkan">Dibatalkan</option>
        </select>
      </label>
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Status Order
        <select v-model="filters.order_status" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950">
          <option value="">Semua</option>
          <option v-for="status in ['diterima', 'diproses', 'dikirim', 'terkirim', 'pengembalian', 'kembali', 'dibatalkan']" :key="status" :value="status">{{ status }}</option>
        </select>
      </label>
      <AgentPicker v-if="isSuperAdmin" v-model="filters.agent_id" />
      <button type="button" class="rounded-lg bg-stone-800 px-3 py-2 text-xs font-medium text-white hover:bg-stone-900 dark:bg-stone-100 dark:text-stone-900" @click="load()">
        Terapkan
      </button>
      <button type="button" class="ml-auto flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-xs font-medium text-white hover:bg-brand-700" @click="download">
        <AppIcon name="download" :size="14" /> Unduh .xlsx
      </button>
    </div>

    <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>

    <div v-else class="overflow-x-auto rounded-xl border border-stone-200 dark:border-stone-800">
      <table class="w-full text-left text-sm">
        <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
          <tr>
            <th class="px-4 py-2">Order No</th>
            <th class="px-4 py-2">Tanggal</th>
            <th class="px-4 py-2">SKU</th>
            <th class="px-4 py-2">Produk</th>
            <th class="px-4 py-2 text-right">Harga</th>
            <th class="px-4 py-2">Qty</th>
            <th class="px-4 py-2">Status Item</th>
            <th class="px-4 py-2 text-right">Subtotal</th>
            <th class="px-4 py-2">Konsumen</th>
            <th class="px-4 py-2">Tgl Kirim</th>
            <th class="px-4 py-2">Kurir</th>
            <th class="px-4 py-2">Status Order</th>
            <th class="px-4 py-2">Sales</th>
            <th class="px-4 py-2">Korsal</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
          <tr v-for="row in items" :key="row.order_item_id">
            <td class="px-4 py-2">{{ row.order_no }}</td>
            <td class="px-4 py-2 whitespace-nowrap">{{ row.order_date }}</td>
            <td class="px-4 py-2">{{ row.sku || '-' }}</td>
            <td class="px-4 py-2">{{ row.product }}</td>
            <td class="px-4 py-2 text-right">{{ formatRupiah(row.unit_price) }}</td>
            <td class="px-4 py-2">{{ row.quantity }}</td>
            <td class="px-4 py-2 capitalize">{{ row.item_status }}</td>
            <td class="px-4 py-2 text-right">{{ formatRupiah(row.subtotal) }}</td>
            <td class="px-4 py-2">{{ row.customer || '-' }}</td>
            <td class="px-4 py-2 whitespace-nowrap">{{ row.delivery_date }}</td>
            <td class="px-4 py-2">{{ row.courier }}</td>
            <td class="px-4 py-2 capitalize">{{ row.order_status }}</td>
            <td class="px-4 py-2">{{ row.sales }}</td>
            <td class="px-4 py-2">{{ row.korsal }}</td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="14" class="px-4 py-6 text-center text-stone-400">Tidak ada data.</td></tr>
        </tbody>
      </table>
    </div>

    <div v-if="lastPage > 1" class="mt-6 flex items-center justify-center gap-2 text-sm">
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700" :disabled="currentPage <= 1" @click="load(currentPage - 1)">
        Sebelumnya
      </button>
      <span class="text-stone-500">{{ currentPage }} / {{ lastPage }}</span>
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700" :disabled="currentPage >= lastPage" @click="load(currentPage + 1)">
        Berikutnya
      </button>
    </div>
  </DashboardLayout>
</template>
