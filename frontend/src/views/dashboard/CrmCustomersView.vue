<script setup lang="ts">
import { ref, computed } from 'vue'
import { RouterLink } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import {
  listCrmCustomers,
  createCrmCustomer,
  downloadCrmCustomersXlsx,
  PROSPECT_PERCENTS,
  type CrmCustomer,
  type CrmCustomerPayload,
} from '@/api/crm'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
// Only the owning Sales/SKS may write (BUSINESS-RULES §27); agen/admin/korsal are read-only (OD-01).
const canWrite = computed(() => ['sales', 'sales-kurir-sub', 'korsal'].includes(auth.user?.role ?? ''))
const showOwner = computed(() => !['sales', 'sales-kurir-sub'].includes(auth.user?.role ?? ''))

const items = ref<CrmCustomer[]>([])
const loading = ref(true)
const errorMessage = ref('')
const showArchived = ref(false)
const search = ref('')
// FU = Follow Up (open follow-up date on the latest visit), D = Deal (latest visit status 7). Not stored statuses.
const stage = ref<'' | 'FU' | 'D'>('')
const currentPage = ref(1)
const lastPage = ref(1)

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return items.value
  return items.value.filter((c) => c.name.toLowerCase().includes(q) || (c.phone ?? '').includes(q))
})

async function load(page = 1) {
  loading.value = true
  errorMessage.value = ''
  try {
    const result = await listCrmCustomers({ archived: showArchived.value, page, per_page: 50, stage: stage.value || undefined })
    items.value = result.items
    currentPage.value = result.currentPage
    lastPage.value = result.lastPage
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat pelanggan CRM.'
  } finally {
    loading.value = false
  }
}
load()

function setStage(next: '' | 'FU' | 'D') {
  stage.value = next
  load()
}

function toggleArchived() {
  showArchived.value = !showArchived.value
  load()
}

/* ---------- create ---------- */
const showCreate = ref(false)
const saving = ref(false)
const formError = ref('')
const blank = (): CrmCustomerPayload => ({
  name: '', phone: '', email: '', customer_type: '', relation: '', address: '', prospect_percent: null, note: '',
})
const form = ref<CrmCustomerPayload>(blank())

function openCreate() {
  form.value = blank()
  formError.value = ''
  showCreate.value = true
}

async function submitCreate() {
  saving.value = true
  formError.value = ''
  try {
    await createCrmCustomer(form.value)
    showCreate.value = false
    await load()
  } catch (e) {
    formError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menyimpan pelanggan.'
  } finally {
    saving.value = false
  }
}

const inputClass =
  'mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950'
</script>

