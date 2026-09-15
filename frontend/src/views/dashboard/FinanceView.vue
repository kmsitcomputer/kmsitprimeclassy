<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { getFinanceSummary, type FinanceSummary } from '@/api/reports'
import { formatRupiah } from '@/utils/format'

const loading = ref(true)
const summary = ref<FinanceSummary | null>(null)
const from = ref('')
const to = ref('')

async function load() {
  loading.value = true
  summary.value = await getFinanceSummary({ from: from.value || undefined, to: to.value || undefined })
  loading.value = false
}

onMounted(load)
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Keuangan</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">Ringkasan total transaksi dan refund seluruh agen.</p>

    <div class="mb-6 flex flex-wrap items-end gap-3">
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Dari
        <input v-model="from" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Sampai
        <input v-model="to" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <button type="button" class="rounded-lg bg-stone-800 px-3 py-2 text-xs font-medium text-white hover:bg-stone-900 dark:bg-stone-100 dark:text-stone-900" @click="load">
        Terapkan
      </button>
    </div>

    <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>

    <div v-else-if="summary" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
      <div class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm dark:border-stone-800 dark:bg-stone-900">
        <div class="mb-2 flex items-center gap-2 text-stone-500 dark:text-stone-400">
          <AppIcon name="box" :size="16" />
          <span class="text-xs font-medium uppercase tracking-wide">Total Order</span>
        </div>
        <p class="font-display text-2xl font-semibold text-stone-900 dark:text-stone-50">{{ summary.total_orders }}</p>
      </div>
      <div class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm dark:border-stone-800 dark:bg-stone-900">
        <div class="mb-2 flex items-center gap-2 text-emerald-600 dark:text-emerald-400">
          <AppIcon name="cash" :size="16" />
          <span class="text-xs font-medium uppercase tracking-wide">Total Transaksi (Lunas)</span>
        </div>
        <p class="font-display text-2xl font-semibold text-stone-900 dark:text-stone-50">{{ formatRupiah(summary.total_transactions) }}</p>
      </div>
      <div class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm dark:border-stone-800 dark:bg-stone-900">
        <div class="mb-2 flex items-center gap-2 text-emerald-600 dark:text-emerald-400">
          <AppIcon name="cash" :size="16" />
          <span class="text-xs font-medium uppercase tracking-wide">Total Diterima (termasuk DP)</span>
        </div>
        <p class="font-display text-2xl font-semibold text-stone-900 dark:text-stone-50">{{ formatRupiah(summary.total_received) }}</p>
      </div>
      <div class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm dark:border-stone-800 dark:bg-stone-900">
        <div class="mb-2 flex items-center gap-2 text-amber-600 dark:text-amber-400">
          <AppIcon name="cash" :size="16" />
          <span class="text-xs font-medium uppercase tracking-wide">Sisa Pembayaran (Outstanding)</span>
        </div>
        <p class="font-display text-2xl font-semibold text-stone-900 dark:text-stone-50">{{ formatRupiah(summary.total_outstanding) }}</p>
        <p class="mt-1 text-xs text-stone-400">Termasuk sisa DP: {{ formatRupiah(summary.total_dp_outstanding) }}</p>
      </div>
      <div class="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm dark:border-stone-800 dark:bg-stone-900">
        <div class="mb-2 flex items-center gap-2 text-red-600 dark:text-red-400">
          <AppIcon name="cash" :size="16" />
          <span class="text-xs font-medium uppercase tracking-wide">Total Refund</span>
        </div>
        <p class="font-display text-2xl font-semibold text-stone-900 dark:text-stone-50">{{ formatRupiah(summary.total_refunds) }}</p>
      </div>
    </div>
  </DashboardLayout>
</template>
