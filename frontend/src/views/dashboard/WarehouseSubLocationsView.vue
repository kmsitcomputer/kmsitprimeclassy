<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { assignSubLocationOwner, createSubLocation, deactivateSubLocation, listSubLocations, type SubLocation } from '@/api/warehouse'
import { listUsers } from '@/api/users'
import type { AuthUser } from '@/api/types'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
// R-01: only Agen/Admin manage Sub Location ownership — Gudang can never create or assign one.
const canManageOwnership = computed(() => auth.user?.role === 'agen' || auth.user?.role === 'admin')

const locations = ref<SubLocation[]>([])
const eligibleOwners = ref<AuthUser[]>([])
const listState = ref<'loading' | 'error' | 'ready'>('loading')
const listError = ref('')

const code = ref('')
const name = ref('')
const address = ref('')
const contact = ref('')
const ownerUserId = ref<number | ''>('')
const creating = ref(false)
const createError = ref('')
const success = ref('')

const assigningId = ref<number | null>(null)
const assignOwnerSelection = ref<Record<number, number | ''>>({})
const assignError = ref<Record<number, string>>({})

async function load() {
  listState.value = 'loading'
  listError.value = ''
  try {
    const tasks: Promise<unknown>[] = [listSubLocations().then((rows) => { locations.value = rows })]
    if (canManageOwnership.value) {
      tasks.push(listUsers(1, { role: 'sales-kurir-sub' }).then(({ users }) => { eligibleOwners.value = users }))
    }
    await Promise.all(tasks)
    listState.value = 'ready'
  } catch (e) {
    listState.value = 'error'
    listError.value = e instanceof Error ? e.message : 'Gagal memuat daftar Sub Location.'
  }
}

async function create() {
  createError.value = ''; success.value = ''
  if (creating.value) return
  try {
    if (!code.value.trim() || !name.value.trim()) throw new Error('Isi kode dan nama lokasi.')
    if (!ownerUserId.value) throw new Error('Pilih Sales-Kurir-Sub pemilik lokasi.')
    creating.value = true
    await createSubLocation({
      owner_user_id: Number(ownerUserId.value), code: code.value.trim(), name: name.value.trim(),
      address: address.value.trim() || undefined, contact_number: contact.value.trim() || undefined,
    })
    code.value = ''; name.value = ''; address.value = ''; contact.value = ''; ownerUserId.value = ''
    success.value = 'Sub Location berhasil dibuat dan dimiliki oleh Sales-Kurir-Sub terpilih.'
    await load()
  } catch (e) {
    createError.value = e instanceof Error ? e.message : 'Gagal menyimpan lokasi Sub.'
  } finally {
    creating.value = false
  }
}

async function deactivate(id: number) {
  createError.value = ''; success.value = ''
  try {
    await deactivateSubLocation(id)
    success.value = 'Lokasi dinonaktifkan.'
    await load()
  } catch (e) { createError.value = e instanceof Error ? e.message : 'Lokasi berisi stok atau tidak dapat dinonaktifkan.' }
}

