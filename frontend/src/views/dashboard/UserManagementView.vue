<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import PasswordInput from '@/components/ui/PasswordInput.vue'
import { useAuthStore } from '@/stores/auth'
import {
  listUsers,
  createUser,
  updateUser,
  deleteUser,
  reassignReferral,
  type CreateUserPayload,
} from '@/api/users'
import type { AuthUser } from '@/api/types'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'

const auth = useAuthStore()

const ALLOWED_CREATIONS: Record<string, CreateUserPayload['role'][]> = {
  super_admin: ['agen'],
  agen: ['korsal', 'sales', 'admin', 'keuangan', 'kurir'],
  korsal: ['sales'],
}

/** Mirrors UserPolicy::delete — super_admin deletes any non-self, non-super-admin, non-agent account; an agen may delete only admin/keuangan/kurir within its own branch. */
function canDelete(user: AuthUser): boolean {
  if (auth.user?.role === 'super_admin') {
    return user.id !== auth.user.id && user.role !== 'super_admin' && user.role !== 'agen'
  }

  if (auth.user?.role === 'agen') {
    return ['admin', 'keuangan', 'kurir'].includes(user.role) && user.agent_id === auth.user.agent_id
  }

  return false
}

/** Mirrors UserPolicy::update — keuangan may view the branch roster but is not an account manager, so it can only ever edit itself. */
function canEdit(user: AuthUser): boolean {
  return auth.user?.role !== 'keuangan' || user.id === auth.user?.id
}

/** Reassign referral is only meaningful for these two target roles — see ReferralReassignmentService. */
function canReassign(user: AuthUser): boolean {
  return canEdit(user) && (user.role === 'sales' || user.role === 'konsumen')
}

const ROLE_LABELS: Record<string, string> = {
  super_admin: 'Super Admin',
  agen: 'Agen',
  korsal: 'Korsal',
  sales: 'Sales',
  admin: 'Admin',
  keuangan: 'Keuangan',
  kurir: 'Kurir',
  konsumen: 'Konsumen',
}

const creatableRoles = computed(() => ALLOWED_CREATIONS[auth.user?.role ?? ''] ?? [])

const loading = ref(false)
const errorMessage = ref('')
const users = ref<AuthUser[]>([])

const showCreate = ref(false)
const form = ref<CreateUserPayload>({
  role: 'sales',
  name: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: '',
})
const createError = ref('')
const creating = ref(false)

const needsKorsalId = computed(() => auth.user?.role === 'agen' && form.value.role === 'sales')
const korsalCandidates = ref<AuthUser[]>([])
const korsalLoading = ref(false)
watch(needsKorsalId, async (required) => {
  form.value.korsal_id = undefined
  if (!required) return
  korsalLoading.value = true
  try {
    const all: AuthUser[] = []
    let page = 1
    let lastPage = 1
    do {
      const result = await listUsers(page, { role: 'korsal' })
      all.push(...result.users)
      lastPage = result.meta.last_page
      page++
    } while (page <= lastPage)
    korsalCandidates.value = all
  } catch {
    createError.value = 'Gagal memuat Korsal. Buka kembali form untuk mencoba lagi.'
    korsalCandidates.value = []
  } finally {
    korsalLoading.value = false
  }
})

const editingId = ref<number | null>(null)
const editForm = ref<{ name: string; phone: string; status: 'active' | 'inactive' | 'suspended' }>({
  name: '',
  phone: '',
  status: 'active',
})

/* ---------- Reassign referral (sales -> korsal, konsumen -> sales) ---------- */
const reassigningUser = ref<AuthUser | null>(null)
const reassignCandidates = ref<AuthUser[]>([])
const reassignTargetId = ref<number | null>(null)
const reassignError = ref('')
const reassigning = ref(false)

async function openReassign(user: AuthUser) {
  reassigningUser.value = user
  reassignError.value = ''
  reassignTargetId.value = null
  const targetRole = user.role === 'sales' ? 'korsal' : 'sales'
  const { users: candidates } = await listUsers(1, { role: targetRole })
  reassignCandidates.value = candidates.filter((c) => c.id !== user.id)
}

function closeReassign() {
  reassigningUser.value = null
}

async function submitReassign() {
  if (!reassigningUser.value || reassignTargetId.value === null) return
  reassigning.value = true
  reassignError.value = ''
  try {
    const payload =
      reassigningUser.value.role === 'sales'
        ? { korsal_id: reassignTargetId.value }
        : { sales_id: reassignTargetId.value }
    await reassignReferral(reassigningUser.value.id, payload)
    closeReassign()
    await load()
  } catch (e) {
    reassignError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memindahkan referral.'
  } finally {
    reassigning.value = false
  }
}

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const { users: rows } = await listUsers()
    users.value = rows
  } catch {
    errorMessage.value = 'Gagal memuat daftar pengguna.'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  form.value = {
    role: creatableRoles.value[0] ?? 'sales',
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
  }
  createError.value = ''
  showCreate.value = true
}

