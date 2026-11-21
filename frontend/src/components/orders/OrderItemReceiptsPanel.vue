<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { formatDate, formatDateTime } from '@/utils/format'
import { uploadMedia } from '@/api/media'
import { createOrderItemReceipt, listOrderItemReceipts, type OrderItemReceipt } from '@/api/receipts'

/**
 * PENERIMAAN PER-ITEM (REM-07) — riwayat penerimaan fisik SATU baris pesanan dan form pencatatan
 * penerimaan baru (bisa bertahap/parsial). Semua angka ringkasan dihitung dari data server yang
 * baru dimuat, bukan dari tebakan lokal: total diterima = Σ quantity pada riwayat, dan sisa
 * dihitung terhadap `remainingQuantity` bila induknya memberikannya. Bukti foto diunggah dulu ke
 * `/media` (collection `item_receipts`), lalu id-nya dikirim sebagai `evidence_media_id`.
 */
const props = defineProps<{ orderItemId: number; remainingQuantity?: number }>()
const emit = defineEmits<{ recorded: [] }>()

const receipts = ref<OrderItemReceipt[]>([])
const loading = ref(false)
const loadError = ref('')
const formError = ref('')
const success = ref('')
const submitting = ref(false)

/* ---- form draft -------------------------------------------------------------------------- */
const formQuantity = ref('')
const formReceivedOn = ref('')
const formNote = ref('')
const formFile = ref<File | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

