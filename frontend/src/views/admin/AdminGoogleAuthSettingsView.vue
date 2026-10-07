<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import PasswordInput from '@/components/ui/PasswordInput.vue'
import {
  getGlobalGoogleAuthConfig,
  saveGlobalGoogleAuthConfig,
  type GoogleAuthConfigView,
} from '@/api/googleAuthSettings'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'

/**
 * IMP-001 — Super Admin's GLOBAL/default Google Auth configuration.
 *
 * The single tenant for "enable/disable Google Authentication, set the
 * Google Client ID / Client Secret, and see the callback/redirect URL". The
 * saved row is the dashboard truth; .env remains only a bootstrap/fallback
 * (see GoogleAuthConfigService::resolvedForAgent — an Agen's own row wins
 * over this global row when present).
 *
 * The Client Secret is NEVER returned by the API (`has_secret` only). When a
 * secret is already stored the field renders empty and the UI shows "Sudah
 * dikonfigurasi"; saving with an empty secret PRESERVES the stored one;
 * "Hapus secret" sends clear_secret=true. Authorization is enforced
 * server-side (GoogleAuthSettingController::update -> manage-system-config);
 * this page is only reachable by super_admin, and the backend still refuses
 * anyone else.
 */
const loading = ref(false)
const saving = ref(false)
const clearing = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

/** The API origin (same-origin '' in the single-domain production layout). */
const apiBase = window.__APP_CONFIG__?.API_URL ?? import.meta.env.VITE_API_URL ?? ''

const form = ref<{
  is_enabled: boolean
  client_id: string
  redirect_uri: string
  frontend_url: string
  client_secret: string
  has_secret: boolean
}>({
  is_enabled: false,
  client_id: '',
  redirect_uri: '',
  frontend_url: '',
  client_secret: '',
  has_secret: false,
})

async function load() {
  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const view = await getGlobalGoogleAuthConfig()
    form.value = {
      is_enabled: view.is_enabled,
      client_id: view.client_id ?? '',
      redirect_uri: view.redirect_uri ?? '',
      frontend_url: view.frontend_url ?? '',
      client_secret: '',
      has_secret: view.has_secret,
    }
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat pengaturan Google Auth.'
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const view = await saveGlobalGoogleAuthConfig({
      is_enabled: form.value.is_enabled,
      client_id: form.value.client_id || null,
      // A blank secret is deliberately included as an EMPTY string — the
      // backend treats it as "preserve the stored one" (never clears).
      client_secret: form.value.client_secret,
      redirect_uri: form.value.redirect_uri || null,
      frontend_url: form.value.frontend_url || null,
    })
    form.value.client_secret = ''
    form.value.has_secret = view.has_secret
    successMessage.value = 'Pengaturan Google Auth berhasil disimpan.'
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menyimpan pengaturan Google Auth.'
  } finally {
    saving.value = false
  }
}

async function clearSecret() {
  clearing.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const view = await saveGlobalGoogleAuthConfig({ clear_secret: true })
    form.value.client_secret = ''
    form.value.has_secret = view.has_secret
    successMessage.value = 'Secret Google Auth dihapus.'
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menghapus secret Google Auth.'
  } finally {
    clearing.value = false
  }
}

onMounted(load)
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Google Auth — Pengaturan Global</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
      Konfigurasi global login Google untuk konsumen. Setiap Agen dapat mengesampingkannya dengan konfigurasi milik
      cabangnya sendiri; nilai di sini menjadi default.
    </p>

    <p v-if="errorMessage" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ errorMessage }}
    </p>
    <p v-if="successMessage" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
      {{ successMessage }}
    </p>
    <p v-if="loading" class="text-sm text-stone-500 dark:text-stone-400">Memuat...</p>

    <form v-else class="max-w-2xl space-y-5" @submit.prevent="save">
      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="mb-3 text-sm font-semibold text-stone-700 dark:text-stone-200">Status</h2>
        <label class="flex cursor-pointer items-center gap-3 text-sm text-stone-700 dark:text-stone-200">
          <input v-model="form.is_enabled" type="checkbox" class="h-4 w-4 rounded border-stone-300 text-brand-600 focus:ring-brand-500" />
          <span>
            <strong>Aktifkan Google Authentication</strong>
            <span class="block text-xs font-normal text-stone-500 dark:text-stone-400">
              Menampilkan tombol "Masuk dengan Google" / "Daftar dengan Google" di halaman login & registrasi konsumen.
            </span>
          </span>
        </label>
      </div>

      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="mb-3 text-sm font-semibold text-stone-700 dark:text-stone-200">Kredensial OAuth</h2>
        <div class="space-y-3">
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Google Client ID
            <input v-model="form.client_id" type="text" autocomplete="off" placeholder="xxxxxxxx.apps.googleusercontent.com"
              class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Google Client Secret
            <PasswordInput v-model="form.client_secret" autocomplete="off" :required="!form.has_secret" />
            <span class="mt-1 block text-xs text-stone-500 dark:text-stone-400">
              <span v-if="form.has_secret" class="font-medium text-emerald-600 dark:text-emerald-400">Sudah dikonfigurasi ✓</span>
              <span v-else>Belum dikonfigurasi.</span>
              Secret tidak pernah ditampilkan kembali. Kosongkan kolom ini saat menyimpan untuk mempertahankan secret yang
              sudah tersimpan.
            </span>
          </label>
          <div v-if="form.has_secret" class="flex items-center gap-2">
            <AppButton size="sm" variant="danger" type="button" :disabled="clearing" @click="clearSecret">
              {{ clearing ? 'Menghapus...' : 'Hapus secret tersimpan' }}
            </AppButton>
            <span class="text-xs text-stone-400">Menghapus membuat login Google tidak berfungsi sampai secret baru diisi.</span>
          </div>
        </div>
      </div>

      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="mb-3 text-sm font-semibold text-stone-700 dark:text-stone-200">Callback / Redirect</h2>
        <div class="space-y-3">
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Redirect URI (callback)
            <input v-model="form.redirect_uri" type="text" autocomplete="off" placeholder="https://app.example/api/v1/auth/google/callback"
              class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            <span class="mt-1 block text-xs text-stone-500 dark:text-stone-400">
              URL yang didaftarkan di konsol Google sebagai "Authorized redirect URI". Kosongkan untuk memakai default
              ({{ apiBase }}<span v-if="apiBase">/</span>api/v1/auth/google/callback).
            </span>
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Frontend URL (tujuan setelah callback)
            <input v-model="form.frontend_url" type="text" autocomplete="off" placeholder="https://app.example"
              class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            <span class="mt-1 block text-xs text-stone-500 dark:text-stone-400">
              Origin SPA yang menerima browser setelah callback Google. Kosongkan = origin yang sama.
            </span>
          </label>
        </div>
      </div>

      <div class="flex gap-2">
        <AppButton type="submit" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Simpan pengaturan' }}</AppButton>
      </div>
    </form>
  </DashboardLayout>
</template>