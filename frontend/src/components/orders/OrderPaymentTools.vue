<script setup lang="ts">
import { computed, ref } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import PrimeFileUpload from '@/components/ui/PrimeFileUpload.vue'
import { recordInstallment, setOrderDueDate } from '@/api/payments'
import { ApiError } from '@/api/client'
import type { Order } from '@/api/types'
import { formatRupiah, formatDate } from '@/utils/format'

/**
 * Installment / payoff / cash receipt entry + per-order due date + invoice status for ONE order.
 * Pure UI: every rule (scope, amounts, status, self-verification) is enforced by the backend; this
 * component only decides what to offer and surfaces the server's message.
 */
const props = defineProps<{
  order: Order
  /** konsumen | korsal | sales | sales-kurir-sub | agen | admin | keuangan | super_admin */
  role: string
}>()
const emit = defineEmits<{ (e: 'changed'): void }>()

const IN_FULFILMENT = ['diproses', 'dikirim', 'terkirim']

const remaining = computed(() => Number(props.order.payment_summary?.remaining_balance ?? 0))
const hasPendingRecord = computed(() => props.order.payment_summary?.pending_verification_status === 'pending')

/** Same eligibility the server applies (manual transfer order, in fulfilment, balance left, nothing pending). */
const canRecordInstallment = computed(() =>
  props.order.payment_method?.type === 'manual'
  && IN_FULFILMENT.includes(props.order.status)
  && remaining.value > 0
  && !hasPendingRecord.value
  && ['konsumen', 'korsal', 'sales', 'sales-kurir-sub', 'agen', 'admin', 'keuangan', 'super_admin'].includes(props.role),
)
/** Cash is a field-collection channel, never offered to the konsumen. */
const canRecordCash = computed(() => props.role !== 'konsumen')
const canSetDueDate = computed(() => ['admin', 'keuangan', 'super_admin'].includes(props.role))

const amount = ref('')
const channel = ref<'transfer' | 'cash'>('transfer')
const receiptReference = ref('')
const receiptPhoto = ref<File | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)

async function submitInstallment() {
  error.value = null
  notice.value = null
  busy.value = true
  try {
    await recordInstallment(props.order.id, {
      amount: Number(amount.value),
      channel: channel.value,
      receiptReference: channel.value === 'cash' ? receiptReference.value || undefined : undefined,
      receiptPhoto: channel.value === 'cash' ? receiptPhoto.value : null,
    })
    notice.value = channel.value === 'cash'
      ? 'Penerimaan tunai dicatat dan menunggu verifikasi Keuangan.'
      : 'Cicilan dicatat. Unggah bukti transfer agar dapat diverifikasi Keuangan.'
    amount.value = ''
    receiptReference.value = ''
    receiptPhoto.value = null
    emit('changed')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal mencatat pembayaran.'
  } finally {
    busy.value = false
  }
}

const dueDate = ref(props.order.due_date ?? '')
const dueReason = ref('')
const savingDue = ref(false)
const dueError = ref<string | null>(null)

async function saveDueDate() {
  dueError.value = null
  savingDue.value = true
  try {
    await setOrderDueDate(props.order.id, dueDate.value, dueReason.value)
    dueReason.value = ''
    emit('changed')
  } catch (e) {
    dueError.value = e instanceof ApiError ? e.message : 'Gagal menyimpan jatuh tempo.'
  } finally {
    savingDue.value = false
  }
}
</script>

