<script setup lang="ts">
/**
 * Pengaturan › Integrasi › SMTP (Super Admin). Points PrimeClassy's outgoing e-mail (OTP, notifications) at the EXISTING mail server.
 * The password is write-only (never returned, wiped from the form after saving); the test e-mail goes only to the signed-in
 * Super Admin's own address. Every rule is enforced by the backend — this page only drives it.
 */
import { ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { getSmtpSettings, sendSmtpTestEmail, testSmtpConnection, updateSmtpSettings, type SmtpEncryption, type SmtpSettings } from '@/api/smtp'

const settings = ref<SmtpSettings | null>(null)
const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const testing = ref<'' | 'connection' | 'email'>('')
const notice = ref<{ kind: 'ok' | 'err'; text: string } | null>(null)
const testResult = ref<{ ok: boolean; text: string } | null>(null)

const form = ref({ enabled: false, transactional_enabled: false, host: '', port: 587, encryption: 'tls' as SmtpEncryption, username: '', password: '', from_address: '', from_name: '', timeout: 10 })

function hydrate(s: SmtpSettings) {
  settings.value = s
  form.value = {
    enabled: s.enabled, transactional_enabled: s.transactional_enabled ?? false, host: s.host ?? '', port: s.port ?? 587, encryption: s.encryption, username: s.username ?? '',
    password: '', from_address: s.from_address ?? '', from_name: s.from_name ?? '', timeout: s.timeout,
  }
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    hydrate(await getSmtpSettings())
  } catch (e) {
    loadError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat pengaturan SMTP.'
  } finally {
    loading.value = false
  }
}
load()

const fail = (e: unknown, fallback: string) => (notice.value = { kind: 'err', text: e instanceof ApiError ? formatApiError(e) : fallback })

async function save() {
  saving.value = true
  notice.value = null
  try {
    const f = form.value
    hydrate(
      await updateSmtpSettings({
        enabled: f.enabled, transactional_enabled: f.transactional_enabled, host: f.host, port: Number(f.port), encryption: f.encryption, username: f.username || null,
        from_address: f.from_address, from_name: f.from_name || null, timeout: Number(f.timeout),
        ...(f.password ? { password: f.password } : {}),
      }),
    )
    notice.value = { kind: 'ok', text: 'Pengaturan SMTP disimpan.' }
  } catch (e) {
    fail(e, 'Gagal menyimpan pengaturan.')
  } finally {
    saving.value = false
  }
}

async function runTest(kind: 'connection' | 'email') {
  testing.value = kind
  testResult.value = null
  try {
    const r = kind === 'connection' ? await testSmtpConnection() : await sendSmtpTestEmail()
    testResult.value = {
      ok: r.ok,
      text: r.ok ? (kind === 'connection' ? 'Koneksi dan login SMTP berhasil.' : 'Email uji dikirim ke alamat Anda sendiri.') : `Gagal: ${r.detail ?? 'tidak diketahui'}`,
    }
  } catch (e) {
    fail(e, 'Tes gagal dijalankan.')
  } finally {
    testing.value = ''
  }
}

const inputClass = 'mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950'
const cardClass = 'rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900'
const btnPrimary = 'rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50'
const btnGhost =
  'rounded-lg border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-50 dark:border-stone-700 dark:text-stone-200 dark:hover:bg-stone-800'
</script>

