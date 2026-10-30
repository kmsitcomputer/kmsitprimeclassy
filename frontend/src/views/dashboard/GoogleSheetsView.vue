<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AgentPicker from '@/components/ui/AgentPicker.vue'
import { http, ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { formatDateTime } from '@/utils/format'
import { useAuthStore } from '@/stores/auth'
const auth = useAuthStore()
interface Destination {
  id: number
  spreadsheet_id: string
  agent_id: number | null
  spreadsheet_title: string | null
  connection_status: string
  last_tested_at: string | null
  last_error_code: string | null
}
interface Mapping {
  field: string
  label: string
}
type Classification = 'SYSTEM' | 'DERIVED' | 'MANUAL'
interface CatalogColumn {
  field: string
  label: string
  classification: Classification
  allowed_values?: string[]
}
interface CatalogEntry {
  key: string
  name: string
  description: string
  column_count: number
  default_column_count: number
  columns: CatalogColumn[]
  filters: string[]
  legacy_alias: boolean
  suggested_tabs: Record<string, string>
  protected_columns: string[]
  restricted_for_admin: boolean
}
interface Config {
  id: number
  name: string
  destination_id: number
  tab: string
  dataset: string
  columns: Mapping[]
  filters: {
    status?: string
    period?: string
    from?: string
    to?: string
    category_group?: string
  } | null
}
interface Log {
  id: number
  config_id: number
  dataset: string
  tab: string
  status: string
  rows_success: number
  rows_failed: number
  started_at: string
  error_summary: string | null
}
const destinations = ref<Destination[]>([])
const configs = ref<Config[]>([])
const datasets = ref<Record<string, string[]>>({})
const datasetDefaults = ref<Record<string, Mapping[]>>({})
const datasetFilters = ref<Record<string, string[]>>({})
const catalog = ref<Record<string, CatalogEntry>>({})
const moneyPositions = ref<string[]>([])
const logs = ref<Log[]>([])
const connection = ref('not_checked')
const spreadsheetTitle = ref('')
const serviceAccountEmail = ref('')
const lastTestedAt = ref('')
const connectionErrorCode = ref('')
const error = ref('')
const busy = ref(false)
const editing = ref<number | null>(null)
const form = ref({
  name: '',
  destination_id: 0,
  tab: '',
  dataset: 'products',
  columns: [] as Mapping[],
  status: '',
  period: '',
  from: '',
  to: '',
  category_group: '',
})
const spreadsheet = ref('')
const agent = ref<number | null>(null)
const fields = computed(() => datasets.value[form.value.dataset] ?? [])
const supportsPeriod = computed(() =>
  (datasetFilters.value[form.value.dataset] ?? []).includes('period'),
)
const supportsCategoryGroup = computed(() =>
  (datasetFilters.value[form.value.dataset] ?? []).includes('category_group'),
)
const current = computed<CatalogEntry | null>(() => catalog.value[form.value.dataset] ?? null)
const columnMeta = computed(() => {
  const map: Record<string, CatalogColumn> = {}
  for (const c of current.value?.columns ?? []) map[c.field] = c
  return map
})
// Legacy alias stays selectable only while an existing config still uses it.
const pickableDatasets = computed(() =>
  Object.keys(datasets.value).filter(
    (key) => !catalog.value[key]?.legacy_alias || form.value.dataset === key,
  ),
)
const classCounts = computed(() => {
  const counts: Record<Classification, number> = { SYSTEM: 0, DERIVED: 0, MANUAL: 0 }
  for (const c of form.value.columns) {
    const klass = columnMeta.value[c.field]?.classification ?? 'SYSTEM'
    counts[klass]++
  }
  return counts
})
// A dataset with protected columns anchors rows on its identity column ("no").
const missingIdentity = computed(
  () =>
    (current.value?.protected_columns.length ?? 0) > 0 &&
    form.value.columns.length > 0 &&
    !form.value.columns.some((c) => c.field === 'no'),
)
const badgeClass: Record<Classification, string> = {
  SYSTEM: 'bg-stone-100 text-stone-700 dark:bg-stone-800 dark:text-stone-300',
  DERIVED: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
  MANUAL: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
}
const classLabel: Record<Classification, string> = {
  SYSTEM: 'SYSTEM · otomatis dari data',
  DERIVED: 'DERIVED · dihitung otomatis',
  MANUAL: 'MANUAL · diisi staf, dilindungi',
}
function datasetName(key: string) {
  return catalog.value[key]?.name ?? key
}
function lastLog(config: Config) {
  return logs.value.find((l) => l.config_id === config.id) ?? null
}
const statusText: Record<string, string> = {
  success: 'Berhasil',
  failed: 'Gagal',
  running: 'Berjalan',
}
function applyTab(tab: string, group: string) {
  form.value.tab = tab
  form.value.category_group = group
}
const inputClass =
  'mt-1 block w-full rounded-lg border border-stone-300 bg-white p-2 text-sm dark:border-stone-700 dark:bg-stone-900'
async function run(action: () => Promise<void>) {
  busy.value = true
  error.value = ''
  try {
    await action()
  } catch (e) {
    error.value = e instanceof ApiError ? formatApiError(e) : 'Operasi gagal. Silakan coba lagi.'
  } finally {
    busy.value = false
  }
}
async function registerDestination() {
  await run(async () => {
    await http.post('/google-sheets/destinations', {
      spreadsheet_id: spreadsheet.value,
      agent_id: agent.value,
    })
    spreadsheet.value = ''
    await load()
  })
}

async function load() {
  const { data } = await http.get('/google-sheets')
  destinations.value = data.data.destinations
  configs.value = data.data.configs
  datasets.value = data.data.datasets
  datasetDefaults.value = data.data.dataset_defaults ?? {}
  datasetFilters.value = data.data.dataset_filters ?? {}
  catalog.value = data.data.dataset_catalog ?? {}
  moneyPositions.value = data.data.money_positions ?? []
  logs.value = data.data.logs
  serviceAccountEmail.value = data.data.service_account_email ?? ''
  if (!data.data.enabled) connection.value = 'disabled'
  const selected = destinations.value.find((item) => item.id === form.value.destination_id)
  if (selected) applyDestinationStatus(selected)
}
function applyDestinationStatus(destination?: Destination) {
  connection.value = destination?.connection_status ?? 'not_checked'
  spreadsheetTitle.value = destination?.spreadsheet_title ?? ''
  lastTestedAt.value = destination?.last_tested_at ?? ''
  connectionErrorCode.value = destination?.last_error_code ?? ''
}
async function testConnection() {
  busy.value = true
  error.value = ''
  connectionErrorCode.value = ''
  try {
    const { data } = await http.post('/google-sheets/connection', {
      destination_id: form.value.destination_id,
    })
    connection.value = data.data.status
    spreadsheetTitle.value = data.data.spreadsheet_title
    serviceAccountEmail.value = data.data.service_account_email ?? serviceAccountEmail.value
    lastTestedAt.value = data.data.last_tested_at
    await load()
  } catch (e) {
    connection.value = 'failed'
    if (e instanceof ApiError) {
      connectionErrorCode.value = e.errors?.code?.[0] ?? ''
      error.value = e.message
    } else {
      error.value = 'Operasi gagal. Silakan coba lagi.'
    }
    await load()
  } finally {
    busy.value = false
  }
}
function reset() {
  editing.value = null
  form.value = {
    name: '',
    destination_id: destinations.value[0]?.id ?? 0,
    tab: '',
    dataset: defaultDataset(),
    columns: [],
    status: '',
    period: '',
    from: '',
    to: '',
    category_group: '',
  }
  resetColumnsToDefault()
  applyDestinationStatus(destinations.value[0])
}
// The newest transaction worksheet is the format the Owner expects first.
function defaultDataset() {
  const keys = Object.keys(datasets.value)
  return keys.includes('transaction_worksheet') ? 'transaction_worksheet' : (keys[0] ?? 'products')
}
function select(field: string, checked: boolean) {
  form.value.columns = form.value.columns.filter((c) => c.field !== field)
  if (checked) form.value.columns.push({ field, label: field })
}
function moveColumn(index: number, direction: -1 | 1) {
  const target = index + direction
  if (target < 0 || target >= form.value.columns.length) return
  const columns = [...form.value.columns]
  const current = columns[index]!
  columns[index] = columns[target]!
  columns[target] = current
  form.value.columns = columns
}
function resetDatasetFields() {
  resetColumnsToDefault()
  form.value.status = ''
  form.value.period = ''
  form.value.from = ''
  form.value.to = ''
  form.value.category_group = ''
}
function resetColumnsToDefault() {
  form.value.columns = (datasetDefaults.value[form.value.dataset] ?? []).map((column) => ({ ...column }))
}
async function save() {
  await run(async () => {
    const custom = supportsPeriod.value && form.value.period === 'custom'
    const payload = {
      ...form.value,
      filters: {
        status: form.value.status || null,
        period: supportsPeriod.value && form.value.period ? form.value.period : null,
        from: custom ? form.value.from || null : null,
        to: custom ? form.value.to || null : null,
        category_group:
          supportsCategoryGroup.value && form.value.category_group
            ? form.value.category_group
            : null,
      },
    }
    if (editing.value) await http.put(`/google-sheets/configs/${editing.value}`, payload)
    else await http.post('/google-sheets/configs', payload)
    await load()
    reset()
  })
}
function edit(config: Config) {
  editing.value = config.id
  form.value = {
    ...config,
    columns: config.columns.map((c) => ({ ...c })),
    status: config.filters?.status ?? '',
    period: config.filters?.period ?? '',
    from: config.filters?.from ?? '',
    to: config.filters?.to ?? '',
    category_group: config.filters?.category_group ?? '',
  }
}
async function sync(config: Config) {
  await run(async () => {
    await http.post(`/google-sheets/configs/${config.id}/sync`)
    await load()
  })
}
async function remove(config: Config) {
  if (!window.confirm(`Hapus konfigurasi ${config.name}? Data spreadsheet dan log tetap disimpan.`))
    return
  await run(async () => {
    await http.delete(`/google-sheets/configs/${config.id}`)
    await load()
    reset()
  })
}
onMounted(() =>
  run(async () => {
    await load()
    reset()
  }),
)
</script>
<template>
  <DashboardLayout>
    <div class="mx-auto max-w-5xl space-y-6">
      <div>
        <h1 class="text-2xl font-semibold">Google Sheets Sync</h1>
        <p class="mt-2 text-sm text-stone-500">
          Kirim data aplikasi ke tab khusus Google Sheets. Dataset biasa mengganti seluruh nilai
          pada tab tujuan; Laporan C (worksheet transaksi 47 kolom) hanya menulis kolom otomatis
          dan tidak pernah menimpa kolom MANUAL yang diisi staf. Maksimal 10.000 baris per
          sinkronisasi; bila data melebihi batas, sinkronisasi gagal tanpa memotong data dan
          periode filter harus dipersempit.
        </p>
      </div>
      <p v-if="error" role="alert" class="rounded-lg bg-red-50 p-3 text-red-700">{{ error }}</p>
      <section class="rounded-xl border p-4">
        <h2 class="font-semibold">Koneksi</h2>
        <p class="my-2">Status: {{ connection }}</p>
        <button
          :disabled="busy || !form.destination_id"
          class="rounded-lg bg-stone-800 px-4 py-2 text-white disabled:opacity-50"
          @click="testConnection"
        >
          Periksa koneksi
        </button>
        <p v-if="spreadsheetTitle" class="mt-2 text-sm text-emerald-600">Spreadsheet: {{ spreadsheetTitle }}</p>
        <p v-if="serviceAccountEmail" class="mt-2 text-sm">Service Account: {{ serviceAccountEmail }}</p>
        <p v-if="lastTestedAt" class="mt-2 text-sm">Terakhir diuji: {{ formatDateTime(lastTestedAt) }}</p>
        <p v-if="connectionErrorCode" class="mt-2 text-sm text-red-700">Kode error: {{ connectionErrorCode }}</p>
      </section>
      <form
        v-if="['super_admin', 'agen', 'admin'].includes(auth.user?.role || '')"
        class="space-y-3 rounded-xl border p-4"
        @submit.prevent="registerDestination"
      >
        <h2 class="font-semibold">Daftarkan spreadsheet tujuan</h2>
        <p class="text-sm text-stone-500">
          Tetapkan pemilik spreadsheet sebelum membuat konfigurasi. Bagikan spreadsheet ke service
          account melalui Google Sheets.
        </p>
        <label class="block"
          >Spreadsheet ID<input v-model="spreadsheet" required :class="inputClass"
        /></label>
        <AgentPicker v-if="auth.user?.role === 'super_admin'" v-model="agent" :allow-all="true" label="Scope Agen (kosong = global)" />
        <button :disabled="busy" class="rounded-lg bg-stone-800 px-4 py-2 text-white">
          Daftarkan
        </button>
      </form>
      <form class="space-y-3 rounded-xl border p-4" @submit.prevent="save">
        <h2 class="font-semibold">{{ editing ? 'Edit konfigurasi' : 'Konfigurasi baru' }}</h2>
        <p v-if="!destinations.length" class="text-sm">
          Belum ada spreadsheet untuk network ini. Daftarkan spreadsheet tujuan pada formulir di
          atas terlebih dahulu.
        </p>
        <label class="block"
          >Nama<input v-model="form.name" required maxlength="150" :class="inputClass"
        /></label>
        <label class="block"
          >Spreadsheet<select v-model.number="form.destination_id" required :class="inputClass" @change="applyDestinationStatus(destinations.find((item) => item.id === form.destination_id))">
            <option :value="0" disabled>Pilih spreadsheet</option>
            <option v-for="d in destinations" :key="d.id" :value="d.id">
              {{ d.spreadsheet_id }} — {{ d.agent_id ? `Agen ${d.agent_id}` : 'Global' }}
            </option>
          </select></label
        >
        <label class="block"
          >Tab khusus ekspor<input v-model="form.tab" required maxlength="100" :class="inputClass"
        /></label>
        <label class="block"
          >Dataset<select
            v-model="form.dataset"
            :class="inputClass"
            @change="resetDatasetFields"
          >
            <option v-for="key in pickableDatasets" :key="key" :value="key">
              {{ datasetName(key) }}{{ catalog[key] ? ` — ${catalog[key].column_count} kolom` : '' }}
            </option>
          </select></label
        >
        <section
          v-if="current"
          data-testid="dataset-info"
          class="space-y-2 rounded-lg border border-stone-200 p-3 text-sm dark:border-stone-700"
        >
          <p class="font-medium">{{ current.name }} <span class="font-mono text-xs text-stone-500">({{ current.key }})</span></p>
          <p class="text-stone-600 dark:text-stone-400">{{ current.description }}</p>
          <p>
            {{ current.column_count }} kolom tersedia · {{ form.columns.length }} dikirim ·
            <span v-for="k in (['SYSTEM', 'DERIVED', 'MANUAL'] as Classification[])" :key="k" class="mr-1 inline-block rounded px-2 py-0.5 text-xs" :class="badgeClass[k]">{{ k }} {{ classCounts[k] }}</span>
          </p>
          <p v-if="current.protected_columns.length" class="text-amber-700 dark:text-amber-300">
            Kolom MANUAL dilindungi: sinkronisasi memperbarui kolom otomatis per baris
            (identitas kolom "No" = item pesanan), tidak pernah menghapus atau menimpa isian staf,
            dan aman diulang. Baris yang keluar dari filter tetap dipertahankan apa adanya.
          </p>
          <p v-if="missingIdentity" role="alert" class="text-red-700">
            Kolom "No" wajib dikirim agar baris dapat dikenali dan data manual tidak bergeser.
          </p>
          <p v-if="moneyPositions.length && current.protected_columns.includes('money_position')">
            Nilai Posisi Uang yang valid: <strong>{{ moneyPositions.join(' · ') }}</strong>.
          </p>
          <div v-if="Object.keys(current.suggested_tabs).length" class="flex flex-wrap items-center gap-2">
            <span>Worksheet tujuan:</span>
            <button
              v-for="(group, tab) in current.suggested_tabs"
              :key="tab"
              type="button"
              class="rounded border px-2 py-1 text-xs"
              :class="form.tab === tab ? 'border-brand-600 font-semibold' : ''"
              @click="applyTab(String(tab), group)"
            >
              {{ tab }} ({{ group === 'cake' ? 'kategori Cake' : 'kategori lainnya' }})
            </button>
          </div>
        </section>
        <p v-if="form.dataset === 'korsal_fees'" class="text-sm">
          Fee Korsal adalah agregasi komisi Sales dalam hierarkinya.
        </p>
        <p v-if="form.dataset === 'products'" class="text-sm">
          Untuk scope Agen, produk mengikuti pencatatan stok network tersebut.
        </p>
        <fieldset>
          <legend>Kolom yang dikirim</legend>
          <button v-if="datasetDefaults[form.dataset]" type="button" class="ml-3 rounded border px-2 py-1 text-xs" @click="resetColumnsToDefault">Reset ke Default</button>
          <label
            v-for="field in fields"
            :key="field"
            class="mr-4 inline-flex items-center gap-2 py-2"
            ><input
              type="checkbox"
              :checked="form.columns.some((c) => c.field === field)"
              @change="select(field, ($event.target as HTMLInputElement).checked)"
            />{{ columnMeta[field]?.label ?? field }}
            <span v-if="columnMeta[field]" class="rounded px-1.5 py-0.5 text-[10px]" :class="badgeClass[columnMeta[field]!.classification]">{{ columnMeta[field]!.classification }}</span></label
          >
        </fieldset>
        <div v-if="form.columns.length" data-testid="header-preview">
          <p class="text-sm font-medium">Preview header worksheet ({{ form.columns.length }} kolom)</p>
          <div class="mt-1 overflow-x-auto rounded-lg border border-stone-200 dark:border-stone-700">
            <table class="min-w-full text-xs">
              <thead>
                <tr>
                  <th
                    v-for="(column, index) in form.columns"
                    :key="column.field"
                    class="whitespace-nowrap border-r px-2 py-1 text-left font-semibold last:border-r-0"
                    :class="columnMeta[column.field]?.classification === 'MANUAL' ? 'bg-amber-50 dark:bg-amber-900/20' : ''"
                    :title="columnMeta[column.field]?.classification"
                  >
                    {{ index + 1 }}. {{ column.label }}
                  </th>
                </tr>
              </thead>
            </table>
          </div>
        </div>
        <div v-for="(column, index) in form.columns" :key="column.field" class="flex items-end gap-2">
          <label class="block flex-1">Judul kolom {{ column.field
            }}<span v-if="columnMeta[column.field]" class="ml-2 rounded px-1.5 py-0.5 text-[10px]" :title="classLabel[columnMeta[column.field]!.classification]" :class="badgeClass[columnMeta[column.field]!.classification]">{{ columnMeta[column.field]!.classification }}</span><input v-model="column.label" required maxlength="100" :class="inputClass"
          /></label>
          <button type="button" :disabled="index === 0" class="rounded border px-3 py-2 disabled:opacity-30" @click="moveColumn(index, -1)">↑</button>
          <button type="button" :disabled="index === form.columns.length - 1" class="rounded border px-3 py-2 disabled:opacity-30" @click="moveColumn(index, 1)">↓</button>
        </div>
        <label v-if="fields.includes('status')" class="block"
          >Filter status (opsional)<input v-model="form.status" maxlength="40" :class="inputClass"
        /></label>
        <label v-if="supportsPeriod" class="block"
          >Periode laporan (opsional)<select v-model="form.period" :class="inputClass">
            <option value="">Semua periode</option>
            <option value="daily">Harian</option>
            <option value="weekly">Mingguan</option>
            <option value="monthly">Bulanan</option>
            <option value="custom">Rentang tanggal kustom</option>
          </select></label
        >
        <label v-if="supportsCategoryGroup" class="block"
          >Kelompok kategori produk (opsional)<select v-model="form.category_group" :class="inputClass">
            <option value="">Semua kategori</option>
            <option value="cookies">Cookies &amp; kategori lain (COOKIES_2026)</option>
            <option value="cake">Cake (CAKE_2026)</option>
          </select></label
        >
        <template v-if="supportsPeriod && form.period === 'custom'">
          <label class="block"
            >Dari tanggal<input v-model="form.from" type="date" :class="inputClass"
          /></label>
          <label class="block"
            >Sampai tanggal<input v-model="form.to" type="date" :class="inputClass"
          /></label>
        </template>
        <p v-if="supportsPeriod" class="text-sm text-stone-500">
          Periode harian/mingguan/bulanan dihitung saat sinkronisasi berjalan mengikuti kalender
          WIB (Asia/Jakarta), minggu mulai Senin. Bila data melebihi 10.000 baris, sinkronisasi
          gagal dan periode harus dipersempit.
        </p>
        <p class="text-sm text-stone-500">Mode: manual · Aplikasi → Google Sheets</p>
        <button
          :disabled="busy || !form.destination_id || !form.columns.length || missingIdentity"
          class="rounded-lg bg-stone-800 px-4 py-2 text-white disabled:opacity-50"
        >
          Simpan
        </button>
        <button v-if="editing" type="button" class="ml-3" @click="reset">Batal</button>
      </form>
      <section class="space-y-3">
        <h2 class="font-semibold">Konfigurasi tersimpan</h2>
        <article v-for="config in configs" :key="config.id" class="rounded-xl border p-4">
          <h3 class="font-medium">{{ config.name }}</h3>
          <p class="text-sm">
            {{ datasetName(config.dataset) }} · {{ config.columns.length }} kolom → worksheet
            <strong>{{ config.tab }}</strong>
            <span v-if="config.filters?.category_group"> · kelompok {{ config.filters.category_group }}</span>
          </p>
          <p class="my-2 text-sm" data-testid="config-status">
            Status konfigurasi: Tersimpan · Sync terakhir:
            {{ lastLog(config) ? (statusText[lastLog(config)!.status] ?? lastLog(config)!.status) : 'Belum pernah' }}
            <span v-if="lastLog(config)"> ({{ formatDateTime(lastLog(config)!.started_at) }}, {{ lastLog(config)!.rows_success }} baris)</span>
          </p>
          <p v-if="lastLog(config)?.error_summary" role="alert" class="mb-2 text-sm text-red-700">
            Kesalahan sinkronisasi: {{ lastLog(config)!.error_summary }}
          </p>
          <div class="flex gap-4">
            <button
              :disabled="busy"
              class="rounded-lg bg-stone-800 px-3 py-2 text-white"
              @click="sync(config)"
            >
              Sync now</button
            ><button :disabled="busy" @click="edit(config)">Edit</button
            ><button :disabled="busy" @click="remove(config)">Hapus konfigurasi</button>
          </div>
        </article>
      </section>
      <section>
        <h2 class="mb-3 font-semibold">Sync logs (100 terbaru)</h2>
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead>
              <tr>
                <th>Waktu</th>
                <th>Dataset / Tab</th>
                <th>Status</th>
                <th>Baris sukses / gagal</th>
                <th>Error</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="log in logs" :key="log.id" class="border-t">
                <!-- Technical sync log keeps the exact time. -->
                <td class="py-3">{{ formatDateTime(log.started_at) }}</td>
                <td>{{ log.dataset }} / {{ log.tab }}</td>
                <td>{{ log.status }}</td>
                <td>{{ log.rows_success }} / {{ log.rows_failed }}</td>
                <td>{{ log.error_summary }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </DashboardLayout>
</template>