async function assignOwner(location: SubLocation) {
  const selected = assignOwnerSelection.value[location.id]
  assignError.value = { ...assignError.value, [location.id]: '' }
  if (!selected) {
    assignError.value = { ...assignError.value, [location.id]: 'Pilih Sales-Kurir-Sub terlebih dahulu.' }
    return
  }
  assigningId.value = location.id
  try {
    await assignSubLocationOwner(location.id, Number(selected))
    success.value = `Kepemilikan ${location.name} berhasil ditetapkan.`
    await load()
  } catch (e) {
    assignError.value = { ...assignError.value, [location.id]: e instanceof Error ? e.message : 'Gagal menetapkan pemilik.' }
  } finally {
    assigningId.value = null
  }
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
    <p v-if="createError" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ createError }}</p>
    <p v-if="success" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-600">{{ success }}</p>

    <form v-if="canManageOwnership" class="mb-6 grid max-w-xl gap-2 rounded-xl border border-stone-200 bg-white p-4" @submit.prevent="create">
      <h2 class="font-semibold">Tambah Lokasi Sub</h2>
      <input v-model="code" required placeholder="Kode (mis. CIMAHI)" class="rounded-lg border border-stone-200 px-3 py-2 text-sm" />
      <input v-model="name" required placeholder="Nama Lokasi (mis. Sub Gudang Cimahi)" class="rounded-lg border border-stone-200 px-3 py-2 text-sm" />
      <input v-model="address" placeholder="Alamat (opsional)" class="rounded-lg border border-stone-200 px-3 py-2 text-sm" />
      <input v-model="contact" placeholder="Nomor Kontak (opsional)" class="rounded-lg border border-stone-200 px-3 py-2 text-sm" />
      <select v-model="ownerUserId" required class="rounded-lg border border-stone-200 px-3 py-2 text-sm">
        <option value="" disabled>Pilih Sales-Kurir-Sub pemilik...</option>
        <option v-for="owner in eligibleOwners" :key="owner.id" :value="owner.id">{{ owner.name }}</option>
      </select>
      <p v-if="canManageOwnership && !eligibleOwners.length" class="text-xs text-amber-600">Belum ada Sales-Kurir-Sub aktif di network ini untuk dijadikan pemilik.</p>
      <button :disabled="creating" class="rounded-xl bg-brand-600 px-4 py-2 font-semibold text-white disabled:opacity-40">{{ creating ? 'Menyimpan...' : 'Tambah Lokasi' }}</button>
    </form>

    <div v-if="listState === 'loading'" class="rounded-xl border border-stone-200 bg-white p-6 text-center text-sm text-stone-400">Memuat daftar Sub Location...</div>
    <div v-else-if="listState === 'error'" class="rounded-xl border border-red-200 bg-red-50 p-6 text-center text-sm text-red-600">
      {{ listError }}
      <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
    </div>
    <div v-else-if="!locations.length" class="rounded-xl border border-stone-200 bg-white p-6 text-center text-sm text-stone-400">Belum ada Sub Location.</div>
    <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <article v-for="location in locations" :key="location.id" class="min-w-0 rounded-xl border border-stone-200 bg-white p-4">
        <div class="font-semibold text-stone-800">{{ location.name }}</div>
        <div class="truncate text-xs text-stone-400">{{ location.code }}</div>
        <div v-if="location.address" class="mt-1 truncate text-sm text-stone-500">{{ location.address }}</div>
        <div v-if="location.contact_number" class="text-sm text-stone-500">{{ location.contact_number }}</div>
        <div class="mt-1 text-xs" :class="location.is_active ? 'text-emerald-700' : 'text-stone-400'">{{ location.is_active ? 'Aktif' : 'Nonaktif' }}</div>
        <div class="mt-1 text-xs text-stone-500">Pemilik: <strong>{{ location.owner?.name ?? 'Belum ditetapkan' }}</strong></div>

        <div v-if="canManageOwnership && !location.owner && location.is_active" class="mt-2 rounded-lg bg-stone-50 p-2">
          <div class="flex flex-wrap items-center gap-2">
            <select v-model="assignOwnerSelection[location.id]" class="min-w-0 flex-1 rounded-lg border border-stone-200 px-2 py-1 text-xs">
              <option value="" disabled>Tetapkan pemilik...</option>
              <option v-for="owner in eligibleOwners" :key="owner.id" :value="owner.id">{{ owner.name }}</option>
            </select>
            <button type="button" :disabled="assigningId === location.id" class="shrink-0 rounded-lg bg-stone-900 px-3 py-1 text-xs text-white disabled:opacity-40" @click="assignOwner(location)">Tetapkan</button>
          </div>
          <p v-if="assignError[location.id]" class="mt-1 text-xs text-red-600">{{ assignError[location.id] }}</p>
        </div>

        <div class="mt-3 flex gap-2">
          <button type="button" class="rounded-lg bg-stone-900 px-3 py-1 text-sm text-white" @click="detail(location.id)">Detail</button>
          <button v-if="location.is_active" type="button" class="rounded-lg bg-stone-200 px-3 py-1 text-sm" @click="deactivate(location.id)">Deactivate</button>
        </div>
      </article>
    </div>
  </DashboardLayout>
</template>
