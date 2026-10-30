<script setup lang="ts">
/**
 * Laporan Jaringan Transaksi (Owner §7): network totals per role scope —
 * order-grained, no double counting, unattributed kept in separate groups.
 * Admin never sees agent fee anywhere: this report carries no fee columns at
 * all (agent fee lives only in /reports/fees/agent, which excludes Admin).
 */
import { computed, onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AgentPicker from '@/components/ui/AgentPicker.vue'
import { useAuthStore } from '@/stores/auth'
import {
  getNetworkTransactions,
  downloadReportXlsx,
  type NetworkTransactionsReport,
  type ReportFilters,
} from '@/api/reports'
import { formatRupiah, orderStatusLabel } from '@/utils/format'

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')
const loading = ref(false)
const error = ref('')
const report = ref<NetworkTransactionsReport | null>(null)
const filters = ref({ from: '', to: '', status: '', agent_id: undefined as number | undefined })

async function load(pageReset = true) {
  void pageReset
  loading.value = true
  error.value = ''
  try {
    const f: ReportFilters = { ...filters.value }
    if (!isSuperAdmin.value) delete f.agent_id
    report.value = await getNetworkTransactions(f)
  } catch {
    error.value = 'Gagal memuat laporan jaringan.'
  } finally {
    loading.value = false
  }
}
onMounted(() => load())
</script>

<template>
  <DashboardLayout>
    <p class="pc-eyebrow mb-1">Laporan Jaringan</p>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Transaksi Jaringan</h1>
    <p class="mb-4 text-sm text-stone-500 dark:text-stone-400">
      Total transaksi dalam cakupan jaringan Anda — tiap order dihitung tepat satu kali; order tanpa Sales/Korsal
      ditampilkan dalam kelompok terpisah.
    </p>

    <div class="mb-4 flex flex-wrap items-end gap-3">
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Dari
        <input v-model="filters.from" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" @change="load()" />
      </label>
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Sampai
        <input v-model="filters.to" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" @change="load()" />
      </label>
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Status Order
        <select v-model="filters.status" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" @change="load()">
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
      <AgentPicker v-if="isSuperAdmin" v-model="filters.agent_id" />
      <button type="button" class="rounded-lg bg-stone-800 px-3 py-2 text-xs font-medium text-white hover:bg-stone-900 dark:bg-stone-100 dark:text-stone-900" @click="load()">Terapkan</button>
      <button type="button" class="ml-auto flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-xs font-medium text-white hover:bg-brand-700" @click="downloadReportXlsx('network-transactions', filters)">
        <AppIcon name="download" :size="14" /> Unduh .xlsx
      </button>
    </div>

    <div v-if="loading" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <div v-for="i in 4" :key="i" class="h-20 animate-pulse rounded-xl border border-stone-200 bg-stone-100 dark:border-stone-800" />
    </div>
    <p v-else-if="error" class="pc-notice mb-4">{{ error }}</p>

    <template v-else-if="report">
      <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
          <p class="text-[11px] font-medium uppercase tracking-wide text-stone-400">Total Order</p>
          <p class="font-display text-lg font-semibold text-stone-900 dark:text-stone-50">{{ report.totals.order_count }}</p>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
          <p class="text-[11px] font-medium uppercase tracking-wide text-stone-400">Nilai Transaksi</p>
          <p class="font-display text-lg font-semibold text-stone-900 dark:text-stone-50">{{ formatRupiah(report.totals.total_amount) }}</p>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
          <p class="text-[11px] font-medium uppercase tracking-wide text-stone-400">Dibayar</p>
          <p class="font-display text-lg font-semibold text-emerald-600 dark:text-emerald-400">{{ formatRupiah(report.totals.paid_amount) }}</p>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
          <p class="text-[11px] font-medium uppercase tracking-wide text-stone-400">Outstanding (kanonis)</p>
          <p class="font-display text-lg font-semibold text-stone-900 dark:text-stone-50">{{ formatRupiah(report.totals.outstanding_amount) }}</p>
        </div>
      </div>
      <p class="pc-notice mb-4">
        Outstanding memakai piutang kanonis CollectibleReceivable (WS-7; order dibatalkan berkontribusi 0).
      </p>

      <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <div v-if="report.by_korsal" class="overflow-x-auto rounded-xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
          <p class="mb-3 text-[11px] font-medium uppercase tracking-wide text-stone-400">Per Korsal</p>
          <table class="w-full text-sm">
            <thead><tr class="text-left text-xs"><th class="pb-2 font-medium">Korsal</th><th class="pb-2 font-medium">Order</th><th class="pb-2 text-right font-medium">Nilai</th><th class="pb-2 text-right font-medium">Outstanding</th></tr></thead>
            <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
              <tr v-for="k in report.by_korsal" :key="'k'+(k.id ?? 'none')">
                <td class="py-1.5">{{ k.name }}</td>
                <td class="py-1.5">{{ k.order_count }}</td>
                <td class="py-1.5 text-right">{{ formatRupiah(k.total_amount) }}</td>
                <td class="py-1.5 text-right">{{ formatRupiah(k.outstanding_amount) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="report.by_sales" class="overflow-x-auto rounded-xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
          <p class="mb-3 text-[11px] font-medium uppercase tracking-wide text-stone-400">Per Sales / SKS</p>
          <table class="w-full text-sm">
            <thead><tr class="text-left text-xs"><th class="pb-2 font-medium">Sales</th><th class="pb-2 font-medium">Order</th><th class="pb-2 text-right font-medium">Nilai</th><th class="pb-2 text-right font-medium">Outstanding</th></tr></thead>
            <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
              <tr v-for="s in report.by_sales" :key="'s'+(s.id ?? 'none')">
                <td class="py-1.5">{{ s.name }}</td>
                <td class="py-1.5">{{ s.order_count }}</td>
                <td class="py-1.5 text-right">{{ formatRupiah(s.total_amount) }}</td>
                <td class="py-1.5 text-right">{{ formatRupiah(s.outstanding_amount) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
          <p class="mb-3 text-[11px] font-medium uppercase tracking-wide text-stone-400">Per Status Transaksi</p>
          <table class="w-full text-sm">
            <thead><tr class="text-left text-xs"><th class="pb-2 font-medium">Status</th><th class="pb-2 font-medium">Order</th><th class="pb-2 text-right font-medium">Nilai</th></tr></thead>
            <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
              <tr v-for="s in report.by_status" :key="s.status">
                <td class="py-1.5">{{ orderStatusLabel(s.status) }}</td>
                <td class="py-1.5">{{ s.order_count }}</td>
                <td class="py-1.5 text-right">{{ formatRupiah(s.total_amount) }}</td>
              </tr>
              <tr v-if="!report.by_status.length"><td colspan="3" class="py-4 text-center text-stone-400">Tidak ada transaksi pada cakupan ini.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </DashboardLayout>
</template>
