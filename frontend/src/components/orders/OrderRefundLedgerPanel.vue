<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import PrimeFileUpload from '@/components/ui/PrimeFileUpload.vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { formatDate, formatRupiah } from '@/utils/format'
import { secureUuid } from '@/utils/uuid'
import { uploadMedia } from '@/api/media'
import {
  approveRefundObligation,
  cancelRefundAttempt,
  completeRefundAttempt,
  createRefundAttempt,
  failRefundAttempt,
  finalizeCancellation,
  getOrderRefundLedger,
  initiateRefundAttempt,
  markRefundAttemptUnknown,
  materializeDeferred,
  reconcileRefundAttempt,
  reinstateSalesReduction,
  requestDeferredMaterialization,
  resolveRefundVerification,
  type CreateRefundAttemptPayload,
  type OrderRefundLedger,
  type RefundAttempt,
  type RefundAttemptMethod,
  type RefundObligation,
  type RefundPayout,
} from '@/api/refunds'

/**
 * BUKU REFUND PESANAN — the money-out side of ONE order: what is owed (refund_obligations), the payment
 * attempts against it, the realised payouts, and the sales reductions that can be given back.
 *
 * The panel never derives a financial truth of its own: every figure in the summary comes from the server
 * (`metrics_cents`), and after EVERY successful action the ledger is re-read instead of guessing the new
 * state. Only the "sisa" per obligation is computed locally (amount − sum of its payouts) because the API
 * nests the payouts but does not expose that single line. All eligibility rules (status transitions, role,
 * amount limits, idempotency) stay with the backend; this component only offers plausible actions.
 */
const props = defineProps<{ orderId: number }>()
/** Tells the parent a mutation succeeded so it can refresh order-level data (paid_amount, status). */
const emit = defineEmits<{ changed: [] }>()

const ledger = ref<OrderRefundLedger | null>(null)
const loading = ref(false)
const loadError = ref('')
const actionError = ref('')
const formError = ref('')
const success = ref('')
/** One action in flight at a time, keyed so the matching button can show its busy label. */
const busy = ref<string | null>(null)

const expanded = ref<Record<number, boolean>>({})

type FormKind =
  | 'attempt'
  | 'verification'
  | 'deferred_request'
  | 'deferred_materialize'
  | 'reinstate'
  | 'initiate'
  | 'cancel_attempt'
  | 'complete'
  | 'fail'
  | 'unknown'
  | 'reconcile'

interface ActiveForm {
  kind: FormKind
  id: number
}

const activeForm = ref<ActiveForm | null>(null)

/* ---- form draft (one open form at a time) ------------------------------------------------ */
const formAmount = ref('')
const formMethod = ref<RefundAttemptMethod>('bank_transfer')
const formExternalReference = ref('')
const formDestination = ref('')
const formExit = ref<'A' | 'B'>('A')
const formEvidence = ref('')
const formReason = ref('')
const formCode = ref('')
const formMessage = ref('')
const formNote = ref('')
const formProofType = ref('')
const formGatewayReference = ref('')
const formSucceeded = ref(true)
const formProof = ref<File | null>(null)
/** Stable Idempotency-Key for one open form, so an uncertain retry reuses the same logical request. */
const formKey = ref('')

const finalizeConfirm = ref(false)

/* ---- labels ------------------------------------------------------------------------------ */
const obligationStatusLabel: Record<RefundObligation['status'], string> = {
  pending: 'Menunggu persetujuan',
  approved: 'Disetujui',
  partially_paid: 'Dibayar sebagian',
  completed: 'Selesai',
  needs_verification: 'Perlu verifikasi',
  cancelled: 'Dibatalkan',
}
const obligationStatusClass: Record<RefundObligation['status'], string> = {
  pending: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  approved: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
  partially_paid: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300',
  completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  needs_verification: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
  cancelled: 'bg-stone-200 text-stone-700 dark:bg-stone-800 dark:text-stone-300',
}
const attemptStatusLabel: Record<RefundAttempt['status'], string> = {
  created: 'Dibuat',
  processing: 'Diproses',
  succeeded: 'Berhasil',
  failed: 'Gagal',
  unknown: 'Tidak diketahui',
  cancelled: 'Dibatalkan',
}
const attemptStatusClass: Record<RefundAttempt['status'], string> = {
  created: 'bg-stone-200 text-stone-700 dark:bg-stone-800 dark:text-stone-300',
  processing: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
  succeeded: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
  unknown: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  cancelled: 'bg-stone-200 text-stone-700 dark:bg-stone-800 dark:text-stone-300',
}
const methodLabel: Record<RefundAttemptMethod, string> = {
  bank_transfer: 'Transfer bank',
  gateway: 'Gateway',
  cash: 'Tunai',
}
const sourceLabel: Record<RefundObligation['source_type'], string> = {
  return_stage: 'Return',
  fulfillment_shortfall: 'Kekurangan pemenuhan',
  cancellation: 'Pembatalan',
  manual: 'Manual',
  legacy: 'Legacy',
  deferred: 'Ditangguhkan',
}
const payoutVerificationLabel: Record<RefundPayout['verification_status'], string> = {
  proven: 'Terbukti',
  unverified: 'Belum terverifikasi',
  rejected: 'Ditolak',
}
const payoutVerificationClass: Record<RefundPayout['verification_status'], string> = {
  proven: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  unverified: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  rejected: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
}
const formHeading: Record<FormKind, string> = {
  attempt: 'Buat attempt pembayaran',
  verification: 'Selesaikan verifikasi kewajiban',
  deferred_request: 'Minta realisasi nilai ditangguhkan',
  deferred_materialize: 'Realisasikan nilai ditangguhkan',
  reinstate: 'Kembalikan (reinstate) pengurangan penjualan',
  initiate: 'Mulai proses attempt',
  cancel_attempt: 'Batalkan attempt',
  complete: 'Selesaikan attempt (dana terkirim)',
  fail: 'Tandai attempt gagal',
  unknown: 'Tandai attempt tidak diketahui',
  reconcile: 'Rekonsiliasi attempt',
}

