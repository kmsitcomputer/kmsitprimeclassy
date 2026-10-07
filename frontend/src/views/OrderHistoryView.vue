<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import ShopLayout from '@/layouts/ShopLayout.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { listOrders, listOrderRegionOptions, type OrderListFilters, type OrderRegionOptions } from '@/api/orders'
import { useAuthStore } from '@/stores/auth'
import type { Order, PaginationMeta } from '@/api/types'
import { formatRupiah, formatDate, orderStatusLabel, paymentBadgeState, paymentBadgeLabel } from '@/utils/format'

const { t } = useI18n()
const orders = ref<Order[]>([])
const meta = ref<PaginationMeta | null>(null)
const loading = ref(true)

const auth = useAuthStore()
/** Filters are a staff surface (Admin/Keuangan/Sales/Korsal/…); konsumen keeps the plain history. */
const showFilters = computed(() => !auth.isKonsumen && auth.user?.role !== 'gudang')
const STATUS_OPTIONS = ['diterima', 'diproses', 'dikirim', 'terkirim', 'pengembalian', 'kembali', 'dibatalkan']
// Status Pelunasan = canonical orders.payment_status (rejected transfer proof => 'failed').
const SETTLEMENT_OPTIONS: { value: string; label: string }[] = [
  { value: 'unpaid', label: 'Belum dibayar' },
  { value: 'pending_verification', label: 'Menunggu verifikasi' },
  { value: 'partially_paid', label: 'Dibayar sebagian (DP)' },
  { value: 'paid', label: 'Lunas' },
  { value: 'failed', label: 'Ditolak / gagal' },
]
const filters = ref<Required<Pick<OrderListFilters, 'status' | 'payment_status' | 'search' | 'delivery_date' | 'province_id' | 'regency_id' | 'district_id' | 'village_id'>> & { paid: '' | 'paid' | 'unpaid' }>({
  status: '', payment_status: '', search: '', delivery_date: '', province_id: '', regency_id: '', district_id: '', village_id: '', paid: '',
})
const regionOptions = ref<OrderRegionOptions>({ provinces: [], regencies: [], districts: [], villages: [] })

function currentFilters(): OrderListFilters {
  const f = filters.value
  return { ...f, paid: f.paid || undefined }
}

async function loadRegions() {
  try {
    regionOptions.value = await listOrderRegionOptions(currentFilters())
  } catch {
    regionOptions.value = { provinces: [], regencies: [], districts: [], villages: [] }
  }
}
// A parent change resets deeper selections (same contract as Dispatch).
function onProvinceChange() { filters.value.regency_id = ''; filters.value.district_id = ''; filters.value.village_id = ''; void loadRegions() }
function onRegencyChange() { filters.value.district_id = ''; filters.value.village_id = ''; void loadRegions() }
function onDistrictChange() { filters.value.village_id = ''; void loadRegions() }
function resetFilters() {
  filters.value = { status: '', payment_status: '', search: '', delivery_date: '', province_id: '', regency_id: '', district_id: '', village_id: '', paid: '' }
  void loadRegions()
  void load()
}

async function load(page = 1) {
  loading.value = true
  const result = await listOrders(page, showFilters.value ? currentFilters() : {})
  orders.value = result.orders
  meta.value = result.meta
  loading.value = false
}

onMounted(() => {
  void load()
  if (showFilters.value) void loadRegions()
})

const STATUS_STYLES: Record<string, string> = {
  diterima: 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-400',
  diproses: 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-400',
  dikirim: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-400',
  terkirim: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400',
  dibatalkan: 'bg-stone-100 text-stone-500 dark:bg-stone-800 dark:text-stone-400',
  pengembalian: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-400',
  kembali: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-400',
}

const PAYMENT_STYLES: Record<'paid' | 'pending' | 'unpaid', string> = {
  paid: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400',
  pending: 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-400',
  unpaid: 'bg-rose-50 text-rose-700 dark:bg-rose-950 dark:text-rose-400',
}
</script>

