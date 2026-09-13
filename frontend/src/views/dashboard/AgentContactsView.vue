<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import {
  listAgentDirectory,
  createAgentProfile,
  updateAgentProfile,
  deleteAgentProfile,
  toggleAgentStatus,
  type AgentDirectoryRow,
} from '@/api/agents'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'

const loading = ref(false)
const errorMessage = ref('')
const agents = ref<AgentDirectoryRow[]>([])

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    agents.value = await listAgentDirectory()
  } catch {
    errorMessage.value = 'Gagal memuat daftar agen.'
  } finally {
    loading.value = false
  }
}

const showCreate = ref(false)
const createForm = ref({ user_id: 0, store_name: '', address: '', phone: '', latitude: 0, longitude: 0 })
const createError = ref('')

function openCreate() {
  createForm.value = { user_id: 0, store_name: '', address: '', phone: '', latitude: 0, longitude: 0 }
  createError.value = ''
  showCreate.value = true
}

async function submitCreate() {
  createError.value = ''
  try {
    await createAgentProfile(createForm.value)
    showCreate.value = false
    await load()
  } catch (e) {
    createError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menambah profil agen. Pastikan ID pengguna sudah berperan Agen dan belum punya profil.'
  }
}

const editingId = ref<number | null>(null)
const editForm = ref({ store_name: '', address: '', phone: '', latitude: 0, longitude: 0 })

function openEdit(row: AgentDirectoryRow) {
  if (!row.profile) return
  editingId.value = row.profile.id
  editForm.value = {
    store_name: row.profile.store_name,
    address: row.profile.address,
    phone: row.profile.phone ?? '',
    latitude: row.profile.latitude,
    longitude: row.profile.longitude,
  }
}

function closeEdit() {
  editingId.value = null
}

async function submitEdit() {
  if (editingId.value === null) return
  try {
    await updateAgentProfile(editingId.value, editForm.value)
    closeEdit()
    await load()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memperbarui profil agen.'
  }
}

async function remove(row: AgentDirectoryRow) {
  if (!row.profile) return
  if (!confirm(`Hapus profil toko "${row.profile.store_name}"?`)) return
  try {
    await deleteAgentProfile(row.profile.id)
    await load()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menghapus profil agen.'
  }
}

async function toggleStatus(row: AgentDirectoryRow) {
  try {
    await toggleAgentStatus(row.user_id)
    await load()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal mengubah status agen.'
  }
}

onMounted(load)
</script>

<template>
  <DashboardLayout>
    <div class="mb-5 flex items-center justify-between gap-2">
      <div>
        <h1 class="font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Kontak Agen</h1>
        <p class="text-sm text-stone-500 dark:text-stone-400">Kelola profil toko agen yang tampil di halaman publik.</p>
      </div>
      <button type="button" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="openCreate">
        + Profil Agen
      </button>
    </div>

    <p v-if="errorMessage" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ errorMessage }}
    </p>
    <p v-if="loading" class="text-sm text-stone-500 dark:text-stone-400">Memuat...</p>

    <div v-else class="overflow-x-auto rounded-2xl border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900">
      <table class="w-full text-sm">
        <thead class="border-b border-stone-200 text-left text-xs uppercase text-stone-400 dark:border-stone-800 dark:text-stone-500">
          <tr>
            <th class="px-4 py-3">Agen</th>
            <th class="px-4 py-3">Toko</th>
            <th class="px-4 py-3">Alamat</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="agents.length === 0">
            <td colspan="5" class="px-4 py-6 text-center text-stone-400 dark:text-stone-500">Tidak ada data.</td>
          </tr>
          <tr v-for="row in agents" :key="row.user_id" class="border-b border-stone-100 last:border-0 dark:border-stone-800">
            <td class="px-4 py-3 font-medium text-stone-700 dark:text-stone-200">
              {{ row.name }}
              <p class="text-xs font-normal text-stone-400 dark:text-stone-500">ID {{ row.user_id }} · {{ row.email }}</p>
            </td>
            <td class="px-4 py-3 text-stone-500 dark:text-stone-400">{{ row.profile?.store_name ?? '—' }}</td>
            <td class="px-4 py-3 text-stone-500 dark:text-stone-400">{{ row.profile?.address ?? '—' }}</td>
            <td class="px-4 py-3">
              <span
                class="rounded-full px-2 py-0.5 text-xs font-medium"
                :class="row.status === 'active' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-stone-100 text-stone-500 dark:bg-stone-800 dark:text-stone-400'"
              >
                {{ row.status === 'active' ? 'Aktif' : 'Nonaktif' }}
              </span>
            </td>
            <td class="px-4 py-3 text-right">
              <div class="flex justify-end gap-2">
                <button
                  type="button"
                  class="rounded-lg bg-stone-100 px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200"
                  @click="toggleStatus(row)"
                >
                  {{ row.status === 'active' ? 'Nonaktifkan' : 'Aktifkan' }}
                </button>
                <button
                  v-if="row.profile"
                  type="button"
                  class="rounded-lg bg-stone-100 px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200"
                  @click="openEdit(row)"
                >
                  Edit
                </button>
                <button
                  v-if="row.profile"
                  type="button"
                  class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-100 dark:bg-red-950 dark:text-red-300"
                  @click="remove(row)"
                >
                  Hapus
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create modal -->
    <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showCreate = false">
      <div class="w-full max-w-md rounded-2xl bg-white p-5 dark:bg-stone-900">
        <h2 class="mb-4 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">Profil Agen Baru</h2>
        <p v-if="createError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ createError }}</p>
        <form class="space-y-3" @submit.prevent="submitCreate">
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            ID Pengguna (Agen)
            <input v-model.number="createForm.user_id" type="number" required class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Nama Toko
            <input v-model="createForm.store_name" type="text" required class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Alamat
            <input v-model="createForm.address" type="text" required class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Telepon
            <input v-model="createForm.phone" type="text" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <div class="grid grid-cols-2 gap-3">
            <label class="block text-sm text-stone-600 dark:text-stone-300">
              Latitude
              <input v-model.number="createForm.latitude" type="number" step="any" required class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </label>
            <label class="block text-sm text-stone-600 dark:text-stone-300">
              Longitude
              <input v-model.number="createForm.longitude" type="number" step="any" required class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </label>
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="rounded-lg px-3 py-2 text-sm text-stone-500 dark:text-stone-400" @click="showCreate = false">Batal</button>
            <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">Simpan</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Edit modal -->
    <div v-if="editingId !== null" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="closeEdit">
      <div class="w-full max-w-md rounded-2xl bg-white p-5 dark:bg-stone-900">
        <h2 class="mb-4 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">Edit Profil Agen</h2>
        <div class="space-y-3">
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Nama Toko
            <input v-model="editForm.store_name" type="text" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Alamat
            <input v-model="editForm.address" type="text" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Telepon
            <input v-model="editForm.phone" type="text" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <div class="grid grid-cols-2 gap-3">
            <label class="block text-sm text-stone-600 dark:text-stone-300">
              Latitude
              <input v-model.number="editForm.latitude" type="number" step="any" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </label>
            <label class="block text-sm text-stone-600 dark:text-stone-300">
              Longitude
              <input v-model.number="editForm.longitude" type="number" step="any" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </label>
          </div>
        </div>
        <div class="mt-4 flex justify-end gap-2">
          <button type="button" class="rounded-lg px-3 py-2 text-sm text-stone-500 dark:text-stone-400" @click="closeEdit">Batal</button>
          <button type="button" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="submitEdit">Simpan</button>
        </div>
      </div>
    </div>
  </DashboardLayout>
</template>