/* ---- money helpers ----------------------------------------------------------------------- */
function toCents(decimal: string): number {
  const value = Number(decimal)
  return Number.isFinite(value) ? Math.round(value * 100) : 0
}

function idr(cents: number): string {
  return formatRupiah(cents / 100)
}

function amountToCents(raw: string): number {
  const value = Number(raw)
  return Number.isFinite(value) && value > 0 ? Math.round(value * 100) : 0
}

function paidCents(obligation: RefundObligation): number {
  return obligation.payouts.reduce((total, payout) => total + toCents(payout.amount), 0)
}

/** Local remainder only: obligation amount minus what its payouts already realised. */
function remainingCents(obligation: RefundObligation): number {
  return Math.max(0, toCents(obligation.amount) - paidCents(obligation))
}

function sortedAttempts(obligation: RefundObligation): RefundAttempt[] {
  return [...obligation.attempts].sort((a, b) => b.id - a.id)
}

/* ---- summary metrics (server-computed, cents) -------------------------------------------- */
interface MetricRow {
  key: string
  label: string
  value: number
  tone: string
}

const metrics = computed<MetricRow[]>(() => {
  const m = ledger.value?.metrics_cents
  if (!m) return []
  return [
    { key: 'gross', label: 'Nilai bruto', value: m.gross_cents, tone: '' },
    { key: 'paid', label: 'Sudah dibayar', value: m.paid_cents, tone: 'text-emerald-700 dark:text-emerald-400' },
    { key: 'billable', label: 'Dapat ditagih', value: m.billable_cents, tone: '' },
    { key: 'outstanding', label: 'Sisa kewajiban', value: m.outstanding_cents, tone: 'text-amber-700 dark:text-amber-400' },
    { key: 'overpayment', label: 'Kelebihan bayar', value: m.overpayment_cents, tone: 'text-red-700 dark:text-red-400' },
    { key: 'reserved', label: 'Direservasi', value: m.reserved_cents, tone: '' },
    { key: 'available', label: 'Tersedia', value: m.available_cents, tone: '' },
    { key: 'applied', label: 'Sudah diterapkan', value: m.applied_cents, tone: '' },
    { key: 'proven', label: 'Terbukti', value: m.proven_cents, tone: 'text-emerald-700 dark:text-emerald-400' },
    { key: 'unverified', label: 'Belum terverifikasi', value: m.unverified_cents, tone: 'text-amber-700 dark:text-amber-400' },
  ]
})

/* ---- loading / lifecycle ----------------------------------------------------------------- */
function errorText(e: unknown, fallback: string): string {
  return e instanceof ApiError ? formatApiError(e) : fallback
}

async function fetchLedger(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    ledger.value = await getOrderRefundLedger(props.orderId)
  } catch (e) {
    loadError.value = errorText(e, 'Gagal memuat buku refund pesanan.')
  } finally {
    loading.value = false
  }
}

watch(
  () => props.orderId,
  () => {
    ledger.value = null
    activeForm.value = null
    success.value = ''
    void fetchLedger()
  },
  { immediate: true },
)

/* ---- form state -------------------------------------------------------------------------- */
function resetDraft(): void {
  formAmount.value = ''
  formMethod.value = 'bank_transfer'
  formExternalReference.value = ''
  formDestination.value = ''
  formExit.value = 'A'
  formEvidence.value = ''
  formReason.value = ''
  formCode.value = ''
  formMessage.value = ''
  formNote.value = ''
  formProofType.value = ''
  formGatewayReference.value = ''
  formSucceeded.value = true
  formProof.value = null
  formError.value = ''
}

function openForm(kind: FormKind, id: number, defaultAmountCents?: number): void {
  if (busy.value !== null) return
  actionError.value = ''
  formError.value = ''
  success.value = ''
  resetDraft()
  activeForm.value = { kind, id }
  formKey.value = secureUuid()
  if (defaultAmountCents !== undefined && defaultAmountCents > 0) {
    formAmount.value = String(Math.round(defaultAmountCents / 100))
  }
}

function closeForm(): void {
  activeForm.value = null
  formError.value = ''
  resetDraft()
}

function isForm(kind: FormKind, id: number): boolean {
  return activeForm.value?.kind === kind && activeForm.value.id === id
}

function obligationForForm(form: ActiveForm): RefundObligation | null {
  const obligations = ledger.value?.obligations ?? []
  return (
    obligations.find((ob) => ob.id === form.id || ob.attempts.some((a) => a.id === form.id)) ?? null
  )
}

function attemptForForm(form: ActiveForm): RefundAttempt | null {
  const obligations = ledger.value?.obligations ?? []
  for (const ob of obligations) {
    const attempt = ob.attempts.find((a) => a.id === form.id)
    if (attempt) return attempt
  }
  return null
}

function hasOpenFormFor(obligation: RefundObligation): boolean {
  const form = activeForm.value
  if (!form) return false
  if (form.kind === 'reinstate') return false
  return form.id === obligation.id || obligation.attempts.some((a) => a.id === form.id)
}

const formTitle = computed<string>(() => {
  const form = activeForm.value
  if (!form) return ''
  return formHeading[form.kind]
})

const formContext = computed<string>(() => {
  const form = activeForm.value
  if (!form) return ''
  const attempt = attemptForForm(form)
  if (attempt) return `Attempt ${attempt.attempt_no}`
  const obligation = obligationForForm(form)
  return obligation ? obligation.obligation_no : ''
})