<template>
  <ShopLayout>
    <h1 class="mb-4 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">{{ t('orders.historyTitle') }}</h1>

    <form v-if="showFilters" class="mb-4 grid gap-2 rounded-2xl border border-stone-200 bg-white p-3 text-xs sm:grid-cols-3 dark:border-stone-800 dark:bg-stone-900" @submit.prevent="load()">
      <input v-model="filters.search" type="search" placeholder="Cari no. order / penerima" class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 dark:border-stone-700 dark:bg-stone-950" />
      <select v-model="filters.status" class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 dark:border-stone-700 dark:bg-stone-950">
        <option value="">Status Order: semua</option>
        <option v-for="st in STATUS_OPTIONS" :key="st" :value="st">{{ orderStatusLabel(st) }}</option>
      </select>
      <select v-model="filters.payment_status" class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 dark:border-stone-700 dark:bg-stone-950">
        <option value="">Status Pelunasan: semua</option>
        <option v-for="o in SETTLEMENT_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
      <input v-model="filters.delivery_date" type="date" class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 dark:border-stone-700 dark:bg-stone-950" />
      <select v-model="filters.paid" class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 dark:border-stone-700 dark:bg-stone-950">
        <option value="">Lunas / belum lunas: semua</option>
        <option value="paid">Lunas</option>
        <option value="unpaid">Belum lunas</option>
      </select>
      <select v-model="filters.province_id" class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 dark:border-stone-700 dark:bg-stone-950" @change="onProvinceChange">
        <option value="">Provinsi: semua</option>
        <option v-for="o in regionOptions.provinces" :key="o.id" :value="o.id">{{ o.name }}</option>
      </select>
      <select v-model="filters.regency_id" :disabled="!filters.province_id" class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 disabled:opacity-50 dark:border-stone-700 dark:bg-stone-950" @change="onRegencyChange">
        <option value="">Kab/Kota: semua</option>
        <option v-for="o in regionOptions.regencies" :key="o.id" :value="o.id">{{ o.name }}</option>
      </select>
      <select v-model="filters.district_id" :disabled="!filters.regency_id" class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 disabled:opacity-50 dark:border-stone-700 dark:bg-stone-950" @change="onDistrictChange">
        <option value="">Kecamatan: semua</option>
        <option v-for="o in regionOptions.districts" :key="o.id" :value="o.id">{{ o.name }}</option>
      </select>
      <select v-model="filters.village_id" :disabled="!filters.district_id" class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 disabled:opacity-50 dark:border-stone-700 dark:bg-stone-950">
        <option value="">Kelurahan: semua</option>
        <option v-for="o in regionOptions.villages" :key="o.id" :value="o.id">{{ o.name }}</option>
      </select>
      <div class="flex gap-2 sm:col-span-2">
        <button type="submit" class="rounded-lg bg-brand-600 px-3 py-1.5 font-medium text-white">Terapkan</button>
        <button type="button" class="rounded-lg bg-stone-100 px-3 py-1.5 text-stone-700 dark:bg-stone-800 dark:text-stone-200" @click="resetFilters">Reset</button>
      </div>
    </form>

    <div v-if="loading" class="space-y-3">
      <div v-for="i in 3" :key="i" class="h-24 animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
    </div>
    <div v-else-if="!orders.length" class="flex flex-col items-center gap-3 rounded-2xl bg-stone-100 py-16 text-center dark:bg-stone-900">
      <AppIcon name="clock" :size="40" class="text-stone-300 dark:text-stone-700" />
      <p class="text-sm text-stone-500 dark:text-stone-400">{{ t('orders.empty') }}</p>
      <RouterLink :to="{ name: 'products' }" class="text-sm font-medium text-brand-600 dark:text-brand-400">{{ t('orders.startShopping') }}</RouterLink>
    </div>
    <ul v-else class="space-y-3">
      <li v-for="order in orders" :key="order.id">
        <RouterLink
          :to="{ name: 'order-detail', params: { id: order.id } }"
          class="block rounded-2xl border border-stone-200 bg-white p-4 hover:border-brand-300 dark:border-stone-800 dark:bg-stone-900"
        >
          <div class="flex items-center justify-between gap-2">
            <span class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ order.order_no }}</span>
            <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="STATUS_STYLES[order.status]">
              {{ orderStatusLabel(order.status) }}
            </span>
            <!-- UAT: order status and payment status are independent domains — both badges, each
                 from canonical server state only (paymentBadgeLabel never recomputes the ledger). -->
            <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="PAYMENT_STYLES[paymentBadgeState(order)]">
              {{ paymentBadgeLabel(order) }}
            </span>
          </div>
          <p class="mt-1 text-xs text-stone-400">{{ formatDate(order.created_at) }}</p>
          <p v-if="order.konsumen || order.sales" class="mt-1 text-xs text-stone-500 dark:text-stone-400">
            <span v-if="order.konsumen">{{ order.konsumen.name }}</span>
            <span v-if="order.konsumen && order.sales"> &middot; </span>
            <span v-if="order.sales">Sales: {{ order.sales.name }}</span>
          </p>
          <div class="mt-2 flex items-center justify-between">
            <span class="text-xs text-stone-500 dark:text-stone-400">{{ t('orders.itemsCount', { count: order.items.length }) }}</span>
            <span v-if="order.total_amount !== undefined" class="font-display text-sm font-semibold text-brand-700 dark:text-brand-300">{{ formatRupiah(order.total_amount) }}</span>
          </div>
        </RouterLink>
      </li>
    </ul>

    <div v-if="meta && meta.last_page > 1" class="mt-6 flex items-center justify-center gap-2">
      <button type="button" class="grid h-9 w-9 place-items-center rounded-full border border-stone-200 disabled:opacity-40 dark:border-stone-700" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">
        <AppIcon name="chevron-left" :size="16" />
      </button>
      <span class="text-sm text-stone-600 dark:text-stone-300">{{ meta.current_page }} / {{ meta.last_page }}</span>
      <button type="button" class="grid h-9 w-9 place-items-center rounded-full border border-stone-200 disabled:opacity-40 dark:border-stone-700" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">
        <AppIcon name="chevron-right" :size="16" />
      </button>
    </div>
  </ShopLayout>
</template>
