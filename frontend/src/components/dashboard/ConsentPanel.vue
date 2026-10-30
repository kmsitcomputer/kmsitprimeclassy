<script setup lang="ts">
import PrimeFileUpload from '@/components/ui/PrimeFileUpload.vue'
/**
 * REM-005 / WS-9 UI: OTP-verified, append-only consent evidence for an order.
 * Renders nothing unless the viewer may capture consent or the order already has consent events.
 * Every rule (scope, OTP, authority, state machine) is enforced by the backend; this only drives it.
 */
import { ref, computed } from 'vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { formatDateTime } from '@/utils/format'
import { useAuthStore } from '@/stores/auth'
import {
  getConsentContext,
  listConsentEvents,
  issueConsentOtp,
  verifyConsentOtp,
  captureConsent,
  issueCrmConsentOtp,
  captureCrmConsent,
  initiateAssistedReturn,
  consentAction,
  type ConsentContext,
  type ConsentEvent,
  type ConsentAction,
} from '@/api/crm'

const props = defineProps<{ orderId: number }>()
const auth = useAuthStore()

const ctx = ref<ConsentContext | null>(null)
const events = ref<ConsentEvent[]>([])
const loading = ref(true)
const errorMessage = ref('')

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    ;[ctx.value, events.value] = await Promise.all([getConsentContext(props.orderId), listConsentEvents(props.orderId)])
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat data persetujuan.'
  } finally {
    loading.value = false
  }
}
load()

const visible = computed(() => !loading.value && (errorMessage.value !== '' || ctx.value?.can_capture || events.value.length > 0))

/* ---------- history: one chain per root captured event ---------- */
const EVENT_LABEL: Record<string, string> = {
  consent_captured: 'Persetujuan dicatat',
  consent_withdrawn: 'Persetujuan ditarik',
  consent_disputed: 'Persetujuan disengketakan',
  consent_reinstated: 'Persetujuan dipulihkan',
}
const chains = computed(() =>
  events.value
    .filter((e) => e.root_event_id === null)
    .map((root) => {
      const history = events.value.filter((e) => e.id === root.id || e.root_event_id === root.id)
      return { root, history, state: (history.at(-1) ?? root).event_type }
    }),
)
const isReviewer = computed(() => ['super_admin', 'agen', 'admin'].includes(auth.user?.role ?? ''))

const actionReason = ref('')
const actingOn = ref<{ rootId: number; action: ConsentAction } | null>(null)
const actionError = ref('')
const acting = ref(false)

function startAction(rootId: number, action: ConsentAction) {
  actingOn.value = { rootId, action }
  actionReason.value = ''
  actionError.value = ''
}

async function submitAction() {
  if (!actingOn.value) return
  acting.value = true
  actionError.value = ''
  try {
    await consentAction(actingOn.value.rootId, actingOn.value.action, actionReason.value)
    actingOn.value = null
    await load()
  } catch (e) {
    actionError.value = e instanceof ApiError ? formatApiError(e) : 'Aksi gagal diproses.'
  } finally {
    acting.value = false
  }
}

/* ---------- capture wizard ---------- */
const open = ref(false)
const step = ref<'scope' | 'otp' | 'evidence'>('scope')
const qty = ref<Record<number, number>>({})
const challengeId = ref<number | null>(null)
const otpCode = ref('')
const note = ref('')
const evidence = ref<File | null>(null)
const busy = ref(false)
const wizardError = ref('')
// Stable per wizard run so a double submit replays instead of creating a second consent.
const consentKey = ref('')

const scopeLines = computed(() =>
  Object.entries(qty.value)
    .map(([id, q]) => ({ order_item_id: Number(id), quantity: Number(q) }))
    .filter((l) => l.quantity > 0),
)

function startWizard() {
  open.value = true
  step.value = 'scope'
  qty.value = {}
  challengeId.value = null
  otpCode.value = ''
  note.value = ''
  evidence.value = null
  wizardError.value = ''
  consentKey.value = crypto.randomUUID()
}

async function guard(fn: () => Promise<void>, fallback: string) {
  busy.value = true
  wizardError.value = ''
  try {
    await fn()
  } catch (e) {
    wizardError.value = e instanceof ApiError ? formatApiError(e) : fallback
  } finally {
    busy.value = false
  }
}

const purpose = computed(() => ctx.value?.purposes[0] ?? 'assisted_return')

