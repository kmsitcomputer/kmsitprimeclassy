<script setup lang="ts">
import { ref, computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { formatDate } from '@/utils/format'
import {
  getCrmCustomer,
  updateCrmCustomer,
  archiveCrmCustomer,
  listCrmVisits,
  createCrmVisit,
  updateCrmVisit,
  PROSPECT_PERCENTS,
  VISIT_STATUSES,
  VISIT_STATUS_LABEL,
  type CrmCustomer,
  type CrmVisit,
  type VisitStatus,
} from '@/api/crm'
import { useAuthStore } from '@/stores/auth'
import CrmLinkPanel from '@/components/dashboard/CrmLinkPanel.vue'

const route = useRoute()
const auth = useAuthStore()
const id = Number(route.params.id)

const customer = ref<CrmCustomer | null>(null)
const visits = ref<CrmVisit[]>([])
const loading = ref(true)
const errorMessage = ref('')
const notFound = ref(false)

// Server re-checks ownership on every write; this only hides controls for non-owners.
const canWrite = computed(
  () => ['sales', 'sales-kurir-sub', 'korsal'].includes(auth.user?.role ?? '') && customer.value?.owner_user_id === auth.user?.id,
)

// UI hints that mirror the backend (CrmAccess); the server re-checks every call.
const canPlaceOrder = computed(() => {
  const role = auth.user?.role ?? ''
  if (!customer.value || customer.value.archived_at) return false
  if (['agen', 'korsal'].includes(role)) return true // upline scope is verified server-side

  return canWrite.value
})
const canUnlink = computed(() => canWrite.value || ['agen', 'admin'].includes(auth.user?.role ?? ''))

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [c, v] = await Promise.all([getCrmCustomer(id), listCrmVisits(id)])
    customer.value = c
    visits.value = v
  } catch (e) {
    if (e instanceof ApiError && (e.status === 404 || e.status === 403)) notFound.value = true
    else errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat pelanggan.'
  } finally {
    loading.value = false
  }
}
load()

/* ---------- edit customer ---------- */
const editing = ref(false)
const editForm = ref({ name: '', phone: '', email: '', customer_type: '', relation: '', address: '', prospect_percent: null as number | null, note: '' })
const editError = ref('')
const saving = ref(false)

function openEdit() {
  const c = customer.value!
  editForm.value = {
    name: c.name, phone: c.phone ?? '', email: c.email ?? '', customer_type: c.customer_type ?? '',
    relation: c.relation ?? '', address: c.address ?? '', prospect_percent: c.prospect_percent, note: c.note ?? '',
  }
  editError.value = ''
  editing.value = true
}

async function submitEdit() {
  saving.value = true
  editError.value = ''
  try {
    customer.value = await updateCrmCustomer(id, editForm.value)
    editing.value = false
  } catch (e) {
    editError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menyimpan perubahan.'
  } finally {
    saving.value = false
  }
}

async function toggleArchive() {
  const archived = customer.value?.archived_at != null
  if (!archived && !window.confirm('Arsipkan pelanggan ini? Riwayat kunjungan tetap tersimpan.')) return
  try {
    customer.value = await archiveCrmCustomer(id, archived)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal mengubah status arsip.'
  }
}

/* ---------- visits ---------- */
const today = () => new Date().toISOString().slice(0, 10)
const visitForm = ref<{ visit_date: string; status: VisitStatus; prospect_percent: number | null; note: string; plan_next_date: string }>({
  visit_date: today(), status: 'N', prospect_percent: null, note: '', plan_next_date: '',
})
const editingVisitId = ref<number | null>(null)
const visitError = ref('')
const savingVisit = ref(false)

function resetVisitForm() {
  editingVisitId.value = null
  visitError.value = ''
  visitForm.value = { visit_date: today(), status: 'N', prospect_percent: null, note: '', plan_next_date: '' }
}

function startEditVisit(v: CrmVisit) {
  editingVisitId.value = v.id
  visitError.value = ''
  visitForm.value = {
    visit_date: v.visit_date.slice(0, 10), status: v.status, prospect_percent: v.prospect_percent,
    note: v.note ?? '', plan_next_date: v.plan_next_date ? v.plan_next_date.slice(0, 10) : '',
  }
}

async function submitVisit() {
  savingVisit.value = true
  visitError.value = ''
  try {
    if (editingVisitId.value) await updateCrmVisit(editingVisitId.value, visitForm.value)
    else await createCrmVisit(id, visitForm.value)
    resetVisitForm()
    await load()
  } catch (e) {
    visitError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menyimpan kunjungan.'
  } finally {
    savingVisit.value = false
  }
}

const inputClass =
  'mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950'
