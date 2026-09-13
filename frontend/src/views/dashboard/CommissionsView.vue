<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AgentPicker from '@/components/ui/AgentPicker.vue'
import { useAuthStore } from '@/stores/auth'
import { listCommissions, commissionSummary, type CommissionRow, type CommissionSummary } from '@/api/commissions'
import { formatDate } from '@/utils/format'

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')

const loading = ref(false)
const errorMessage = ref('')
const rows = ref<CommissionRow[]>([])
const summary = ref<CommissionSummary | null>(null)

const from = ref('')
const to = ref('')
const status = ref('')
const agentId = ref<number | undefined>(undefined)

const STATUS_LABELS: Record<string, string> = {
  pending: 'Menunggu',
  earned: 'Terhitung',
  paid: 'Dibayar',
  cancelled: 'Dibatalkan',
}

function formatMoney(value: string | number) {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(
    Number(value),
  )
}

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const filters = { from: from.value || undefined, to: to.value || undefined, status: status.value || undefined, agent_id: agentId.value }
    const [list, sum] = await Promise.all([listCommissions(1, filters), commissionSummary(filters)])
    rows.value = list.rows
    summary.value = sum
  } catch {
    errorMessage.value = 'Gagal memuat data komisi.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Komisi</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">Ringkasan dan riwayat komisi/fee Anda.</p>

    <div class="mb-5 flex flex-wrap items-end gap-3">
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Dari
        <input v-model="from" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Sampai
        <input v-model="to" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Status
        <select v-model="status" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950">
          <option value="">Semua</option>
          <option value="pending">Menunggu</option>
          <option value="earned">Terhitung</option>
          <option value="paid">Dibayar</option>
          <option value="cancelled">Dibatalkan</option>
        </select>
      </label>
      <AgentPicker v-if="isSuperAdmin" v-model="agentId" />
      <button type="button" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="load">
        Terapkan
      </button>
    </div>

    <p v-if="errorMessage" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ errorMessage }}
    </p>

    <div v-if="summary" class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
      <!-- Each tile only renders when the backend actually included that fee-type
           key — its absence means this viewer's role must never see that category
           exists at all (never just an unfiltered zero). -->
      <div v-if="summary.total_agent_fee !== undefined" class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <p class="text-xs uppercase text-stone-400 dark:text-stone-500">Fee Agen</p>
        <p class="mt-1 font-display text-xl font-semibold tabular-nums text-stone-800 dark:text-stone-100">
          {{ formatMoney(summary.total_agent_fee) }}
        </p>
      </div>
      <div v-if="summary.total_sales_fee !== undefined" class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <p class="text-xs uppercase text-stone-400 dark:text-stone-500">Fee Sales</p>
        <p class="mt-1 font-display text-xl font-semibold tabular-nums text-stone-800 dark:text-stone-100">
          {{ formatMoney(summary.total_sales_fee) }}
        </p>
      </div>
      <div v-if="summary.total_courier_fee !== undefined" class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <p class="text-xs uppercase text-stone-400 dark:text-stone-500">Fee Kurir</p>
        <p class="mt-1 font-display text-xl font-semibold tabular-nums text-stone-800 dark:text-stone-100">
          {{ formatMoney(summary.total_courier_fee) }}
        </p>
      </div>
    </div>

    <p v-if="loading" class="text-sm text-stone-500 dark:text-stone-400">Memuat...</p>

    <div v-else class="overflow-x-auto rounded-2xl border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900">
      <table class="w-full text-sm">
        <thead class="border-b border-stone-200 text-left text-xs uppercase text-stone-400 dark:border-stone-800 dark:text-stone-500">
          <tr>
            <th class="px-4 py-3">Order</th>
            <th class="px-4 py-3">Peran</th>
            <th class="px-4 py-3 text-right">Jumlah</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3">Tanggal</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="rows.length === 0">
            <td colspan="5" class="px-4 py-6 text-center text-stone-400 dark:text-stone-500">Tidak ada data.</td>
          </tr>
          <tr v-for="row in rows" :key="row.id" class="border-b border-stone-100 last:border-0 dark:border-stone-800">
            <td class="px-4 py-3 font-medium text-stone-700 dark:text-stone-200">#{{ row.order_id }}</td>
            <td class="px-4 py-3 text-stone-500 dark:text-stone-400">{{ row.beneficiary_role }}</td>
            <td class="px-4 py-3 text-right tabular-nums">{{ formatMoney(row.amount) }}</td>
            <td class="px-4 py-3 text-stone-500 dark:text-stone-400">{{ STATUS_LABELS[row.status] ?? row.status }}</td>
            <td class="px-4 py-3 text-stone-500 dark:text-stone-400">{{ formatDate(row.earned_at) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </DashboardLayout>
</template>