<template>
  <DashboardLayout>
    <p class="text-xs text-stone-500 dark:text-stone-400">Pengaturan › Integrasi › SMTP</p>
    <h1 class="font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">SMTP</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
      Server email yang dipakai untuk OTP dan notifikasi. Ini mengarahkan PrimeClassy ke server email yang sudah ada; tidak membuat atau mengubah server email. Hanya Super Admin.
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

    <form v-else-if="settings" :class="cardClass" @submit.prevent="save">
      <p class="mb-4 text-xs text-stone-500" data-test="active-source">
        Konfigurasi aktif saat ini:
        <strong>{{ settings.active_source === 'dashboard' ? 'dari dashboard ini' : 'dari konfigurasi server (.env)' }}</strong>
      </p>

      <label class="mb-4 flex items-center gap-2 text-sm text-stone-700 dark:text-stone-200">
        <input v-model="form.enabled" type="checkbox" class="h-4 w-4 accent-brand-600" /> Gunakan pengaturan SMTP dari dashboard ini
      </label>
      <label class="mb-4 flex items-start gap-2 text-sm text-stone-700 dark:text-stone-200">
        <input v-model="form.transactional_enabled" type="checkbox" class="mt-0.5 h-4 w-4 accent-brand-600" data-test="transactional-enabled" />
        <span>
          Kirim email transaksi (pesanan, pembayaran, pengiriman, retur, refund)
          <span class="block text-xs text-stone-500">Hanya ke alamat email terverifikasi milik admin cabang dan pembuat pesanan. Nonaktif secara bawaan.</span>
        </span>
      </label>

      <div class="grid gap-4 sm:grid-cols-2">
        <label class="text-sm text-stone-600 dark:text-stone-300">Host
          <input v-model="form.host" type="text" maxlength="190" placeholder="mail.contoh.com" autocomplete="off" :class="inputClass" />
        </label>
        <label class="text-sm text-stone-600 dark:text-stone-300">Port
          <select v-model.number="form.port" :class="inputClass">
            <option v-for="p in [25, 465, 587, 2525, 1025, 2465]" :key="p" :value="p">{{ p }}</option>
          </select>
        </label>
        <label class="text-sm text-stone-600 dark:text-stone-300">Enkripsi
          <select v-model="form.encryption" :class="inputClass">
            <option value="tls">STARTTLS (wajib, umumnya port 587)</option>
            <option value="ssl">SSL/TLS langsung (umumnya port 465)</option>
            <option value="none">Tanpa enkripsi (hanya relay lokal)</option>
          </select>
        </label>
        <label class="text-sm text-stone-600 dark:text-stone-300">Timeout (detik)
          <input v-model.number="form.timeout" type="number" min="2" max="60" :class="inputClass" />
        </label>
        <label class="text-sm text-stone-600 dark:text-stone-300">Username
          <input v-model="form.username" type="text" maxlength="190" autocomplete="off" :class="inputClass" />
        </label>
        <label class="text-sm text-stone-600 dark:text-stone-300">Password
          <input
            v-model="form.password"
            type="password"
            maxlength="300"
            autocomplete="new-password"
            :placeholder="settings.has_password ? 'Tersimpan — kosongkan untuk mempertahankan' : 'Belum diisi'"
            :class="inputClass"
          />
        </label>
        <label class="text-sm text-stone-600 dark:text-stone-300">Email pengirim
          <input v-model="form.from_address" type="email" maxlength="190" placeholder="noreply@contoh.com" :class="inputClass" />
        </label>
        <label class="text-sm text-stone-600 dark:text-stone-300">Nama pengirim
          <input v-model="form.from_name" type="text" maxlength="100" :class="inputClass" />
        </label>
      </div>

      <p v-if="form.encryption === 'none'" role="note" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">
        Tanpa enkripsi, kredensial dan isi email dikirim sebagai teks biasa. Pakai hanya untuk relay di jaringan tepercaya.
      </p>

      <div class="mt-5 flex flex-wrap gap-2">
        <button type="submit" :disabled="saving" :class="btnPrimary">{{ saving ? 'Menyimpan...' : 'Simpan' }}</button>
        <button type="button" :disabled="!!testing || !settings.complete" :class="btnGhost" @click="runTest('connection')">{{ testing === 'connection' ? 'Menguji...' : 'Tes koneksi' }}</button>
        <button type="button" :disabled="!!testing || !settings.complete" :class="btnGhost" @click="runTest('email')">{{ testing === 'email' ? 'Mengirim...' : 'Kirim email uji ke saya' }}</button>
      </div>
      <p v-if="!settings.complete" class="mt-2 text-xs text-stone-500">Simpan host, port, dan email pengirim terlebih dahulu untuk mengaktifkan tes.</p>
      <p v-if="testResult" role="status" class="mt-3 text-sm" :class="testResult.ok ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'" data-test="test-result">{{ testResult.text }}</p>
    </form>
  </DashboardLayout>
</template>
