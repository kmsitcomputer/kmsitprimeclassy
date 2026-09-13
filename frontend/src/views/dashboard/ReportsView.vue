<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AgentPicker from '@/components/ui/AgentPicker.vue'
import { useAuthStore } from '@/stores/auth'
import {
  downloadReportXlsx,
  getTransactionsReport,
  getSalesKorsalFeeReport,
  getAgentFeeReport,
  getCourierFeeReport,
  getCancellationsRefundsReport,
  getPaymentStatusReport,
  type ReportFilters,
  type ReportKey,
} from '@/api/reports'
import { formatRupiah, formatDate, skuLabel, orderStatusLabel, paymentStatusLabel } from '@/utils/format'

type TabKey = 'transactions' | 'sales-korsal' | 'agent' | 'courier' | 'cancellations-refunds' | 'payment-status'

const TABS: { key: TabKey; label: string; reportKey: ReportKey }[] = [
  { key: 'transactions', label: 'Transaksi', reportKey: 'transactions' },
  { key: 'sales-korsal', label: 'Fee Sales & Korsal', reportKey: 'fees/sales-korsal' },
  { key: 'agent', label: 'Fee Agen', reportKey: 'fees/agent' },
  { key: 'courier', label: 'Fee Kurir', reportKey: 'fees/courier' },
  { key: 'cancellations-refunds', label: 'Batal & Refund', reportKey: 'cancellations-refunds' },
  { key: 'payment-status', label: 'Status Pembayaran', reportKey: 'payment-status' },
]

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

// "Fee Agen" belongs to the agen/super_admin level only — admin (per-agent
// staff) must never see this fee type, not even as a visible menu tab.
const visibleTabs = computed(() =>
  TABS.filter((tab) => tab.key !== 'agent' || ['super_admin', 'agen'].includes(auth.user?.role ?? '')),
)

const activeTab = ref<TabKey>('transactions')

const filters = ref({
  from: '',
  to: '',
  sales_id: '',
  korsal_id: '',
  courier_id: '',
  status: '',
  agent_id: undefined as number | undefined,
})

function activeFilters(): ReportFilters {
  const f: ReportFilters = {}
  if (filters.value.from) f.from = filters.value.from
  if (filters.value.to) f.to = filters.value.to
  if (filters.value.sales_id) f.sales_id = Number(filters.value.sales_id)
  if (filters.value.korsal_id) f.korsal_id = Number(filters.value.korsal_id)
  if (filters.value.courier_id) f.courier_id = Number(filters.value.courier_id)
  if (filters.value.status) f.status = filters.value.status
  if (filters.value.agent_id) f.agent_id = filters.value.agent_id
  return f
}

const loading = ref(false)
const transactions = ref<Awaited<ReturnType<typeof getTransactionsReport>>>([])
const salesKorsal = ref<Awaited<ReturnType<typeof getSalesKorsalFeeReport>>>({ sales: [], korsal: [] })
const agentFees = ref<Awaited<ReturnType<typeof getAgentFeeReport>>>([])
const courierFees = ref<Awaited<ReturnType<typeof getCourierFeeReport>>>([])
const cancellationsRefunds = ref<Awaited<ReturnType<typeof getCancellationsRefundsReport>>>({
  cancellations: [], adjustments: [], returns: [],
})
const paymentStatus = ref<Awaited<ReturnType<typeof getPaymentStatusReport>>>([])

async function load() {
  loading.value = true
  const f = activeFilters()
  try {
    switch (activeTab.value) {
      case 'transactions':
        transactions.value = await getTransactionsReport(f)
        break
      case 'sales-korsal':
        salesKorsal.value = await getSalesKorsalFeeReport(f)
        break
      case 'agent':
        agentFees.value = await getAgentFeeReport(f)
        break
      case 'courier':
        courierFees.value = await getCourierFeeReport(f)
        break
      case 'cancellations-refunds':
        cancellationsRefunds.value = await getCancellationsRefundsReport(f)
        break
      case 'payment-status':
        paymentStatus.value = await getPaymentStatusReport(f)
        break
    }
  } finally {
    loading.value = false
  }
}

watch(activeTab, load, { immediate: true })

function applyFilters() {
  load()
}

function download() {
  const tab = TABS.find((t) => t.key === activeTab.value)!
  downloadReportXlsx(tab.reportKey, activeFilters())
}

