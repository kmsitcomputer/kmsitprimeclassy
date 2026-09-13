<script setup lang="ts">
import { ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { getSalesCustomersReport, downloadSalesCustomersXlsx, type SalesCustomerReportRow } from '@/api/reports'
import { formatRupiah } from '@/utils/format'

/** Sales-only: every konsumen this sales sold to — never another sales' customers, never agen-level fee. */
const items = ref<SalesCustomerReportRow[]>([])
const loading = ref(true)
const currentPage = ref(1)
const lastPage = ref(1)
const totalKonsumen = ref(0)
const totalFee = ref(0)
const filters = ref({ from: '', to: '', search: '' })

function buildFilters() {
  return {
    from: filters.value.from || undefined,
    to: filters.value.to || undefined,
    search: filters.value.search || undefined,
  }
}

async function load(page = 1) {
  loading.value = true
  const result = await getSalesCustomersReport({ ...buildFilters(), page })
  items.value = result.items
  currentPage.value = result.currentPage
  lastPage.value = result.lastPage
  totalKonsumen.value = result.total
  totalFee.value = result.totalFee
  loading.value = false
}

load()

function download() {
  downloadSalesCustomersXlsx(buildFilters())
}
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Konsumen Saya</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
      Semua konsumen yang pernah membeli melalui Anda, beserta jumlah order dan fee yang Anda dapatkan dari masing-masing.
    </p>

    <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
      <div class="rounded-xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <p class="text-xs text-stone-400">Total Konsumen</p>
        <p class="mt-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">{{ totalKonsumen }}</p>
      </div>
      <div class="rounded-xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <p class="text-xs text-stone-400">Total Fee (sesuai filter)</p>
        <p class="mt-1 font-display text-xl font-semibold text-brand-700 dark:text-brand-300">{{ formatRupiah(totalFee) }}</p>
      </div>
    </div>

    <div class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Dari
        <input v-model="filters.from" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Sampai
        <input v-model="filters.to" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Cari
        <input v-model="filters.search" type="text" placeholder="Nama atau telepon konsumen" class="mt-1 block w-48 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
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
            <th class="px-4 py-2">Nama Konsumen</th>
            <th class="px-4 py-2">Telepon</th>
            <th class="px-4 py-2">Jumlah Order</th>
            <th class="px-4 py-2 text-right">Total Fee</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
          <tr v-for="row in items" :key="row.konsumen_id">
            <td class="px-4 py-2">{{ row.konsumen_name }}</td>
            <td class="px-4 py-2">{{ row.konsumen_phone }}</td>
            <td class="px-4 py-2">{{ row.order_count }}</td>
            <td class="px-4 py-2 text-right">{{ formatRupiah(row.total_fee) }}</td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="4" class="px-4 py-6 text-center text-stone-400">Tidak ada data.</td></tr>
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
