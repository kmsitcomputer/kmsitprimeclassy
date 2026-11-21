<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { ApiError } from '@/api/client'
import { getOpname, type StockOpname, type StockOpnameItem } from '@/api/opnames'
import { formatDateTime, skuLabel } from '@/utils/format'

// Route registers this view with `props: true` (route name: warehouse-opname-detail),
// so the id arrives as a prop and a change re-loads the opname below. Read-only view.
const props = defineProps<{ id: number }>()

const router = useRouter()

const opname = ref<StockOpname | null>(null)
const state = ref<'loading' | 'error' | 'ready'>('loading')
const loadError = ref('')

const STOCK_LABEL: Record<string, string> = { transit: 'Transit', factory_plan: 'Plan Pabrik', sub: 'Sub' }
const TYPE_LABEL: Record<string, string> = { physical_opname: 'Opname Fisik', plan_reconciliation: 'Rekonsiliasi Plan' }
// Copied from WarehouseOpnamesView — the same status vocabulary everywhere.
const STATUS_LABEL: Record<string, string> = { draft: 'Draf', submitted: 'Diajukan', approved: 'Disetujui', rejected: 'Ditolak', cancelled: 'Dibatalkan' }
const STATUS_CLASS: Record<string, string> = {
  draft: 'bg-stone-200 text-stone-600',
  submitted: 'bg-amber-100 text-amber-800',
  approved: 'bg-emerald-100 text-emerald-800',
  rejected: 'bg-red-100 text-red-700',
  cancelled: 'bg-stone-100 text-stone-500',
}

function stockTypeLabel(stockType: string | null): string {
  return stockType ? (STOCK_LABEL[stockType] ?? stockType) : '-'
}

function typeLabel(opnameType: string): string {
  return TYPE_LABEL[opnameType] ?? opnameType
}

function statusLabel(status: string): string {
  return STATUS_LABEL[status] ?? status
}

function statusClass(status: string): string {
  return STATUS_CLASS[status] ?? 'bg-stone-100 text-stone-600'
}

// A non-zero difference is the whole point of an opname — highlight it red.
function diffClass(difference: number | null): string {
  return difference !== null && difference !== 0 ? 'font-semibold text-red-600' : ''
}

function itemName(item: StockOpnameItem): string {
  return item.product?.name ?? (item.product_variation_id != null ? `Varian #${item.product_variation_id}` : `Produk #${item.product_id ?? '-'}`)
}

function messageOf(e: unknown, fallback: string): string {
  return e instanceof ApiError || e instanceof Error ? e.message : fallback
}

async function load() {
  state.value = 'loading'
  loadError.value = ''
  try {
    opname.value = await getOpname(props.id)
    state.value = 'ready'
  } catch (e) {
    opname.value = null
    state.value = 'error'
    loadError.value = messageOf(e, 'Gagal memuat opname.')
  }
}

watch(() => props.id, load, { immediate: true })

function goBack() {
  void router.push({ name: 'warehouse-opnames' })
}
</script>

<template>
  <DashboardLayout>
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1 text-sm text-stone-600" @click="goBack">Kembali</button>
      <span v-if="opname" class="text-sm text-stone-400">Detail stock opname (hanya lihat)</span>
    </div>

    <div v-if="state === 'loading'" class="text-sm text-stone-400">Memuat...</div>

    <div v-else-if="state === 'error'" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-600">
      {{ loadError }}
      <button type="button" class="ml-2 underline" @click="load()">Coba lagi</button>
    </div>

    <template v-else-if="opname">
      <!-- ================= Header ================= -->
      <section class="mb-6 rounded-xl border border-stone-200 bg-white p-4">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
          <h1 class="font-display text-2xl font-semibold">{{ opname.opname_number }}</h1>
          <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(opname.status)">{{ statusLabel(opname.status) }}</span>
        </div>

        <dl class="grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-xs text-stone-400">Tipe Opname</dt>
            <dd class="font-medium">{{ typeLabel(opname.opname_type) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-stone-400">Tipe Stok</dt>
            <dd class="font-medium">{{ stockTypeLabel(opname.stock_type) }}</dd>
          </div>
        </dl>

        <div class="mt-4 grid gap-2 border-t border-stone-100 pt-3 text-xs text-stone-500">
          <p>Dibuat: <span class="text-stone-700">{{ formatDateTime(opname.created_at) }}</span></p>
        </div>
      </section>

      <!-- ================= Items ================= -->
      <section class="rounded-xl border border-stone-200 bg-white p-4">
        <h2 class="mb-3 font-display text-xl font-semibold">Item Opname</h2>
        <div v-if="!opname.items.length" class="text-sm text-stone-400">Tidak ada item pada opname ini.</div>
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[36rem] text-left text-sm">
            <thead class="text-xs uppercase tracking-wide text-stone-400">
              <tr class="border-b border-stone-200">
                <th class="py-2 pr-3 font-medium">Produk</th>
                <th class="py-2 pr-3 font-medium">Varian</th>
                <th class="py-2 pr-3 font-medium">SKU</th>
                <th class="py-2 pr-3 text-right font-medium">Stok Sistem</th>
                <th class="py-2 pr-3 text-right font-medium">Hasil Hitung</th>
                <th class="py-2 pr-3 text-right font-medium">Selisih</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in opname.items" :key="item.id" class="border-b border-stone-100 last:border-0">
                <td class="py-2 pr-3">{{ itemName(item) }}</td>
                <td class="py-2 pr-3 text-stone-500">{{ item.variation?.label ?? '-' }}</td>
                <td class="py-2 pr-3 text-xs text-stone-400">{{ skuLabel(item.variation?.sku ?? item.product?.sku ?? null) }}</td>
                <td class="py-2 pr-3 text-right">{{ item.system_quantity }}</td>
                <td class="py-2 pr-3 text-right">{{ item.counted_quantity ?? '-' }}</td>
                <td class="py-2 pr-3 text-right" :class="diffClass(item.difference)">{{ item.difference ?? '-' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </DashboardLayout>
</template>