const statusBadge: Record<VisitStatus, string> = {
  N: 'bg-stone-100 text-stone-700 dark:bg-stone-800 dark:text-stone-300',
  '1': 'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-300',
  '2': 'bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-300',
  '3': 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300',
  '4': 'bg-pink-100 text-pink-800 dark:bg-pink-950 dark:text-pink-300',
  '5': 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
  '6': 'bg-red-200 text-red-900 dark:bg-red-900 dark:text-red-200',
  '7': 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
}
</script>

<template>
  <DashboardLayout>
    <RouterLink :to="{ name: 'crm-customers' }" class="mb-3 inline-block text-sm text-brand-700 hover:underline dark:text-brand-400">
      &larr; Pelanggan CRM
    </RouterLink>

    <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>

    <div v-else-if="notFound" class="rounded-xl border border-stone-200 bg-white p-8 text-center text-sm text-stone-500 dark:border-stone-800 dark:bg-stone-900">
      Pelanggan tidak ditemukan atau Anda tidak memiliki akses.
    </div>

    <p v-else-if="errorMessage && !customer" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ errorMessage }} <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
    </p>

    <template v-else-if="customer">
      <p v-if="errorMessage" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ errorMessage }}</p>

      <section class="mb-6 rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 class="font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">{{ customer.name }}</h1>
            <span v-if="customer.archived_at" class="mt-1 inline-block rounded-full bg-stone-100 px-2 py-0.5 text-xs text-stone-600 dark:bg-stone-800 dark:text-stone-300">Diarsipkan</span>
          </div>
          <div class="flex flex-wrap gap-2">
            <RouterLink
              v-if="canPlaceOrder"
              :to="{ name: 'crm-assisted-order', params: { id: customer.id } }"
              class="rounded-lg bg-brand-600 px-3 py-2 text-xs font-medium text-white hover:bg-brand-700"
            >Buat Pesanan</RouterLink>
            <button v-if="canWrite" type="button" class="rounded-lg border border-stone-200 px-3 py-2 text-xs font-medium dark:border-stone-700" @click="openEdit">Ubah</button>
            <button v-if="canWrite" type="button" class="rounded-lg border border-stone-200 px-3 py-2 text-xs font-medium dark:border-stone-700" @click="toggleArchive">
              {{ customer.archived_at ? 'Pulihkan' : 'Arsipkan' }}
            </button>
          </div>
        </div>
        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
          <div><dt class="text-stone-500">Telepon</dt><dd>{{ customer.phone ?? '-' }}</dd></div>
          <div><dt class="text-stone-500">Email</dt><dd class="break-all">{{ customer.email ?? '-' }}</dd></div>
          <div><dt class="text-stone-500">Prospek</dt><dd>{{ customer.prospect_percent !== null ? customer.prospect_percent + '%' : '-' }}</dd></div>
          <div><dt class="text-stone-500">Tipe</dt><dd>{{ customer.customer_type ?? '-' }}</dd></div>
          <div><dt class="text-stone-500">Relasi</dt><dd>{{ customer.relation ?? '-' }}</dd></div>
          <div><dt class="text-stone-500">Pemilik</dt><dd>{{ customer.owner?.name ?? '#' + customer.owner_user_id }}</dd></div>
          <div class="sm:col-span-2 lg:col-span-3"><dt class="text-stone-500">Alamat</dt><dd>{{ customer.address ?? '-' }}</dd></div>
          <div v-if="customer.note" class="sm:col-span-2 lg:col-span-3"><dt class="text-stone-500">Catatan</dt><dd class="whitespace-pre-line">{{ customer.note }}</dd></div>
        </dl>
      </section>

      <CrmLinkPanel
        :customer="customer"
        :can-link="canWrite && !customer.archived_at"
        :can-unlink="canUnlink"
        @changed="(c) => (customer = { ...customer!, ...c })"
      />

      <section v-if="canWrite && !customer.archived_at" class="mb-6 rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="mb-3 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ editingVisitId ? 'Ubah Kunjungan' : 'Catat Kunjungan' }}</h2>
        <p v-if="visitError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ visitError }}</p>
        <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="submitVisit">
          <label class="text-sm text-stone-600 dark:text-stone-300">Tanggal
            <input v-model="visitForm.visit_date" type="date" required :class="inputClass" />
          </label>
          <label class="text-sm text-stone-600 dark:text-stone-300">Status
            <select v-model="visitForm.status" required :class="inputClass">
              <option v-for="s in VISIT_STATUSES" :key="s" :value="s">{{ VISIT_STATUS_LABEL[s] }}{{ s === '7' ? ' (D = Deal)' : '' }}</option>
            </select>
          </label>
          <label class="text-sm text-stone-600 dark:text-stone-300">Prospek (%)
            <select v-model="visitForm.prospect_percent" :class="inputClass">
              <option :value="null">-</option>
              <option v-for="p in PROSPECT_PERCENTS" :key="p" :value="p">{{ p }}%</option>
            </select>
          </label>
          <label class="text-sm text-stone-600 dark:text-stone-300">Tindak lanjut / FU berikutnya
            <input v-model="visitForm.plan_next_date" type="date" :min="visitForm.visit_date" :class="inputClass" />
          </label>
          <label class="text-sm text-stone-600 sm:col-span-2 lg:col-span-4 dark:text-stone-300">Catatan
            <textarea v-model="visitForm.note" rows="2" maxlength="2000" :class="inputClass" />
          </label>
          <div class="flex gap-2 sm:col-span-2 lg:col-span-4">
            <button type="submit" :disabled="savingVisit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50">
              {{ savingVisit ? 'Menyimpan...' : editingVisitId ? 'Simpan Perubahan' : 'Simpan Kunjungan' }}
            </button>
            <button v-if="editingVisitId" type="button" class="rounded-lg px-3 py-2 text-sm text-stone-500" @click="resetVisitForm">Batal</button>
          </div>
        </form>
      </section>

      <section>
        <h2 class="mb-3 text-sm font-semibold text-stone-800 dark:text-stone-100">Riwayat Kunjungan</h2>
        <ol v-if="visits.length" class="space-y-3">
          <li v-for="v in visits" :key="v.id" class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
            <div class="flex flex-wrap items-center gap-2 text-sm">
              <span class="font-medium text-stone-800 dark:text-stone-100">{{ formatDate(v.visit_date) }}</span>
              <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusBadge[v.status]">{{ VISIT_STATUS_LABEL[v.status] }}</span>
              <span v-if="v.prospect_percent !== null" class="text-xs text-stone-500">{{ v.prospect_percent }}%</span>
              <button v-if="canWrite" type="button" class="ml-auto text-xs font-medium text-brand-700 dark:text-brand-400" @click="startEditVisit(v)">Ubah</button>
            </div>
            <p v-if="v.note" class="mt-1 whitespace-pre-line text-sm text-stone-600 dark:text-stone-300">{{ v.note }}</p>
            <p v-if="v.plan_next_date" class="mt-1 text-xs text-stone-500">Tindak lanjut: {{ formatDate(v.plan_next_date) }}</p>
          </li>
        </ol>
        <p v-else class="rounded-xl border border-dashed border-stone-300 p-6 text-center text-sm text-stone-400 dark:border-stone-700">Belum ada kunjungan tercatat.</p>
      </section>

      <div v-if="editing" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4" @click.self="editing = false">
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white p-5 sm:rounded-2xl dark:bg-stone-900">
          <h2 class="mb-4 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">Ubah Pelanggan</h2>
          <p v-if="editError" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ editError }}</p>
          <form class="space-y-3" @submit.prevent="submitEdit">
            <label class="block text-sm text-stone-600 dark:text-stone-300">Nama
              <input v-model="editForm.name" type="text" required maxlength="255" :class="inputClass" />
            </label>
            <label class="block text-sm text-stone-600 dark:text-stone-300">Telepon
              <input v-model="editForm.phone" type="tel" maxlength="30" :class="inputClass" />
            </label>
            <label class="block text-sm text-stone-600 dark:text-stone-300">Email
              <input v-model="editForm.email" type="email" maxlength="255" :class="inputClass" />
            </label>
            <div class="grid grid-cols-2 gap-3">
              <label class="block text-sm text-stone-600 dark:text-stone-300">Tipe
                <input v-model="editForm.customer_type" type="text" maxlength="50" :class="inputClass" />
              </label>
              <label class="block text-sm text-stone-600 dark:text-stone-300">Relasi
                <input v-model="editForm.relation" type="text" maxlength="100" :class="inputClass" />
              </label>
            </div>
            <label class="block text-sm text-stone-600 dark:text-stone-300">Alamat
              <textarea v-model="editForm.address" rows="2" maxlength="1000" :class="inputClass" />
            </label>
            <label class="block text-sm text-stone-600 dark:text-stone-300">Prospek (%)
              <select v-model="editForm.prospect_percent" :class="inputClass">
                <option :value="null">-</option>
                <option v-for="p in PROSPECT_PERCENTS" :key="p" :value="p">{{ p }}%</option>
              </select>
            </label>
            <label class="block text-sm text-stone-600 dark:text-stone-300">Catatan
              <textarea v-model="editForm.note" rows="2" maxlength="2000" :class="inputClass" />
            </label>
            <div class="flex justify-end gap-2 pt-2">
              <button type="button" class="rounded-lg px-3 py-2 text-sm text-stone-500" @click="editing = false">Batal</button>
              <button type="submit" :disabled="saving" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50">
                {{ saving ? 'Menyimpan...' : 'Simpan' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </template>
  </DashboardLayout>
</template>