async function submitCreate() {
  createError.value = ''
  creating.value = true
  try {
    await createUser(form.value)
    showCreate.value = false
    await load()
  } catch (e) {
    createError.value =
      e instanceof ApiError
        ? formatApiError(e)
        : 'Gagal membuat pengguna. Periksa kembali data yang diisi.'
  } finally {
    creating.value = false
  }
}

function openEdit(user: AuthUser) {
  editingId.value = user.id
  editForm.value = {
    name: user.name,
    phone: user.phone,
    status: (user.status as 'active' | 'inactive' | 'suspended') ?? 'active',
  }
}

function closeEdit() {
  editingId.value = null
}

async function submitEdit() {
  if (editingId.value === null) return
  try {
    await updateUser(editingId.value, editForm.value)
    closeEdit()
    await load()
  } catch {
    errorMessage.value = 'Gagal memperbarui pengguna.'
  }
}

async function remove(user: AuthUser) {
  if (auth.user && (user.id === auth.user.id || user.role === 'super_admin' || user.role === 'agen')) {
    errorMessage.value = 'Akun ini tidak dapat dihapus dari dashboard ini.'
    return
  }

  if (!confirm(`Hapus pengguna "${user.name}"? Tindakan ini tidak dapat dibatalkan sendiri.`))
    return

  try {
    await deleteUser(user.id)
    await load()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menghapus pengguna.'
  }
}

onMounted(load)
</script>