<template>
  <div class="mt-3 space-y-3" data-testid="order-payment-tools">
    <!-- Invoice status: the number is the order number; it exists only once issued. -->
    <p class="text-xs text-stone-500 dark:text-stone-400" data-testid="invoice-status">
      <template v-if="order.invoice_issued_at">
        Invoice <strong>{{ order.order_no }}</strong> terbit {{ formatDate(order.invoice_issued_at) }}
        &middot; {{ remaining > 0 ? (order.payment_method?.type === 'cod' ? 'COD — Belum Dibayar' : 'Belum Lunas') : 'Lunas' }}
      </template>
      <template v-else-if="order.payment_method?.type === 'cod'">Invoice COD belum terbit.</template>
      <template v-else>Invoice belum terbit (terbit setelah pembayaran pertama diverifikasi).</template>
    </p>

    <!-- Due date: visible to every financial viewer, editable by Admin/Keuangan only. -->
    <div class="rounded-lg bg-stone-50 p-3 text-sm dark:bg-stone-800/60">
      <p>
        Jatuh tempo:
        <strong>{{ order.due_date ? formatDate(order.due_date) : 'Belum ditetapkan' }}</strong>
        <span v-if="order.is_overdue" class="ml-2 rounded-full bg-red-600 px-2 py-0.5 text-xs font-semibold text-white">Lewat jatuh tempo</span>
      </p>
      <div v-if="canSetDueDate" class="mt-2 space-y-2">
        <input v-model="dueDate" type="date" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-1.5 text-xs dark:border-stone-700 dark:bg-stone-950" />
        <input v-model="dueReason" type="text" maxlength="255" placeholder="Alasan perubahan" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-1.5 text-xs dark:border-stone-700 dark:bg-stone-950" />
        <AppButton size="sm" :disabled="savingDue || !dueDate || !dueReason.trim()" @click="saveDueDate">Simpan jatuh tempo</AppButton>
        <p v-if="dueError" class="text-xs text-red-600">{{ dueError }}</p>
      </div>
    </div>

    <!-- Installment / payoff / cash receipt -->
    <div v-if="canRecordInstallment" class="rounded-lg border border-stone-200 p-3 dark:border-stone-700" data-testid="installment-form">
      <p class="text-sm font-medium text-stone-800 dark:text-stone-100">Bayar cicilan / pelunasan</p>
      <p class="mb-2 text-xs text-stone-500 dark:text-stone-400">Sisa tagihan {{ formatRupiah(remaining) }}. Pembayaran baru berstatus menunggu verifikasi dan belum mengurangi tagihan.</p>
      <div class="space-y-2">
        <label v-if="canRecordCash" class="block text-xs font-medium text-stone-600 dark:text-stone-300">Metode
          <select v-model="channel" class="ml-2 rounded-lg border border-stone-200 bg-white px-2 py-1 text-xs dark:border-stone-700 dark:bg-stone-950">
            <option value="transfer">Transfer Bank</option>
            <option value="cash">Tunai</option>
          </select>
        </label>
        <input v-model="amount" type="number" min="1" :max="remaining" inputmode="numeric" placeholder="Nominal" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
        <button type="button" class="text-xs font-medium text-brand-600 dark:text-brand-400" @click="amount = String(remaining)">Bayar lunas ({{ formatRupiah(remaining) }})</button>
        <template v-if="channel === 'cash'">
          <input v-model="receiptReference" type="text" maxlength="120" placeholder="No. / referensi tanda terima" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-1.5 text-xs dark:border-stone-700 dark:bg-stone-950" />
          <PrimeFileUpload
            :model-value="receiptPhoto ? [receiptPhoto] : []"
            accept="image/jpeg,image/png,image/webp"
            :max-size-mb="5"
            test-id="cash-receipt-upload"
            @update:model-value="receiptPhoto = $event[0] ?? null"
          />
        </template>
        <AppButton size="sm" :disabled="busy || !(Number(amount) > 0) || (channel === 'cash' && !receiptReference.trim() && !receiptPhoto)" @click="submitInstallment">
          {{ busy ? 'Menyimpan…' : channel === 'cash' ? 'Catat penerimaan tunai' : 'Ajukan pembayaran' }}
        </AppButton>
        <p v-if="error" class="text-xs text-red-600" role="alert">{{ error }}</p>
        <p v-if="notice" class="text-xs text-emerald-600" role="status">{{ notice }}</p>
      </div>
    </div>
  </div>
</template>
