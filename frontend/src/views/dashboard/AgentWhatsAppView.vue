<script setup lang="ts">
/**
 * WhatsApp Saya (Agen) / WhatsApp Cabang (Admin Agen). The Agent — or the Admin of its branch — pairs and manages the branch WhatsApp number here; order notifications and verification codes of this
 * branch are sent from it. The Super Admin only has the global ON/OFF. Every rule (ownership, one device, isolation) is enforced by the
 * backend — this page only drives it. No GOWA secret, QR source URL or full number is ever in the browser.
 */
import { computed, onBeforeUnmount, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AgentWaEvents from '@/components/whatsapp/AgentWaEvents.vue'
import AgentWaTesting from '@/components/whatsapp/AgentWaTesting.vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { formatDateTime } from '@/utils/format'
import {
  createAgentWhatsAppDevice,
  getAgentWhatsApp,
  getAgentWhatsAppScope,
  setAgentWhatsAppTarget,
  listAgentWhatsAppMessages,
  logoutAgentWhatsApp,
  reconnectAgentWhatsApp,
  replaceAgentWhatsAppDevice,
  startAgentCodePairing,
  startAgentQrPairing,
  type AgentWaMessage,
  type AgentWaStatus,
} from '@/api/agentWhatsapp'

const scopes = ref<{ id: number; name: string }[]>([])
const selectedAgent = ref<number | undefined>(undefined)
const status = ref<AgentWaStatus | null>(null)
const messages = ref<AgentWaMessage[]>([])
const loading = ref(true)
const loadError = ref('')
const busy = ref(false)
const notice = ref<{ kind: 'ok' | 'err'; text: string } | null>(null)

const qrImage = ref<string | null>(null)
const qrSeconds = ref(0)
const pairCode = ref<string | null>(null)
const phone = ref('')
const confirmingReplace = ref(false)

let poll: ReturnType<typeof setInterval> | null = null
let countdown: ReturnType<typeof setInterval> | null = null

const stopTimers = () => {
  if (poll) clearInterval(poll)
  if (countdown) clearInterval(countdown)
  poll = countdown = null
}
onBeforeUnmount(stopTimers)

const say = (kind: 'ok' | 'err', text: string) => (notice.value = { kind, text })
const fail = (e: unknown, fallback: string) => say('err', e instanceof ApiError ? formatApiError(e) : fallback)

const device = computed(() => status.value?.device ?? null)
const state = computed(() => device.value?.state ?? 'none')
const unavailable = computed(() => !!status.value && !status.value.gateway_ready)
const globalOff = computed(() => !!status.value && status.value.gateway_ready && !status.value.global_enabled)

const STATE_LABEL: Record<string, { text: string; cls: string }> = {
  none: { text: 'Belum ada perangkat', cls: 'bg-stone-100 text-stone-700 dark:bg-stone-800 dark:text-stone-300' },
  not_paired: { text: 'Belum terhubung', cls: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' },
  pairing: { text: 'Menunggu pemindaian', cls: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' },
  connected: { text: 'Terhubung', cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' },
  disconnected: { text: 'Terputus', cls: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' },
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    if (!scopes.value.length) {
      scopes.value = await getAgentWhatsAppScope()
      selectedAgent.value = scopes.value[0]?.id
    }
    setAgentWhatsAppTarget(selectedAgent.value)
    status.value = await getAgentWhatsApp()
    messages.value = status.value.table_ready ? await listAgentWhatsAppMessages().catch(() => []) : []
  } catch (e) {
    loadError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat status WhatsApp.'
  } finally {
    loading.value = false
  }
}
load()

/** Switching the managed Agen reloads everything for that Agen; the backend rejects any Agen outside the actor's scope. */
function changeAgent() {
  stopTimers()
  qrImage.value = pairCode.value = null
  load()
}

async function refresh() {
  try {
    status.value = await getAgentWhatsApp()
    if (status.value.device?.state === 'connected') {
      stopTimers()
      qrImage.value = null
      pairCode.value = null
      say('ok', 'WhatsApp berhasil terhubung.')
      messages.value = await listAgentWhatsAppMessages().catch(() => messages.value)
    }
  } catch {
    /* a failed poll is retried on the next tick */
  }
}

/** While a QR / code is on screen, ask the backend (which asks the gateway) every few seconds whether the phone has linked. */
function watchPairing() {
  stopTimers()
  poll = setInterval(refresh, 4000)
  countdown = setInterval(() => {
    if (qrSeconds.value > 0) qrSeconds.value -= 1
  }, 1000)
}

async function run(action: () => Promise<unknown>, okText?: string) {
  busy.value = true
  notice.value = null
  try {
    await action()
    if (okText) say('ok', okText)
  } catch (e) {
    fail(e, 'Permintaan gagal. Coba lagi.')
  } finally {
    busy.value = false
  }
}

const createDevice = () => run(async () => { status.value = await createAgentWhatsAppDevice() }, 'Perangkat dibuat. Pilih cara menghubungkan.')

const showQr = () =>
  run(async () => {
    pairCode.value = null
    const r = await startAgentQrPairing()
    qrImage.value = r.image
    qrSeconds.value = r.expires_in ?? 30
    status.value = status.value ? { ...status.value, device: r.device } : status.value
    watchPairing()
  })

const showCode = () =>
  run(async () => {
    qrImage.value = null
    const r = await startAgentCodePairing(phone.value)
    pairCode.value = r.code
    status.value = status.value ? { ...status.value, device: r.device } : status.value
    watchPairing()
  })

const reconnect = () =>
  run(async () => {
    const d = await reconnectAgentWhatsApp()
    if (status.value) status.value = { ...status.value, device: d }
  }, 'Permintaan sambung ulang dikirim.')

const logout = () =>
  run(async () => {
    const d = await logoutAgentWhatsApp()
    if (status.value) status.value = { ...status.value, device: d }
    qrImage.value = pairCode.value = null
    stopTimers()
  }, 'WhatsApp diputuskan. Perangkat tetap tersimpan dan dapat dihubungkan lagi.')

const replace = () =>
  run(async () => {
    const d = await replaceAgentWhatsAppDevice()
    if (status.value) status.value = { ...status.value, device: d }
    confirmingReplace.value = false
    qrImage.value = pairCode.value = null
    stopTimers()
  }, 'Perangkat diganti. Hubungkan nomor WhatsApp yang baru.')

const inputClass = 'mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950'
const cardClass = 'rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900'
const btnPrimary = 'rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50'
const btnGhost =
  'rounded-lg border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-50 dark:border-stone-700 dark:text-stone-200 dark:hover:bg-stone-800'

const SKIP: Record<string, string> = {
  agent_device_not_ready: 'Perangkat belum terhubung',
  gateway_disabled: 'WhatsApp dimatikan global',
  number_not_verified: 'Nomor penerima belum terverifikasi',
  recipient_not_verified: 'Penerima tidak memenuhi syarat',
  no_phone: 'Penerima tanpa nomor',
}
const outcome = (m: AgentWaMessage) => (m.skip_reason ? SKIP[m.skip_reason] ?? m.skip_reason : m.error_class ?? '')
</script>

<template>
  <DashboardLayout>
    <p class="text-xs text-stone-500 dark:text-stone-400">Pengaturan › WhatsApp</p>
    <h1 class="font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">WhatsApp Cabang</h1>
    <div v-if="scopes.length" class="mb-3 text-sm text-stone-600 dark:text-stone-300" data-test="wa-scope">
      <label v-if="scopes.length > 1" class="flex items-center gap-2">Agen
        <select v-model="selectedAgent" class="rounded-lg border border-stone-200 bg-white px-2 py-1 text-sm dark:border-stone-700 dark:bg-stone-950" @change="changeAgent">
          <option v-for="a in scopes" :key="a.id" :value="a.id">{{ a.name }}</option>
        </select>
      </label>
      <span v-else>Cabang: <strong>{{ scopes[0]?.name }}</strong></span>
    </div>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
      Hubungkan nomor WhatsApp cabang Anda. Notifikasi pesanan dan kode verifikasi pelanggan cabang ini dikirim dari nomor ini, dan hanya dari nomor ini.
    </p>

    <p
      v-if="notice"
      :role="notice.kind === 'err' ? 'alert' : 'status'"
      class="mb-4 rounded-lg px-3 py-2 text-sm"
      :class="notice.kind === 'ok' ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300'"
      data-test="notice"
    >
      {{ notice.text }}
    </p>

    <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>
    <p v-else-if="loadError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ loadError }} <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
    </p>

    <template v-else-if="status">
      <p v-if="unavailable" role="alert" class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-300" data-test="gateway-unavailable">
        Layanan WhatsApp belum disiapkan oleh administrator platform. Anda belum dapat menghubungkan perangkat.
      </p>
      <p v-else-if="globalOff" role="status" class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-300" data-test="global-off">
        WhatsApp sedang dimatikan oleh Super Admin untuk seluruh platform. Perangkat dan sesi Anda tetap tersimpan, tetapi tidak ada pesan yang dikirim sampai diaktifkan kembali.
      </p>

      <section :class="cardClass" aria-label="Status perangkat">
        <div class="flex flex-wrap items-center gap-3">
          <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">Perangkat WhatsApp</h2>
          <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="STATE_LABEL[state]?.cls" data-test="state">{{ STATE_LABEL[state]?.text }}</span>
        </div>
        <p v-if="device?.phone_masked" class="mt-2 text-sm text-stone-700 dark:text-stone-200" data-test="masked-phone">Nomor: {{ device.phone_masked }}</p>
        <p v-if="device?.last_checked_at" class="mt-1 text-xs text-stone-500">Diperiksa {{ formatDateTime(device.last_checked_at) }}</p>

        <!-- no device yet -->
        <div v-if="!device" class="mt-4">
          <button type="button" :disabled="busy || unavailable" :class="btnPrimary" @click="createDevice">Buat perangkat</button>
        </div>

        <!-- not connected: choose a pairing method -->
        <div v-else-if="state !== 'connected'" class="mt-4 space-y-4">
          <div class="flex flex-wrap gap-2">
            <button type="button" :disabled="busy || unavailable" :class="btnPrimary" @click="showQr">{{ qrImage ? 'Muat ulang QR' : 'Tampilkan QR' }}</button>
            <button v-if="state === 'disconnected'" type="button" :disabled="busy || unavailable" :class="btnGhost" @click="reconnect">Sambung ulang</button>
          </div>

          <div v-if="qrImage" class="flex flex-col items-start gap-2" data-test="qr">
            <img :src="qrImage" alt="Kode QR untuk menghubungkan WhatsApp" class="h-56 w-56 max-w-full rounded-lg border border-stone-200 bg-white p-2" />
            <p class="text-xs text-stone-500">
              Buka WhatsApp di ponsel › Perangkat tertaut › Tautkan perangkat, lalu pindai kode ini.
              <span v-if="qrSeconds > 0">Berlaku sekitar {{ qrSeconds }} detik.</span>
              <span v-else class="text-amber-700">Kode kedaluwarsa — muat ulang QR.</span>
            </p>
          </div>

          <form class="max-w-sm" @submit.prevent="showCode">
            <label class="text-sm text-stone-600 dark:text-stone-300" for="agent-wa-phone">Atau gunakan kode tautan (masukkan nomor WhatsApp yang akan dihubungkan)</label>
            <input id="agent-wa-phone" v-model="phone" inputmode="tel" placeholder="08xxxxxxxxxx" :class="inputClass" />
            <button type="submit" :disabled="busy || unavailable || !phone.trim()" :class="[btnGhost, 'mt-2']">Minta kode tautan</button>
          </form>
          <div v-if="pairCode" class="rounded-lg bg-stone-50 p-3 dark:bg-stone-800" data-test="pair-code">
            <p class="text-xs text-stone-500">Masukkan kode ini di WhatsApp › Perangkat tertaut › Tautkan dengan nomor telepon:</p>
            <p class="mt-1 font-mono text-xl tracking-widest text-stone-800 dark:text-stone-100">{{ pairCode }}</p>
          </div>
        </div>

        <!-- connected -->
        <div v-else class="mt-4 flex flex-wrap gap-2">
          <button type="button" :disabled="busy" :class="btnGhost" @click="reconnect">Sambung ulang</button>
          <button type="button" :disabled="busy" :class="btnGhost" @click="logout">Putuskan WhatsApp</button>
        </div>

        <!-- controlled replacement -->
        <div v-if="device" class="mt-5 border-t border-stone-100 pt-4 dark:border-stone-800">
          <button v-if="!confirmingReplace" type="button" :disabled="busy" class="text-sm text-red-600 underline dark:text-red-400" @click="confirmingReplace = true">Ganti perangkat…</button>
          <div v-else class="rounded-lg bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-300" role="alertdialog" aria-label="Konfirmasi ganti perangkat">
            <p>Mengganti perangkat memutus sesi WhatsApp saat ini. Pesan baru tidak akan terkirim sampai nomor yang baru dihubungkan. Lanjutkan?</p>
            <div class="mt-2 flex gap-2">
              <button type="button" :disabled="busy" class="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50" @click="replace">Ya, ganti perangkat</button>
              <button type="button" :class="btnGhost" @click="confirmingReplace = false">Batal</button>
            </div>
          </div>
        </div>
      </section>

      <section v-if="status.table_ready" :class="[cardClass, 'mt-5']" aria-label="Riwayat pengiriman">
        <h2 class="mb-3 font-display text-base font-semibold text-stone-800 dark:text-stone-100">Riwayat pengiriman cabang ini</h2>
        <p v-if="!messages.length" class="text-sm text-stone-500" data-test="no-history">Belum ada pesan.</p>
        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-left text-sm">
            <thead class="text-xs uppercase text-stone-500">
              <tr><th class="px-2 py-1">Waktu</th><th class="px-2 py-1">Jenis</th><th class="px-2 py-1">Penerima</th><th class="px-2 py-1">Status</th><th class="px-2 py-1">Keterangan</th></tr>
            </thead>
            <tbody>
              <tr v-for="m in messages" :key="m.id" class="border-t border-stone-100 dark:border-stone-800">
                <td class="px-2 py-1.5 whitespace-nowrap">{{ formatDateTime(m.created_at) }}</td>
                <td class="px-2 py-1.5">{{ m.purpose === 'otp' ? 'Kode verifikasi' : m.event ?? m.purpose }}</td>
                <td class="px-2 py-1.5">{{ m.recipient ?? '—' }}</td>
                <td class="px-2 py-1.5">{{ m.status }}</td>
                <td class="px-2 py-1.5 text-stone-500">{{ outcome(m) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <div class="mt-5 space-y-5">
        <AgentWaTesting :key="'t' + selectedAgent" :device-connected="state === 'connected'" :gateway-on="!unavailable && !globalOff" />
        <AgentWaEvents :key="'e' + selectedAgent" />
      </div>
    </template>
  </DashboardLayout>
</template>