/** Business date of today in `YYYY-MM-DD` — the only format the backend accepts for `received_on`. */
function todayIso(): string {
  const now = new Date()
  const y = String(now.getFullYear()).padStart(4, '0')
  const m = String(now.getMonth() + 1).padStart(2, '0')
  const d = String(now.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

function resetForm(): void {
  formQuantity.value = props.remainingQuantity !== undefined && props.remainingQuantity > 0 ? String(props.remainingQuantity) : ''
  formReceivedOn.value = todayIso()
  formNote.value = ''
  formFile.value = null
  if (fileInput.value) fileInput.value.value = ''
}

/* ---- summary ----------------------------------------------------------------------------- */
const totalReceived = computed<number>(() => receipts.value.reduce((total, receipt) => total + receipt.quantity, 0))
const remaining = computed<number | null>(() =>
  props.remainingQuantity !== undefined ? Math.max(0, props.remainingQuantity - totalReceived.value) : null,
)

/* ---- loading / lifecycle ----------------------------------------------------------------- */
function errorText(e: unknown, fallback: string): string {
  return e instanceof ApiError ? formatApiError(e) : fallback
}

async function fetchReceipts(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    receipts.value = await listOrderItemReceipts(props.orderItemId)
  } catch (e) {
    loadError.value = errorText(e, 'Gagal memuat riwayat penerimaan.')
  } finally {
    loading.value = false
  }
}

watch(
  () => props.orderItemId,
  () => {
    receipts.value = []
    formError.value = ''
    success.value = ''
    resetForm()
    void fetchReceipts()
  },
  { immediate: true },
)

/* ---- actions ----------------------------------------------------------------------------- */
function onFileChange(event: Event): void {
  const input = event.target as HTMLInputElement
  formFile.value = input.files?.[0] ?? null
}

async function submit(): Promise<void> {
  if (submitting.value) return
  formError.value = ''
  success.value = ''

  const quantity = Number(formQuantity.value)
  if (!Number.isFinite(quantity) || quantity < 1 || !Number.isInteger(quantity)) {
    formError.value = 'Jumlah harus berupa bilangan bulat minimal 1.'
    return
  }
  const receivedOn = formReceivedOn.value.trim()
  if (!/^\d{4}-\d{2}-\d{2}$/.test(receivedOn)) {
    formError.value = 'Tanggal terima wajib diisi dengan format YYYY-MM-DD.'
    return
  }
  const note = formNote.value.trim()
  if (note.length > 500) {
    formError.value = 'Catatan bukti maksimal 500 karakter.'
    return
  }

  submitting.value = true
  try {
    let evidenceMediaId: number | undefined
    if (formFile.value) {
      const media = await uploadMedia(formFile.value, 'item_receipts')
      evidenceMediaId = media.id
    }
    await createOrderItemReceipt(props.orderItemId, {
      quantity,
      received_on: receivedOn,
      ...(note ? { evidence_note: note } : {}),
      ...(evidenceMediaId !== undefined ? { evidence_media_id: evidenceMediaId } : {}),
    })
    success.value = 'Penerimaan berhasil dicatat.'
    resetForm()
    await fetchReceipts()
    emit('recorded')
  } catch (e) {
    formError.value = errorText(e, 'Pencatatan penerimaan gagal diproses.')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section
    data-testid="order-item-receipts"
    class="space-y-3 rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900"
    role="region"
    aria-label="Penerimaan item"
  >
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div>
        <h2 class="font-display text-sm font-semibold text-stone-800 dark:text-stone-100">Penerimaan Item</h2>
        <p class="text-xs text-stone-500 dark:text-stone-400">
          Riwayat penerimaan fisik baris pesanan ini. Penerimaan dapat dicatat bertahap per jumlah yang datang.
        </p>
      </div>
      <AppButton size="sm" variant="secondary" :disabled="loading" @click="fetchReceipts()">
        {{ loading ? 'Memuat…' : 'Muat ulang' }}
      </AppButton>
    </div>

    <p v-if="loadError" role="alert" class="text-sm text-red-600 dark:text-red-400">{{ loadError }}</p>
    <p v-if="success" role="status" class="text-sm text-emerald-600 dark:text-emerald-400">{{ success }}</p>

    <!-- Ringkasan -->
    <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3" data-testid="receipts-summary">
      <div class="rounded-xl bg-stone-50 p-2 dark:bg-stone-800/60">
        <dt class="text-[11px] text-stone-500 dark:text-stone-400">Total diterima</dt>
        <dd class="font-semibold text-stone-800 dark:text-stone-100" data-testid="receipts-total">{{ totalReceived }}</dd>
      </div>
      <div v-if="remaining !== null" class="rounded-xl bg-stone-50 p-2 dark:bg-stone-800/60">
        <dt class="text-[11px] text-stone-500 dark:text-stone-400">Sisa belum diterima</dt>
        <dd
          class="font-semibold"
          :class="remaining > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400'"
          data-testid="receipts-remaining"
        >
          {{ remaining }}
        </dd>
      </div>
    </dl>

    <!-- Riwayat -->
    <div v-if="loading && !receipts.length" class="space-y-2" role="status" aria-live="polite">
      <p class="text-sm text-stone-500 dark:text-stone-400">Memuat riwayat penerimaan…</p>
      <div v-for="i in 2" :key="i" class="h-10 animate-pulse rounded-xl bg-stone-100 dark:bg-stone-800" />
    </div>
    <div v-else-if="receipts.length" class="overflow-x-auto">
      <table class="w-full min-w-[32rem] text-left text-sm">
        <thead class="text-xs text-stone-500 dark:text-stone-400">
          <tr>
            <th class="py-1 pr-2 text-right font-medium">Jumlah</th>
            <th class="px-2 py-1 font-medium">Tanggal terima</th>
            <th class="px-2 py-1 font-medium">Dicatat</th>
            <th class="px-2 py-1 font-medium">Diverifikasi</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="receipt in receipts"
            :key="receipt.id"
            class="border-t border-stone-200 dark:border-stone-700"
            :data-testid="`receipt-${receipt.id}`"
          >
            <td class="py-1.5 pr-2 text-right font-medium text-stone-800 dark:text-stone-100">{{ receipt.quantity }}</td>
            <td class="px-2 py-1.5">{{ formatDate(receipt.received_on) }}</td>
            <td class="px-2 py-1.5 text-xs">{{ formatDateTime(receipt.received_at) }}</td>
            <td class="px-2 py-1.5 text-xs">{{ formatDateTime(receipt.verified_at) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-else class="py-4 text-center text-sm text-stone-400">Belum ada penerimaan.</p>

    <!-- Form catat penerimaan -->
    <div
      class="space-y-2 rounded-xl border border-stone-200 bg-white p-3 text-xs dark:border-stone-700 dark:bg-stone-900"
      data-testid="receipt-form"
    >
      <p class="text-sm font-semibold text-stone-800 dark:text-stone-100">Catat Penerimaan</p>
      <p v-if="remaining !== null && remaining > 0" class="text-stone-500 dark:text-stone-400">
        Sisa belum diterima {{ remaining }} unit.
      </p>

      <div class="grid gap-2 sm:grid-cols-2">
        <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
          Jumlah diterima
          <input
            v-model="formQuantity"
            type="number"
            min="1"
            step="1"
            inputmode="numeric"
            class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950"
          />
        </label>
        <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
          Tanggal terima (YYYY-MM-DD)
          <input
            v-model="formReceivedOn"
            type="date"
            required
            class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950"
          />
        </label>
      </div>

      <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
        Catatan bukti (opsional, maks. 500 karakter)
        <textarea
          v-model="formNote"
          rows="2"
          maxlength="500"
          class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950"
        />
      </label>

      <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
        Foto bukti (opsional)
        <input
          ref="fileInput"
          type="file"
          accept="image/*"
          class="mt-1 w-full text-sm"
          data-testid="receipt-evidence-input"
          @change="onFileChange"
        />
      </label>

      <p v-if="formError" role="alert" class="text-xs text-red-600 dark:text-red-400">{{ formError }}</p>

      <AppButton size="sm" :disabled="submitting || loading" data-testid="receipt-submit" @click="submit()">
        {{ submitting ? 'Menyimpan…' : 'Catat Penerimaan' }}
      </AppButton>
    </div>
  </section>
</template>
