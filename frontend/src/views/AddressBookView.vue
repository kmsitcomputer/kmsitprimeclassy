<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue'
import ShopLayout from '@/layouts/ShopLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { listAddresses, createAddress, updateAddress, deleteAddress, type AddressPayload } from '@/api/addresses'
import { listProvinces, listRegencies, listDistricts, listVillages } from '@/api/regions'
import { ApiError } from '@/api/client'
import type { KonsumenAddress, RegionOption } from '@/api/types'

const addresses = ref<KonsumenAddress[]>([])
const loading = ref(true)
const errorMessage = ref('')

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    addresses.value = await listAddresses()
  } catch {
    errorMessage.value = 'Gagal memuat alamat.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

/* ---------- Add/edit form — same cascading Provinsi/Kota/Kecamatan/Kelurahan pattern as checkout ---------- */
const showForm = ref(false)
const editingId = ref<number | null>(null)
const form = reactive({
  label: '',
  recipient_name: '',
  phone: '',
  address_line: '',
  village_id: null as string | null,
  latitude: null as number | null,
  longitude: null as number | null,
  is_default: false,
})
const submitting = ref(false)
const formError = ref('')

const provinces = ref<RegionOption[]>([])
const regencies = ref<RegionOption[]>([])
const districts = ref<RegionOption[]>([])
const villages = ref<RegionOption[]>([])
const selectedProvince = ref<string | null>(null)
const selectedRegency = ref<string | null>(null)
const selectedDistrict = ref<string | null>(null)

listProvinces().then((list) => (provinces.value = list))

watch(selectedProvince, async (id) => {
  regencies.value = []
  districts.value = []
  villages.value = []
  selectedRegency.value = null
  selectedDistrict.value = null
  form.village_id = null
  if (id) regencies.value = await listRegencies(id)
})
watch(selectedRegency, async (id) => {
  districts.value = []
  villages.value = []
  selectedDistrict.value = null
  form.village_id = null
  if (id) districts.value = await listDistricts(id)
})
watch(selectedDistrict, async (id) => {
  villages.value = []
  form.village_id = null
  if (id) villages.value = await listVillages(id)
})

const locating = ref(false)
function useMyLocation() {
  if (!navigator.geolocation) return
  locating.value = true
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      form.latitude = pos.coords.latitude
      form.longitude = pos.coords.longitude
      locating.value = false
    },
    () => {
      locating.value = false
    },
  )
}

const formComplete = computed(() => !!form.recipient_name && !!form.phone && !!form.address_line && !!form.village_id && form.latitude !== null && form.longitude !== null)

function resetForm() {
  form.label = ''
  form.recipient_name = ''
  form.phone = ''
  form.address_line = ''
  form.village_id = null
  form.latitude = null
  form.longitude = null
  form.is_default = false
  selectedProvince.value = null
  selectedRegency.value = null
  selectedDistrict.value = null
  formError.value = ''
}

function openCreate() {
  resetForm()
  editingId.value = null
  showForm.value = true
}

// The saved-address resource only carries display names for district/regency/province
// (no ids) — see KonsumenAddressResource — so editing pre-fills the text fields but the
// region cascade must be re-selected to change the village.
function openEdit(addr: KonsumenAddress) {
  resetForm()
  editingId.value = addr.id
  form.label = addr.label
  form.recipient_name = addr.recipient_name
  form.phone = addr.phone
  form.address_line = addr.address_line
  form.village_id = addr.village?.id ?? null
  form.latitude = Number(addr.latitude)
  form.longitude = Number(addr.longitude)
  form.is_default = addr.is_default
  showForm.value = true
}

function closeForm() {
  showForm.value = false
}

async function submitForm() {
  if (!formComplete.value) return
  submitting.value = true
  formError.value = ''
  try {
    const payload: AddressPayload = {
      label: form.label || undefined,
      recipient_name: form.recipient_name,
      phone: form.phone,
      address_line: form.address_line,
      village_id: form.village_id!,
      latitude: form.latitude!,
      longitude: form.longitude!,
      is_default: form.is_default,
    }
    if (editingId.value) {
      await updateAddress(editingId.value, payload)
    } else {
      await createAddress(payload)
    }
    closeForm()
    await load()
  } catch (e) {
    formError.value = e instanceof ApiError ? e.message : 'Gagal menyimpan alamat.'
  } finally {
    submitting.value = false
  }
}

async function remove(addr: KonsumenAddress) {
  if (!confirm(`Hapus alamat "${addr.label}"?`)) return
  await deleteAddress(addr.id)
  await load()
}

async function setDefault(addr: KonsumenAddress) {
  await updateAddress(addr.id, {
    recipient_name: addr.recipient_name,
    phone: addr.phone,
    address_line: addr.address_line,
    village_id: addr.village!.id,
    latitude: Number(addr.latitude),
    longitude: Number(addr.longitude),
    is_default: true,
  })
  await load()
}
</script>

