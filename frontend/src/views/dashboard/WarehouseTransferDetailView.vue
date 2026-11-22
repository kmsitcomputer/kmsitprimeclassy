<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { ApiError } from '@/api/client'
import { cancelTransfer, getTransfer, type StockTransfer } from '@/api/transfers'
import { handoverPrintUrl } from '@/api/handovers'
import { useAuthStore } from '@/stores/auth'
import { formatDateTime, skuLabel } from '@/utils/format'

// Route registers this view with `props: true` (route name: warehouse-transfer-detail),
// so the id arrives as a prop and a change re-loads the transfer below.
const props = defineProps<{ id: number }>()

const router = useRouter()
const auth = useAuthStore()
// CancelTransfer is gudang-only on the backend — this is a UI gate only, the backend stays authoritative.
const isGudang = computed(() => auth.user?.role === 'gudang')

const transfer = ref<StockTransfer | null>(null)
// loading | error | ready (a successful load may still hold zero items — that is an empty state, not an error).
const state = ref<'loading' | 'error' | 'ready'>('loading')
const loadError = ref('')
const error = ref('')
const success = ref('')
const cancelling = ref(false)
const showCancelConfirm = ref(false)

const STOCK_LABEL: Record<string, string> = { transit: 'Transit', factory_plan: 'Plan Pabrik', sub: 'Sub' }

// Copied from WarehouseTransfersView — the same badge vocabulary everywhere.
function transferStatusLabel(status: string): string {
  return status === 'pending' ? 'Menunggu' : status === 'completed' ? 'Selesai' : status === 'rejected' ? 'Ditolak' : status === 'cancelled' ? 'Dibatalkan' : status
}

function transferStatusClass(status: string): string {
  return status === 'pending'
    ? 'bg-amber-100 text-amber-800'
    : status === 'completed'
      ? 'bg-emerald-100 text-emerald-800'
      : 'bg-red-100 text-red-700'
}

function stockTypeLabel(stockType: string): string {
  return STOCK_LABEL[stockType] ?? stockType
}

function bucketLabel(stockType: string, subLocationId?: number | null): string {
  return subLocationId != null ? `${stockTypeLabel(stockType)} · Sub Lokasi #${subLocationId}` : stockTypeLabel(stockType)
}

// CancelTransfer (backend/internal/inventory/transfers.go): gudang actor, status must still be
// pending, and neither side may touch the factory_plan bucket (Plan moves only through Admin approval).
const canCancel = computed(() =>
  transfer.value !== null &&
  transfer.value.status === 'pending' &&
  isGudang.value &&
  transfer.value.source_stock_type !== 'factory_plan' &&
  transfer.value.destination_stock_type !== 'factory_plan',
)

function itemName(item: StockTransfer['items'][number]): string {
  return item.product?.name ?? (item.product_variation_id != null ? `Varian #${item.product_variation_id}` : `Produk #${item.product_id ?? '-'}`)
}

function messageOf(e: unknown, fallback: string): string {
  return e instanceof ApiError || e instanceof Error ? e.message : fallback
}

async function load() {
  state.value = 'loading'
  loadError.value = ''
  showCancelConfirm.value = false
  try {
    transfer.value = await getTransfer(props.id)
    state.value = 'ready'
  } catch (e) {
    transfer.value = null
    state.value = 'error'
    loadError.value = messageOf(e, 'Gagal memuat transfer.')
  }
}

watch(() => props.id, load, { immediate: true })

async function confirmCancel() {
  if (!transfer.value || cancelling.value) return
  error.value = ''
  success.value = ''
  cancelling.value = true
  try {
    transfer.value = await cancelTransfer(transfer.value.id)
    success.value = 'Transfer dibatalkan.'
    showCancelConfirm.value = false
  } catch (e) {
    error.value = messageOf(e, 'Gagal membatalkan transfer.')
  } finally {
    cancelling.value = false
  }
}

function printHandover() {
  if (transfer.value?.handover?.id) window.open(handoverPrintUrl(transfer.value.handover.id), '_blank')
}

function goBack() {
  void router.push({ name: 'warehouse-transfers' })
}
</script>

