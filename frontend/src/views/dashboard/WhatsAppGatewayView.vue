<script setup lang="ts">
/**
 * Pengaturan › Integrasi › WhatsApp Gateway (Super Admin). Configures PrimeClassy's OWN use of GOWA only: it cannot
 * administer GOWA devices. The API password is write-only (never returned, never kept after saving), every setting
 * change is audited server-side, and every rule is enforced by the backend — this page only drives it.
 */
import { ref, computed, watch } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { formatDateTime } from '@/utils/format'
import {
  getWhatsAppSettings,
  updateWhatsAppSettings,
  testWhatsAppConnection,
  sendWhatsAppTestMessage,
  previewWhatsAppTemplate,
  saveWhatsAppTemplate,
  resetWhatsAppTemplate,
  listWhatsAppMessages,
  retryWhatsAppMessage,
  type WaSettings,
  type WaConnectionStatus,
  type WaMessage,
  type WaMessagePage,
  type WaMessageStatus,
  type WaOtp,
} from '@/api/whatsapp'

type Tab = 'koneksi' | 'otp' | 'notifikasi' | 'riwayat'
const tabs: { key: Tab; label: string }[] = [
  { key: 'koneksi', label: 'Koneksi' },
  { key: 'otp', label: 'OTP' },
  { key: 'notifikasi', label: 'Notifikasi' },
  { key: 'riwayat', label: 'Riwayat' },
]
const tab = ref<Tab>('koneksi')

const settings = ref<WaSettings | null>(null)
const loading = ref(true)
const loadError = ref('')

/* ---------------------------------------------------------------- load */
const conn = ref({ enabled: false, base_url: '', username: '', password: '', device_id: '', timeout: 10, perRecipient: 6, perMinute: 20, testNumbersText: '', clearTests: false })
const otp = ref<WaOtp>({ enabled: false, ttl_minutes: 5, max_attempts: 5, cooldown_seconds: 60, limit: 3, limit_window_minutes: 10 })
const templates = ref<Record<string, string>>({})

function hydrate(s: WaSettings) {
  settings.value = s
  conn.value = {
    enabled: s.enabled, base_url: s.base_url ?? '', username: s.username ?? '', password: '', device_id: s.device_id ?? '',
    timeout: s.timeout, perRecipient: s.limits?.per_recipient_per_hour ?? 6, perMinute: s.limits?.global_per_minute ?? 20,
    testNumbersText: '', clearTests: false,
  }
  otp.value = { ...s.otp }
  templates.value = Object.fromEntries(s.events.map((e) => [e.key, e.template]))
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    hydrate(await getWhatsAppSettings())
  } catch (e) {
    loadError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat pengaturan WhatsApp.'
  } finally {
    loading.value = false
  }
}
load()

const tableReady = computed(() => settings.value?.table_ready === true)

/* ---------------------------------------------------------------- helpers */
const notice = ref<{ kind: 'ok' | 'err'; text: string } | null>(null)
let noticeTimer: ReturnType<typeof setTimeout> | null = null
function say(kind: 'ok' | 'err', text: string) {
  notice.value = { kind, text }
  if (noticeTimer) clearTimeout(noticeTimer)
  if (kind === 'ok') noticeTimer = setTimeout(() => (notice.value = null), 4000)
}
const fail = (e: unknown, fallback: string) => say('err', e instanceof ApiError ? formatApiError(e) : fallback)

const inputClass =
  'mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950'
const cardClass = 'rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900'
const btnPrimary = 'rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50'
const btnGhost =
  'rounded-lg border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-50 dark:border-stone-700 dark:text-stone-200 dark:hover:bg-stone-800'

/* ---------------------------------------------------------------- Koneksi */
const saving = ref(false)

