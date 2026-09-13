<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import ImageUploader from '@/components/ui/ImageUploader.vue'
import { getAdminSettings, updateSettings, type WebsiteSettings } from '@/api/settings'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'

const loading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const form = ref<WebsiteSettings | null>(null)

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    form.value = await getAdminSettings()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat pengaturan website.'
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!form.value) return
  saving.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    // An empty contact-email field must be sent as null, not "" — the
    // backend's `email` format rule rejects an empty string even though the
    // field is nullable (only an actual null bypasses format validation).
    const payload = { ...form.value, site_contact_email: form.value.site_contact_email || null }
    form.value = await updateSettings(payload)
    successMessage.value = 'Pengaturan berhasil disimpan.'
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menyimpan pengaturan.'
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Pengaturan Website</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">Identitas toko, kontak, dan SEO yang tampil di halaman publik.</p>

    <p v-if="errorMessage" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ errorMessage }}
    </p>
    <p v-if="successMessage" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
      {{ successMessage }}
    </p>
    <p v-if="loading" class="text-sm text-stone-500 dark:text-stone-400">Memuat...</p>

    <form v-else-if="form" class="max-w-2xl space-y-5" @submit.prevent="save">
      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="mb-3 text-sm font-semibold text-stone-700 dark:text-stone-200">Logo & Favicon</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <ImageUploader
            v-model:media-id="form.site_logo_media_id"
            v-model:url="form.logo_url"
            collection="site_logo"
            label="Logo"
          />
          <ImageUploader
            v-model:media-id="form.site_favicon_media_id"
            v-model:url="form.favicon_url"
            collection="site_favicon"
            label="Favicon"
            accept="image/png"
            :max-size-mb="0.5"
          />
        </div>
      </div>

      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="mb-3 text-sm font-semibold text-stone-700 dark:text-stone-200">Identitas Toko</h2>
        <div class="space-y-3">
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Nama Situs
            <input v-model="form.site_title" type="text" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Slogan
            <input v-model="form.site_slogan" type="text" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Tentang Kami
            <textarea v-model="form.site_about" rows="4" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
        </div>
      </div>

      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="mb-3 text-sm font-semibold text-stone-700 dark:text-stone-200">Kontak</h2>
        <div class="space-y-3">
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Email
            <input v-model="form.site_contact_email" type="email" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Telepon
            <input v-model="form.site_contact_phone" type="text" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Alamat
            <input v-model="form.site_address" type="text" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
        </div>
      </div>

      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="mb-3 text-sm font-semibold text-stone-700 dark:text-stone-200">SEO</h2>
        <div class="space-y-3">
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Judul SEO
            <input v-model="form.site_seo_title" type="text" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Deskripsi SEO
            <textarea v-model="form.site_seo_description" rows="3" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Kata Kunci SEO
            <input v-model="form.site_seo_keywords" type="text" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
        </div>
      </div>

      <div class="flex justify-end">
        <button type="submit" class="rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50" :disabled="saving">
          Simpan Perubahan
        </button>
      </div>
    </form>
  </DashboardLayout>
</template>
