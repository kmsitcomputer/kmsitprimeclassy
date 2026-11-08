<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import PasswordInput from '@/components/ui/PasswordInput.vue'
import { useAuthStore } from '@/stores/auth'
import {
  listUsers,
  createUser,
  updateUser,
  deleteUser,
  reassignReferral,
  convertToSalesKurir,
  type CreateUserPayload,
} from '@/api/users'
import type { AuthUser } from '@/api/types'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'

const auth = useAuthStore()
const { t } = useI18n()

const ALLOWED_CREATIONS: Record<string, CreateUserPayload['role'][]> = {
  super_admin: ['agen'],
  agen: ['korsal', 'sales', 'admin', 'keuangan', 'kurir', 'gudang', 'sales-kurir-sub', 'koordinator-kurir'],
  korsal: ['sales', 'sales-kurir-sub'],
  // A1-02 — the Human-approved Agen → Koordinator Kurir → Kurir leg: a Koordinator
  // may create/manage Kurir in its own branch (backend HierarchyRules mirrors this).
  'koordinator-kurir': ['kurir'],
}

/** Mirrors UserPolicy::delete — super_admin deletes any non-self, non-super-admin, non-agent account; an agen may delete every role beneath it (anything except another agen/super_admin) within its own branch. */
function canDelete(user: AuthUser): boolean {
  if (auth.user?.role === 'super_admin') {
    return user.id !== auth.user.id && user.role !== 'super_admin' && user.role !== 'agen'
  }

  if (auth.user?.role === 'agen') {
    return (
      user.id !== auth.user.id &&
      user.role !== 'super_admin' &&
      user.role !== 'agen' &&
      user.agent_id === auth.user.agent_id
    )
  }

  return false
}

/** Mirrors UserPolicy::update — keuangan may view the branch roster but is not an account manager, so it can only ever edit itself. */
function canEdit(user: AuthUser): boolean {
  if (auth.user?.role === 'koordinator-kurir') {
    return user.role === 'kurir' && user.agent_id === auth.user.agent_id && user.parent_id === auth.user.id
  }
  return auth.user?.role !== 'keuangan' || user.id === auth.user?.id
}

/** Reassign referral is only meaningful for these two target roles — see ReferralReassignmentService. */
function isSalesSeller(user: AuthUser): boolean {
  return user.role === 'sales' || user.role === 'sales-kurir-sub'
}

function canReassign(user: AuthUser): boolean {
  return canEdit(user) && (isSalesSeller(user) || user.role === 'konsumen')
}

function canConvertToSalesKurir(user: AuthUser): boolean {
  return auth.user?.role === 'agen' && user.role === 'sales' && user.agent_id === auth.user.agent_id
}

