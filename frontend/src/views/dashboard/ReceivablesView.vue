<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { getFinanceOrders, type FinanceOrderRow } from '@/api/reports'
import { ApiError } from '@/api/errors'
import { formatRupiah } from '@/utils/format'

/**
 * Improvement 10 + Owner-approved centralized Piutang menu (§8B): Admin and
 * Keuangan see every order that still owes money — searchable by order number
 * or customer, filterable Belum Lunas / Jatuh Tempo / Terlambat — straight from
 * the EXISTING finance-orders source (no second financial truth; scope and
 * authorization stay backend-authoritative). Rows link to Detail Pesanan.
 */
const { t } = useI18n()
const router = useRouter()

const rows = ref<FinanceOrderRow[]>([])
const loading = ref(true)
const errorMessage = ref('')
const search = ref('')
const filter = ref<'all' | 'unpaid' | 'due' | 'overdue'>('all')
const page = ref(1)
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 15, summary: { count: 0, outstanding: 0 } })
let debounce: ReturnType<typeof setTimeout> | undefined

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const result = await getFinanceOrders({
      receivable: true,
      search: search.value || undefined,
      due_filter: filter.value === 'due' || filter.value === 'overdue' ? filter.value : undefined,
      page: page.value,
      per_page: 15,
    })
    rows.value = result.orders
    meta.value = result.meta
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : t('receivables.loadError')
  } finally {
    loading.value = false
  }
}

const visible = computed(() => rows.value)

const totals = computed(() => ({
  count: meta.value.summary.count,
  outstanding: meta.value.summary.outstanding,
}))

const filters: Array<{ key: 'all' | 'unpaid' | 'due' | 'overdue'; label: string }> = [
  { key: 'all', label: t('receivables.filterAll') },
  { key: 'unpaid', label: t('receivables.filterUnpaid') },
  { key: 'due', label: t('receivables.filterDue') },
  { key: 'overdue', label: t('receivables.filterOverdue') },
]

watch([search, filter], () => { page.value = 1; clearTimeout(debounce); debounce = setTimeout(load, 250) })
watch(page, load)

onMounted(load)
</script>

<template>
  <div class="space-y-4">
    <header class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="font-display text-2xl font-semibold text-stone-900 dark:text-stone-50">{{ t('receivables.title') }}</h1>
      <p class="text-sm text-stone-500 dark:text-stone-400">
        {{ t('receivables.summary', { count: totals.count, amount: formatRupiah(totals.outstanding) }) }}
      </p>
    </header>

    <div class="flex flex-wrap items-center gap-2" data-testid="receivables-controls">
      <input
        v-model="search"
        type="search"
        :placeholder="t('receivables.searchPlaceholder')"
        class="rounded-lg border border-stone-200 px-3 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950"
        data-testid="receivables-search"
      />
      <button
        v-for="f in filters"
        :key="f.key"
        type="button"
        class="rounded-lg px-3 py-1.5 text-xs font-medium"
        :class="filter === f.key ? 'bg-stone-800 text-white dark:bg-stone-200 dark:text-stone-900' : 'bg-stone-100 text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200'"
        :data-testid="`receivables-filter-${f.key}`"
        @click="filter = f.key"
      >
        {{ f.label }}
      </button>
    </div>

    <p v-if="errorMessage" class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-400">{{ errorMessage }}</p>
    <p v-else-if="loading" class="text-sm text-stone-500">{{ t('common.loading') }}</p>
    <p v-else-if="visible.length === 0" class="rounded-lg border border-stone-200 p-4 text-sm text-stone-500 dark:border-stone-700" data-testid="receivables-empty">
      {{ t('receivables.empty') }}
    </p>

    <div v-else class="overflow-x-auto">
      <table class="w-full min-w-[720px] text-left text-sm">
        <thead>
          <tr class="border-b border-stone-200 text-xs uppercase text-stone-500 dark:border-stone-700">
            <th class="px-2 py-2">{{ t('receivables.colOrder') }}</th>
            <th class="px-2 py-2">{{ t('receivables.colCustomer') }}</th>
            <th class="px-2 py-2">{{ t('receivables.colTotal') }}</th>
            <th class="px-2 py-2">{{ t('receivables.colPaid') }}</th>
            <th class="px-2 py-2">{{ t('receivables.colOutstanding') }}</th>
            <th class="px-2 py-2">{{ t('receivables.colDue') }}</th>
            <th class="px-2 py-2">{{ t('receivables.colStatus') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in visible"
            :key="row.order_id"
            class="cursor-pointer border-b border-stone-100 hover:bg-stone-50 dark:border-stone-800 dark:hover:bg-stone-900"
            :data-testid="`receivables-row-${row.order_id}`"
            @click="router.push(`/orders/${row.order_id}`)"
          >
            <td class="px-2 py-2 font-medium text-stone-800 dark:text-stone-200">{{ row.order_no }}</td>
            <td class="px-2 py-2">{{ row.customer ?? '—' }}</td>
            <td class="px-2 py-2">{{ formatRupiah(row.grand_total) }}</td>
            <td class="px-2 py-2">{{ formatRupiah(row.total_paid) }}</td>
            <td class="px-2 py-2 font-semibold" :class="row.remaining > 0 ? 'text-red-700 dark:text-red-400' : 'text-emerald-700 dark:text-emerald-400'">
              {{ formatRupiah(row.remaining) }}
            </td>
            <td class="px-2 py-2">
              <span v-if="row.is_overdue" class="rounded bg-red-100 px-1.5 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950 dark:text-red-400">{{ row.due_date }}</span>
              <span v-else>{{ row.due_date ?? '—' }}</span>
            </td>
            <td class="px-2 py-2">{{ row.payment_status }}</td>
          </tr>
        </tbody>
      </table>
      <div class="mt-3 flex items-center justify-end gap-2">
        <button type="button" :disabled="page <= 1" class="rounded border px-3 py-1 disabled:opacity-40" @click="page--">‹</button>
        <span class="text-xs text-stone-500">{{ meta.current_page }} / {{ meta.last_page }}</span>
        <button type="button" :disabled="page >= meta.last_page" class="rounded border px-3 py-1 disabled:opacity-40" @click="page++">›</button>
      </div>
    </div>
  </div>
</template>