/* ---- actions ----------------------------------------------------------------------------- */
async function runAction(key: string, fn: () => Promise<unknown>, message: string): Promise<boolean> {
  if (busy.value !== null) return false
  busy.value = key
  actionError.value = ''
  success.value = ''
  try {
    await fn()
    success.value = message
    activeForm.value = null
    await fetchLedger()
    emit('changed')
    return true
  } catch (e) {
    actionError.value = errorText(e, 'Aksi refund gagal diproses.')
    return false
  } finally {
    busy.value = null
  }
}

async function approve(obligation: RefundObligation): Promise<void> {
  await runAction(`approve-${obligation.id}`, () => approveRefundObligation(obligation.id), 'Kewajiban refund disetujui.')
}

async function finalize(): Promise<void> {
  const ok = await runAction('finalize', () => finalizeCancellation(props.orderId), 'Pembatalan difinalisasi dan kewajiban refund dikunci.')
  if (ok) finalizeConfirm.value = false
}

async function submitForm(): Promise<void> {
  const form = activeForm.value
  if (!form || busy.value !== null) return
  formError.value = ''
  busy.value = `${form.kind}-${form.id}`

  try {
    if (form.kind === 'reinstate') {
      const evidence = formEvidence.value.trim()
      if (evidence.length < 3) {
        formError.value = 'Bukti (evidence) wajib diisi minimal 3 karakter.'
        return
      }
      const reason = formReason.value.trim()
      await reinstateSalesReduction(form.id, { evidence, ...(reason ? { reason } : {}) })
      success.value = 'Pengurangan penjualan berhasil di-reinstate.'
      activeForm.value = null
      await fetchLedger()
      return
    }

    const obligation = obligationForForm(form)
    if (!obligation) {
      formError.value = 'Data kewajiban tidak ditemukan. Muat ulang panel.'
      return
    }

    switch (form.kind) {
      case 'attempt': {
        const cents = amountToCents(formAmount.value)
        const limit = remainingCents(obligation)
        if (cents < 1) {
          formError.value = 'Nominal harus lebih dari Rp0.'
          return
        }
        if (cents > limit) {
          formError.value = `Nominal melebihi sisa kewajiban (${idr(limit)}).`
          return
        }
        const payload: CreateRefundAttemptPayload = { amount_cents: cents, method: formMethod.value }
        const reference = formExternalReference.value.trim()
        if (reference) payload.external_reference = reference
        const destination = formDestination.value.trim()
        if (destination) payload.destination_snapshot = { label: destination }
        await createRefundAttempt(obligation.id, payload, formKey.value)
        success.value = 'Attempt pembayaran dibuat.'
        break
      }
      case 'verification': {
        const evidence = formEvidence.value.trim()
        if (evidence.length < 3) {
          formError.value = 'Bukti (evidence) wajib diisi minimal 3 karakter.'
          return
        }
        const reason = formReason.value.trim()
        await resolveRefundVerification(obligation.id, {
          exit: formExit.value,
          evidence,
          ...(reason ? { reason } : {}),
        })
        success.value = 'Verifikasi kewajiban refund diselesaikan.'
        break
      }
      case 'deferred_request':
      case 'deferred_materialize': {
        const cents = amountToCents(formAmount.value)
        if (cents < 1) {
          formError.value = 'Nominal harus lebih dari Rp0.'
          return
        }
        if (form.kind === 'deferred_request') {
          await requestDeferredMaterialization(obligation.id, cents, formKey.value)
          success.value = 'Permintaan realisasi nilai ditangguhkan dikirim.'
        } else {
          await materializeDeferred(obligation.id, cents, formKey.value)
          success.value = 'Nilai ditangguhkan direalisasikan menjadi kewajiban anak.'
        }
        break
      }
      case 'initiate': {
        const attempt = attemptForForm(form)
        if (!attempt) {
          formError.value = 'Data attempt tidak ditemukan. Muat ulang panel.'
          return
        }
        const reference = formExternalReference.value.trim()
        await initiateRefundAttempt(attempt.id, reference ? { external_reference: reference } : {})
        success.value = 'Attempt mulai diproses.'
        break
      }
      case 'cancel_attempt': {
        const attempt = attemptForForm(form)
        if (!attempt) {
          formError.value = 'Data attempt tidak ditemukan. Muat ulang panel.'
          return
        }
        const reason = formReason.value.trim()
        if (!reason) {
          formError.value = 'Alasan pembatalan wajib diisi.'
          return
        }
        if (reason.length > 255) {
          formError.value = 'Alasan pembatalan maksimal 255 karakter.'
          return
        }
        await cancelRefundAttempt(attempt.id, reason)
        success.value = 'Attempt dibatalkan.'
        break
      }
      case 'complete': {
        const attempt = attemptForForm(form)
        if (!attempt) {
          formError.value = 'Data attempt tidak ditemukan. Muat ulang panel.'
          return
        }
        let proofMediaId: number | undefined
        if (formProof.value) {
          const media = await uploadMedia(formProof.value, 'cod_payment_proof')
          proofMediaId = media.id
        }
        const proofType = formProofType.value.trim()
        const gatewayReference = formGatewayReference.value.trim()
        await completeRefundAttempt(attempt.id, {
          ...(proofMediaId !== undefined ? { proof_media_id: proofMediaId } : {}),
          ...(proofType ? { proof_type: proofType } : {}),
          ...(gatewayReference ? { gateway_reference: gatewayReference } : {}),
        })
        success.value = 'Attempt selesai dan pembayaran refund tercatat.'
        break
      }
      case 'fail': {
        const attempt = attemptForForm(form)
        if (!attempt) {
          formError.value = 'Data attempt tidak ditemukan. Muat ulang panel.'
          return
        }
        const code = formCode.value.trim()
        const message = formMessage.value.trim()
        if (!code) {
          formError.value = 'Kode kegagalan wajib diisi.'
          return
        }
        if (code.length > 60) {
          formError.value = 'Kode kegagalan maksimal 60 karakter.'
          return
        }
        if (!message) {
          formError.value = 'Pesan kegagalan wajib diisi.'
          return
        }
        if (message.length > 255) {
          formError.value = 'Pesan kegagalan maksimal 255 karakter.'
          return
        }
        await failRefundAttempt(attempt.id, { code, message })
        success.value = 'Attempt ditandai gagal; kewajiban tetap terbuka.'
        break
      }
      case 'unknown': {
        const attempt = attemptForForm(form)
        if (!attempt) {
          formError.value = 'Data attempt tidak ditemukan. Muat ulang panel.'
          return
        }
        const note = formNote.value.trim()
        await markRefundAttemptUnknown(attempt.id, note || undefined)
        success.value = 'Attempt ditandai tidak diketahui dan menunggu rekonsiliasi.'
        break
      }
      case 'reconcile': {
        const attempt = attemptForForm(form)
        if (!attempt) {
          formError.value = 'Data attempt tidak ditemukan. Muat ulang panel.'
          return
        }
        const evidence = formEvidence.value.trim()
        if (evidence.length < 3) {
          formError.value = 'Bukti (evidence) rekonsiliasi wajib diisi minimal 3 karakter.'
          return
        }
        await reconcileRefundAttempt(attempt.id, { succeeded: formSucceeded.value, evidence })
        success.value = formSucceeded.value
          ? 'Rekonsiliasi: dana dinyatakan berhasil terkirim.'
          : 'Rekonsiliasi: dana dinyatakan tidak terkirim.'
        break
      }
    }

    activeForm.value = null
    await fetchLedger()
  } catch (e) {
    formError.value = errorText(e, 'Aksi refund gagal diproses.')
  } finally {
    busy.value = null
  }
}