<template>
  <DashboardLayout>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Pelanggan CRM</h1>
        <p class="text-sm text-stone-500 dark:text-stone-400">
          Kontak calon pelanggan tanpa akun. Bukan akun konsumen dan tidak memiliki transaksi sendiri.
        </p>
      </div>
      <button
        v-if="canWrite"
        type="button"
        class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
        @click="openCreate"
      >
        Tambah Pelanggan
      </button>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
      <input
        v-model="search"
        type="search"
        placeholder="Cari nama atau telepon (halaman ini)"
        class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm sm:min-w-0 sm:flex-1 sm:w-auto dark:border-stone-700 dark:bg-stone-950"
      />
      <div class="flex gap-1" role="group" aria-label="Tahap">
        <button
          v-for="opt in [{ v: '', t: 'Semua' }, { v: 'FU', t: 'FU (Follow Up)' }, { v: 'D', t: 'D (Deal)' }] as const"
          :key="opt.v"
          type="button"
          :aria-pressed="stage === opt.v"
          class="rounded-lg border px-3 py-2 text-xs font-medium"
          :class="stage === opt.v ? 'border-brand-600 bg-brand-600 text-white' : 'border-stone-200 text-stone-600 hover:bg-stone-50 dark:border-stone-700 dark:text-stone-300 dark:hover:bg-stone-800'"
          @click="setStage(opt.v)"
        >{{ opt.t }}</button>
      </div>
      <button
        type="button"
        class="rounded-lg border border-stone-200 px-3 py-2 text-xs font-medium text-stone-600 hover:bg-stone-50 dark:border-stone-700 dark:text-stone-300 dark:hover:bg-stone-800"
        @click="toggleArchived"
      >
        {{ showArchived ? 'Tampilkan Aktif' : 'Tampilkan Arsip' }}
      </button>
      <button
        type="button"
        class="rounded-lg border border-stone-200 px-3 py-2 text-xs font-medium text-stone-600 hover:bg-stone-50 dark:border-stone-700 dark:text-stone-300 dark:hover:bg-stone-800"
        data-testid="crm-export"
        @click="downloadCrmCustomersXlsx({ archived: showArchived, stage: stage })"
      >
        Unduh .xlsx
      </button>
    </div>

    <p v-if="errorMessage" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ errorMessage }}
      <button type="button" class="ml-2 underline" @click="load(currentPage)">Coba lagi</button>
    </p>

    <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>

    <div v-else-if="!errorMessage" class="overflow-x-auto rounded-xl border border-stone-200 dark:border-stone-800">
      <table class="w-full min-w-[34rem] text-left text-sm">
        <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
          <tr>
            <th class="px-4 py-2">Nama</th>
            <th class="px-4 py-2">Telepon</th>
            <th class="px-4 py-2">Tipe</th>
            <th class="px-4 py-2">Prospek</th>
            <th v-if="showOwner" class="px-4 py-2">Pemilik</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
          <tr v-for="c in filtered" :key="c.id" class="hover:bg-stone-50 dark:hover:bg-stone-900">
            <td class="px-4 py-2">
              <RouterLink :to="{ name: 'crm-customer-detail', params: { id: c.id } }" class="font-medium text-brand-700 hover:underline dark:text-brand-400">
                {{ c.name }}
              </RouterLink>
            </td>
            <td class="px-4 py-2">{{ c.phone ?? '-' }}</td>
            <td class="px-4 py-2">{{ c.customer_type ?? '-' }}</td>
            <td class="px-4 py-2">{{ c.prospect_percent !== null ? c.prospect_percent + '%' : '-' }}</td>
            <td v-if="showOwner" class="px-4 py-2">#{{ c.owner_user_id }}</td>
          </tr>
        </tbody>
      </table>
      <p v-if="filtered.length === 0" class="px-4 py-8 text-center text-stone-400">
        {{ items.length === 0 ? (showArchived ? 'Belum ada pelanggan yang diarsipkan.' : 'Belum ada pelanggan CRM.') : 'Tidak ada yang cocok dengan pencarian.' }}
      </p>
    </div>

    <div v-if="lastPage > 1" class="mt-6 flex items-center justify-center gap-2 text-sm">
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700" :disabled="currentPage <= 1" @click="load(currentPage - 1)">
        Sebelumnya
      </button>
      <span class="text-stone-500">{{ currentPage }} / {{ lastPage }}</span>
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700" :disabled="currentPage >= lastPage" @click="load(currentPage + 1)">
        Berikutnya
      </button>
    </div>

    <div v-if="showCreate" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4" @click.self="showCreate = false">
      <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white p-5 sm:rounded-2xl dark:bg-stone-900">
        <h2 class="mb-4 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">Pelanggan CRM Baru</h2>
        <p v-if="formError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ formError }}</p>
        <form class="space-y-3" @submit.prevent="submitCreate">
          <label class="block text-sm text-stone-600 dark:text-stone-300">Nama
            <input v-model="form.name" type="text" required maxlength="255" :class="inputClass" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">Telepon
            <input v-model="form.phone" type="tel" maxlength="30" :class="inputClass" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">Email
            <input v-model="form.email" type="email" maxlength="255" :class="inputClass" />
          </label>
          <div class="grid grid-cols-2 gap-3">
            <label class="block text-sm text-stone-600 dark:text-stone-300">Tipe
              <input v-model="form.customer_type" type="text" maxlength="50" :class="inputClass" />
            </label>
            <label class="block text-sm text-stone-600 dark:text-stone-300">Relasi
              <input v-model="form.relation" type="text" maxlength="100" :class="inputClass" />
            </label>
          </div>
          <label class="block text-sm text-stone-600 dark:text-stone-300">Alamat
            <textarea v-model="form.address" rows="2" maxlength="1000" :class="inputClass" />
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">Prospek (%)
            <select v-model="form.prospect_percent" :class="inputClass">
              <option :value="null">-</option>
              <option v-for="p in PROSPECT_PERCENTS" :key="p" :value="p">{{ p }}%</option>
            </select>
          </label>
          <label class="block text-sm text-stone-600 dark:text-stone-300">Catatan
            <textarea v-model="form.note" rows="2" maxlength="2000" :class="inputClass" />
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="rounded-lg px-3 py-2 text-sm text-stone-500 dark:text-stone-400" @click="showCreate = false">Batal</button>
            <button type="submit" :disabled="saving" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50">
              {{ saving ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </DashboardLayout>
</template>
