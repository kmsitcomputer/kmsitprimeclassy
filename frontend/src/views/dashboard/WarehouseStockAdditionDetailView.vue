<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { ApiError } from '@/api/client'
import { approveStockAdditionRequest, getStockAdditionRequest, rejectStockAdditionRequest, type StockAdditionRequest } from '@/api/warehouse'
import { useAuthStore } from '@/stores/auth'
import { formatDateTime, skuLabel } from '@/utils/format'

// Route registers this view with `props: true` (route name: warehouse-stock-addition-detail),
// so the id arrives as a prop and a change re-loads the request below.
const props = defineProps<{ id: number }>()

const router = useRouter()
const auth = useAuthStore()
// Approve/reject are admin-only on the backend (RequireRole("admin")) — UI gate only.
const isAdmin = computed(() => auth.user?.role === 'admin')

const request = ref<StockAdditionRequest | null>(null)
const state = ref<'loading' | 'error' | 'ready'>('loading')
const loadError = ref('')
const error = ref('')
const success = ref('')
const busy = ref<'approve' | 'reject' | null>(null)
const showRejectForm = ref(false)
const rejectReason = ref('')

const TYPE_LABEL: Record<string, string> = { stock_addition: 'Penambahan Stok', sub_adjustment: 'Penyesuaian Stok Sub' }
const STOCK_LABEL: Record<string, string> = { transit: 'Transit', factory_plan: 'Plan Pabrik', sub: 'Sub' }
const STATUS_LABEL: Record<string, string> = { pending: 'Menunggu', approved: 'Disetujui', rejected: 'Ditolak' }
const STATUS_CLASS: Record<string, string> = {
  pending: 'bg-amber-100 text-amber-800',
  approved: 'bg-emerald-100 text-emerald-800',
  rejected: 'bg-red-100 text-red-700',
}

function requestTypeLabel(requestType: string | null | undefined): string {
  return requestType ? (TYPE_LABEL[requestType] ?? requestType) : '-'
}

function stockTypeLabel(stockType: string): string {
  return STOCK_LABEL[stockType] ?? stockType
}

function statusLabel(status: string): string {
  return STATUS_LABEL[status] ?? status
}

function statusClass(status: string): string {
  return STATUS_CLASS[status] ?? 'bg-stone-100 text-stone-600'
}

function messageOf(e: unknown, fallback: string): string {
  return e instanceof ApiError || e instanceof Error ? e.message : fallback
}

async function load() {
  state.value = 'loading'
  loadError.value = ''
  showRejectForm.value = false
  rejectReason.value = ''
  try {
    request.value = await getStockAdditionRequest(props.id)
    state.value = 'ready'
  } catch (e) {
    request.value = null
    state.value = 'error'
    loadError.value = messageOf(e, 'Gagal memuat permintaan penambahan stok.')
  }
}

watch(() => props.id, load, { immediate: true })

async function approve() {
  if (!request.value || busy.value) return
  error.value = ''
  success.value = ''
  busy.value = 'approve'
  try {
    await approveStockAdditionRequest(request.value.id)
    success.value = 'Permintaan disetujui.'
    await load()
  } catch (e) {
    error.value = messageOf(e, 'Gagal menyetujui permintaan.')
  } finally {
    busy.value = null
  }
}

async function reject() {
  if (!request.value || busy.value) return
  error.value = ''
  success.value = ''
  const reason = rejectReason.value.trim()
  if (!reason) {
    error.value = 'Alasan penolakan wajib diisi.'
    return
  }
  busy.value = 'reject'
  try {
    await rejectStockAdditionRequest(request.value.id, reason)
    success.value = 'Permintaan ditolak.'
    showRejectForm.value = false
    rejectReason.value = ''
    await load()
  } catch (e) {
    error.value = messageOf(e, 'Gagal menolak permintaan.')
  } finally {
    busy.value = null
  }
}

function goBack() {
  router.back()
}
</script>

