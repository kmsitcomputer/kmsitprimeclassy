<script setup lang="ts">
/**
 * OD-03 / OD-04: link a CRM customer to a konsumen ACCOUNT. The proof is the OTP emailed to that account's
 * verified address — never a name/phone/email match. Linking changes future authorization only; no order,
 * payment, refund or commission is rewritten. The backend enforces every rule; this drives the steps.
 */
import { ref, computed } from 'vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { listLinkCandidates, requestLinkOtp, verifyConsentOtp, linkCrmCustomer, unlinkCrmCustomer, type CrmCustomer, type LinkCandidate } from '@/api/crm'

const props = defineProps<{ customer: CrmCustomer; canLink: boolean; canUnlink: boolean }>()
const emit = defineEmits<{ (e: 'changed', customer: CrmCustomer): void }>()

const isLinked = computed(() => props.customer.linked_konsumen_id != null)
const step = ref<'idle' | 'pick' | 'otp'>('idle')
const candidates = ref<LinkCandidate[]>([])
const loadingCandidates = ref(false)
const picked = ref<number | null>(null)
const challengeId = ref<number | null>(null)
const code = ref('')
const busy = ref(false)
const error = ref('')
const unlinking = ref(false)
const reason = ref('')

async function startPick() {
  step.value = 'pick'
  error.value = ''
  loadingCandidates.value = true
  try {
    // Only the seller's own referred konsumen are reachable (CI-05), contact masked: no enumeration of other accounts.
    candidates.value = await listLinkCandidates()
  } catch (e) {
    error.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat daftar konsumen.'
  } finally {
    loadingCandidates.value = false
  }
}

async function run(fn: () => Promise<void>, fallback: string) {
  busy.value = true
  error.value = ''
  try {
    await fn()
  } catch (e) {
    error.value = e instanceof ApiError ? formatApiError(e) : fallback
  } finally {
    busy.value = false
  }
}

const sendOtp = () =>
  run(async () => {
    challengeId.value = (await requestLinkOtp(props.customer.id, picked.value!)).challenge_id
    step.value = 'otp'
  }, 'Gagal mengirim kode verifikasi.')

const confirm = () =>
  run(async () => {
    await verifyConsentOtp(challengeId.value!, code.value)
    emit('changed', await linkCrmCustomer(props.customer.id, picked.value!, challengeId.value!))
    step.value = 'idle'
    code.value = ''
  }, 'Kode verifikasi tidak valid.')

const unlink = () =>
  run(async () => {
    emit('changed', await unlinkCrmCustomer(props.customer.id, reason.value))
    unlinking.value = false
    reason.value = ''
  }, 'Gagal melepas tautan.')

const inputClass =
  'mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950'
</script>

<template>
  <section class="mb-6 rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
    <h2 class="mb-2 text-sm font-semibold text-stone-800 dark:text-stone-100">Tautan Akun Konsumen</h2>
    <p class="mb-3 text-xs text-stone-500 dark:text-stone-400">
      Menautkan hanya mengubah otorisasi ke depan. Pesanan, pembayaran, refund, dan komisi yang sudah ada tidak berubah.
    </p>

    <p v-if="error" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ error }}</p>

    <template v-if="isLinked">
      <p class="text-sm text-emerald-700 dark:text-emerald-400">Tertaut ke akun konsumen #{{ customer.linked_konsumen_id }}.</p>
      <div v-if="canUnlink" class="mt-3">
        <button v-if="!unlinking" type="button" class="rounded-lg border border-stone-200 px-3 py-1.5 text-xs dark:border-stone-700" @click="unlinking = true">Lepas Tautan</button>
        <form v-else class="space-y-2" @submit.prevent="unlink">
          <label class="block text-sm text-stone-600 dark:text-stone-300">Alasan (dicatat dalam audit)
            <textarea v-model="reason" required rows="2" maxlength="1000" :class="inputClass" />
          </label>
          <div class="flex gap-2">
            <button type="submit" :disabled="busy || !reason.trim()" class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-medium text-white disabled:opacity-50">{{ busy ? 'Memproses...' : 'Lepas' }}</button>
            <button type="button" class="px-2 text-xs text-stone-500" @click="unlinking = false">Batal</button>
          </div>
        </form>
      </div>
    </template>

    <template v-else-if="canLink">
      <button v-if="step === 'idle'" type="button" class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-medium text-white hover:bg-brand-700" @click="startPick">
        Tautkan ke Akun Konsumen
      </button>

      <div v-else-if="step === 'pick'" class="space-y-3">
        <p class="text-sm font-medium text-stone-700 dark:text-stone-200">1. Pilih akun konsumen Anda</p>
        <p v-if="loadingCandidates" class="text-sm text-stone-500">Memuat...</p>
        <p v-else-if="!candidates.length" class="rounded-lg border border-dashed border-stone-300 p-4 text-center text-sm text-stone-400 dark:border-stone-700">Belum ada konsumen referral Anda.</p>
        <select v-else v-model="picked" :class="inputClass" aria-label="Akun konsumen">
          <option :value="null" disabled>Pilih konsumen...</option>
          <option v-for="c in candidates" :key="c.id" :value="c.id" :disabled="!c.email_verified">{{ c.name }} · {{ c.email_masked ?? c.phone_tail ?? '-' }}{{ c.email_verified ? '' : ' (email belum terverifikasi)' }}</option>
        </select>
        <div class="flex gap-2">
          <button type="button" :disabled="busy || picked === null" class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-medium text-white disabled:opacity-50" @click="sendOtp">{{ busy ? 'Mengirim...' : 'Kirim Kode ke Email Konsumen' }}</button>
          <button type="button" class="px-2 text-xs text-stone-500" @click="step = 'idle'">Batal</button>
        </div>
      </div>

      <form v-else class="space-y-3" @submit.prevent="confirm">
        <p class="text-sm font-medium text-stone-700 dark:text-stone-200">2. Masukkan kode 6 digit yang dikirim ke email akun tersebut</p>
        <input v-model="code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code" placeholder="000000" class="block w-40 rounded-lg border border-stone-200 bg-white px-3 py-2 text-center text-lg tracking-widest dark:border-stone-700 dark:bg-stone-950" />
        <div class="flex gap-2">
          <button type="submit" :disabled="busy || code.length !== 6" class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-medium text-white disabled:opacity-50">{{ busy ? 'Memeriksa...' : 'Verifikasi & Tautkan' }}</button>
          <button type="button" :disabled="busy" class="px-2 text-xs text-stone-500 underline" @click="sendOtp">Kirim ulang</button>
          <button type="button" class="px-2 text-xs text-stone-500" @click="step = 'idle'">Batal</button>
        </div>
      </form>
    </template>

    <p v-else class="text-sm text-stone-400">Belum tertaut ke akun konsumen.</p>
  </section>
</template>