<template>
  <ShopLayout>
    <div class="mx-auto max-w-2xl px-4 py-8">
      <div class="mb-5 flex items-center justify-between">
        <h1 class="font-display text-xl font-semibold text-stone-800 dark:text-stone-100">Alamat Saya</h1>
        <AppButton size="sm" @click="openCreate"><AppIcon name="plus" :size="16" /> Tambah Alamat</AppButton>
      </div>

      <p v-if="errorMessage" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ errorMessage }}</p>
      <p v-if="loading" class="text-sm text-stone-500">Memuat...</p>

      <div v-else class="space-y-3">
        <div v-for="addr in addresses" :key="addr.id" class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
          <div class="flex items-start justify-between gap-2">
            <div>
              <p class="flex items-center gap-2 text-sm font-semibold text-stone-800 dark:text-stone-100">
                {{ addr.label }}
                <span v-if="addr.is_default" class="rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-medium text-brand-700 dark:bg-brand-950 dark:text-brand-300">Utama</span>
              </p>
              <p class="mt-1 text-sm text-stone-600 dark:text-stone-300">{{ addr.recipient_name }} &middot; {{ addr.phone }}</p>
              <p class="text-xs text-stone-500 dark:text-stone-400">{{ addr.address_line }}</p>
              <p v-if="addr.village" class="text-xs text-stone-400">
                {{ addr.village.name }}, {{ addr.village.district }}, {{ addr.village.regency }}, {{ addr.village.province }}
              </p>
            </div>
          </div>
          <div class="mt-3 flex gap-2">
            <button v-if="!addr.is_default" type="button" class="text-xs font-medium text-brand-600 dark:text-brand-400" @click="setDefault(addr)">Jadikan Utama</button>
            <button type="button" class="text-xs font-medium text-stone-500 dark:text-stone-400" @click="openEdit(addr)">Edit</button>
            <button type="button" class="text-xs font-medium text-red-600 dark:text-red-400" @click="remove(addr)">Hapus</button>
          </div>
        </div>
        <p v-if="!addresses.length" class="rounded-2xl bg-stone-100 p-8 text-center text-sm text-stone-500 dark:bg-stone-900">
          Belum ada alamat tersimpan.
        </p>
      </div>
    </div>

    <div v-if="showForm" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 pt-10" @click.self="closeForm">
      <form class="w-full max-w-md rounded-2xl bg-white p-5 dark:bg-stone-900" @submit.prevent="submitForm">
        <h2 class="mb-4 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">
          {{ editingId ? 'Edit Alamat' : 'Tambah Alamat' }}
        </h2>
        <p v-if="formError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ formError }}</p>

        <div class="space-y-3">
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Label (mis. Rumah, Kantor)
            <input v-model="form.label" type="text" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Nama Penerima
            <input v-model="form.recipient_name" type="text" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            No. Telepon
            <input v-model="form.phone" type="tel" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>

          <p class="text-xs text-stone-400">Pilih wilayah (Provinsi &rarr; Kota &rarr; Kecamatan &rarr; Kelurahan):</p>
          <div class="grid grid-cols-2 gap-2">
            <select v-model="selectedProvince" class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950">
              <option :value="null" disabled>Provinsi</option>
              <option v-for="p in provinces" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <select v-model="selectedRegency" :disabled="!selectedProvince" class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm disabled:opacity-50 dark:border-stone-700 dark:bg-stone-950">
              <option :value="null" disabled>Kota/Kabupaten</option>
              <option v-for="r in regencies" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
            <select v-model="selectedDistrict" :disabled="!selectedRegency" class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm disabled:opacity-50 dark:border-stone-700 dark:bg-stone-950">
              <option :value="null" disabled>Kecamatan</option>
              <option v-for="d in districts" :key="d.id" :value="d.id">{{ d.name }}</option>
            </select>
            <select v-model="form.village_id" :disabled="!selectedDistrict" class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm disabled:opacity-50 dark:border-stone-700 dark:bg-stone-950">
              <option :value="null" disabled>Kelurahan/Desa</option>
              <option v-for="v in villages" :key="v.id" :value="v.id">{{ v.name }}</option>
            </select>
          </div>

          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Alamat Lengkap
            <textarea v-model="form.address_line" rows="2" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>

          <button type="button" class="flex items-center gap-2 rounded-lg border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 dark:border-stone-700 dark:text-stone-200" :disabled="locating" @click="useMyLocation">
            <AppIcon name="map-pin" :size="16" /> {{ locating ? 'Mendeteksi...' : 'Gunakan Lokasi Saya' }}
          </button>
          <p v-if="form.latitude !== null" class="text-xs text-emerald-600">Koordinat terdeteksi ✓</p>

          <label class="flex items-center gap-2 text-sm text-stone-600 dark:text-stone-300">
            <input v-model="form.is_default" type="checkbox" /> Jadikan alamat utama
          </label>
        </div>

        <div class="mt-5 flex justify-end gap-2">
          <button type="button" class="rounded-lg px-3 py-2 text-sm text-stone-500 dark:text-stone-400" @click="closeForm">Batal</button>
          <AppButton type="submit" :disabled="!formComplete || submitting">{{ submitting ? 'Menyimpan...' : 'Simpan' }}</AppButton>
        </div>
      </form>
    </div>
  </ShopLayout>
</template>
