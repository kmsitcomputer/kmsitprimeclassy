<script setup lang="ts">
import { reactive, ref, onMounted } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { getOwnStoreProfile, updateOwnStoreProfile } from '@/api/agents'
import { ApiError } from '@/api/client'

/**
 * Agen-only self-service "Kontak Agen" — the same store_name/address/
 * phone/lat-lng data super_admin manages for every agent via the Kontak
 * Agen admin page, but scoped to the acting agen's own record only (see
 * AgentStoreProfileController). Feeds the public store-locator page and
 * OpenRoute's own distance calculation.
 */
const form = reactive({
  store_name: '',
  address: '',
  phone: '',
  latitude: null as number | null,
  longitude: null as number | null,
})
const loading = ref(true)
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const success = ref(false)

async function load() {
  loading.value = true
  const profile = await getOwnStoreProfile()
  if (profile) {
    form.store_name = profile.store_name
    form.address = profile.address
    form.phone = profile.phone ?? ''
    form.latitude = profile.latitude
    form.longitude = profile.longitude
  }
  loading.value = false
}
onMounted(load)

function useMyLocation() {
  if (!navigator.geolocation) return
  navigator.geolocation.getCurrentPosition((pos) => {
    form.latitude = pos.coords.latitude
    form.longitude = pos.coords.longitude
  })
}

async function save() {
  saving.value = true
  errors.value = {}
  success.value = false
  try {
    await updateOwnStoreProfile({
      store_name: form.store_name,
      address: form.address,
      phone: form.phone || null,
      latitude: form.latitude as number,
      longitude: form.longitude as number,
    })
    success.value = true
  } catch (e) {
    if (e instanceof ApiError && e.errors) errors.value = e.errors
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">Profil Toko Saya</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
      Informasi ini tampil di halaman publik "Cari Agen/Toko" dan dipakai untuk perhitungan ongkir Kurir Online (OpenRoute).
    </p>

    <div v-if="loading" class="h-64 animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />

    <form v-else class="max-w-lg space-y-3 rounded-2xl border border-stone-200 bg-white p-5 dark:border-stone-800 dark:bg-stone-900" @submit.prevent="save">
      <div>
        <label class="block text-sm font-medium text-stone-700 dark:text-stone-300">Nama Toko</label>
        <input v-model="form.store_name" type="text" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
        <p v-if="errors.store_name" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ errors.store_name[0] }}</p>
      </div>
      <div>
        <label class="block text-sm font-medium text-stone-700 dark:text-stone-300">Alamat</label>
        <textarea v-model="form.address" rows="3" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
        <p v-if="errors.address" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ errors.address[0] }}</p>
      </div>
      <div>
        <label class="block text-sm font-medium text-stone-700 dark:text-stone-300">Telepon</label>
        <input v-model="form.phone" type="text" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-stone-700 dark:text-stone-300">Latitude</label>
          <input v-model.number="form.latitude" type="number" step="any" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
        </div>
        <div>
          <label class="block text-sm font-medium text-stone-700 dark:text-stone-300">Longitude</label>
          <input v-model.number="form.longitude" type="number" step="any" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
        </div>
      </div>
      <button type="button" class="text-xs font-medium text-brand-600 dark:text-brand-400" @click="useMyLocation">
        Gunakan lokasi saya sekarang
      </button>

      <div class="flex items-center gap-3 pt-2">
        <AppButton type="submit" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Simpan' }}</AppButton>
        <span v-if="success" class="text-sm text-emerald-600 dark:text-emerald-400">Tersimpan.</span>
      </div>
    </form>
  </DashboardLayout>
</template>
