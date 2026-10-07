<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import PasswordInput from '@/components/ui/PasswordInput.vue'
import {
  getAgentGoogleAuthConfig,
  saveAgentGoogleAuthConfig,
  type GoogleAuthConfigView,
} from '@/api/googleAuthSettings'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'

/**
 * IMP-001 — an Agen's OWN branch Google Auth configuration.
 *
 * The Agen (or their branch Admin — same backend scope) manages only this
 * branch's Google Auth credentials/switch. The agent_id used by the backend
 * is ALWAYS the authenticated user's own branch (never input), so there is
 * no cross-Agent access. The effective config for this branch resolves
 * Agen row -> global row -> .env (GoogleAuthConfigService); this page shows
 * only this branch's own row.
 *
 * Client Secret handling identical to the Super Admin page: never echoed
 * (`has_secret` only), blank save preserves, clear_secret removes.
 */
const loading = ref(false)
const saving = ref(false)
const clearing = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

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
    const view = await getAgentGoogleAuthConfig()
    form.value = {
      is_enabled: view.is_enabled,
      client_id: view.client_id ?? '',
      redirect_uri: view.redirect_uri ?? '',
      frontend_url: view.frontend_url ?? '',
      client_secret: '',
      has_secret: view.has_secret,
    }
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat konfigurasi Google Auth cabang.'
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const view = await saveAgentGoogleAuthConfig({
      is_enabled: form.value.is_enabled,
      client_id: form.value.client_id || null,
      // Blank secret = preserve the stored one (backend semantics).
      client_secret: form.value.client_secret,
      redirect_uri: form.value.redirect_uri || null,
      frontend_url: form.value.frontend_url || null,
    })
    form.value.client_secret = ''
    form.value.has_secret = view.has_secret
    successMessage.value = 'Konfigurasi Google Auth cabang disimpan.'
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menyimpan konfigurasi Google Auth cabang.'
  } finally {
    saving.value = false
  }
}

async function clearSecret() {
  clearing.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const view = await saveAgentGoogleAuthConfig({ clear_secret: true })
    form.value.client_secret = ''
    form.value.has_secret = view.has_secret
    successMessage.value = 'Secret Google Auth cabang dihapus.'
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menghapus secret Google Auth cabang.'
  } finally {
    clearing.value = false
  }
}

onMounted(load)
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Google Auth — Cabang Saya</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
      Konfigurasi login Google khusus cabang Anda. Jika belum diisi, sistem memakai konfigurasi global Super Admin
      (atau nilai .env sebagai fallback).
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
            <strong>Aktifkan Google Authentication untuk cabang ini</strong>
            <span class="block text-xs font-normal text-stone-500 dark:text-stone-400">
              Memakai kredensial cabang untuk login Google konsumen cabang Anda.
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
            <span class="text-xs text-stone-400">Menghapus membuat login Google cabang tidak berfungsi sampai secret baru diisi.</span>
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
              URL callback yang didaftarkan di konsol Google. Kosongkan = memakai nilai global/default.
            </span>
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Frontend URL (tujuan setelah callback)
            <input v-model="form.frontend_url" type="text" autocomplete="off" placeholder="https://app.example"
              class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            <span class="mt-1 block text-xs text-stone-500 dark:text-stone-400">
              Origin SPA tujuan setelah callback Google. Kosongkan = origin yang sama.
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