<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { getFinanceSummary, getFinanceOrders, type FinanceSummary, type FinanceOrderRow } from '@/api/reports'
import { formatRupiah } from '@/utils/format'

const loading = ref(true)
const summary = ref<FinanceSummary | null>(null)
const orders = ref<FinanceOrderRow[]>([])
const from = ref('')
const to = ref('')

async function load() {
  loading.value = true
  const filters = { from: from.value || undefined, to: to.value || undefined }
  const [s, o] = await Promise.all([getFinanceSummary(filters), getFinanceOrders(filters)])
  summary.value = s
  orders.value = o.orders
  loading.value = false
}

/** R-04 / §G: allowed fee/commission totals for this order (per the actor's finance authority). */
function feeTotal(row: FinanceOrderRow): number {
  return Object.values(row.fees ?? {}).reduce((sum, amount) => sum + Number(amount || 0), 0)
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

    <!-- R-04 / §G: per-order canonical finance projection (PaymentSummaryService truth, one row per order).
         UAT-004: "DP Diajukan" is the submitted-but-unverified nominal — informational triage only,
         never added to DP Dibayar / Total Dibayar before canonical verification. -->
    <div v-if="!loading && orders.length" class="mt-8">
      <h2 class="mb-3 text-sm font-semibold text-stone-800 dark:text-stone-100">Rincian Per Order</h2>
      <p class="mb-3 text-xs text-stone-500 dark:text-stone-400">
        DP Diajukan = nominal yang sudah disubmit dan menunggu verifikasi — belum dihitung sebagai DP Dibayar / Total Dibayar.
      </p>
      <div class="overflow-x-auto rounded-2xl border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900">
        <table class="min-w-full text-sm">
          <thead class="bg-stone-50 text-left text-xs uppercase text-stone-500 dark:bg-stone-800 dark:text-stone-400">
            <tr>
              <th class="px-3 py-2">Order No</th>
              <th class="px-3 py-2">Pelanggan</th>
              <th class="px-3 py-2">Tanggal</th>
              <th class="px-3 py-2">Metode Bayar</th>
              <th class="px-3 py-2 text-right">Grand Total</th>
              <th class="px-3 py-2 text-right">DP Diajukan</th>
              <th class="px-3 py-2 text-right">DP Dibayar</th>
              <th class="px-3 py-2 text-right">Total Dibayar</th>
              <th class="px-3 py-2 text-right">Sisa</th>
              <th class="px-3 py-2">Status Pembayaran</th>
              <th class="px-3 py-2">Status Verifikasi</th>
              <th class="px-3 py-2">Bukti</th>
              <th class="px-3 py-2 text-right">Refund</th>
              <th class="px-3 py-2 text-right">Additional Payment</th>
              <th class="px-3 py-2 text-right">Fee</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
            <tr v-for="row in orders" :key="row.order_id">
              <td class="px-3 py-2 font-medium text-stone-700 dark:text-stone-200">{{ row.order_no }}</td>
              <td class="px-3 py-2 text-stone-500 dark:text-stone-400">{{ row.customer ?? '-' }}</td>
              <td class="px-3 py-2 text-stone-500 dark:text-stone-400">{{ row.order_date ?? '-' }}</td>
              <td class="px-3 py-2 text-stone-500 dark:text-stone-400">{{ row.payment_method ?? '-' }}</td>
              <td class="px-3 py-2 text-right tabular-nums">{{ formatRupiah(row.grand_total) }}</td>
              <td class="px-3 py-2 text-right tabular-nums">
                <span :class="(row.dp_submitted ?? 0) > 0 ? 'font-semibold text-amber-700 dark:text-amber-400' : ''">
                  {{ formatRupiah(row.dp_submitted ?? 0) }}
                </span>
              </td>
              <td class="px-3 py-2 text-right tabular-nums">{{ formatRupiah(row.dp_paid) }}</td>
              <td class="px-3 py-2 text-right tabular-nums">{{ formatRupiah(row.total_paid) }}</td>
              <td class="px-3 py-2 text-right tabular-nums">{{ formatRupiah(row.remaining) }}</td>
              <td class="px-3 py-2">{{ row.payment_status }}</td>
              <td class="px-3 py-2">
                <span v-if="row.verification_status === 'pending'" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-400">
                  Menunggu verifikasi
                </span>
                <span v-else class="text-stone-400">—</span>
              </td>
              <td class="px-3 py-2">
                <span v-if="row.verification_status === 'pending'">{{ row.has_pending_proof ? 'Ada' : 'Tidak ada' }}</span>
                <span v-else class="text-stone-400">—</span>
              </td>
              <td class="px-3 py-2 text-right tabular-nums">{{ formatRupiah(row.refund) }}</td>
              <td class="px-3 py-2 text-right tabular-nums">{{ formatRupiah(row.additional_payment) }}</td>
              <td class="px-3 py-2 text-right tabular-nums">{{ formatRupiah(feeTotal(row)) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </DashboardLayout>
</template>
