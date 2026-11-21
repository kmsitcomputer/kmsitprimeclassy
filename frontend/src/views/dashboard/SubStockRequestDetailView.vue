<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { getSubStockRequest, subStockAction, type SubStockRequest, type SubStockRequestItem } from '@/api/subStock'
import { ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { formatDateTime, skuLabel } from '@/utils/format'

// Route registers this view with `props: true` (route name: sub-stock-request-detail),
// so the id arrives as a prop and a change re-loads the request below.
const props = defineProps<{ id: number }>()

const router = useRouter()
const auth = useAuthStore()

type Action = 'approve' | 'reject' | 'cancel' | 'execute' | 'receive'

const request = ref<SubStockRequest | null>(null)
// loading | error | ready (a successful load may still hold zero items — that is an empty state, not an error).
const state = ref<'loading' | 'error' | 'ready'>('loading')
const loadError = ref('')
const error = ref('')
const success = ref('')
const busy = ref<Action | null>(null)
const showRejectForm = ref(false)
const rejectReason = ref('')
const showCancelForm = ref(false)
const cancelReason = ref('')

// UI gates only — the backend remains authoritative for every action (policy + requester checks).
const isAdmin = computed(() => auth.user?.role === 'admin')
const isGudang = computed(() => auth.user?.role === 'gudang')

const directionLabel = computed(() => (request.value?.direction === 'replenish' ? 'Replenish' : 'Retur'))

const STATUS_LABELS: Record<string, string> = {
  requested: 'Menunggu Persetujuan',
  approved: 'Disetujui',
  rejected: 'Ditolak',
  cancelled: 'Dibatalkan',
  executed: 'Dieksekusi',
  received: 'Diterima',
}

const STATUS_CLASSES: Record<string, string> = {
  requested: 'bg-amber-100 text-amber-800',
  approved: 'bg-blue-100 text-blue-800',
  rejected: 'bg-red-100 text-red-700',
  cancelled: 'bg-stone-200 text-stone-600',
  executed: 'bg-indigo-100 text-indigo-800',
  received: 'bg-emerald-100 text-emerald-800',
}

const SUCCESS_MESSAGES: Record<Action, string> = {
  approve: 'Permintaan disetujui.',
  reject: 'Permintaan ditolak.',
  cancel: 'Permintaan dibatalkan.',
  execute: 'Permintaan dieksekusi. Stok Sub diperbarui.',
  receive: 'Penerimaan dikonfirmasi.',
}

function statusLabel(status: string): string {
  return STATUS_LABELS[status] ?? status
}

function statusClass(status: string): string {
  return STATUS_CLASSES[status] ?? 'bg-stone-100 text-stone-600'
}

function itemName(item: SubStockRequestItem): string {
  return item.product?.name ?? (item.product_variation_id != null ? `Varian #${item.product_variation_id}` : `Produk #${item.product_id ?? '-'}`)
}

function itemSku(item: SubStockRequestItem): string {
  return skuLabel(item.product?.sku ?? item.variation?.sku ?? null)
}

function messageOf(e: unknown, fallback: string): string {
  return e instanceof ApiError || e instanceof Error ? e.message : fallback
}

async function load() {
  state.value = 'loading'
  loadError.value = ''
  try {
    request.value = await getSubStockRequest(props.id)
    state.value = 'ready'
  } catch (e) {
    request.value = null
    state.value = 'error'
    loadError.value = messageOf(e, 'Gagal memuat permintaan stok Sub.')
  }
}

watch(() => props.id, load, { immediate: true })

async function runAction(action: Action, body: { reason?: string } = {}) {
  if (busy.value) return
  error.value = ''
  success.value = ''
  busy.value = action
  try {
    await subStockAction(props.id, action, body)
    success.value = SUCCESS_MESSAGES[action]
    showRejectForm.value = false
    showCancelForm.value = false
    rejectReason.value = ''
    cancelReason.value = ''
    await load()
  } catch (e) {
    error.value = messageOf(e, 'Aksi gagal diproses.')
  } finally {
    busy.value = null
  }
}

function submitReject() {
  const reason = rejectReason.value.trim()
  if (!reason) {
    error.value = 'Alasan penolakan wajib diisi.'
    success.value = ''
    return
  }
  void runAction('reject', { reason })
}

function submitCancel() {
  const reason = cancelReason.value.trim()
  void runAction('cancel', reason ? { reason } : {})
}

function goBack() {
  void router.push({ name: 'sub-stock' })
}
</script>

<template>
  <DashboardLayout>
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1 text-sm text-stone-600" @click="goBack">Kembali</button>
      <span v-if="request" class="text-sm text-stone-400">Detail permintaan stok Sub</span>
    </div>

    <p v-if="error" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>
    <p v-if="success" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-600">{{ success }}</p>

    <div v-if="state === 'loading'" class="text-sm text-stone-400">Memuat permintaan...</div>

    <div v-else-if="state === 'error'" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-600">
      {{ loadError }}
      <button type="button" class="ml-2 underline" @click="load()">Coba lagi</button>
    </div>

    <template v-else-if="request">
      <!-- ================= Header ================= -->
      <section class="mb-6 rounded-xl border border-stone-200 bg-white p-4">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
          <h1 class="font-display text-2xl font-semibold">{{ request.request_number }}</h1>
          <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs text-stone-600">{{ directionLabel }}</span>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(request.status)">{{ statusLabel(request.status) }}</span>
          </div>
        </div>

        <dl class="grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-xs text-stone-400">Sub Location</dt>
            <dd class="font-medium">{{ request.sub_location?.name ?? '-' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-stone-400">Pengaju</dt>
            <dd class="font-medium">{{ request.requester?.name ?? '-' }}</dd>
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

        <div class="mt-4 grid gap-2 border-t border-stone-100 pt-3 text-xs text-stone-500 sm:grid-cols-2 lg:grid-cols-3">
          <p>Diajukan: <span class="text-stone-700">{{ formatDateTime(request.created_at) }}</span></p>
          <p>Disetujui: <span class="text-stone-700">{{ formatDateTime(request.approved_at) }}</span></p>
          <p>Ditolak: <span class="text-stone-700">{{ formatDateTime(request.rejected_at) }}</span></p>
          <p>Dibatalkan: <span class="text-stone-700">{{ formatDateTime(request.cancelled_at) }}</span></p>
          <p>Dieksekusi: <span class="text-stone-700">{{ formatDateTime(request.executed_at) }}</span></p>
          <p>Diterima: <span class="text-stone-700">{{ formatDateTime(request.received_at) }}</span></p>
        </div>

        <!-- ================= Actions ================= -->
        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-stone-100 pt-4">
          <template v-if="request.status === 'requested'">
            <template v-if="isAdmin">
              <button
                type="button"
                :disabled="busy !== null"
                class="rounded-lg bg-emerald-600 px-3 py-1 text-sm text-white disabled:opacity-40"
                @click="runAction('approve')"
              >
                {{ busy === 'approve' ? 'Memproses...' : 'Setujui' }}
              </button>
              <button
                type="button"
                :disabled="busy !== null"
                class="rounded-lg bg-red-600 px-3 py-1 text-sm text-white disabled:opacity-40"
                @click="showRejectForm = !showRejectForm"
              >
                Tolak
              </button>
            </template>
          </template>

          <button
            v-if="request.status === 'requested' || request.status === 'approved'"
            type="button"
            :disabled="busy !== null"
            class="rounded-lg bg-stone-200 px-3 py-1 text-sm text-stone-700 disabled:opacity-40"
            @click="showCancelForm = !showCancelForm"
          >
            Batalkan
          </button>

          <button
            v-if="request.status === 'approved' && isGudang"
            type="button"
            :disabled="busy !== null"
            class="rounded-lg bg-brand-600 px-3 py-1 text-sm text-white disabled:opacity-40"
            @click="runAction('execute')"
          >
            {{ busy === 'execute' ? 'Memproses...' : 'Eksekusi' }}
          </button>

          <button
            v-if="request.status === 'executed'"
            type="button"
            :disabled="busy !== null"
            class="rounded-lg bg-emerald-600 px-3 py-1 text-sm text-white disabled:opacity-40"
            @click="runAction('receive')"
          >
            {{ busy === 'receive' ? 'Memproses...' : 'Konfirmasi Terima' }}
          </button>
        </div>

        <!-- Inline reject form (Admin only, reason required) -->
        <div v-if="isAdmin && request.status === 'requested' && showRejectForm" class="mt-3 rounded-lg bg-stone-50 p-3">
          <label class="mb-2 block text-xs text-stone-500" for="sub-stock-reject-reason">Alasan penolakan (wajib)</label>
          <div class="flex flex-wrap items-center gap-2">
            <input
              id="sub-stock-reject-reason"
              v-model="rejectReason"
              type="text"
              maxlength="255"
              placeholder="Alasan penolakan"
              class="min-w-0 flex-1 rounded-lg border border-stone-200 px-3 py-2 text-sm"
              :disabled="busy !== null"
            />
            <button
              type="button"
              :disabled="busy !== null"
              class="rounded-lg bg-red-600 px-3 py-1 text-sm text-white disabled:opacity-40"
              @click="submitReject"
            >
              {{ busy === 'reject' ? 'Memproses...' : 'Tolak Permintaan' }}
            </button>
          </div>
        </div>

        <!-- Inline cancel form (reason optional) -->
        <div v-if="(request.status === 'requested' || request.status === 'approved') && showCancelForm" class="mt-3 rounded-lg bg-stone-50 p-3">
          <label class="mb-2 block text-xs text-stone-500" for="sub-stock-cancel-reason">Alasan pembatalan (opsional)</label>
          <div class="flex flex-wrap items-center gap-2">
            <input
              id="sub-stock-cancel-reason"
              v-model="cancelReason"
              type="text"
              maxlength="255"
              placeholder="Alasan pembatalan"
              class="min-w-0 flex-1 rounded-lg border border-stone-200 px-3 py-2 text-sm"
              :disabled="busy !== null"
            />
            <button
              type="button"
              :disabled="busy !== null"
              class="rounded-lg bg-stone-700 px-3 py-1 text-sm text-white disabled:opacity-40"
              @click="submitCancel"
            >
              {{ busy === 'cancel' ? 'Memproses...' : 'Batalkan Permintaan' }}
            </button>
          </div>
        </div>
      </section>

      <!-- ================= Items ================= -->
      <section class="rounded-xl border border-stone-200 bg-white p-4">
        <h2 class="mb-3 font-display text-xl font-semibold">Item Permintaan</h2>
        <div v-if="!request.items.length" class="text-sm text-stone-400">Tidak ada item pada permintaan ini.</div>
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
              <tr v-for="item in request.items" :key="item.id" class="border-b border-stone-100 last:border-0">
                <td class="py-2 pr-3">{{ itemName(item) }}</td>
                <td class="py-2 pr-3 text-stone-500">{{ item.variation?.sku ?? '-' }}</td>
                <td class="py-2 pr-3 text-xs text-stone-400">{{ itemSku(item) }}</td>
                <td class="py-2 pr-3 text-right font-medium">{{ item.quantity }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </DashboardLayout>
</template>