<template>
  <DashboardLayout>
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1 text-sm text-stone-600" @click="goBack">Kembali</button>
      <span v-if="transfer" class="text-sm text-stone-400">Detail stock transfer</span>
    </div>

    <p v-if="error" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>
    <p v-if="success" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-600">{{ success }}</p>

    <div v-if="state === 'loading'" class="text-sm text-stone-400">Memuat...</div>

    <div v-else-if="state === 'error'" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-600">
      {{ loadError }}
      <button type="button" class="ml-2 underline" @click="load()">Coba lagi</button>
    </div>

    <template v-else-if="transfer">
      <!-- ================= Header ================= -->
      <section class="mb-6 rounded-xl border border-stone-200 bg-white p-4">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
          <h1 class="font-display text-2xl font-semibold">{{ transfer.transfer_number }}</h1>
          <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="transferStatusClass(transfer.status)">{{ transferStatusLabel(transfer.status) }}</span>
        </div>

        <dl class="grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-xs text-stone-400">Asal</dt>
            <dd class="font-medium">{{ bucketLabel(transfer.source_stock_type, transfer.source_sub_location_id) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-stone-400">Tujuan</dt>
            <dd class="font-medium">{{ bucketLabel(transfer.destination_stock_type, transfer.destination_sub_location_id) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-stone-400">Referensi</dt>
            <dd>{{ transfer.reference ?? '-' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-stone-400">Pembuat</dt>
            <dd class="font-medium">{{ transfer.creator?.name ?? '-' }}</dd>
          </div>
          <div class="sm:col-span-2">
            <dt class="text-xs text-stone-400">Catatan</dt>
            <dd>{{ transfer.note ?? '-' }}</dd>
          </div>
          <div v-if="transfer.rejection_reason" class="sm:col-span-2">
            <dt class="text-xs text-stone-400">Alasan Penolakan</dt>
            <dd class="text-red-600">{{ transfer.rejection_reason }}</dd>
          </div>
        </dl>

        <div class="mt-4 grid gap-2 border-t border-stone-100 pt-3 text-xs text-stone-500">
          <p>Dibuat: <span class="text-stone-700">{{ formatDateTime(transfer.created_at) }}</span></p>
        </div>

        <!-- ================= Actions ================= -->
        <div v-if="canCancel || transfer.handover?.id" class="mt-4 flex flex-wrap items-center gap-2 border-t border-stone-100 pt-4">
          <AppButton v-if="canCancel" variant="danger" size="sm" :disabled="cancelling" @click="showCancelConfirm = !showCancelConfirm">
            Batalkan Transfer
          </AppButton>
          <AppButton v-if="transfer.handover?.id" variant="secondary" size="sm" @click="printHandover">Cetak Berita Acara</AppButton>
        </div>

        <!-- Inline cancel confirmation -->
        <div v-if="canCancel && showCancelConfirm" class="mt-3 rounded-lg bg-red-50 p-3">
          <p class="mb-2 text-sm text-red-700">Yakin membatalkan transfer ini? Stok tidak akan berpindah.</p>
          <div class="flex flex-wrap items-center gap-2">
            <AppButton variant="danger" size="sm" :disabled="cancelling" @click="confirmCancel">
              {{ cancelling ? 'Memproses...' : 'Ya, Batalkan' }}
            </AppButton>
            <AppButton variant="ghost" size="sm" :disabled="cancelling" @click="showCancelConfirm = false">Batal</AppButton>
          </div>
        </div>
      </section>

      <!-- ================= Items ================= -->
      <section class="rounded-xl border border-stone-200 bg-white p-4">
        <h2 class="mb-3 font-display text-xl font-semibold">Item Transfer</h2>
        <div v-if="!transfer.items.length" class="text-sm text-stone-400">Tidak ada item pada transfer ini.</div>
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[32rem] text-left text-sm">
            <thead class="text-xs uppercase tracking-wide text-stone-400">
              <tr class="border-b border-stone-200">
                <th class="py-2 pr-3 font-medium">Produk</th>
                <th class="py-2 pr-3 font-medium">Varian</th>
                <th class="py-2 pr-3 font-medium">SKU</th>
                <th class="py-2 pr-3 text-right font-medium">Jumlah</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in transfer.items" :key="item.id" class="border-b border-stone-100 last:border-0">
                <td class="py-2 pr-3">{{ itemName(item) }}</td>
                <td class="py-2 pr-3 text-stone-500">{{ item.variation?.label ?? '-' }}</td>
                <td class="py-2 pr-3 text-xs text-stone-400">{{ skuLabel(item.variation?.sku ?? item.product?.sku ?? null) }}</td>
                <td class="py-2 pr-3 text-right font-medium">{{ item.quantity }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </DashboardLayout>
</template>
