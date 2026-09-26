<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { createSubLocation, deactivateSubLocation, listSubLocations, type SubLocation } from '@/api/warehouse'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const isGudang = computed(() => auth.user?.role === 'gudang')

const locations = ref<SubLocation[]>([])
const code = ref('')
const name = ref('')
const address = ref('')
const contact = ref('')
const error = ref('')
const success = ref('')

async function load() {
  locations.value = await listSubLocations()
}

async function create() {
  error.value = ''; success.value = ''
  try {
    if (!code.value.trim() || !name.value.trim()) throw new Error('Isi kode dan nama lokasi.')
    await createSubLocation({ code: code.value.trim(), name: name.value.trim(), address: address.value.trim() || undefined, contact_number: contact.value.trim() || undefined })
    code.value = ''; name.value = ''; address.value = ''; contact.value = ''
    success.value = 'Sub Location berhasil dibuat.'
    await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal menyimpan lokasi Sub.' }
}

async function deactivate(id: number) {
  error.value = ''; success.value = ''
  try {
    await deactivateSubLocation(id)
    await load()
  } catch { error.value = 'Lokasi berisi stok atau tidak dapat dinonaktifkan.' }
}

function detail(id: number) {
  void router.push({ name: 'warehouse-sub-location-detail', params: { id } })
}

onMounted(load)
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold">Sub Locations</h1>
    <p class="mb-5 text-sm text-stone-500">Stok fisik lokasi Sub — pencatatan independen, bukan stok tersedia untuk dijual.</p>
    <p v-if="error" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>
    <p v-if="success" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-600">{{ success }}</p>

    <form v-if="isGudang" class="mb-6 grid max-w-xl gap-2 rounded-xl border border-stone-200 bg-white p-4" @submit.prevent="create">
      <h2 class="font-semibold">Tambah Lokasi Sub</h2>
      <input v-model="code" required placeholder="Kode (mis. CIMAHI)" class="rounded-lg border border-stone-200 px-3 py-2 text-sm" />
      <input v-model="name" required placeholder="Nama Lokasi (mis. Sub Gudang Cimahi)" class="rounded-lg border border-stone-200 px-3 py-2 text-sm" />
      <input v-model="address" placeholder="Alamat (opsional)" class="rounded-lg border border-stone-200 px-3 py-2 text-sm" />
      <input v-model="contact" placeholder="Nomor Kontak (opsional)" class="rounded-lg border border-stone-200 px-3 py-2 text-sm" />
      <button class="rounded-xl bg-brand-600 px-4 py-2 font-semibold text-white">Tambah Lokasi</button>
    </form>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <article v-for="location in locations" :key="location.id" class="min-w-0 rounded-xl border border-stone-200 bg-white p-4">
        <div class="font-semibold text-stone-800">{{ location.name }}</div>
        <div class="truncate text-xs text-stone-400">{{ location.code }}</div>
        <div v-if="location.address" class="mt-1 truncate text-sm text-stone-500">{{ location.address }}</div>
        <div v-if="location.contact_number" class="text-sm text-stone-500">{{ location.contact_number }}</div>
        <div class="mt-1 text-xs" :class="location.is_active ? 'text-emerald-700' : 'text-stone-400'">{{ location.is_active ? 'Aktif' : 'Nonaktif' }}</div>
        <div class="mt-3 flex gap-2">
          <button type="button" class="rounded-lg bg-stone-900 px-3 py-1 text-sm text-white" @click="detail(location.id)">Detail</button>
          <button v-if="location.is_active" type="button" class="rounded-lg bg-stone-200 px-3 py-1 text-sm" @click="deactivate(location.id)">Deactivate</button>
        </div>
      </article>
    </div>
  </DashboardLayout>
</template>
