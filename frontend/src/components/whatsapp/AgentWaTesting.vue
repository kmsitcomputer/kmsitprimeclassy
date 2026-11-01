<script setup lang="ts">
/**
 * Registered test numbers + test-send for ONE Agen. A real message is only ever sent from this Agen's own paired device and only to a
 * number registered here (the backend enforces both, plus rate limits); the page just drives it. Full numbers are never displayed back.
 */
import { computed, onMounted, ref } from 'vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import {
  addAgentWaTestNumber,
  listAgentWaTestNumbers,
  removeAgentWaTestNumber,
  sendAgentWaTest,
  type AgentWaTestNumbers,
  type AgentWaTestResult,
} from '@/api/agentWhatsapp'

const props = defineProps<{ deviceConnected: boolean; gatewayOn: boolean }>()

const data = ref<AgentWaTestNumbers>({ numbers: [], max: 5 })
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const newNumber = ref('')
const attested = ref(false)
const text = ref('')
const target = ref('')
const result = ref<AgentWaTestResult | null>(null)

const canSend = computed(() => props.deviceConnected && props.gatewayOn && data.value.numbers.length > 0)

async function run(work: () => Promise<void>) {
  busy.value = true
  error.value = ''
  try {
    await work()
  } catch (e) {
    error.value = e instanceof ApiError ? formatApiError(e) : 'Permintaan gagal. Coba lagi.'
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  try {
    data.value = await listAgentWaTestNumbers()
  } catch (e) {
    error.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat nomor uji.'
  } finally {
    loading.value = false
  }
})

const add = () =>
  run(async () => {
    data.value = await addAgentWaTestNumber(newNumber.value)
    newNumber.value = ''
    attested.value = false
  })
const remove = (id: number) =>
  run(async () => {
    data.value = await removeAgentWaTestNumber(id)
  })
const send = () =>
  run(async () => {
    result.value = null
    result.value = await sendAgentWaTest(target.value, text.value)
  })

const registeredNumberHint = 'Kirim ke nomor uji terdaftar: masukkan ulang nomor lengkapnya (nomor tidak pernah ditampilkan penuh).'

const inputClass = 'mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950'
const cardClass = 'rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900'
const btnPrimary = 'rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50'
const btnGhost =
  'rounded-lg border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-50 dark:border-stone-700 dark:text-stone-200 dark:hover:bg-stone-800'
</script>

<template>
  <section :class="cardClass" aria-label="Pesan uji" data-test="wa-testing">
    <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">Nomor uji &amp; pesan uji</h2>
    <p class="mt-1 text-xs text-stone-500 dark:text-stone-400">
      Pesan uji dikirim dari perangkat WhatsApp cabang ini, hanya ke nomor uji yang Anda daftarkan di bawah (maks. {{ data.max }}). Tidak ada pengiriman dari perangkat lain.
    </p>

    <p v-if="error" role="alert" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300" data-test="wa-test-error">{{ error }}</p>

    <ul v-if="!loading && data.numbers.length" class="mt-3 divide-y divide-stone-100 text-sm dark:divide-stone-800" data-test="wa-test-list">
      <li v-for="n in data.numbers" :key="n.id" class="flex items-center justify-between py-1.5">
        <span class="font-mono text-stone-700 dark:text-stone-200">{{ n.masked }}</span>
        <button type="button" :disabled="busy" class="text-xs text-red-600 underline dark:text-red-400" @click="remove(n.id)">Hapus</button>
      </li>
    </ul>
    <p v-else-if="!loading" class="mt-3 text-sm text-stone-500" data-test="wa-test-empty">Belum ada nomor uji terdaftar.</p>

    <form v-if="data.numbers.length < data.max" class="mt-3 max-w-sm space-y-2" @submit.prevent="add">
      <label class="block text-sm text-stone-600 dark:text-stone-300" for="wa-test-new">Daftarkan nomor uji</label>
      <input id="wa-test-new" v-model="newNumber" inputmode="tel" placeholder="08xxxxxxxxxx" :class="inputClass" data-test="wa-test-new" />
      <label class="flex items-start gap-2 text-xs text-stone-600 dark:text-stone-300">
        <input v-model="attested" type="checkbox" class="mt-0.5 h-4 w-4 accent-brand-600" data-test="wa-test-attest" />
        Saya menjamin nomor ini milik saya atau pemiliknya mengizinkan menerima pesan uji.
      </label>
      <button type="submit" :disabled="busy || !newNumber.trim() || !attested" :class="btnGhost" data-test="wa-test-add">Daftarkan</button>
    </form>

    <form class="mt-5 max-w-sm space-y-2 border-t border-stone-100 pt-4 dark:border-stone-800" @submit.prevent="send">
      <p class="text-xs text-stone-500">{{ registeredNumberHint }}</p>
      <label class="block text-sm text-stone-600 dark:text-stone-300" for="wa-test-target">Nomor tujuan</label>
      <input id="wa-test-target" v-model="target" inputmode="tel" placeholder="08xxxxxxxxxx" :class="inputClass" data-test="wa-test-target" />
      <label class="block text-sm text-stone-600 dark:text-stone-300" for="wa-test-text">Pesan (opsional)</label>
      <textarea id="wa-test-text" v-model="text" rows="2" maxlength="300" :class="inputClass" />
      <button type="submit" :disabled="busy || !canSend || !target.trim()" :class="btnPrimary" data-test="wa-test-send">Kirim pesan uji</button>
      <p v-if="!deviceConnected" class="text-xs text-amber-700 dark:text-amber-400" data-test="wa-test-device-hint">Hubungkan perangkat WhatsApp cabang ini terlebih dahulu.</p>
      <p v-else-if="!gatewayOn" class="text-xs text-amber-700 dark:text-amber-400">WhatsApp sedang dimatikan secara global.</p>
    </form>

    <p
      v-if="result"
      role="status"
      class="mt-3 rounded-lg px-3 py-2 text-sm"
      :class="result.status === 'sent' ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300'"
      data-test="wa-test-result"
    >
      Status: {{ result.status }} → {{ result.recipient }}<span v-if="result.sender_suffix"> · perangkat …{{ result.sender_suffix }}</span>
      <span v-if="result.skip_reason || result.error_class"> · {{ result.skip_reason ?? result.error_class }}</span>
    </p>
  </section>
</template>