async function saveConnection() {
  saving.value = true
  try {
    const c = conn.value
    const numbers = c.testNumbersText.split(/[\n,;]+/).map((n) => n.trim()).filter(Boolean)
    const s = await updateWhatsAppSettings({
      enabled: c.enabled,
      base_url: c.base_url,
      username: c.username,
      device_id: c.device_id,
      timeout: Number(c.timeout),
      limits: { per_recipient_per_hour: Number(c.perRecipient), global_per_minute: Number(c.perMinute) },
      ...(c.password ? { password: c.password } : {}),
      ...(c.clearTests ? { test_numbers: [] } : numbers.length ? { test_numbers: numbers } : {}),
    })
    hydrate(s) // also wipes the password field: the secret never lingers in the page
    say('ok', 'Pengaturan koneksi disimpan.')
  } catch (e) {
    fail(e, 'Gagal menyimpan pengaturan.')
  } finally {
    saving.value = false
  }
}

const status = ref<WaConnectionStatus | null>(null)
const testing = ref(false)
const STATE_LABEL: Record<string, { text: string; cls: string }> = {
  connected: { text: 'Terhubung', cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' },
  disconnected: { text: 'Perangkat terputus', cls: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' },
  unauthorized: { text: 'Kredensial ditolak', cls: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' },
  unreachable: { text: 'Server tidak terjangkau', cls: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' },
  not_configured: { text: 'Belum dikonfigurasi', cls: 'bg-stone-100 text-stone-700 dark:bg-stone-800 dark:text-stone-300' },
  error: { text: 'Galat dari gateway', cls: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' },
}

async function testConnection() {
  testing.value = true
  status.value = null
  try {
    status.value = await testWhatsAppConnection()
  } catch (e) {
    fail(e, 'Gagal menguji koneksi.')
  } finally {
    testing.value = false
  }
}

const testNumber = ref('')
const testText = ref('')
const sendingTest = ref(false)
const testResult = ref<WaMessage | null>(null)

async function sendTest() {
  sendingTest.value = true
  testResult.value = null
  try {
    testResult.value = await sendWhatsAppTestMessage(testNumber.value, testText.value)
    say(testResult.value.status === 'sent' ? 'ok' : 'err', `Pesan uji: ${statusText(testResult.value.status)}.`)
  } catch (e) {
    fail(e, 'Gagal mengirim pesan uji.')
  } finally {
    sendingTest.value = false
  }
}

/* ---------------------------------------------------------------- OTP */
async function saveOtp() {
  saving.value = true
  try {
    hydrate(await updateWhatsAppSettings({ otp: { ...otp.value } }))
    say('ok', 'Pengaturan OTP disimpan.')
  } catch (e) {
    fail(e, 'Gagal menyimpan pengaturan OTP.')
  } finally {
    saving.value = false
  }
}

/* ---------------------------------------------------------------- Notifikasi */
const previews = ref<Record<string, string>>({})
const eventErrors = ref<Record<string, string>>({})
const busyEvent = ref<string | null>(null)

async function toggleEvent(key: string, enabled: boolean) {
  busyEvent.value = key
  try {
    hydrate(await updateWhatsAppSettings({ events: { [key]: enabled } }))
  } catch (e) {
    fail(e, 'Gagal mengubah event.')
    await load()
  } finally {
    busyEvent.value = null
  }
}

async function preview(key: string) {
  eventErrors.value[key] = ''
  try {
    previews.value[key] = await previewWhatsAppTemplate(key, templates.value[key] ?? '')
  } catch (e) {
    previews.value[key] = ''
    eventErrors.value[key] = e instanceof ApiError ? formatApiError(e) : 'Pratinjau gagal.'
  }
}

async function saveTemplate(key: string) {
  busyEvent.value = key
  eventErrors.value[key] = ''
  try {
    hydrate(await saveWhatsAppTemplate(key, templates.value[key] ?? ''))
    say('ok', 'Template disimpan.')
  } catch (e) {
    eventErrors.value[key] = e instanceof ApiError ? formatApiError(e) : 'Gagal menyimpan template.'
  } finally {
    busyEvent.value = null
  }
}

async function resetTemplate(key: string) {
  busyEvent.value = key
  try {
    hydrate(await resetWhatsAppTemplate(key))
    previews.value[key] = ''
    say('ok', 'Template dikembalikan ke bawaan.')
  } catch (e) {
    fail(e, 'Gagal mengembalikan template.')
  } finally {
    busyEvent.value = null
  }
}

function insertVariable(key: string, variable: string) {
  templates.value[key] = (templates.value[key] ?? '') + `{${variable}}`
}

/* ---------------------------------------------------------------- Riwayat */
const history = ref<WaMessagePage | null>(null)
const historyLoading = ref(false)
const historyError = ref('')
const filters = ref({ status: '', purpose: '' })
const page = ref(1)

async function loadHistory() {
  historyLoading.value = true
  historyError.value = ''
  try {
    history.value = await listWhatsAppMessages({
      page: page.value, status: filters.value.status || undefined, purpose: filters.value.purpose || undefined,
    })
  } catch (e) {
    historyError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat riwayat.'
  } finally {
    historyLoading.value = false
  }
}

watch(tab, (t) => {
  if (t === 'riwayat' && !history.value) loadHistory()
})

function applyFilters() {
  page.value = 1
  loadHistory()
}

const STATUS_TEXT: Record<WaMessageStatus, string> = {
  queued: 'Antri', sending: 'Mengirim', sent: 'Terkirim', failed: 'Gagal', unknown: 'Tidak pasti', skipped: 'Dilewati',
}
const statusText = (s: WaMessageStatus) => STATUS_TEXT[s] ?? s
const STATUS_CLASS: Record<WaMessageStatus, string> = {
  queued: 'bg-stone-100 text-stone-700 dark:bg-stone-800 dark:text-stone-300',
  sending: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
  sent: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
  unknown: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  skipped: 'bg-stone-100 text-stone-500 dark:bg-stone-800 dark:text-stone-400',
}

const retrying = ref<WaMessage | null>(null)
const riskAccepted = ref(false)
const retryBusy = ref(false)
const retryError = ref('')

function askRetry(m: WaMessage) {
  retrying.value = m
  riskAccepted.value = false
  retryError.value = ''
}

async function confirmRetry() {
  if (!retrying.value) return
  retryBusy.value = true
  retryError.value = ''
  try {
    await retryWhatsAppMessage(retrying.value.id, riskAccepted.value)
    retrying.value = null
    say('ok', 'Percobaan ulang diantrekan.')
    await loadHistory()
  } catch (e) {
    retryError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal mencoba ulang.'
  } finally {
    retryBusy.value = false
  }
}
</script>

<template>
  <DashboardLayout>
    <p class="text-xs text-stone-500 dark:text-stone-400">Pengaturan › Integrasi › WhatsApp Gateway</p>
    <h1 class="font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">WhatsApp Gateway</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
      Provider GOWA untuk OTP dan notifikasi transaksi. Hanya Super Admin yang dapat mengubah konfigurasi global ini.
    </p>

    <p
      v-if="notice"
      :role="notice.kind === 'err' ? 'alert' : 'status'"
      class="mb-4 rounded-lg px-3 py-2 text-sm"
      :class="notice.kind === 'ok' ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300'"
    >
      {{ notice.text }}
    </p>

    <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>
    <p v-else-if="loadError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ loadError }} <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
    </p>

    <template v-else-if="settings">
      <p v-if="!tableReady" role="alert" class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">
        Tabel log pengiriman belum ada di database ini (migrasi menunggu persetujuan Owner). Konfigurasi, tes koneksi, dan template sudah dapat dipakai;
        notifikasi otomatis, pesan uji, dan riwayat baru aktif setelah migrasi dijalankan.
      </p>

      <div class="mb-5 flex gap-1 overflow-x-auto border-b border-stone-200 dark:border-stone-800" role="tablist" aria-label="Bagian WhatsApp Gateway">
        <button
          v-for="t in tabs"
          :key="t.key"
          type="button"
          role="tab"
          :aria-selected="tab === t.key"
          class="whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium"
          :class="tab === t.key ? 'border-brand-600 text-brand-700 dark:text-brand-400' : 'border-transparent text-stone-500 hover:text-stone-700 dark:text-stone-400'"
          @click="tab = t.key"
        >
          {{ t.label }}
        </button>
      </div>

      <!-- ============================== KONEKSI ============================== -->
      <section v-show="tab === 'koneksi'" role="tabpanel" aria-label="Koneksi" class="space-y-5">
        <form :class="cardClass" @submit.prevent="saveConnection">
          <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-semibold text-stone-800 dark:text-stone-100">Koneksi GOWA</h2>
            <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-stone-200">
              <input v-model="conn.enabled" type="checkbox" class="h-4 w-4 accent-brand-600" />
              Aktifkan gateway
            </label>
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="text-sm text-stone-600 dark:text-stone-300">Provider
              <input value="GOWA" disabled :class="inputClass" />
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Base URL
              <input v-model="conn.base_url" type="url" maxlength="255" placeholder="http://127.0.0.1:3001" :class="inputClass" />
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Username API
              <input v-model="conn.username" type="text" autocomplete="off" maxlength="100" :class="inputClass" />
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Password API
              <input
                v-model="conn.password"
                type="password"
                autocomplete="new-password"
                maxlength="200"
                :placeholder="settings.has_password ? 'Tersimpan (kosong = tetap)' : 'Belum diisi'"
                :class="inputClass"
              />
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Device ID
              <input v-model="conn.device_id" type="text" maxlength="100" placeholder="opsional jika hanya satu perangkat" :class="inputClass" />
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Timeout (detik)
              <input v-model.number="conn.timeout" type="number" min="2" max="30" :class="inputClass" />
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Batas pesan per penerima / jam
              <input v-model.number="conn.perRecipient" type="number" min="1" max="60" :class="inputClass" data-test="limit-recipient" />
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Batas pesan seluruh gateway / menit
              <input v-model.number="conn.perMinute" type="number" min="1" max="120" :class="inputClass" data-test="limit-global" />
            </label>
          </div>

          <div class="mt-4 rounded-xl border border-stone-100 p-3 dark:border-stone-800">
            <p class="text-sm font-medium text-stone-700 dark:text-stone-200">Nomor uji yang diizinkan</p>
            <p class="mb-2 text-xs text-stone-500">
              Pesan uji nyata hanya dapat dikirim ke nomor di daftar ini.
              <span v-if="settings.test_numbers_count">Saat ini {{ settings.test_numbers_count }} nomor: {{ settings.test_numbers.map((n) => n.masked).join(', ') }}.</span>
              <span v-else>Belum ada nomor uji.</span>
            </p>
            <label class="block text-sm text-stone-600 dark:text-stone-300">Ganti daftar (satu nomor per baris; kosong = tidak diubah)
              <textarea v-model="conn.testNumbersText" rows="2" :disabled="conn.clearTests" placeholder="0812xxxxxxxx" :class="inputClass" />
            </label>
            <label class="mt-2 flex items-center gap-2 text-xs text-stone-600 dark:text-stone-300">
              <input v-model="conn.clearTests" type="checkbox" class="h-4 w-4" /> Kosongkan seluruh daftar nomor uji
            </label>
          </div>

          <div class="mt-4 flex flex-wrap gap-2">
            <button type="submit" :disabled="saving" :class="btnPrimary">{{ saving ? 'Menyimpan...' : 'Simpan Koneksi' }}</button>
            <button type="button" :disabled="testing" :class="btnGhost" @click="testConnection">{{ testing ? 'Menguji...' : 'Test Connection' }}</button>
          </div>
        </form>

        <div v-if="status" :class="cardClass" aria-live="polite">
          <div class="flex flex-wrap items-center gap-3">
            <h2 class="text-sm font-semibold text-stone-800 dark:text-stone-100">Status koneksi</h2>
            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="STATE_LABEL[status.state]?.cls">{{ STATE_LABEL[status.state]?.text ?? status.state }}</span>
          </div>
          <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
            <div><dt class="text-stone-500">Server terjangkau</dt><dd>{{ status.reachable ? 'Ya' : 'Tidak' }}</dd></div>
            <div><dt class="text-stone-500">Terhubung / login</dt><dd>{{ status.connected ? 'Ya' : 'Tidak' }} / {{ status.logged_in ? 'Ya' : 'Tidak' }}</dd></div>
            <div><dt class="text-stone-500">Perangkat terdaftar</dt><dd>{{ status.devices }}{{ status.device_id ? ' · ' + status.device_id : '' }}</dd></div>
          </dl>
          <p v-if="status.detail" class="mt-2 text-xs text-stone-500">{{ status.detail }}</p>
        </div>

        <form :class="cardClass" @submit.prevent="sendTest">
          <h2 class="mb-1 text-sm font-semibold text-stone-800 dark:text-stone-100">Pesan uji</h2>
          <p class="mb-3 text-xs text-stone-500">Mengirim pesan WhatsApp nyata, hanya ke nomor uji yang diizinkan di atas.</p>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="text-sm text-stone-600 dark:text-stone-300">Nomor tujuan
              <input v-model="testNumber" type="tel" required maxlength="20" placeholder="0812xxxxxxxx" :class="inputClass" />
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Isi pesan (opsional)
              <input v-model="testText" type="text" maxlength="300" placeholder="Bawaan: pesan uji PrimeClassy" :class="inputClass" />
            </label>
          </div>
          <button type="submit" :disabled="sendingTest || !testNumber.trim() || !tableReady" :class="[btnPrimary, 'mt-3']">
            {{ sendingTest ? 'Mengirim...' : 'Kirim Pesan Uji' }}
          </button>
          <div v-if="testResult" class="mt-3 rounded-lg bg-stone-50 p-3 text-sm dark:bg-stone-950" aria-live="polite">
            <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="STATUS_CLASS[testResult.status]">{{ statusText(testResult.status) }}</span>
            <span class="ml-2 text-stone-500">ke {{ testResult.recipient }}</span>
            <p v-if="testResult.error_message" class="mt-1 text-xs text-red-700 dark:text-red-300">{{ testResult.error_message }}</p>
            <p v-if="testResult.status === 'unknown'" class="mt-1 text-xs text-amber-700 dark:text-amber-300">Hasil tidak pasti: periksa ponsel penerima sebelum mengirim ulang.</p>
          </div>
        </form>
      </section>

      <!-- ============================== OTP ============================== -->
      <section v-show="tab === 'otp'" role="tabpanel" aria-label="OTP" class="space-y-5">
        <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300" role="note">
          OTP email tetap tersedia dan menjadi cadangan yang aman. OTP WhatsApp untuk consent dan penautan hanya dikirim ke nomor <strong>akun konsumen yang sudah
          dibuktikan</strong> pemiliknya (kode verifikasi nomor). Nomor yang diketik Sales pada pelanggan CRM atau nomor profil yang belum diverifikasi
          <strong>tidak</strong> dianggap terverifikasi, dan mengubah nomor membatalkan kelayakan ini.
        </p>
        <form :class="cardClass" @submit.prevent="saveOtp">
          <label class="mb-4 flex items-center gap-2 text-sm text-stone-700 dark:text-stone-200">
            <input v-model="otp.enabled" type="checkbox" class="h-4 w-4 accent-brand-600" /> Aktifkan OTP WhatsApp (saluran pengiriman)
          </label>
          <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <label class="text-sm text-stone-600 dark:text-stone-300">Masa berlaku (menit)
              <input v-model.number="otp.ttl_minutes" type="number" min="1" max="30" :class="inputClass" />
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Maksimum percobaan
              <input v-model.number="otp.max_attempts" type="number" min="1" max="10" :class="inputClass" />
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Cooldown kirim ulang (detik)
              <input v-model.number="otp.cooldown_seconds" type="number" min="0" max="600" :class="inputClass" />
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Batas pengiriman per pelanggan
              <input v-model.number="otp.limit" type="number" min="1" max="10" :class="inputClass" />
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Dalam jendela (menit)
              <input v-model.number="otp.limit_window_minutes" type="number" min="1" max="60" :class="inputClass" />
            </label>
          </div>
          <button type="submit" :disabled="saving" :class="[btnPrimary, 'mt-4']">{{ saving ? 'Menyimpan...' : 'Simpan Pengaturan OTP' }}</button>
        </form>
      </section>

      <!-- ============================== NOTIFIKASI ============================== -->
      <section v-show="tab === 'notifikasi'" role="tabpanel" aria-label="Notifikasi" class="space-y-4">
        <p class="text-xs text-stone-500 dark:text-stone-400">
          Notifikasi transaksi dikirim setelah perubahan tersimpan, ke pembeli ber-akun pada nomor di akunnya. Pesanan dari pelanggan CRM tidak dikirimi
          karena nomornya belum terverifikasi. Semua event bawaan <strong>mati</strong> sampai Anda mengaktifkannya. Tidak ada pesan promosi di sini.
        </p>
        <article v-for="e in settings.events" :key="e.key" :class="cardClass">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ e.label }}</h3>
            <label class="flex items-center gap-2 text-sm text-stone-700 dark:text-stone-200">
              <input
                type="checkbox"
                class="h-4 w-4 accent-brand-600"
                :checked="e.enabled"
                :disabled="busyEvent === e.key"
                :aria-label="'Aktifkan ' + e.label"
                @change="toggleEvent(e.key, ($event.target as HTMLInputElement).checked)"
              />
              Aktif
            </label>
          </div>
          <label class="mt-3 block text-sm text-stone-600 dark:text-stone-300">Template
            <textarea v-model="templates[e.key]" rows="8" maxlength="1000" :class="inputClass" :aria-label="'Template ' + e.label" />
          </label>
          <p class="mt-2 text-xs text-stone-500">
            Variabel (klik untuk menyisipkan):
            <button
              v-for="v in e.variables"
              :key="v"
              type="button"
              class="ml-1 rounded bg-stone-100 px-1.5 py-0.5 font-mono text-[11px] text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200"
              @click="insertVariable(e.key, v)"
            >{{ '{' + v + '}' }}</button>
          </p>
          <p class="mt-1 text-[11px] text-stone-400">Baris yang berisi variabel opsional kosong (misalnya resi) dihapus otomatis; data tidak pernah dikarang.</p>
          <p v-if="eventErrors[e.key]" role="alert" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-300">{{ eventErrors[e.key] }}</p>
          <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" :class="btnGhost" @click="preview(e.key)">Preview</button>
            <button type="button" :disabled="busyEvent === e.key" :class="btnPrimary" @click="saveTemplate(e.key)">Simpan Template</button>
            <button type="button" :disabled="busyEvent === e.key || e.is_default_template" :class="btnGhost" @click="resetTemplate(e.key)">Kembalikan Bawaan</button>
          </div>
          <pre v-if="previews[e.key]" class="mt-3 whitespace-pre-wrap rounded-xl bg-stone-50 p-3 font-sans text-sm text-stone-700 dark:bg-stone-950 dark:text-stone-200" aria-label="Pratinjau">{{ previews[e.key] }}</pre>
        </article>
      </section>

      <!-- ============================== RIWAYAT ============================== -->
      <section v-show="tab === 'riwayat'" role="tabpanel" aria-label="Riwayat" class="space-y-4">
        <div :class="[cardClass, 'flex flex-wrap items-end gap-3']">
          <label class="text-sm text-stone-600 dark:text-stone-300">Status
            <select v-model="filters.status" :class="inputClass">
              <option value="">Semua</option>
              <option v-for="(label, key) in STATUS_TEXT" :key="key" :value="key">{{ label }}</option>
            </select>
          </label>
          <label class="text-sm text-stone-600 dark:text-stone-300">Jenis
            <select v-model="filters.purpose" :class="inputClass">
              <option value="">Semua</option>
              <option value="transaction">Transaksi</option>
              <option value="otp">OTP</option>
              <option value="test">Uji</option>
            </select>
          </label>
          <button type="button" :class="btnPrimary" @click="applyFilters">Terapkan</button>
        </div>

        <p v-if="historyError" role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
          {{ historyError }} <button type="button" class="ml-2 underline" @click="loadHistory">Coba lagi</button>
        </p>
        <p v-else-if="historyLoading" class="text-sm text-stone-500">Memuat...</p>
        <p v-else-if="history?.migration_pending" class="rounded-xl border border-dashed border-amber-300 p-6 text-center text-sm text-amber-800 dark:border-amber-900 dark:text-amber-300">
          Riwayat pengiriman belum tersedia: migrasi database menunggu persetujuan Owner.
        </p>
        <template v-else-if="history">
          <div class="overflow-x-auto rounded-xl border border-stone-200 dark:border-stone-800">
            <table class="w-full min-w-[44rem] text-left text-sm">
              <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
                <tr>
                  <th class="px-3 py-2">Waktu</th><th class="px-3 py-2">Event</th><th class="px-3 py-2">Penerima</th>
                  <th class="px-3 py-2">Status</th><th class="px-3 py-2">Percobaan</th><th class="px-3 py-2">Keterangan</th><th class="px-3 py-2" />
                </tr>
              </thead>
              <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
                <tr v-for="m in history.data" :key="m.id">
                  <td class="px-3 py-2 whitespace-nowrap">{{ formatDateTime(m.created_at) }}</td>
                  <td class="px-3 py-2">{{ m.event ?? '-' }}<span class="ml-1 text-xs text-stone-400">{{ m.purpose }}</span></td>
                  <td class="px-3 py-2 font-mono text-xs">{{ m.recipient ?? '-' }}</td>
                  <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="STATUS_CLASS[m.status]">{{ statusText(m.status) }}</span></td>
                  <td class="px-3 py-2">{{ m.attempts }}/{{ m.max_attempts }}</td>
                  <td class="px-3 py-2 text-xs text-stone-500">{{ m.skip_reason ?? m.error_message ?? '' }}</td>
                  <td class="px-3 py-2 text-right">
                    <button v-if="m.can_retry" type="button" class="text-xs font-medium text-brand-700 underline dark:text-brand-400" @click="askRetry(m)">Coba ulang</button>
                  </td>
                </tr>
                <tr v-if="!history.data.length"><td colspan="7" class="px-3 py-8 text-center text-stone-400">Belum ada pengiriman.</td></tr>
              </tbody>
            </table>
          </div>
          <div v-if="history.last_page > 1" class="flex items-center justify-center gap-2 text-sm">
            <button type="button" class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700" :disabled="page <= 1" @click="page--; loadHistory()">Sebelumnya</button>
            <span class="text-stone-500">{{ history.current_page }} / {{ history.last_page }}</span>
            <button type="button" class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700" :disabled="page >= history.last_page" @click="page++; loadHistory()">Berikutnya</button>
          </div>
        </template>
      </section>

      <!-- retry confirmation -->
      <div v-if="retrying" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4" @click.self="retrying = null">
        <div class="w-full max-w-md rounded-t-2xl bg-white p-5 sm:rounded-2xl dark:bg-stone-900" role="dialog" aria-modal="true" aria-label="Konfirmasi coba ulang">
          <h2 class="mb-2 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">Coba ulang pesan #{{ retrying.id }}</h2>
          <p v-if="retrying.needs_duplicate_risk_confirmation" class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-300">
            Hasil pengiriman sebelumnya <strong>tidak pasti</strong>: pesan mungkin sudah sampai. Mengirim ulang dapat menghasilkan pesan ganda.
          </p>
          <p v-else class="mb-3 text-sm text-stone-600 dark:text-stone-300">Pengiriman sebelumnya gagal secara pasti. Pesan akan dikirim ulang.</p>
          <label v-if="retrying.needs_duplicate_risk_confirmation" class="mb-3 flex items-start gap-2 text-sm text-stone-700 dark:text-stone-200">
            <input v-model="riskAccepted" type="checkbox" class="mt-0.5 h-4 w-4" /> Saya memahami risiko pesan ganda.
          </label>
          <p v-if="retryError" role="alert" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ retryError }}</p>
          <div class="flex justify-end gap-2">
            <button type="button" class="px-3 py-2 text-sm text-stone-500" @click="retrying = null">Batal</button>
            <button
              type="button"
              :disabled="retryBusy || (retrying.needs_duplicate_risk_confirmation && !riskAccepted)"
              :class="btnPrimary"
              @click="confirmRetry"
            >{{ retryBusy ? 'Memproses...' : 'Coba Ulang' }}</button>
          </div>
        </div>
      </div>
    </template>
  </DashboardLayout>
</template>
