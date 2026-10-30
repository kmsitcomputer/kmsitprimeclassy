<script setup lang="ts">
/**
 * Ringkasan Jaringan — the report surface for the consolidated network summary
 * (`GET /reports/network-summary → ReportService::networkSummary`).
 *
 * Audiences mirror the backend role gate EXACTLY: super_admin (all branches,
 * AgentPicker narrowing to one branch), agen (own branch), keuangan (own
 * branch, financial view). ADMIN is deliberately ABSENT — the per-agen table
 * carries the agent-fee rollup, and the admin must never see agent fee data
 * (BUSINESS-RULES; `NetworkAndFunnelReportTest::…contains_no_agent_fee…`).
 * The server re-authorizes every call; this view only renders what it gets.
 */
import { computed, onMounted, reactive, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AgentPicker from '@/components/ui/AgentPicker.vue'
import { useAuthStore } from '@/stores/auth'
import { getNetworkSummary, type NetworkSummary } from '@/api/reports'
import { formatRupiah } from '@/utils/format'
import { formatApiError } from '@/utils/apiError'
import { ApiError } from '@/api/client'

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

const filters = reactive({ from: '', to: '', agent_id: undefined as number | undefined })
const loading = ref(true)
const error = ref('')
const report = ref<NetworkSummary | null>(null)

async function load() {
  loading.value = true
  error.value = ''
  try {
    report.value = await getNetworkSummary({
      from: filters.from || undefined,
      to: filters.to || undefined,
      agent_id: isSuperAdmin.value ? filters.agent_id : undefined,
    })
  } catch (e) {
    error.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat ringkasan jaringan.'
    report.value = null
  } finally {
    loading.value = false
  }
}

onMounted(load)

const TOTAL_CARDS = [
  ['Order', 'order_count', 'count'],
  ['Item Terkirim', 'item_count', 'count'],
  ['Nilai Order', 'total_amount', 'money'],
  ['Dibayar', 'paid_amount', 'money'],
  ['Outstanding', 'outstanding_amount', 'money'],
  ['Refund', 'refund_total', 'money'],
  ['Refund Retur', 'return_refund_total', 'money'],
  ['Additional Payment', 'additional_payment_total', 'money'],
] as const

const HEADCOUNT_CARDS = [
  ['Korsal', 'korsal_count'],
  ['Sales', 'sales_count'],
  ['Sales Aktif Jual', 'sales_with_sales_count'],
  ['Kurir', 'courier_count'],
] as const
</script>

<template>
  <DashboardLayout>
    <p class="pc-eyebrow mb-1">Laporan Jaringan</p>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Ringkasan Jaringan</h1>
    <p class="mb-4 text-sm text-stone-500 dark:text-stone-400">
      Ringkasan terkonsolidasi: total uang, headcount tim, tabel per-agen, produk terlaris dan split metode/status pembayaran.
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
      <AgentPicker v-if="isSuperAdmin" v-model="filters.agent_id" />
      <button type="button" class="rounded-lg bg-stone-800 px-3 py-2 text-xs font-medium text-white hover:bg-stone-900 dark:bg-stone-100 dark:text-stone-900" @click="load()">Terapkan</button>
    </div>

    <div v-if="loading" class="h-40 animate-pulse rounded-xl border border-stone-200 bg-stone-100 dark:border-stone-800" />
    <p v-else-if="error" class="pc-notice mb-4" data-testid="network-summary-error">{{ error }}</p>

    <template v-else-if="report">
      <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4" data-testid="network-summary-totals">
        <div
          v-for="[label, key, kind] in TOTAL_CARDS"
          :key="key"
          class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900"
        >
          <p class="text-[10px] font-semibold uppercase tracking-wide text-stone-400">{{ label }}</p>
          <p class="font-display text-lg font-semibold text-stone-900 dark:text-stone-50">
            {{ kind === 'money' ? formatRupiah(Number(report.totals[key])) : report.totals[key] }}
          </p>
        </div>
      </div>

      <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4" data-testid="network-summary-headcounts">
        <div
          v-for="[label, key] in HEADCOUNT_CARDS"
          :key="key"
          class="rounded-xl border border-brand-200 bg-brand-50 p-3 text-center dark:border-brand-800 dark:bg-brand-950"
        >
          <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-300">{{ label }}</p>
          <p class="font-display text-lg font-semibold text-brand-700 dark:text-brand-300">{{ report[key] }}</p>
        </div>
      </div>

      <div class="mb-4 overflow-x-auto rounded-xl border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900">
        <table class="w-full text-sm" data-testid="network-summary-per-agen">
          <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
            <tr>
              <th class="px-3 py-2 text-left">Agen</th>
              <th class="px-3 py-2 text-right">Korsal</th>
              <th class="px-3 py-2 text-right">Sales</th>
              <th class="px-3 py-2 text-right">Sales Aktif</th>
              <th class="px-3 py-2 text-right">Order</th>
              <th class="px-3 py-2 text-right">Nominal</th>
              <th class="px-3 py-2 text-right">Dibayar</th>
              <th class="px-3 py-2 text-right">Outstanding</th>
              <th class="px-3 py-2 text-right">Stok</th>
              <th class="px-3 py-2 text-right">Fee</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
            <tr v-for="row in report.per_agen" :key="row.agent_id">
              <td class="px-3 py-2 text-stone-800 dark:text-stone-100">{{ row.agent_name ?? ('Agen #' + row.agent_id) }}</td>
              <td class="px-3 py-2 text-right">{{ row.korsal_count }}</td>
              <td class="px-3 py-2 text-right">{{ row.sales_count }}</td>
              <td class="px-3 py-2 text-right">{{ row.active_sales_count }}</td>
              <td class="px-3 py-2 text-right">{{ row.order_count }}</td>
              <td class="px-3 py-2 text-right">{{ formatRupiah(row.total_amount) }}</td>
              <td class="px-3 py-2 text-right">{{ formatRupiah(row.paid_amount) }}</td>
              <td class="px-3 py-2 text-right">{{ formatRupiah(row.outstanding_amount) }}</td>
              <td class="px-3 py-2 text-right">{{ row.stock_quantity }}</td>
              <td class="px-3 py-2 text-right">{{ formatRupiah(row.total_fee) }}</td>
            </tr>
            <tr v-if="report.per_agen.length === 0">
              <td colspan="10" class="py-3 text-center text-stone-400">Tidak ada data dalam rentang ini.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="grid gap-4 lg:grid-cols-3">
        <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900">
          <h2 class="px-3 pt-3 font-display text-sm font-semibold text-stone-700 dark:text-stone-200">Produk Terlaris</h2>
          <table class="mt-2 w-full text-sm">
            <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
              <tr><th class="px-3 py-2 text-left">Produk</th><th class="px-3 py-2 text-right">Qty</th><th class="px-3 py-2 text-right">Nilai</th></tr>
            </thead>
            <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
              <tr v-for="p in report.products" :key="p.product_name_snapshot + (p.sku_snapshot ?? '')">
                <td class="px-3 py-2">
                  <span class="text-stone-800 dark:text-stone-100">{{ p.product_name_snapshot }}</span>
                  <span class="ml-1 text-xs text-stone-400">{{ p.sku_snapshot }}</span>
                </td>
                <td class="px-3 py-2 text-right">{{ p.quantity }}</td>
                <td class="px-3 py-2 text-right">{{ formatRupiah(Number(p.total_amount)) }}</td>
              </tr>
              <tr v-if="report.products.length === 0">
                <td colspan="3" class="py-3 text-center text-stone-400">Tidak ada penjualan.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900">
          <h2 class="px-3 pt-3 font-display text-sm font-semibold text-stone-700 dark:text-stone-200">Metode Pembayaran</h2>
          <table class="mt-2 w-full text-sm">
            <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
              <tr><th class="px-3 py-2 text-left">Metode</th><th class="px-3 py-2 text-right">Order</th><th class="px-3 py-2 text-right">Nilai</th></tr>
            </thead>
            <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
              <tr v-for="m in report.payment_methods" :key="(m.method_code ?? 'none') + (m.method_name ?? '')">
                <td class="px-3 py-2 text-stone-800 dark:text-stone-100">{{ m.method_name ?? 'Lainnya' }}</td>
                <td class="px-3 py-2 text-right">{{ m.order_count }}</td>
                <td class="px-3 py-2 text-right">{{ formatRupiah(Number(m.total_amount)) }}</td>
              </tr>
              <tr v-if="report.payment_methods.length === 0">
                <td colspan="3" class="py-3 text-center text-stone-400">Tidak ada pembayaran.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900">
          <h2 class="px-3 pt-3 font-display text-sm font-semibold text-stone-700 dark:text-stone-200">Status Pembayaran</h2>
          <table class="mt-2 w-full text-sm">
            <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
              <tr><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-right">Order</th><th class="px-3 py-2 text-right">Nilai</th></tr>
            </thead>
            <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
              <tr v-for="s in report.payment_status" :key="s.payment_status">
                <td class="px-3 py-2 text-stone-800 dark:text-stone-100">{{ s.payment_status }}</td>
                <td class="px-3 py-2 text-right">{{ s.order_count }}</td>
                <td class="px-3 py-2 text-right">{{ formatRupiah(Number(s.total_amount)) }}</td>
              </tr>
              <tr v-if="report.payment_status.length === 0">
                <td colspan="3" class="py-3 text-center text-stone-400">Tidak ada order.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <p class="mt-4 flex items-center gap-1 text-xs text-stone-400">
        <AppIcon name="chart-bar" :size="12" />
        Nominal uang dihitung dari kebenaran pembayaran level Order (bukan akumulasi transaksi).
      </p>
      <p v-if="auth.user?.role === 'keuangan'" class="mt-1 text-xs text-stone-400">Rentang data: {{ filters.from || 'awal' }} s.d. {{ filters.to || 'hari ini' }} — cabang Anda sendiri.</p>
    </template>
  </DashboardLayout>
</template>