<template>
  <DashboardLayout>
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1 text-sm text-stone-600" @click="goBack">Kembali</button>
      <span v-if="request" class="text-sm text-stone-400">Detail permintaan penambahan stok</span>
    </div>

    <p v-if="error" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>
    <p v-if="success" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-600">{{ success }}</p>

    <div v-if="state === 'loading'" class="text-sm text-stone-400">Memuat...</div>

    <div v-else-if="state === 'error'" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-600">
      {{ loadError }}
      <button type="button" class="ml-2 underline" @click="load()">Coba lagi</button>
    </div>

    <template v-else-if="request">
      <!-- ================= Header ================= -->
      <section class="mb-6 rounded-xl border border-stone-200 bg-white p-4">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
          <h1 class="font-display text-2xl font-semibold">Permintaan #{{ request.id }}</h1>
          <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs text-stone-600">{{ requestTypeLabel(request.request_type) }}</span>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(request.status)">{{ statusLabel(request.status) }}</span>
          </div>
        </div>

        <div class="mb-4 flex flex-wrap items-start gap-4">
          <img v-if="request.product_image_url" :src="request.product_image_url" :alt="request.product_name ?? 'Produk'" class="h-16 w-16 shrink-0 rounded-xl object-cover" />
          <div v-else class="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-stone-100 text-[10px] text-stone-400">Tanpa foto</div>
          <div class="min-w-0">
            <div class="font-semibold text-stone-800">{{ request.product_name ?? '-' }}</div>
            <div v-if="request.variation_label" class="text-xs text-stone-500">Varian: {{ request.variation_label }}</div>
            <div class="text-xs text-stone-400">{{ skuLabel(request.sku ?? null) }}</div>
          </div>
        </div>

        <dl class="grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-xs text-stone-400">Tipe Stok Tujuan</dt>
            <dd class="font-medium">{{ stockTypeLabel(request.target_stock_type) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-stone-400">Sub Lokasi</dt>
            <dd class="font-medium">{{ request.sub_location?.name ?? '-' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-stone-400">Jumlah</dt>
            <dd class="font-medium">{{ request.quantity }}</dd>
          </div>
          <div>
            <dt class="text-xs text-stone-400">Stok Saat Ini</dt>
            <dd class="font-medium">{{ request.current_stock ?? '-' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-stone-400">Pengaju</dt>
            <dd class="font-medium">{{ request.requester?.name ?? '-' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-stone-400">Referensi</dt>
            <dd>{{ request.reference ?? '-' }}</dd>
          </div>
          <div class="sm:col-span-2">
            <dt class="text-xs text-stone-400">Catatan</dt>
            <dd>{{ request.note ?? '-' }}</dd>
          </div>
          <div v-if="request.rejection_reason" class="sm:col-span-2">
            <dt class="text-xs text-stone-400">Alasan Penolakan</dt>
            <dd class="text-red-600">{{ request.rejection_reason }}</dd>
          </div>
        </dl>

        <div class="mt-4 grid gap-2 border-t border-stone-100 pt-3 text-xs text-stone-500">
          <p>Diajukan: <span class="text-stone-700">{{ formatDateTime(request.created_at) }}</span></p>
        </div>

        <!-- ================= Actions (Admin, pending only) ================= -->
        <div v-if="isAdmin && request.status === 'pending'" class="mt-4 flex flex-wrap items-center gap-2 border-t border-stone-100 pt-4">
          <AppButton variant="primary" size="sm" :disabled="busy !== null" @click="approve">
            {{ busy === 'approve' ? 'Memproses...' : 'Setujui' }}
          </AppButton>
          <AppButton variant="danger" size="sm" :disabled="busy !== null" @click="showRejectForm = !showRejectForm">Tolak</AppButton>
        </div>

        <!-- Inline reject form (reason required) -->
        <div v-if="isAdmin && request.status === 'pending' && showRejectForm" class="mt-3 rounded-lg bg-stone-50 p-3">
          <label class="mb-2 block text-xs text-stone-500" for="stock-addition-reject-reason">Alasan penolakan (wajib)</label>
          <div class="flex flex-wrap items-center gap-2">
            <input
              id="stock-addition-reject-reason"
              v-model="rejectReason"
              type="text"
              maxlength="255"
              placeholder="Alasan penolakan"
              class="min-w-0 flex-1 rounded-lg border border-stone-200 px-3 py-2 text-sm"
              :disabled="busy !== null"
            />
            <AppButton variant="danger" size="sm" :disabled="busy !== null" @click="reject">
              {{ busy === 'reject' ? 'Memproses...' : 'Tolak Permintaan' }}
            </AppButton>
          </div>
        </div>
      </section>
    </template>
  </DashboardLayout>
</template>