<template>
  <DashboardLayout>
    <div class="mb-5 flex items-center justify-between gap-2">
      <div>
        <h1 class="font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">
          Pengguna
        </h1>
        <p class="text-sm text-stone-500 dark:text-stone-400">
          Kelola akun staf dalam jaringan Anda.
        </p>
      </div>
      <button
        v-if="creatableRoles.length > 0"
        type="button"
        class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
        @click="openCreate"
      >
        + Tambah Pengguna
      </button>
    </div>

    <p
      v-if="errorMessage"
      class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
    >
      {{ errorMessage }}
    </p>
    <p v-if="loading" class="text-sm text-stone-500 dark:text-stone-400">Memuat...</p>

    <div
      v-else
      class="overflow-x-auto rounded-2xl border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900"
    >
      <table class="w-full text-sm">
        <thead
          class="border-b border-stone-200 text-left text-xs uppercase text-stone-400 dark:border-stone-800 dark:text-stone-500"
        >
          <tr>
            <th class="px-4 py-3">Nama</th>
            <th class="px-4 py-3">Peran</th>
            <th class="px-4 py-3">Kontak</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="users.length === 0">
            <td colspan="5" class="px-4 py-6 text-center text-stone-400 dark:text-stone-500">
              Tidak ada data.
            </td>
          </tr>
          <tr
            v-for="user in users"
            :key="user.id"
            class="border-b border-stone-100 last:border-0 dark:border-stone-800"
          >
            <td class="px-4 py-3 font-medium text-stone-700 dark:text-stone-200">
              {{ user.name }}
            </td>
            <td class="px-4 py-3 text-stone-500 dark:text-stone-400">
              {{ ROLE_LABELS[user.role] ?? user.role }}
            </td>
            <td class="px-4 py-3 text-stone-500 dark:text-stone-400">
              <p>{{ user.email }}</p>
              <p class="text-xs">{{ user.phone }}</p>
            </td>
            <td class="px-4 py-3">
              <span
                class="rounded-full px-2 py-0.5 text-xs font-medium"
                :class="{
                  'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300':
                    user.status === 'active',
                  'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300':
                    user.status === 'suspended',
                  'bg-stone-100 text-stone-500 dark:bg-stone-800 dark:text-stone-400':
                    user.status !== 'active' && user.status !== 'suspended',
                }"
              >
                {{
                  user.status === 'active'
                    ? 'Aktif'
                    : user.status === 'suspended'
                      ? 'Suspended'
                      : 'Nonaktif'
                }}
              </span>
            </td>
            <td class="px-4 py-3 text-right">
              <button
                v-if="canEdit(user)"
                type="button"
                class="mr-2 rounded-lg bg-stone-100 px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200"
                @click="openEdit(user)"
              >
                Edit
              </button>
              <button
                v-if="canReassign(user)"
                type="button"
                class="mr-2 rounded-lg bg-stone-100 px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200"
                @click="openReassign(user)"
              >
                {{ user.role === 'sales' ? 'Pindah Korsal' : 'Pindah Sales' }}
              </button>
              <button
                v-if="canDelete(user)"
                type="button"
                class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100 dark:bg-red-950 dark:text-red-300"
                @click="remove(user)"
              >
                Hapus
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create modal -->
    <div
      v-if="showCreate"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      @click.self="showCreate = false"
    >
      <div class="w-full max-w-md rounded-2xl bg-white p-5 dark:bg-stone-900">
        <h2 class="mb-4 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">
          Tambah Pengguna
        </h2>
        <p
          v-if="createError"
          class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
        >
          {{ createError }}
        </p>
        <form class="space-y-3" @submit.prevent="submitCreate">
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Peran
            <select
              v-model="form.role"
              class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
            >
              <option v-for="role in creatableRoles" :key="role" :value="role">
                {{ ROLE_LABELS[role] ?? role }}
              </option>
            </select>
          </label>
          <label v-if="needsKorsalId" class="block text-sm text-stone-600 dark:text-stone-300">
            Korsal (wajib)
            <select
              v-model.number="form.korsal_id"
              required
              :disabled="korsalLoading"
              class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
            >
              <option :value="undefined" disabled>Pilih Korsal</option>
              <option v-for="korsal in korsalCandidates" :key="korsal.id" :value="korsal.id">
                {{ korsal.name }}
              </option>
            </select>
            <p v-if="!korsalLoading && !korsalCandidates.length" class="mt-1 text-xs">
              Buat Korsal terlebih dahulu sebelum menambahkan Sales.
            </p>
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Nama
            <input
              v-model="form.name"
              type="text"
              required
              class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
            />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Email
            <input
              v-model="form.email"
              type="email"
              required
              class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
            />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Telepon
            <input
              v-model="form.phone"
              type="text"
              required
              class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
            />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Password
            <PasswordInput v-model="form.password" required autocomplete="new-password" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Konfirmasi Password
            <PasswordInput
              v-model="form.password_confirmation"
              required
              autocomplete="new-password"
            />
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button
              type="button"
              class="rounded-lg px-3 py-2 text-sm text-stone-500 dark:text-stone-400"
              @click="showCreate = false"
            >
              Batal
            </button>
            <button
              type="submit"
              class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50"
              :disabled="creating || korsalLoading || (needsKorsalId && !form.korsal_id)"
            >
              Simpan
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Edit modal -->
    <div
      v-if="editingId !== null"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      @click.self="closeEdit"
    >
      <div class="w-full max-w-sm rounded-2xl bg-white p-5 dark:bg-stone-900">
        <h2 class="mb-4 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">
          Edit Pengguna
        </h2>
        <div class="space-y-3">
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Nama
            <input
              v-model="editForm.name"
              type="text"
              class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
            />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Telepon
            <input
              v-model="editForm.phone"
              type="text"
              class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
            />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">
            Status
            <select
              v-model="editForm.status"
              class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
            >
              <option value="active">Aktif</option>
              <option value="inactive">Nonaktif</option>
              <option value="suspended">Suspended</option>
            </select>
          </label>
        </div>
        <div class="mt-4 flex justify-end gap-2">
          <button
            type="button"
            class="rounded-lg px-3 py-2 text-sm text-stone-500 dark:text-stone-400"
            @click="closeEdit"
          >
            Batal
          </button>
          <button
            type="button"
            class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
            @click="submitEdit"
          >
            Simpan
          </button>
        </div>
      </div>
    </div>

    <!-- Reassign referral modal -->
    <div
      v-if="reassigningUser"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      @click.self="closeReassign"
    >
      <div class="w-full max-w-sm rounded-2xl bg-white p-5 dark:bg-stone-900">
        <h2 class="mb-1 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">
          Pindahkan {{ reassigningUser.name }}
        </h2>
        <p class="mb-4 text-xs text-stone-500 dark:text-stone-400">
          {{
            reassigningUser.role === 'sales'
              ? 'Pilih Korsal baru untuk sales ini.'
              : 'Pilih Sales baru untuk konsumen ini.'
          }}
        </p>
        <p
          v-if="reassignError"
          class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
        >
          {{ reassignError }}
        </p>
        <select
          v-model.number="reassignTargetId"
          class="block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
        >
          <option :value="null" disabled>
            — Pilih {{ reassigningUser.role === 'sales' ? 'Korsal' : 'Sales' }} —
          </option>
          <option v-for="c in reassignCandidates" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <p v-if="reassignCandidates.length === 0" class="mt-2 text-xs text-stone-400">
          Tidak ada kandidat lain di jaringan ini.
        </p>
        <div class="mt-4 flex justify-end gap-2">
          <button
            type="button"
            class="rounded-lg px-3 py-2 text-sm text-stone-500 dark:text-stone-400"
            @click="closeReassign"
          >
            Batal
          </button>
          <button
            type="button"
            class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50"
            :disabled="reassignTargetId === null || reassigning"
            @click="submitReassign"
          >
            Pindahkan
          </button>
        </div>
      </div>
    </div>
  </DashboardLayout>
</template>