const paymentStatusGrouped = computed(() => {
  const groups = new Map<string, { agent_name: string; lunas: number; belum_lunas: number; lunas_amount: number; belum_lunas_amount: number }>()
  const LUNAS = new Set(['paid', 'partially_refunded', 'refunded'])
  for (const row of paymentStatus.value) {
    const key = String(row.agent_id ?? 'none')
    if (!groups.has(key)) {
      groups.set(key, { agent_name: row.agent_name ?? '-', lunas: 0, belum_lunas: 0, lunas_amount: 0, belum_lunas_amount: 0 })
    }
    const g = groups.get(key)!
    if (LUNAS.has(row.payment_status)) {
      g.lunas += row.order_count
      g.lunas_amount += Number(row.total_amount)
    } else {
      g.belum_lunas += row.order_count
      g.belum_lunas_amount += Number(row.total_amount)
    }
  }
  return Array.from(groups.values())
})
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Laporan</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
      Data penjualan, fee, dan status pembayaran per periode — bisa diunduh sebagai .xlsx.
    </p>

    <div class="mb-4 flex flex-wrap gap-1 border-b border-stone-200 dark:border-stone-800">
      <button
        v-for="tab in visibleTabs"
        :key="tab.key"
        type="button"
        class="rounded-t-lg px-3 py-2 text-sm font-medium"
        :class="
          activeTab === tab.key
            ? 'border-b-2 border-brand-600 text-brand-700 dark:text-brand-400'
            : 'text-stone-500 hover:text-stone-800 dark:hover:text-stone-200'
        "
        @click="activeTab = tab.key"
      >
        {{ tab.label }}
      </button>
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
      <label v-if="['transactions', 'sales-korsal'].includes(activeTab)" class="text-sm text-stone-600 dark:text-stone-300">
        ID Sales
        <input v-model="filters.sales_id" type="number" class="mt-1 block w-28 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <label v-if="['transactions', 'sales-korsal'].includes(activeTab)" class="text-sm text-stone-600 dark:text-stone-300">
        ID Korsal
        <input v-model="filters.korsal_id" type="number" class="mt-1 block w-28 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <label v-if="['transactions', 'courier', 'cancellations-refunds'].includes(activeTab)" class="text-sm text-stone-600 dark:text-stone-300">
        ID Kurir
        <input v-model="filters.courier_id" type="number" class="mt-1 block w-28 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <label v-if="activeTab === 'transactions'" class="text-sm text-stone-600 dark:text-stone-300">
        Status Item
        <input v-model="filters.status" type="text" placeholder="mis. terkirim" class="mt-1 block w-36 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <AgentPicker v-if="isSuperAdmin" v-model="filters.agent_id" />
      <button type="button" class="rounded-lg bg-stone-800 px-3 py-2 text-xs font-medium text-white hover:bg-stone-900 dark:bg-stone-100 dark:text-stone-900" @click="applyFilters">
        Terapkan
      </button>
      <button type="button" class="ml-auto flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-xs font-medium text-white hover:bg-brand-700" @click="download">
        <AppIcon name="download" :size="14" /> Unduh .xlsx
      </button>
    </div>

    <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>

    <div v-else class="overflow-x-auto rounded-xl border border-stone-200 dark:border-stone-800">
      <!-- Transaksi -->
      <table v-if="activeTab === 'transactions'" class="w-full text-left text-sm">
        <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
          <tr>
            <th class="px-4 py-2">Order</th>
            <th class="px-4 py-2">Tanggal</th>
            <th class="px-4 py-2">Sales</th>
            <th class="px-4 py-2">Korsal</th>
            <th class="px-4 py-2">Produk</th>
            <th class="px-4 py-2">Qty</th>
            <th class="px-4 py-2">Status</th>
            <th class="px-4 py-2">Kurir</th>
            <th class="px-4 py-2 text-right">Subtotal</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
          <tr v-for="row in transactions" :key="row.order_item_id">
            <td class="px-4 py-2">{{ row.order_no }}</td>
            <td class="px-4 py-2">{{ formatDate(row.order_created_at) }}</td>
            <td class="px-4 py-2">{{ row.sales_name ?? '-' }}</td>
            <td class="px-4 py-2">{{ row.korsal_name ?? '-' }}</td>
            <td class="px-4 py-2">
              <div>{{ row.product_name_snapshot }}</div>
              <div class="text-xs text-stone-400 dark:text-stone-500">{{ skuLabel(row.sku) }}</div>
            </td>
            <td class="px-4 py-2">{{ row.fulfilled_quantity }}</td>
            <td class="px-4 py-2">{{ orderStatusLabel(row.item_status) }}</td>
            <td class="px-4 py-2">{{ row.courier_name ?? '-' }}</td>
            <td class="px-4 py-2 text-right">{{ formatRupiah(row.subtotal_snapshot) }}</td>
          </tr>
          <tr v-if="transactions.length === 0"><td colspan="9" class="px-4 py-6 text-center text-stone-400">Tidak ada data.</td></tr>
        </tbody>
      </table>

      <!-- Fee Sales & Korsal -->
      <template v-else-if="activeTab === 'sales-korsal'">
        <p class="border-b border-stone-200 bg-stone-50 px-4 py-2 text-xs font-semibold text-stone-500 dark:border-stone-800 dark:bg-stone-900 dark:text-stone-400">Per Sales</p>
        <table class="w-full text-left text-sm">
          <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
            <tr><th class="px-4 py-2">Sales</th><th class="px-4 py-2">Jumlah Transaksi</th><th class="px-4 py-2 text-right">Total Fee</th></tr>
          </thead>
          <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
            <tr v-for="row in salesKorsal.sales" :key="row.sales_id">
              <td class="px-4 py-2">{{ row.sales_name }}</td>
              <td class="px-4 py-2">{{ row.transaction_count }}</td>
              <td class="px-4 py-2 text-right">{{ formatRupiah(row.total_fee) }}</td>
            </tr>
            <tr v-if="salesKorsal.sales.length === 0"><td colspan="3" class="px-4 py-6 text-center text-stone-400">Tidak ada data.</td></tr>
          </tbody>
        </table>
        <p class="border-y border-stone-200 bg-stone-50 px-4 py-2 text-xs font-semibold text-stone-500 dark:border-stone-800 dark:bg-stone-900 dark:text-stone-400">Per Korsal (roll-up hierarki)</p>
        <table class="w-full text-left text-sm">
          <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
            <tr><th class="px-4 py-2">Korsal</th><th class="px-4 py-2">Jumlah Transaksi</th><th class="px-4 py-2">Jumlah Sales Aktif</th><th class="px-4 py-2 text-right">Total Fee</th></tr>
          </thead>
          <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
            <tr v-for="row in salesKorsal.korsal" :key="row.korsal_id">
              <td class="px-4 py-2">{{ row.korsal_name }}</td>
              <td class="px-4 py-2">{{ row.transaction_count }}</td>
              <td class="px-4 py-2">{{ row.active_sales_count }}</td>
              <td class="px-4 py-2 text-right">{{ formatRupiah(row.total_fee) }}</td>
            </tr>
            <tr v-if="salesKorsal.korsal.length === 0"><td colspan="4" class="px-4 py-6 text-center text-stone-400">Tidak ada data.</td></tr>
          </tbody>
        </table>
      </template>

      <!-- Fee Agen -->
      <table v-else-if="activeTab === 'agent'" class="w-full text-left text-sm">
        <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
          <tr><th class="px-4 py-2">Agen</th><th class="px-4 py-2">Jumlah Transaksi</th><th class="px-4 py-2">Jumlah Sales Aktif</th><th class="px-4 py-2 text-right">Total Fee</th></tr>
        </thead>
        <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
          <tr v-for="row in agentFees" :key="row.agent_id">
            <td class="px-4 py-2">{{ row.agent_name }}</td>
            <td class="px-4 py-2">{{ row.transaction_count }}</td>
            <td class="px-4 py-2">{{ row.active_sales_count }}</td>
            <td class="px-4 py-2 text-right">{{ formatRupiah(row.total_fee) }}</td>
          </tr>
          <tr v-if="agentFees.length === 0"><td colspan="4" class="px-4 py-6 text-center text-stone-400">Tidak ada data.</td></tr>
        </tbody>
      </table>

      <!-- Fee Kurir -->
      <table v-else-if="activeTab === 'courier'" class="w-full text-left text-sm">
        <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
          <tr><th class="px-4 py-2">Kurir</th><th class="px-4 py-2">Jumlah Pengiriman</th><th class="px-4 py-2 text-right">Total Fee</th></tr>
        </thead>
        <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
          <tr v-for="row in courierFees" :key="row.courier_id">
            <td class="px-4 py-2">{{ row.courier_name }}</td>
            <td class="px-4 py-2">{{ row.delivery_count }}</td>
            <td class="px-4 py-2 text-right">{{ formatRupiah(row.total_fee) }}</td>
          </tr>
          <tr v-if="courierFees.length === 0"><td colspan="3" class="px-4 py-6 text-center text-stone-400">Tidak ada data.</td></tr>
        </tbody>
      </table>

      <!-- Batal & Refund -->
      <template v-else-if="activeTab === 'cancellations-refunds'">
        <p class="border-b border-stone-200 bg-stone-50 px-4 py-2 text-xs font-semibold text-stone-500 dark:border-stone-800 dark:bg-stone-900 dark:text-stone-400">Order Dibatalkan</p>
        <table class="w-full text-left text-sm">
          <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
            <tr><th class="px-4 py-2">Order</th><th class="px-4 py-2">Tanggal</th><th class="px-4 py-2">Konsumen</th><th class="px-4 py-2">Alasan</th></tr>
          </thead>
          <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
            <tr v-for="row in cancellationsRefunds.cancellations" :key="String(row.id)">
              <td class="px-4 py-2">{{ row.order_no }}</td>
              <td class="px-4 py-2">{{ row.cancelled_at ? formatDate(String(row.cancelled_at)) : '-' }}</td>
              <td class="px-4 py-2">{{ (row.konsumen as { name?: string } | null)?.name ?? '-' }}</td>
              <td class="px-4 py-2">{{ row.cancellation_reason }}</td>
            </tr>
            <tr v-if="cancellationsRefunds.cancellations.length === 0"><td colspan="4" class="px-4 py-6 text-center text-stone-400">Tidak ada data.</td></tr>
          </tbody>
        </table>
        <p class="border-y border-stone-200 bg-stone-50 px-4 py-2 text-xs font-semibold text-stone-500 dark:border-stone-800 dark:bg-stone-900 dark:text-stone-400">Retur (Refund)</p>
        <table class="w-full text-left text-sm">
          <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
            <tr><th class="px-4 py-2">Order</th><th class="px-4 py-2">Produk</th><th class="px-4 py-2">Kurir</th><th class="px-4 py-2">Status Refund</th><th class="px-4 py-2 text-right">Jumlah</th></tr>
          </thead>
          <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
            <tr v-for="row in cancellationsRefunds.returns" :key="String(row.id)">
              <td class="px-4 py-2">{{ row.order_no }}</td>
              <td class="px-4 py-2">
                <div>{{ row.product_name_snapshot }}</div>
                <div class="text-xs text-stone-400 dark:text-stone-500">{{ skuLabel(String(row.product_sku ?? '')) }}</div>
              </td>
              <td class="px-4 py-2">{{ row.courier_name ?? '-' }}</td>
              <td class="px-4 py-2">{{ row.refund_status }}</td>
              <td class="px-4 py-2 text-right">{{ formatRupiah(String(row.refund_amount ?? 0)) }}</td>
            </tr>
            <tr v-if="cancellationsRefunds.returns.length === 0"><td colspan="5" class="px-4 py-6 text-center text-stone-400">Tidak ada data.</td></tr>
          </tbody>
        </table>
      </template>

      <!-- Status Pembayaran -->
      <table v-else-if="activeTab === 'payment-status'" class="w-full text-left text-sm">
        <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
          <tr>
            <th class="px-4 py-2">Agen</th>
            <th class="px-4 py-2">Order Lunas</th>
            <th class="px-4 py-2 text-right">Nilai Lunas</th>
            <th class="px-4 py-2">Order Belum Lunas</th>
            <th class="px-4 py-2 text-right">Nilai Belum Lunas</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
          <tr v-for="row in paymentStatusGrouped" :key="row.agent_name">
            <td class="px-4 py-2">{{ row.agent_name }}</td>
            <td class="px-4 py-2">{{ row.lunas }}</td>
            <td class="px-4 py-2 text-right">{{ formatRupiah(row.lunas_amount) }}</td>
            <td class="px-4 py-2">{{ row.belum_lunas }}</td>
            <td class="px-4 py-2 text-right">{{ formatRupiah(row.belum_lunas_amount) }}</td>
          </tr>
          <tr v-if="paymentStatusGrouped.length === 0"><td colspan="5" class="px-4 py-6 text-center text-stone-400">Tidak ada data.</td></tr>
        </tbody>
      </table>
    </div>
    <p v-if="activeTab === 'payment-status'" class="mt-2 text-xs text-stone-400">
      Status mentah: {{ paymentStatusLabel('paid') }}, {{ paymentStatusLabel('unpaid') }}, dll. — dikelompokkan di sini sebagai Lunas (paid/partially_refunded/refunded) vs Belum Lunas (sisanya).
    </p>
  </DashboardLayout>
</template>