const ROLE_LABELS: Record<string, string> = {
  super_admin: 'Super Admin',
  agen: 'Agen',
  korsal: 'Korsal',
  sales: 'Sales',
  admin: 'Admin',
  keuangan: 'Keuangan',
  kurir: 'Kurir',
  gudang: t('roles.gudang'),
  'sales-kurir-sub': t('roles.salesKurir'),
  'koordinator-kurir': t('roles.koordinatorKurir'),
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

const needsKorsalId = computed(
  () => ['sales', 'sales-kurir-sub'].includes(form.value.role) && auth.user?.role === 'agen',
)
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

// Candidate selector is bounded and server-filtered (never page-1 only, never the whole table):
// at most CANDIDATE_LIMIT rows per target role, narrowed by a debounced server-side search.
const CANDIDATE_LIMIT = 20
const candidateSearch = ref('')
const candidateTotal = ref(0)
const candidatesLoading = ref(false)
let candidateSeq = 0
let candidateTimer: ReturnType<typeof setTimeout> | undefined

async function loadCandidates() {
  const user = reassigningUser.value
  if (!user) return
  const seq = ++candidateSeq
  candidatesLoading.value = true
  try {
    const targetRoles = isSalesSeller(user) ? ['korsal'] : ['sales', 'sales-kurir-sub']
    const pages = await Promise.all(
      targetRoles.map((role) =>
        listUsers(1, { role, agent_id: user.agent_id ?? undefined, status: 'active', search: candidateSearch.value.trim() || undefined, per_page: CANDIDATE_LIMIT }),
      ),
    )
    if (seq !== candidateSeq) return
    reassignCandidates.value = pages.flatMap((p) => p.users).filter((c) => c.id !== user.id)
    candidateTotal.value = pages.reduce((sum, p) => sum + p.meta.total, 0)
  } catch (e) {
    if (seq !== candidateSeq) return
    reassignError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat kandidat.'
  } finally {
    if (seq === candidateSeq) candidatesLoading.value = false
  }
}

function onCandidateSearch() {
  candidateSeq++
  reassignTargetId.value = null
  clearTimeout(candidateTimer)
  candidateTimer = setTimeout(() => void loadCandidates(), 300)
}

async function openReassign(user: AuthUser) {
  reassigningUser.value = user
  reassignError.value = ''
  reassignTargetId.value = null
  candidateSearch.value = ''
  reassignCandidates.value = []
  await loadCandidates()
}

function closeReassign() {
  clearTimeout(candidateTimer)
  candidateSeq++
  reassigningUser.value = null
}

async function submitReassign() {
  if (!reassigningUser.value || reassignTargetId.value === null) return
  reassigning.value = true
  reassignError.value = ''
  try {
    const payload =
      isSalesSeller(reassigningUser.value)
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

async function convertSales(user: AuthUser) {
  if (!confirm(`Ubah ${user.name} menjadi Sales-Kurir-Sub?`)) return
  try {
    await convertToSalesKurir(user.id)
    await load()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal mengubah role pengguna.'
  }
}

// UI-USER-001: server-side pagination + search + role/status filters. Search/filter/page-size
// changes reset to page 1; a sequence number drops stale responses so an older request can never
// overwrite newer results.
const PAGE_SIZES = [10, 25, 50, 100]
const filters = ref({ search: '', role: '', status: '', per_page: 25 })
const pageMeta = ref({ current_page: 1, last_page: 1, total: 0 })
let loadSeq = 0
let searchTimer: ReturnType<typeof setTimeout> | undefined

const hasActiveFilters = computed(() => filters.value.search !== '' || filters.value.role !== '' || filters.value.status !== '')
const filterRoleOptions = computed(() => Object.keys(ROLE_LABELS).filter((r) => r !== 'super_admin'))

async function load(page = pageMeta.value.current_page) {
  const seq = ++loadSeq
  loading.value = true
  errorMessage.value = ''
  try {
    const { users: rows, meta } = await listUsers(page, {
      search: filters.value.search.trim() || undefined,
      role: filters.value.role || undefined,
      status: filters.value.status || undefined,
      per_page: filters.value.per_page,
    })
    if (seq !== loadSeq) return
    // A delete can empty the last page: step back instead of showing an empty table.
    if (rows.length === 0 && page > 1 && meta.last_page < page) {
      await load(meta.last_page)
      return
    }
    users.value = rows
    pageMeta.value = { current_page: meta.current_page, last_page: meta.last_page, total: meta.total }
  } catch (e) {
    if (seq !== loadSeq) return
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat daftar pengguna.'
  } finally {
    if (seq === loadSeq) loading.value = false
  }
}

function applyFilters() {
  void load(1)
}

function onSearchInput() {
  loadSeq++
  clearTimeout(searchTimer)
  searchTimer = setTimeout(applyFilters, 350)
}

function resetFilters() {
  clearTimeout(searchTimer)
  filters.value = { search: '', role: '', status: '', per_page: filters.value.per_page }
  applyFilters()
}

function goToPage(delta: number) {
  const next = Math.max(1, Math.min(pageMeta.value.last_page, pageMeta.value.current_page + delta))
  if (next !== pageMeta.value.current_page) void load(next)
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

onMounted(() => void load(1))
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
    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-2xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
      <label class="flex min-w-[12rem] flex-1 flex-col gap-1 text-xs text-stone-500">
        Cari (nama, email, telepon)
        <input v-model="filters.search" type="search" maxlength="100"
          class="rounded-lg border border-stone-200 bg-white px-3 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950"
          @input="onSearchInput" @keyup.enter="applyFilters" />
      </label>
      <label class="flex flex-col gap-1 text-xs text-stone-500">
        Peran
        <select v-model="filters.role" class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" @change="applyFilters">
          <option value="">Semua peran</option>
          <option v-for="r in filterRoleOptions" :key="r" :value="r">{{ ROLE_LABELS[r] }}</option>
        </select>
      </label>
      <label class="flex flex-col gap-1 text-xs text-stone-500">
        Status
        <select v-model="filters.status" class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" @change="applyFilters">
          <option value="">Semua status</option>
          <option value="active">Aktif</option>
          <option value="inactive">Nonaktif</option>
          <option value="suspended">Suspended</option>
        </select>
      </label>
      <label class="flex flex-col gap-1 text-xs text-stone-500">
        Per halaman
        <select v-model.number="filters.per_page" class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" @change="applyFilters">
          <option v-for="n in PAGE_SIZES" :key="n" :value="n">{{ n }}</option>
        </select>
      </label>
      <button v-if="hasActiveFilters" type="button" class="rounded-lg px-3 py-1.5 text-sm text-stone-500 underline dark:text-stone-400" @click="resetFilters">
        Reset filter
      </button>
    </div>

    <p v-if="loading" class="text-sm text-stone-500 dark:text-stone-400" role="status">Memuat...</p>

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
              {{ hasActiveFilters ? 'Tidak ada pengguna yang cocok dengan filter.' : 'Tidak ada data.' }}
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
                {{ isSalesSeller(user) ? 'Pindah Korsal' : 'Pindah Sales' }}
              </button>
              <button
                v-if="canConvertToSalesKurir(user)"
                type="button"
                class="mr-2 rounded-lg bg-stone-100 px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200"
                @click="convertSales(user)"
              >
                Jadikan Sales-Kurir-Sub
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

    <div v-if="!loading" class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm text-stone-500 dark:text-stone-400">
      <span>Total {{ pageMeta.total }} pengguna · Halaman {{ pageMeta.current_page }} / {{ pageMeta.last_page }}</span>
      <span class="flex gap-2">
        <button type="button" class="rounded-lg bg-stone-100 px-3 py-1.5 text-xs font-medium disabled:opacity-40 dark:bg-stone-800" :disabled="pageMeta.current_page <= 1" @click="goToPage(-1)">Sebelumnya</button>
        <button type="button" class="rounded-lg bg-stone-100 px-3 py-1.5 text-xs font-medium disabled:opacity-40 dark:bg-stone-800" :disabled="pageMeta.current_page >= pageMeta.last_page" @click="goToPage(1)">Berikutnya</button>
      </span>
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
            isSalesSeller(reassigningUser)
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
        <input v-model="candidateSearch" type="search" maxlength="100" placeholder="Cari nama, email, telepon…"
          class="mb-2 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
          @input="onCandidateSearch" />
        <select
          v-model.number="reassignTargetId"
          class="block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
        >
          <option :value="null" disabled>
            — Pilih {{ isSalesSeller(reassigningUser) ? 'Korsal' : 'Sales' }} —
          </option>
          <option v-for="c in reassignCandidates" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <p v-if="candidatesLoading" class="mt-2 text-xs text-stone-400" role="status">Memuat kandidat…</p>
        <p v-else-if="reassignCandidates.length === 0" class="mt-2 text-xs text-stone-400">
          Tidak ada kandidat yang cocok di jaringan ini.
        </p>
        <p v-else-if="candidateTotal > reassignCandidates.length" class="mt-2 text-xs text-stone-400">
          Menampilkan {{ reassignCandidates.length }} dari {{ candidateTotal }} kandidat — persempit dengan pencarian.
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