/** CRM-origin order: the customer has no account, so there is no verified channel to OTP — evidence only. */
const isCrm = computed(() => ctx.value?.mode === 'crm')
/** Owner 2026-10-31: no OTP channel => no consent. CRM: only the linked account's verified email; account: the konsumen's. */
const channelAvailable = computed(() => (isCrm.value ? ctx.value?.channel_available === true : ctx.value?.subject_email_verified === true))
const canContinueFromScope = computed(() => scopeLines.value.length > 0 && channelAvailable.value)

const sendOtp = () =>
  guard(async () => {
    const issued = isCrm.value
      ? await issueCrmConsentOtp(ctx.value!.crm_customer_id!, purpose.value)
      : await issueConsentOtp(ctx.value!.subject_user_id!, purpose.value)
    challengeId.value = issued.challenge_id
    step.value = 'otp'
  }, 'Gagal mengirim kode verifikasi.')

const checkOtp = () =>
  guard(async () => {
    await verifyConsentOtp(challengeId.value!, otpCode.value)
    step.value = 'evidence'
  }, 'Kode verifikasi tidak valid.')

const submitCapture = () =>
  guard(async () => {
    if (isCrm.value) {
      await captureCrmConsent(ctx.value!.crm_customer_id!, {
        challengeId: challengeId.value!,
        orderId: props.orderId,
        purpose: purpose.value,
        note: note.value,
        scope: scopeLines.value,
        evidence: evidence.value!,
        consentKey: consentKey.value,
      })
      open.value = false
      await load()

      return
    }

    await captureConsent({
      subjectUserId: ctx.value!.subject_user_id!,
      orderId: props.orderId,
      purpose: purpose.value,
      challengeId: challengeId.value!,
      note: note.value,
      scope: scopeLines.value,
      evidence: evidence.value!,
      consentKey: consentKey.value,
    })
    open.value = false
    await load()
  }, 'Gagal menyimpan persetujuan.')

/* ---------- assisted return (CRM-origin only): initiate under a valid consent, nothing more ---------- */
const returning = ref<number | null>(null) // root consent id being used
const returnQty = ref<Record<number, number>>({})
const returnReason = ref('')
const returnFile = ref<File | null>(null)
const returnError = ref('')
const returnDone = ref('')
const submittingReturn = ref(false)

function startReturn(rootId: number) {
  returning.value = rootId
  returnQty.value = {}
  returnReason.value = ''
  returnFile.value = null
  returnError.value = ''
  returnDone.value = ''
}

const returnLines = computed(() =>
  Object.entries(returnQty.value)
    .map(([id, q]) => ({ order_item_id: Number(id), quantity: Number(q) }))
    .filter((l) => l.quantity > 0),
)

async function submitReturn() {
  submittingReturn.value = true
  returnError.value = ''
  try {
    const created = await initiateAssistedReturn(props.orderId, {
      consentEventId: returning.value!,
      reason: returnReason.value,
      items: returnLines.value,
      evidence: returnFile.value!,
    })
    returning.value = null
    returnDone.value = `Permintaan return #${created.id} diajukan. Peninjauan tetap oleh Admin/Agen.`
  } catch (e) {
    returnError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal mengajukan return.'
  } finally {
    submittingReturn.value = false
  }
}

const inputClass =
  'mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950'
</script>

