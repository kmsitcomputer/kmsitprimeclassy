<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import {
  listAdminLanguages,
  createLanguage,
  updateLanguage,
  toggleLanguageActive,
  setDefaultLanguage,
  type Language,
} from '@/api/languages'
import { ApiError } from '@/api/client'

const languages = ref<Language[]>([])
const loading = ref(true)
const showForm = ref(false)
const editingId = ref<number | null>(null)
const submitting = ref(false)
const generalError = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})

const form = reactive({ code: '', name: '', sort_order: 0 })

async function load() {
  loading.value = true
  languages.value = await listAdminLanguages()
  loading.value = false
}

onMounted(load)

function openCreateForm() {
  form.code = ''
  form.name = ''
  form.sort_order = languages.value.length
  editingId.value = null
  errors.value = {}
  generalError.value = null
  showForm.value = true
}

function openEditForm(lang: Language) {
  form.code = lang.code
  form.name = lang.name
  form.sort_order = lang.sort_order ?? 0
  editingId.value = lang.id
  errors.value = {}
  generalError.value = null
  showForm.value = true
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null
  try {
    if (editingId.value) {
      await updateLanguage(editingId.value, { name: form.name, sort_order: form.sort_order })
    } else {
      await createLanguage({ code: form.code, name: form.name, sort_order: form.sort_order })
    }
    await load()
    showForm.value = false
  } catch (e) {
    if (e instanceof ApiError) {
      generalError.value = e.message
      errors.value = e.errors ?? {}
    } else {
      generalError.value = 'Terjadi kesalahan.'
    }
  } finally {
    submitting.value = false
  }
}

async function toggleActive(lang: Language) {
  try {
    await toggleLanguageActive(lang.id)
    await load()
  } catch (e) {
    alert(e instanceof ApiError ? e.message : 'Terjadi kesalahan.')
  }
}

async function makeDefault(lang: Language) {
  try {
    await setDefaultLanguage(lang.id)
    await load()
  } catch (e) {
    alert(e instanceof ApiError ? e.message : 'Terjadi kesalahan.')
  }
}
</script>

<template>
  <DashboardLayout>
    <div class="mb-6 flex items-center justify-between">
      <h1 class="text-xl font-semibold text-stone-900 dark:text-stone-50">Bahasa</h1>
      <AppButton @click="openCreateForm"><AppIcon name="plus" :size="18" /> Tambah</AppButton>
    </div>

    <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>

    <div v-else class="overflow-hidden rounded-xl border border-stone-200 dark:border-stone-800">
      <table class="w-full text-left text-sm">
        <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
          <tr>
            <th class="px-4 py-2">Kode</th>
            <th class="px-4 py-2">Nama</th>
            <th class="px-4 py-2">Urutan</th>
            <th class="px-4 py-2">Status</th>
            <th class="px-4 py-2" />
          </tr>
        </thead>
        <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
          <tr v-for="lang in languages" :key="lang.id">
            <td class="px-4 py-2 font-mono text-xs">{{ lang.code }}</td>
            <td class="px-4 py-2">
              {{ lang.name }}
              <span v-if="lang.is_default" class="ml-1 rounded bg-brand-50 px-1.5 py-0.5 text-[11px] text-brand-700 dark:bg-brand-950 dark:text-brand-300">Default</span>
            </td>
            <td class="px-4 py-2">{{ lang.sort_order }}</td>
            <td class="px-4 py-2">
              <span :class="lang.is_active ? 'text-emerald-600 dark:text-emerald-400' : 'text-stone-400'">
                {{ lang.is_active ? 'Aktif' : 'Nonaktif' }}
              </span>
            </td>
            <td class="px-4 py-2 text-right">
              <button class="mr-3 text-brand-600 hover:underline dark:text-brand-400" @click="openEditForm(lang)">Edit</button>
              <button v-if="!lang.is_default" class="mr-3 text-stone-500 hover:underline dark:text-stone-400" @click="makeDefault(lang)">Jadikan Default</button>
              <button
                v-if="!(lang.is_default && lang.is_active)"
                class="text-red-600 hover:underline dark:text-red-400"
                @click="toggleActive(lang)"
              >
                {{ lang.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Teleport to="body">
      <div v-if="showForm" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 pt-10">
        <form class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-stone-900" @submit.prevent="submit">
          <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-stone-900 dark:text-stone-50">{{ editingId ? 'Edit' : 'Tambah' }} Bahasa</h2>
            <button type="button" @click="showForm = false"><AppIcon name="close" :size="20" /></button>
          </div>

          <p v-if="generalError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">{{ generalError }}</p>

          <div class="space-y-3">
            <div>
              <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Kode (mis. id, en)</label>
              <input v-model="form.code" type="text" :disabled="!!editingId" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm disabled:opacity-60 dark:border-stone-700 dark:bg-stone-950" />
              <p v-if="errors.code" class="mt-1 text-xs text-red-600">{{ errors.code[0] }}</p>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Nama</label>
              <input v-model="form.name" type="text" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
              <p v-if="errors.name" class="mt-1 text-xs text-red-600">{{ errors.name[0] }}</p>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Urutan</label>
              <input v-model.number="form.sort_order" type="number" min="0" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </div>
          </div>

          <AppButton type="submit" size="lg" block class="mt-5" :disabled="submitting">{{ submitting ? 'Menyimpan...' : 'Simpan' }}</AppButton>
        </form>
      </div>
    </Teleport>
  </DashboardLayout>
</template>
