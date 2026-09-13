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
}
interface Mapping {
  field: string
  label: string
}
interface Config {
  id: number
  name: string
  destination_id: number
  tab: string
  dataset: string
  columns: Mapping[]
  filters: { status?: string } | null
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
const logs = ref<Log[]>([])
const connection = ref('not_checked')
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
})
const spreadsheet = ref('')
const agent = ref<number | null>(null)
const fields = computed(() => datasets.value[form.value.dataset] ?? [])
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
async function load() {
  const { data } = await http.get('/google-sheets')
  destinations.value = data.data.destinations
  configs.value = data.data.configs
  datasets.value = data.data.datasets
  logs.value = data.data.logs
  if (!data.data.enabled) connection.value = 'disabled'
}
function reset() {
  editing.value = null
  form.value = {
    name: '',
    destination_id: destinations.value[0]?.id ?? 0,
    tab: '',
    dataset: 'products',
    columns: [],
    status: '',
  }
}
function select(field: string, checked: boolean) {
  form.value.columns = form.value.columns.filter((c) => c.field !== field)
  if (checked) form.value.columns.push({ field, label: field })
}
function resetDatasetFields() {
  form.value.columns = []
  form.value.status = ''
}
async function save() {
  await run(async () => {
    const payload = { ...form.value, filters: { status: form.value.status || null } }
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
          Kirim data aplikasi ke tab khusus Google Sheets. Sync mengganti seluruh nilai pada tab
          tujuan. Maksimal 10.000 baris per sinkronisasi.
        </p>
      </div>
      <p v-if="error" role="alert" class="rounded-lg bg-red-50 p-3 text-red-700">{{ error }}</p>
      <section class="rounded-xl border p-4">
        <h2 class="font-semibold">Koneksi</h2>
        <p class="my-2">Status: {{ connection }}</p>
        <button
          :disabled="busy"
          class="rounded-lg bg-stone-800 px-4 py-2 text-white disabled:opacity-50"
          @click="
            run(async () => {
              const { data } = await http.post('/google-sheets/connection')
              connection = data.data.status
            })
          "
        >
          Periksa koneksi
        </button>
      </section>
      <form
        v-if="auth.user?.role === 'super_admin'"
        class="space-y-3 rounded-xl border p-4"
        @submit.prevent="
          run(async () => {
            await http.post('/google-sheets/destinations', {
              spreadsheet_id: spreadsheet,
              agent_id: agent,
            })
            spreadsheet = ''
            await load()
          })
        "
      >
        <h2 class="font-semibold">Daftarkan spreadsheet tujuan</h2>
        <p class="text-sm text-stone-500">
          Tetapkan pemilik spreadsheet sebelum membuat konfigurasi. Bagikan spreadsheet ke service
          account melalui Google Sheets.
        </p>
        <label class="block"
          >Spreadsheet ID<input v-model="spreadsheet" required :class="inputClass"
        /></label>
        <AgentPicker v-model="agent" :allow-all="true" label="Scope Agen (kosong = global)" />
        <button :disabled="busy" class="rounded-lg bg-stone-800 px-4 py-2 text-white">
          Daftarkan
        </button>
      </form>
      <form class="space-y-3 rounded-xl border p-4" @submit.prevent="save">
        <h2 class="font-semibold">{{ editing ? 'Edit konfigurasi' : 'Konfigurasi baru' }}</h2>
        <p v-if="!destinations.length" class="text-sm">
          Belum ada spreadsheet untuk network ini. Super Admin perlu mendaftarkan spreadsheet
          tujuan.
        </p>
        <label class="block"
          >Nama<input v-model="form.name" required maxlength="150" :class="inputClass"
        /></label>
        <label class="block"
          >Spreadsheet<select v-model.number="form.destination_id" required :class="inputClass">
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
            <option v-for="(_, key) in datasets" :key="key" :value="key">{{ key }}</option>
          </select></label
        >
        <p v-if="form.dataset === 'korsal_fees'" class="text-sm">
          Fee Korsal adalah agregasi komisi Sales dalam hierarkinya.
        </p>
        <p v-if="form.dataset === 'products'" class="text-sm">
          Untuk scope Agen, produk mengikuti pencatatan stok network tersebut.
        </p>
        <fieldset>
          <legend>Kolom yang dikirim</legend>
          <label
            v-for="field in fields"
            :key="field"
            class="mr-4 inline-flex items-center gap-2 py-2"
            ><input
              type="checkbox"
              :checked="form.columns.some((c) => c.field === field)"
              @change="select(field, ($event.target as HTMLInputElement).checked)"
            />{{ field }}</label
          >
        </fieldset>
        <label v-for="column in form.columns" :key="column.field" class="block"
          >Judul kolom {{ column.field
          }}<input v-model="column.label" required maxlength="100" :class="inputClass"
        /></label>
        <label v-if="fields.includes('status')" class="block"
          >Filter status (opsional)<input v-model="form.status" maxlength="40" :class="inputClass"
        /></label>
        <p class="text-sm text-stone-500">Mode: manual · Aplikasi → Google Sheets</p>
        <button
          :disabled="busy || !form.destination_id || !form.columns.length"
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
          <p class="text-sm">{{ config.dataset }} → {{ config.tab }}</p>
          <p class="my-2 text-sm">
            Sync terakhir:
            {{ logs.find((l) => l.config_id === config.id)?.status ?? 'Belum pernah' }}
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