<template>
  <section v-if="visible" class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
      <h2 class="text-sm font-semibold text-stone-800 dark:text-stone-100">Persetujuan Pelanggan (Return)</h2>
      <button
        v-if="ctx?.can_capture && !open && channelAvailable"
        type="button"
        class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-700"
        @click="startWizard"
      >
        Catat Persetujuan
      </button>
    </div>

    <p v-if="errorMessage" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ errorMessage }} <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
    </p>

    <p v-if="ctx?.can_capture && isCrm && !ctx.channel_available" role="alert" class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">
      Persetujuan pelanggan CRM membutuhkan OTP ke kanal terverifikasi. Tautkan pelanggan ini ke akun konsumen dengan email
      terverifikasi terlebih dahulu. Foto dan catatan saja tidak cukup, dan belum ada jalur verifikasi manual.
    </p>
    <p v-if="returnDone" class="mb-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">{{ returnDone }}</p>
    <p v-if="ctx?.can_capture && !isCrm && !ctx.subject_email_verified" class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">
      Konsumen belum memiliki email terverifikasi, sehingga kode OTP tidak dapat dikirim.
    </p>

    <ul v-if="chains.length" class="space-y-3">
      <li v-for="c in chains" :key="c.root.id" class="rounded-xl border border-stone-100 p-3 text-sm dark:border-stone-800">
        <p class="font-medium" :class="c.state === 'consent_withdrawn' || c.state === 'consent_disputed' ? 'text-red-700 dark:text-red-400' : 'text-emerald-700 dark:text-emerald-400'">
          {{ EVENT_LABEL[c.state] }}
        </p>
        <p class="text-xs text-stone-500">
          {{ c.root.scope?.length ?? 0 }} item · bukti {{ c.root.has_evidence ? 'terlampir' : 'tidak ada' }}
          <template v-if="c.root.evidence_sha256"> · SHA-256 {{ c.root.evidence_sha256.slice(0, 12) }}…</template>
        </p>
        <ol class="mt-2 space-y-1 border-l border-stone-200 pl-3 text-xs text-stone-600 dark:border-stone-700 dark:text-stone-300">
          <li v-for="h in c.history" :key="h.id">
            {{ EVENT_LABEL[h.event_type] }} · {{ formatDateTime(h.occurred_at) }}<span v-if="h.note"> — {{ h.note }}</span>
          </li>
        </ol>
        <div class="mt-2 flex flex-wrap gap-2">
          <template v-if="c.state === 'consent_captured' || c.state === 'consent_reinstated'">
            <button type="button" class="rounded-lg border border-stone-200 px-2.5 py-1 text-xs dark:border-stone-700" @click="startAction(c.root.id, 'withdraw')">Tarik</button>
            <button type="button" class="rounded-lg border border-stone-200 px-2.5 py-1 text-xs dark:border-stone-700" @click="startAction(c.root.id, 'dispute')">Sengketakan</button>
          </template>
          <button
            v-if="isCrm && ctx?.can_capture && (c.state === 'consent_captured' || c.state === 'consent_reinstated')"
            type="button"
            class="rounded-lg bg-brand-600 px-2.5 py-1 text-xs font-medium text-white"
            @click="startReturn(c.root.id)"
          >Ajukan Return</button>
          <button v-if="c.state === 'consent_withdrawn'" type="button" class="rounded-lg border border-stone-200 px-2.5 py-1 text-xs dark:border-stone-700" @click="startAction(c.root.id, 'dispute')">Sengketakan</button>
          <button v-if="c.state === 'consent_disputed' && isReviewer" type="button" class="rounded-lg border border-stone-200 px-2.5 py-1 text-xs dark:border-stone-700" @click="startAction(c.root.id, 'reinstate')">Pulihkan (tinjauan)</button>
        </div>
        <form v-if="returning === c.root.id" class="mt-2 space-y-2" @submit.prevent="submitReturn">
          <p v-if="returnError" class="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-300">{{ returnError }}</p>
          <p class="text-xs text-stone-500">Hanya item dan jumlah dalam cakupan persetujuan yang dapat diajukan.</p>
          <div v-for="line in c.root.scope ?? []" :key="line.order_item_id" class="flex items-center justify-between gap-3 text-sm">
            <span class="min-w-0 flex-1 truncate">{{ ctx?.items?.find((i) => i.id === line.order_item_id)?.name ?? 'Item #' + line.order_item_id }}</span>
            <input v-model.number="returnQty[line.order_item_id]" type="number" min="0" :max="line.quantity" class="w-20 rounded-lg border border-stone-200 bg-white px-2 py-1 text-right dark:border-stone-700 dark:bg-stone-950" :aria-label="'Jumlah return item ' + line.order_item_id" />
            <span class="w-14 text-xs text-stone-400">maks {{ line.quantity }}</span>
          </div>
          <label class="block text-xs text-stone-600 dark:text-stone-300">Alasan
            <textarea v-model="returnReason" required rows="2" maxlength="1000" :class="inputClass" />
          </label>
          <PrimeFileUpload
            :model-value="returnFile ? [returnFile] : []"
            label="Foto bukti kondisi barang"
            required
            accept="image/jpeg,image/png,image/webp"
            capture="environment"
            test-id="consent-return-evidence-upload"
            @update:model-value="returnFile = $event[0] ?? null"
          />
          <div class="flex gap-2">
            <button type="submit" :disabled="submittingReturn || !returnLines.length || !returnReason.trim() || !returnFile" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50">{{ submittingReturn ? 'Mengajukan...' : 'Ajukan' }}</button>
            <button type="button" class="px-2 text-xs text-stone-500" @click="returning = null">Batal</button>
          </div>
        </form>
        <form v-if="actingOn?.rootId === c.root.id" class="mt-2 space-y-2" @submit.prevent="submitAction">
          <p v-if="actionError" class="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-300">{{ actionError }}</p>
          <label class="block text-xs text-stone-600 dark:text-stone-300">Alasan
            <textarea v-model="actionReason" required rows="2" maxlength="1000" :class="inputClass" />
          </label>
          <div class="flex gap-2">
            <button type="submit" :disabled="acting || !actionReason.trim()" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50">Kirim</button>
            <button type="button" class="px-2 text-xs text-stone-500" @click="actingOn = null">Batal</button>
          </div>
        </form>
      </li>
    </ul>
    <p v-else-if="!errorMessage" class="text-sm text-stone-400">Belum ada persetujuan tercatat untuk order ini.</p>

    <div v-if="open && ctx?.can_capture" class="mt-4 rounded-xl border border-brand-200 bg-brand-50/40 p-3 dark:border-brand-900 dark:bg-stone-950">
      <p v-if="wizardError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ wizardError }}</p>

      <div v-if="step === 'scope'" class="space-y-3">
        <p class="text-sm font-medium text-stone-700 dark:text-stone-200">1. Pilih item dan jumlah yang disetujui pelanggan</p>
        <div v-for="item in ctx.items" :key="item.id" class="flex items-center justify-between gap-3 text-sm">
          <span class="min-w-0 flex-1 truncate">{{ item.name }}<span v-if="item.variation" class="text-stone-500"> · {{ item.variation }}</span></span>
          <input
            v-model.number="qty[item.id]"
            type="number"
            min="0"
            :max="item.max_quantity"
            :disabled="item.max_quantity < 1"
            class="w-20 rounded-lg border border-stone-200 bg-white px-2 py-1 text-right dark:border-stone-700 dark:bg-stone-950"
            :aria-label="'Jumlah ' + item.name"
          />
          <span class="w-12 text-xs text-stone-400">maks {{ item.max_quantity }}</span>
        </div>
        <div class="flex gap-2">
          <button type="button" :disabled="busy || !canContinueFromScope" class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-medium text-white disabled:opacity-50" @click="sendOtp">
            {{ busy ? 'Mengirim...' : 'Kirim Kode ke Email Pelanggan' }}
          </button>
          <button type="button" class="px-2 text-xs text-stone-500" @click="open = false">Batal</button>
        </div>
      </div>

      <form v-else-if="step === 'otp'" class="space-y-3" @submit.prevent="checkOtp">
        <p class="text-sm font-medium text-stone-700 dark:text-stone-200">2. Minta pelanggan menyebutkan kode 6 digit dari email</p>
        <input v-model="otpCode" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code" placeholder="000000" class="block w-40 rounded-lg border border-stone-200 bg-white px-3 py-2 text-center text-lg tracking-widest dark:border-stone-700 dark:bg-stone-950" />
        <div class="flex gap-2">
          <button type="submit" :disabled="busy || otpCode.length !== 6" class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-medium text-white disabled:opacity-50">{{ busy ? 'Memeriksa...' : 'Verifikasi' }}</button>
          <button type="button" :disabled="busy" class="px-2 text-xs text-stone-500 underline" @click="sendOtp">Kirim ulang</button>
          <button type="button" class="px-2 text-xs text-stone-500" @click="open = false">Batal</button>
        </div>
      </form>

      <form v-else class="space-y-3" @submit.prevent="submitCapture">
        <p class="text-sm font-medium text-stone-700 dark:text-stone-200">3. Lampirkan bukti foto/screenshot dan catatan</p>
        <PrimeFileUpload
          :model-value="evidence ? [evidence] : []"
          label="Foto / screenshot"
          required
          accept="image/jpeg,image/png,image/webp"
          test-id="consent-capture-evidence-upload"
          @update:model-value="evidence = $event[0] ?? null"
        />
        <label class="block text-sm text-stone-600 dark:text-stone-300">Catatan
          <textarea v-model="note" required rows="2" maxlength="1000" :class="inputClass" />
        </label>
        <div class="flex gap-2">
          <button type="submit" :disabled="busy || !evidence || !note.trim()" class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-medium text-white disabled:opacity-50">{{ busy ? 'Menyimpan...' : 'Simpan Persetujuan' }}</button>
          <button type="button" class="px-2 text-xs text-stone-500" @click="open = false">Batal</button>
        </div>
      </form>
    </div>
  </section>
</template>
