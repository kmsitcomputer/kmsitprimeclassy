<script setup lang="ts">
import PrimeFileUpload from '@/components/ui/PrimeFileUpload.vue'
/**
 * G17-D4: a refund on a CRM-origin order needs the recipient identity, method and authorization evidence on
 * record before any money moves. This panel only records evidence (append-only; a correction supersedes the
 * previous row) — it never creates, approves or settles a refund.
 */
import { ref, computed } from 'vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { formatDateTime } from '@/utils/format'
import { listRefundRecipients, recordRefundRecipient, type RefundRecipient, type RecipientMethod } from '@/api/crm'

const props = defineProps<{ orderId: number }>()

const rows = ref<RefundRecipient[]>([])
const loading = ref(true)
const errorMessage = ref('')
const open = ref(false)
const saving = ref(false)
const formError = ref('')

const METHOD_LABEL: Record<RecipientMethod, string> = { bank_transfer: 'Transfer bank', ewallet: 'E-wallet', cash: 'Tunai' }

const blank = () => ({
  recipient_name: '', recipient_phone: '', recipient_relation: '', method: 'bank_transfer' as RecipientMethod,
  bank_or_provider: '', account_number: '', account_holder: '', authorization_note: '',
})
const form = ref(blank())
const evidence = ref<File | null>(null)

const needsDestination = computed(() => form.value.method !== 'cash')
const latest = computed(() => rows.value.at(-1) ?? null)

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    rows.value = await listRefundRecipients(props.orderId)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat penerima refund.'
  } finally {
    loading.value = false
  }
}
load()

function startForm() {
  form.value = blank()
  evidence.value = null
  formError.value = ''
  open.value = true
}

async function submit() {
  saving.value = true
  formError.value = ''
  try {
    const f = form.value
    await recordRefundRecipient(props.orderId, {
      recipient_name: f.recipient_name,
      recipient_phone: f.recipient_phone,
      recipient_relation: f.recipient_relation,
      method: f.method,
      destination: needsDestination.value
        ? { bank_or_provider: f.bank_or_provider, account_number: f.account_number, account_holder: f.account_holder }
        : undefined,
      authorization_note: f.authorization_note,
      evidence: evidence.value,
    })
    open.value = false
    await load()
  } catch (e) {
    formError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menyimpan penerima refund.'
  } finally {
    saving.value = false
  }
}

const inputClass =
  'mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950'
</script>

<template>
  <section class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
      <h2 class="text-sm font-semibold text-stone-800 dark:text-stone-100">Penerima Refund (Pelanggan CRM)</h2>
      <button v-if="!open" type="button" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-700" @click="startForm">
        {{ latest ? 'Koreksi Penerima' : 'Catat Penerima' }}
      </button>
    </div>
    <p class="mb-3 text-xs text-stone-500 dark:text-stone-400">
      Tanpa akun konsumen, refund hanya dapat diproses setelah penerima, metode, dan bukti otorisasi tercatat.
    </p>

    <p v-if="errorMessage" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ errorMessage }} <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
    </p>
    <p v-else-if="loading" class="text-sm text-stone-500">Memuat...</p>

    <div v-else-if="latest" class="rounded-xl border border-stone-100 p-3 text-sm dark:border-stone-800">
      <p class="font-medium text-stone-800 dark:text-stone-100">{{ latest.recipient_name }}
        <span v-if="latest.recipient_relation" class="font-normal text-stone-500"> · {{ latest.recipient_relation }}</span>
      </p>
      <p class="text-xs text-stone-500">
        {{ METHOD_LABEL[latest.method] }}
        <template v-if="latest.destination?.bank_or_provider"> · {{ latest.destination.bank_or_provider }}</template>
        <template v-if="latest.destination?.account_number"> · {{ latest.destination.account_number }} a.n. {{ latest.destination.account_holder }}</template>
        <template v-else-if="latest.destination?.account_number_tail"> · ****{{ latest.destination.account_number_tail }}</template>
      </p>
      <p class="mt-1 text-xs text-stone-600 dark:text-stone-300">{{ latest.authorization_note }}</p>
      <p class="mt-1 text-xs text-stone-400">
        Dicatat {{ formatDateTime(latest.occurred_at) }} · {{ rows.length }} versi
        <span v-if="latest.consent_event_id"> · dasar persetujuan #{{ latest.consent_event_id }}</span>
        <span v-if="latest.has_evidence_file"> · dokumen terlampir</span>
      </p>
    </div>
    <p v-else class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">
      Belum ada penerima refund tercatat. Refund untuk order ini akan ditolak sampai bukti dicatat.
    </p>

    <form v-if="open" class="mt-4 space-y-3 rounded-xl border border-brand-200 bg-brand-50/40 p-3 dark:border-brand-900 dark:bg-stone-950" @submit.prevent="submit">
      <p v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ formError }}</p>
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="text-sm text-stone-600 dark:text-stone-300">Nama penerima
          <input v-model="form.recipient_name" required maxlength="255" :class="inputClass" />
        </label>
        <label class="text-sm text-stone-600 dark:text-stone-300">Telepon
          <input v-model="form.recipient_phone" type="tel" maxlength="30" :class="inputClass" />
        </label>
        <label class="text-sm text-stone-600 dark:text-stone-300">Hubungan dengan pelanggan
          <input v-model="form.recipient_relation" maxlength="100" :class="inputClass" />
        </label>
        <label class="text-sm text-stone-600 dark:text-stone-300">Metode
          <select v-model="form.method" :class="inputClass">
            <option v-for="(label, key) in METHOD_LABEL" :key="key" :value="key">{{ label }}</option>
          </select>
        </label>
      </div>
      <div v-if="needsDestination" class="grid gap-3 sm:grid-cols-3">
        <label class="text-sm text-stone-600 dark:text-stone-300">Bank / penyedia
          <input v-model="form.bank_or_provider" maxlength="100" :class="inputClass" />
        </label>
        <label class="text-sm text-stone-600 dark:text-stone-300">Nomor rekening
          <input v-model="form.account_number" required maxlength="50" inputmode="numeric" :class="inputClass" />
        </label>
        <label class="text-sm text-stone-600 dark:text-stone-300">Atas nama
          <input v-model="form.account_holder" required maxlength="150" :class="inputClass" />
        </label>
      </div>
      <label class="block text-sm text-stone-600 dark:text-stone-300">Catatan otorisasi
        <textarea v-model="form.authorization_note" required rows="2" maxlength="2000" :class="inputClass" />
      </label>
      <PrimeFileUpload
        :model-value="evidence ? [evidence] : []"
        label="Foto / dokumen otorisasi"
        accept="image/jpeg,image/png,image/webp"
        capture="environment"
        test-id="refund-recipient-evidence-upload"
        @update:model-value="evidence = $event[0] ?? null"
      />
      <p class="text-xs text-stone-500">Sertakan dokumen atau foto otorisasi dari pelanggan.</p>
      <div class="flex gap-2">
        <button type="submit" :disabled="saving || !evidence || !form.authorization_note.trim()" class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-medium text-white disabled:opacity-50">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
        <button type="button" class="px-2 text-xs text-stone-500" @click="open = false">Batal</button>
      </div>
    </form>
  </section>
</template>