/* ---- availability rules (mirrors the backend's documented transitions) ------------------- */
function canApprove(obligation: RefundObligation): boolean {
  return obligation.status === 'pending'
}

function canCreateAttempt(obligation: RefundObligation): boolean {
  return (
    obligation.source_type !== 'deferred' &&
    (obligation.status === 'approved' || obligation.status === 'partially_paid')
  )
}

function canResolveVerification(obligation: RefundObligation): boolean {
  return obligation.status === 'needs_verification'
}

function canHandleDeferred(obligation: RefundObligation): boolean {
  return (
    obligation.source_type === 'deferred' &&
    (obligation.status === 'pending' || obligation.status === 'approved' || obligation.status === 'partially_paid')
  )
}

function deferredDefaultCents(obligation: RefundObligation): number {
  if (obligation.deferred_amount) return toCents(obligation.deferred_amount)
  return remainingCents(obligation)
}

function toggleExpanded(id: number): void {
  expanded.value = { ...expanded.value, [id]: !expanded.value[id] }
}
</script>

<template>
  <section
    data-testid="order-refund-ledger"
    class="space-y-3 rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900"
    role="region"
    aria-label="Buku refund pesanan"
  >
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div>
        <h2 class="font-display text-sm font-semibold text-stone-800 dark:text-stone-100">Buku Refund Pesanan</h2>
        <p class="text-xs text-stone-500 dark:text-stone-400">
          Kewajiban refund, attempt pembayaran, dan bukti pengembalian dana. Semua angka ringkasan berasal dari server.
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span
          v-if="ledger?.cancellation_financial_status"
          class="rounded-full bg-stone-100 px-2.5 py-0.5 text-[11px] font-medium uppercase tracking-wide text-stone-700 dark:bg-stone-800 dark:text-stone-300"
          data-testid="refund-cancellation-status"
        >
          Pembatalan: {{ ledger.cancellation_financial_status }}
        </span>
        <AppButton size="sm" variant="secondary" :disabled="loading" @click="fetchLedger()">
          {{ loading ? 'Memuat…' : 'Muat ulang' }}
        </AppButton>
      </div>
    </div>

    <p v-if="loadError" role="alert" class="text-sm text-red-600 dark:text-red-400">{{ loadError }}</p>
    <p v-if="actionError" role="alert" class="text-sm text-red-600 dark:text-red-400">{{ actionError }}</p>
    <p v-if="success" role="status" class="text-sm text-emerald-600 dark:text-emerald-400">{{ success }}</p>

    <div v-if="ledger === null && loading" class="space-y-2" role="status" aria-live="polite">
      <p class="text-sm text-stone-500 dark:text-stone-400">Memuat buku refund…</p>
      <div v-for="i in 3" :key="i" class="h-16 animate-pulse rounded-xl bg-stone-100 dark:bg-stone-800" />
    </div>

    <template v-else-if="ledger">
      <!-- Ringkasan uang (metrics_cents, integer cents) -->
      <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3 lg:grid-cols-5" data-testid="refund-metrics">
        <div v-for="metric in metrics" :key="metric.key" class="rounded-xl bg-stone-50 p-2 dark:bg-stone-800/60">
          <dt class="text-[11px] text-stone-500 dark:text-stone-400">{{ metric.label }}</dt>
          <dd class="font-semibold" :class="metric.tone || 'text-stone-800 dark:text-stone-100'" :data-testid="`refund-metric-${metric.key}`">
            {{ idr(metric.value) }}
          </dd>
        </div>
      </dl>

      <!-- Kewajiban -->
      <div class="overflow-x-auto">
        <table class="w-full min-w-[46rem] text-left text-sm">
          <thead class="text-xs text-stone-500 dark:text-stone-400">
            <tr>
              <th class="py-1 pr-2 font-medium">Kewajiban</th>
              <th class="px-2 py-1 font-medium">Sumber</th>
              <th class="px-2 py-1 font-medium">Status</th>
              <th class="px-2 py-1 text-right font-medium">Nominal</th>
              <th class="px-2 py-1 text-right font-medium">Sisa</th>
              <th class="px-2 py-1 text-right font-medium">Attempt</th>
              <th class="px-2 py-1 text-right font-medium">Detail</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="obligation in ledger.obligations" :key="obligation.id">
              <tr class="border-t border-stone-200 align-top dark:border-stone-700" :data-testid="`refund-obligation-${obligation.id}`">
                <td class="py-2 pr-2 font-medium text-stone-800 dark:text-stone-100">{{ obligation.obligation_no }}</td>
                <td class="px-2 py-2 text-xs">{{ sourceLabel[obligation.source_type] }}</td>
                <td class="px-2 py-2">
                  <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="obligationStatusClass[obligation.status]">
                    {{ obligationStatusLabel[obligation.status] }}
                  </span>
                </td>
                <td class="px-2 py-2 text-right">{{ formatRupiah(obligation.amount) }}</td>
                <td class="px-2 py-2 text-right font-semibold">{{ idr(remainingCents(obligation)) }}</td>
                <td class="px-2 py-2 text-right">{{ obligation.attempts.length }}</td>
                <td class="px-2 py-2 text-right">
                  <button
                    type="button"
                    class="rounded-lg px-2 py-1 text-xs font-medium text-brand-700 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:text-brand-300 dark:hover:bg-brand-950/40"
                    :aria-expanded="!!expanded[obligation.id]"
                    :data-testid="`refund-toggle-${obligation.id}`"
                    @click="toggleExpanded(obligation.id)"
                  >
                    {{ expanded[obligation.id] ? 'Tutup' : 'Buka' }}
                  </button>
                </td>
              </tr>

              <tr v-if="expanded[obligation.id]" class="border-t border-stone-100 bg-stone-50/60 dark:border-stone-800 dark:bg-stone-800/30">
                <td colspan="7" class="space-y-3 p-3">
                  <p v-if="obligation.reason" class="text-xs text-stone-600 dark:text-stone-300">Alasan: {{ obligation.reason }}</p>
                  <p v-if="obligation.deferred_amount" class="text-xs text-stone-600 dark:text-stone-300">
                    Nilai ditangguhkan: <b>{{ formatRupiah(obligation.deferred_amount) }}</b>
                    <span v-if="obligation.deferred_requested_at"> · diminta {{ formatDate(obligation.deferred_requested_at) }}</span>
                  </p>
                  <p v-if="obligation.status === 'completed'" class="text-xs text-emerald-700 dark:text-emerald-400">
                    Kewajiban ini sudah lunas seluruhnya.
                  </p>

                  <!-- Attempt pembayaran -->
                  <div v-if="obligation.attempts.length" class="overflow-x-auto">
                    <p class="mb-1 text-xs font-semibold text-stone-700 dark:text-stone-200">Attempt pembayaran (terbaru dulu)</p>
                    <table class="w-full min-w-[48rem] text-left text-xs">
                      <thead class="text-[11px] text-stone-500 dark:text-stone-400">
                        <tr>
                          <th class="py-1 pr-2 font-medium">No</th>
                          <th class="px-2 py-1 font-medium">Metode</th>
                          <th class="px-2 py-1 text-right font-medium">Nominal</th>
                          <th class="px-2 py-1 font-medium">Status</th>
                          <th class="px-2 py-1 font-medium">Referensi</th>
                          <th class="px-2 py-1 font-medium">Pesan gagal</th>
                          <th class="px-2 py-1 font-medium">Dibuat</th>
                          <th class="px-2 py-1 text-right font-medium">Aksi</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr
                          v-for="attempt in sortedAttempts(obligation)"
                          :key="attempt.id"
                          class="border-t border-stone-200 align-top dark:border-stone-700"
                          :data-testid="`refund-attempt-${attempt.id}`"
                        >
                          <td class="py-1.5 pr-2 font-medium">{{ attempt.attempt_no }}</td>
                          <td class="px-2 py-1.5">{{ methodLabel[attempt.method] }}</td>
                          <td class="px-2 py-1.5 text-right">{{ formatRupiah(attempt.amount) }}</td>
                          <td class="px-2 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="attemptStatusClass[attempt.status]">
                              {{ attemptStatusLabel[attempt.status] }}
                            </span>
                          </td>
                          <td class="px-2 py-1.5">{{ attempt.external_reference ?? '—' }}</td>
                          <td class="px-2 py-1.5 text-red-700 dark:text-red-400">{{ attempt.failure_message ?? '—' }}</td>
                          <td class="px-2 py-1.5">{{ formatDate(attempt.created_at) }}</td>
                          <td class="px-2 py-1.5">
                            <div class="flex flex-wrap justify-end gap-1">
                              <template v-if="attempt.status === 'created'">
                                <AppButton size="sm" variant="secondary" :disabled="busy !== null" @click="openForm('initiate', attempt.id)">Mulai proses</AppButton>
                                <AppButton size="sm" variant="ghost" :disabled="busy !== null" @click="openForm('cancel_attempt', attempt.id)">Batalkan</AppButton>
                              </template>
                              <template v-else-if="attempt.status === 'processing'">
                                <AppButton size="sm" :disabled="busy !== null" @click="openForm('complete', attempt.id)">Selesai</AppButton>
                                <AppButton size="sm" variant="danger" :disabled="busy !== null" @click="openForm('fail', attempt.id)">Gagal</AppButton>
                                <AppButton size="sm" variant="secondary" :disabled="busy !== null" @click="openForm('unknown', attempt.id)">Tidak diketahui</AppButton>
                              </template>
                              <AppButton
                                v-else-if="attempt.status === 'unknown'"
                                size="sm"
                                variant="secondary"
                                :disabled="busy !== null"
                                @click="openForm('reconcile', attempt.id)"
                              >
                                Rekonsiliasi
                              </AppButton>
                              <span v-else class="text-[11px] text-stone-400">—</span>
                            </div>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                  <p v-else class="text-xs text-stone-500 dark:text-stone-400">Belum ada attempt pembayaran.</p>

                  <!-- Payout (uang yang benar-benar keluar) -->
                  <div v-if="obligation.payouts.length">
                    <p class="mb-1 text-xs font-semibold text-stone-700 dark:text-stone-200">Pembayaran tercatat</p>
                    <table class="w-full min-w-[36rem] text-left text-xs">
                      <thead class="text-[11px] text-stone-500 dark:text-stone-400">
                        <tr>
                          <th class="py-1 pr-2 font-medium">No</th>
                          <th class="px-2 py-1 text-right font-medium">Nominal</th>
                          <th class="px-2 py-1 font-medium">Metode</th>
                          <th class="px-2 py-1 font-medium">Dibayar</th>
                          <th class="px-2 py-1 font-medium">Verifikasi</th>
                          <th class="px-2 py-1 font-medium">Bukti</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="payout in obligation.payouts" :key="payout.id" class="border-t border-stone-200 dark:border-stone-700">
                          <td class="py-1.5 pr-2 font-medium">{{ payout.payout_no }}</td>
                          <td class="px-2 py-1.5 text-right">{{ formatRupiah(payout.amount) }}</td>
                          <td class="px-2 py-1.5">{{ methodLabel[payout.method] }}</td>
                          <td class="px-2 py-1.5">{{ formatDate(payout.paid_at) }}</td>
                          <td class="px-2 py-1.5">
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="payoutVerificationClass[payout.verification_status]">
                              {{ payoutVerificationLabel[payout.verification_status] }}
                            </span>
                          </td>
                          <td class="px-2 py-1.5">
                            {{ payout.proof_type ?? '—' }}
                            <span v-if="payout.gateway_reference" class="block text-[11px] text-stone-500">{{ payout.gateway_reference }}</span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>

                  <!-- Kewajiban anak hasil realisasi deferred -->
                  <div v-if="obligation.children.length">
                    <p class="mb-1 text-xs font-semibold text-stone-700 dark:text-stone-200">Kewajiban anak (hasil realisasi)</p>
                    <ul class="space-y-1 text-xs">
                      <li v-for="child in obligation.children" :key="child.id" class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ child.obligation_no }}</span>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="obligationStatusClass[child.status]">
                          {{ obligationStatusLabel[child.status] }}
                        </span>
                        <span>{{ formatRupiah(child.amount) }}</span>
                        <span v-if="child.reason" class="text-stone-500 dark:text-stone-400">· {{ child.reason }}</span>
                      </li>
                    </ul>
                  </div>

                  <!-- Aksi kewajiban -->
                  <div class="flex flex-wrap gap-2">
                    <AppButton
                      v-if="canApprove(obligation)"
                      size="sm"
                      :disabled="busy !== null"
                      :data-testid="`refund-approve-${obligation.id}`"
                      @click="approve(obligation)"
                    >
                      {{ busy === `approve-${obligation.id}` ? 'Menyetujui…' : 'Setujui' }}
                    </AppButton>
                    <AppButton
                      v-if="canCreateAttempt(obligation)"
                      size="sm"
                      :disabled="busy !== null"
                      :data-testid="`refund-attempt-open-${obligation.id}`"
                      @click="openForm('attempt', obligation.id, remainingCents(obligation))"
                    >
                      Buat attempt
                    </AppButton>
                    <AppButton
                      v-if="canResolveVerification(obligation)"
                      size="sm"
                      :disabled="busy !== null"
                      @click="openForm('verification', obligation.id)"
                    >
                      Selesaikan verifikasi
                    </AppButton>
                    <template v-if="canHandleDeferred(obligation)">
                      <AppButton size="sm" variant="secondary" :disabled="busy !== null" @click="openForm('deferred_request', obligation.id, deferredDefaultCents(obligation))">
                        Minta realisasi
                      </AppButton>
                      <AppButton size="sm" variant="secondary" :disabled="busy !== null" @click="openForm('deferred_materialize', obligation.id, deferredDefaultCents(obligation))">
                        Realisasikan
                      </AppButton>
                    </template>
                    <span
                      v-if="!canApprove(obligation) && !canCreateAttempt(obligation) && !canResolveVerification(obligation) && !canHandleDeferred(obligation)"
                      class="text-[11px] text-stone-400"
                    >
                      Tidak ada aksi untuk status ini.
                    </span>
                  </div>
                  <p v-if="canHandleDeferred(obligation)" class="text-[11px] text-stone-500 dark:text-stone-400">
                    Nilai ditangguhkan tidak dibayar langsung: realisasikan dulu menjadi kewajiban anak, lalu bayar kewajiban anak tersebut.
                  </p>

                  <!-- Form aksi inline -->
                  <div
                    v-if="hasOpenFormFor(obligation)"
                    class="space-y-2 rounded-xl border border-stone-200 bg-white p-3 text-xs dark:border-stone-700 dark:bg-stone-900"
                    :data-testid="`refund-form-${obligation.id}`"
                  >
                    <p class="text-sm font-semibold text-stone-800 dark:text-stone-100">
                      {{ formTitle }}
                      <span v-if="formContext" class="text-xs font-normal text-stone-500 dark:text-stone-400">· {{ formContext }}</span>
                    </p>

                    <!-- Buat attempt -->
                    <template v-if="activeForm?.kind === 'attempt'">
                      <p class="text-stone-500 dark:text-stone-400">
                        Sisa kewajiban {{ idr(remainingCents(obligation)) }}. Nominal tidak boleh melebihi sisa.
                      </p>
                      <div class="grid gap-2 sm:grid-cols-2">
                        <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
                          Nominal (Rp)
                          <input v-model="formAmount" type="number" min="1" inputmode="numeric" class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                        </label>
                        <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
                          Metode
                          <select v-model="formMethod" class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950">
                            <option value="bank_transfer">Transfer bank</option>
                            <option value="gateway">Gateway</option>
                            <option value="cash">Tunai</option>
                          </select>
                        </label>
                        <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
                          Referensi eksternal (opsional)
                          <input v-model="formExternalReference" type="text" maxlength="191" class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                        </label>
                        <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
                          Bank / tujuan (opsional)
                          <input v-model="formDestination" type="text" maxlength="191" placeholder="mis. BCA 123456789 a.n. Budi" class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                        </label>
                      </div>
                    </template>

                    <!-- Selesaikan verifikasi -->
                    <template v-else-if="activeForm?.kind === 'verification'">
                      <fieldset class="space-y-1">
                        <legend class="text-[11px] font-medium text-stone-600 dark:text-stone-300">Hasil verifikasi</legend>
                        <label class="flex items-start gap-2">
                          <input v-model="formExit" type="radio" value="A" class="mt-0.5" />
                          <span><b>A — Terverifikasi sudah dibayar:</b> dana sudah diterima penerima, kewajiban dianggap selesai.</span>
                        </label>
                        <label class="flex items-start gap-2">
                          <input v-model="formExit" type="radio" value="B" class="mt-0.5" />
                          <span><b>B — Tidak jadi dibayar:</b> dana tidak terkirim, kewajiban dibuka kembali untuk diproses ulang.</span>
                        </label>
                      </fieldset>
                      <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
                        Bukti (wajib, min. 3 karakter)
                        <textarea v-model="formEvidence" rows="2" maxlength="1000" class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                      </label>
                      <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
                        Catatan tambahan (opsional)
                        <input v-model="formReason" type="text" maxlength="255" class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                      </label>
                    </template>

                    <!-- Deferred request / materialize -->
                    <template v-else-if="activeForm?.kind === 'deferred_request' || activeForm?.kind === 'deferred_materialize'">
                      <p class="text-stone-500 dark:text-stone-400">
                        <template v-if="activeForm.kind === 'deferred_request'">Mengajukan realisasi untuk nilai yang masih ditangguhkan.</template>
                        <template v-else>Mengubah nilai ditangguhkan menjadi kewajiban anak yang dapat dibayar.</template>
                      </p>
                      <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
                        Nominal (Rp)
                        <input v-model="formAmount" type="number" min="1" inputmode="numeric" class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                      </label>
                    </template>

                    <!-- Initiate -->
                    <template v-else-if="activeForm?.kind === 'initiate'">
                      <p class="text-stone-500 dark:text-stone-400">Attempt akan berpindah ke status diproses. Isi referensi bila sudah ada bukti transfer.</p>
                      <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
                        Referensi eksternal (opsional)
                        <input v-model="formExternalReference" type="text" maxlength="191" class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                      </label>
                    </template>

                    <!-- Cancel attempt -->
                    <template v-else-if="activeForm?.kind === 'cancel_attempt'">
                      <p class="text-stone-500 dark:text-stone-400">Hanya attempt yang belum diproses yang dapat dibatalkan.</p>
                      <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
                        Alasan (wajib, maks. 255 karakter)
                        <input v-model="formReason" type="text" maxlength="255" class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                      </label>
                    </template>

                    <!-- Complete -->
                    <template v-else-if="activeForm?.kind === 'complete'">
                      <p class="text-stone-500 dark:text-stone-400">Menyelesaikan attempt akan mencatat pembayaran refund (payout) dan bukti transfer.</p>
                      <PrimeFileUpload
                        :model-value="formProof ? [formProof] : []"
                        accept="image/jpeg,image/png,image/webp"
                        :max-size-mb="5"
                        label="Bukti transfer (opsional)"
                        :test-id="'refund-proof-upload'"
                        @update:model-value="formProof = $event[0] ?? null"
                      />
                      <div class="grid gap-2 sm:grid-cols-2">
                        <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
                          Jenis bukti (opsional, maks. 60)
                          <input v-model="formProofType" type="text" maxlength="60" class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                        </label>
                        <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
                          Referensi gateway (opsional)
                          <input v-model="formGatewayReference" type="text" maxlength="191" class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                        </label>
                      </div>
                    </template>

                    <!-- Fail -->
                    <template v-else-if="activeForm?.kind === 'fail'">
                      <p class="text-stone-500 dark:text-stone-400">Kegagalan permanen: kewajiban tetap terbuka dan dapat dicoba lagi dengan attempt baru.</p>
                      <div class="grid gap-2 sm:grid-cols-2">
                        <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
                          Kode kegagalan (wajib, maks. 60)
                          <input v-model="formCode" type="text" maxlength="60" class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                        </label>
                        <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
                          Pesan (wajib, maks. 255)
                          <input v-model="formMessage" type="text" maxlength="255" class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                        </label>
                      </div>
                    </template>

                    <!-- Unknown -->
                    <template v-else-if="activeForm?.kind === 'unknown'">
                      <p class="text-stone-500 dark:text-stone-400">Gunakan bila tidak ada konfirmasi dari bank/gateway. Attempt menunggu rekonsiliasi.</p>
                      <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
                        Catatan (opsional)
                        <input v-model="formNote" type="text" maxlength="255" class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                      </label>
                    </template>

                    <!-- Reconcile -->
                    <template v-else-if="activeForm?.kind === 'reconcile'">
                      <fieldset class="space-y-1">
                        <legend class="text-[11px] font-medium text-stone-600 dark:text-stone-300">Hasil pencocokan dengan mutasi bank</legend>
                        <label class="flex items-center gap-2">
                          <input v-model="formSucceeded" type="radio" :value="true" />
                          <span>Dana berhasil terkirim</span>
                        </label>
                        <label class="flex items-center gap-2">
                          <input v-model="formSucceeded" type="radio" :value="false" />
                          <span>Dana tidak terkirim</span>
                        </label>
                      </fieldset>
                      <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
                        Bukti (wajib, min. 3 karakter)
                        <textarea v-model="formEvidence" rows="2" maxlength="1000" class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
                      </label>
                    </template>

                    <p v-if="formError" role="alert" class="text-xs text-red-600 dark:text-red-400">{{ formError }}</p>

                    <div class="flex flex-wrap gap-2">
                      <AppButton size="sm" :disabled="busy !== null" @click="submitForm()">
                        {{ busy !== null ? 'Memproses…' : 'Kirim' }}
                      </AppButton>
                      <AppButton size="sm" variant="ghost" :disabled="busy !== null" @click="closeForm()">Batal</AppButton>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
        <p v-if="!ledger.obligations.length" class="py-6 text-center text-sm text-stone-400">Belum ada kewajiban refund untuk pesanan ini.</p>
      </div>

      <!-- Pengurangan penjualan -->
      <div class="space-y-2">
        <h3 class="text-xs font-semibold text-stone-700 dark:text-stone-200">Sales reduction</h3>
        <div v-if="ledger.reductions.length" class="overflow-x-auto">
          <table class="w-full min-w-[38rem] text-left text-sm">
            <thead class="text-xs text-stone-500 dark:text-stone-400">
              <tr>
                <th class="py-1 pr-2 font-medium">Nominal</th>
                <th class="px-2 py-1 font-medium">Alasan</th>
                <th class="px-2 py-1 font-medium">Masuk total pesanan</th>
                <th class="px-2 py-1 font-medium">Dibuat</th>
                <th class="px-2 py-1 font-medium">Status</th>
                <th class="px-2 py-1 text-right font-medium">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="reduction in ledger.reductions" :key="reduction.id" class="border-t border-stone-200 align-top dark:border-stone-700">
                <td class="py-2 pr-2 font-medium">{{ formatRupiah(reduction.amount) }}</td>
                <td class="px-2 py-2 text-xs">{{ reduction.reason ?? '—' }}</td>
                <td class="px-2 py-2 text-xs">{{ reduction.applied_to_order_total ? 'Ya' : 'Tidak' }}</td>
                <td class="px-2 py-2 text-xs">{{ formatDate(reduction.created_at) }}</td>
                <td class="px-2 py-2 text-xs">
                  <span
                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                    :class="reduction.reinstated_at
                      ? 'bg-stone-200 text-stone-700 dark:bg-stone-800 dark:text-stone-300'
                      : 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300'"
                  >
                    {{ reduction.reinstated_at ? `Di-reinstate ${formatDate(reduction.reinstated_at)}` : 'Berlaku' }}
                  </span>
                  <span v-if="reduction.reinstate_evidence" class="mt-0.5 block text-[11px] text-stone-500">{{ reduction.reinstate_evidence }}</span>
                </td>
                <td class="px-2 py-2 text-right">
                  <AppButton
                    v-if="reduction.reinstated_at === null"
                    size="sm"
                    variant="secondary"
                    :disabled="busy !== null"
                    @click="openForm('reinstate', reduction.id)"
                  >
                    Reinstate
                  </AppButton>
                  <span v-else class="text-[11px] text-stone-400">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="text-xs text-stone-400">Tidak ada pengurangan nilai penjualan pada pesanan ini.</p>

        <div
          v-if="activeForm?.kind === 'reinstate'"
          class="space-y-2 rounded-xl border border-stone-200 bg-stone-50/60 p-3 text-xs dark:border-stone-700 dark:bg-stone-800/30"
          data-testid="refund-reinstate-form"
        >
          <p class="text-sm font-semibold text-stone-800 dark:text-stone-100">Kembalikan (reinstate) pengurangan penjualan</p>
          <p class="text-stone-500 dark:text-stone-400">Nilai penjualan/komisi akan dihitung kembali. Tindakan ini memerlukan bukti.</p>
          <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
            Bukti (wajib, min. 3 karakter)
            <textarea v-model="formEvidence" rows="2" maxlength="1000" class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="block text-[11px] font-medium text-stone-600 dark:text-stone-300">
            Alasan (opsional)
            <input v-model="formReason" type="text" maxlength="255" class="mt-1 w-full rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <p v-if="formError" role="alert" class="text-xs text-red-600 dark:text-red-400">{{ formError }}</p>
          <div class="flex flex-wrap gap-2">
            <AppButton size="sm" :disabled="busy !== null" @click="submitForm()">
              {{ busy !== null ? 'Memproses…' : 'Reinstate' }}
            </AppButton>
            <AppButton size="sm" variant="ghost" :disabled="busy !== null" @click="closeForm()">Batal</AppButton>
          </div>
        </div>
      </div>

      <!-- Finalisasi pembatalan (order-level) -->
      <div class="space-y-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-xs dark:border-amber-700 dark:bg-amber-950/30" role="group" aria-label="Finalisasi pembatalan">
        <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Finalisasi Pembatalan</p>
        <p class="text-amber-800 dark:text-amber-200">
          Finalisasi menutup pembatalan pesanan dan <b>mengunci kewajiban refund final</b>. Setelah dikunci tidak ada
          kewajiban refund baru yang dapat ditambahkan.
        </p>
        <div v-if="!finalizeConfirm">
          <AppButton size="sm" variant="danger" :disabled="busy !== null" data-testid="refund-finalize-open" @click="finalizeConfirm = true">
            Finalisasi Pembatalan
          </AppButton>
        </div>
        <div v-else class="space-y-2">
          <p class="font-medium text-amber-900 dark:text-amber-200">Yakin finalisasi sekarang? Tindakan ini tidak dapat dibatalkan.</p>
          <div class="flex flex-wrap gap-2">
            <AppButton size="sm" variant="danger" :disabled="busy !== null" data-testid="refund-finalize-confirm" @click="finalize()">
              {{ busy === 'finalize' ? 'Memproses…' : 'Ya, finalisasi' }}
            </AppButton>
            <AppButton size="sm" variant="ghost" :disabled="busy !== null" @click="finalizeConfirm = false">Batal</AppButton>
          </div>
        </div>
      </div>
    </template>
  </section>
</template>
