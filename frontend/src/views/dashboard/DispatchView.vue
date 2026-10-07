<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AgentPicker from '@/components/ui/AgentPicker.vue'
import { listDispatchQueue, listDispatchCouriers, listDispatchRegionOptions, type DispatchCourier, type DispatchRow } from '@/api/dispatch'
import { assignCourier } from '@/api/shipments'
import { useAuthStore } from '@/stores/auth'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { formatDate } from '@/utils/format'

/**
 * IMP-003 — Koordinator-Kurir dispatch workspace.
 *
 * Lists ONLY the branch's dispatchable deliveries (diproses + still
 * unassigned — the backend re-enforces the same invariant as Gudang's
 * queue server-side; this page renders what the API returns and cannot
 * bypass it). Rows are operational: recipient + destination + delivery
 * date + a coarse paid-in-full flag — no financial amounts. Assigning a
 * courier removes the row from the queue.
 *
  * A1-01: a Koordinator-Kurir can also assign a delivery to THEMSELVES
  * ("Kirim sendiri") — the backend creates their Courier executor profile on
  * first self-assignment and the row then only leaves the dispatch queue
  * once they pick it up; they progress it through Pengiriman Saya exactly
  * like a Kurir.
  *
  * UAT-005: each card is one canonical shipment with its own item list
  * (product + quantity + requested delivery date) — never an ambiguous
  * order-level duplicate.
  *
  * UAT-006: "Ambil Pengiriman" (self-assign via the existing courier_id: 0
  * sentinel contract) is separate from "Tugaskan Kurir" (assign another
  * courier). No second claim subsystem; the server re-validates
  * same-agent/eligibility/unassigned state under lock.
 */
const router = useRouter()
const { t } = useI18n()
const auth = useAuthStore()
const isKoordinator = computed(() => auth.user?.role === 'koordinator-kurir')
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')
// A1-18: super_admin picks an explicit branch — the backend refuses to serve
// dispatch without one.
const agentId = ref<number | null>(null)
const rows = ref<DispatchRow[]>([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const loading = ref(true)
const errorMessage = ref('')
const successMessage = ref('')

const filters = ref({
  delivery_date: '',
  province_id: '',
  regency_id: '',
  district_id: '',
  village_id: '',
  paid: '' as '' | 'paid' | 'unpaid',
})

// UAT consolidated Q–W — cascading region filters. Every option list comes ONLY from the
// current eligible Dispatch queue (GET /dispatch/regions), never from the Indonesian master.
// A level stays disabled until its parent carries a specific value; changing a parent resets
// all deeper selections so an incompatible child can never survive.
const regionOptions = ref<{
  provinces: { id: string; name: string }[]
  regencies: { id: string; name: string }[]
  districts: { id: string; name: string }[]
  villages: { id: string; name: string }[]
}>({ provinces: [], regencies: [], districts: [], villages: [] })
const regionsLoading = ref(false)

const regencyEnabled = computed(() => filters.value.province_id !== '')
const districtEnabled = computed(() => regencyEnabled.value && filters.value.regency_id !== '')
const villageEnabled = computed(() => districtEnabled.value && filters.value.district_id !== '')

async function loadRegions() {
  regionsLoading.value = true
  try {
    regionOptions.value = await listDispatchRegionOptions({
      delivery_date: filters.value.delivery_date || undefined,
      paid: filters.value.paid || undefined,
      province_id: filters.value.province_id || undefined,
      regency_id: filters.value.regency_id || undefined,
      district_id: filters.value.district_id || undefined,
      agent_id: agentId.value ?? undefined,
    })
  } catch {
    regionOptions.value = { provinces: [], regencies: [], districts: [], villages: [] }
  } finally {
    regionsLoading.value = false
  }
}

function onProvinceChange() {
  // S: a parent change resets every deeper selection — incompatible children never survive.
  filters.value.regency_id = ''
  filters.value.district_id = ''
  filters.value.village_id = ''
  void loadRegions()
}

function onRegencyChange() {
  filters.value.district_id = ''
  filters.value.village_id = ''
  void loadRegions()
}

function onDistrictChange() {
  filters.value.village_id = ''
  void loadRegions()
}

const couriers = ref<DispatchCourier[]>([])
const selectedCourier = ref<Record<number, number | ''>>({})
const assigningShipmentId = ref<number | null>(null)

async function load(page = 1) {
  loading.value = true
  errorMessage.value = ''
  try {
    const { rows: r, meta: m } = await listDispatchQueue(page, {
      delivery_date: filters.value.delivery_date || undefined,
      province_id: filters.value.province_id || undefined,
      regency_id: filters.value.regency_id || undefined,
      district_id: filters.value.district_id || undefined,
      village_id: filters.value.village_id || undefined,
      paid: filters.value.paid || undefined,
      agent_id: agentId.value ?? undefined,
    })
    rows.value = r
    meta.value = m
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat antrean dispatch.'
  } finally {
    loading.value = false
  }
}

async function loadCouriers() {
  try {
    couriers.value = await listDispatchCouriers(agentId.value ?? undefined)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat daftar kurir.'
  }
}

function applyFilters() {
  load(1)
  // Region options must follow the current queue scope (date/paid branch included).
  void loadRegions()
}

// A1-18: branch change reloads the queue, the courier list and the region options.
async function onAgentChange() {
  successMessage.value = ''
  await Promise.all([load(1), loadCouriers(), loadRegions()])
}

async function assign(row: DispatchRow) {
  const courierId = selectedCourier.value[row.shipment_id]
  if (!courierId || assigningShipmentId.value !== null) return
  assigningShipmentId.value = row.shipment_id
  errorMessage.value = ''
  successMessage.value = ''
  try {
    await assignCourier(row.shipment_id, Number(courierId))
    successMessage.value = 'Kurir ditugaskan. Order keluar dari antrean dispatch.'
    delete selectedCourier.value[row.shipment_id]
    await load(meta.value.current_page)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menugaskan kurir.'
  } finally {
    assigningShipmentId.value = null
  }
}

async function selfAssign(row: DispatchRow) {
  if (!row.self_assignable || assigningShipmentId.value !== null) return
  assigningShipmentId.value = row.shipment_id
  errorMessage.value = ''
  successMessage.value = ''
  try {
    await assignCourier(row.shipment_id, 0) // 0 = self-executor; resolved server-side
    successMessage.value = 'Anda ditugaskan mengantar sendiri. Pengiriman ada di Pengiriman Saya.'
    await load(meta.value.current_page)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menugaskan diri sendiri.'
  } finally {
    assigningShipmentId.value = null
  }
}

function openOrder(orderId: number) {
  void router.push({ name: 'order-detail', params: { id: orderId } })
}

function onPage(d: number) {
  const next = Math.max(1, Math.min(meta.value.last_page, meta.value.current_page + d))
  void load(next)
}

onMounted(() => {
  void load()
  void loadCouriers()
  void loadRegions()
})
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Dispatch</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
      Antrean pengiriman cabang — order diproses yang belum punya kurir. Tugaskan kurir untuk mulai mengantar.
    </p>

    <p v-if="errorMessage" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ errorMessage }}
    </p>
    <p v-if="successMessage" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
      {{ successMessage }}
    </p>

    <!-- Filters — UAT consolidated Q–W: Tanggal Kirim + cascading Provinsi > Kota/Kabupaten >
         Kecamatan > Kelurahan + Status Pembayaran. Region options come ONLY from the current
         eligible Dispatch queue (regionOptions) — a level stays disabled until its parent carries
         a specific value, and changing a parent resets all deeper selections. -->
    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-2xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
      <AgentPicker v-if="isSuperAdmin" v-model="agentId" :allow-all="false" label="Agen" @update:model-value="onAgentChange" />
      <label class="flex flex-col gap-1 text-xs text-stone-500">
        Tanggal kirim
        <input v-model="filters.delivery_date" type="date"
          class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <label class="flex flex-col gap-1 text-xs text-stone-500">
        Provinsi
        <select v-model="filters.province_id" :disabled="regionsLoading"
          class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm disabled:opacity-50 dark:border-stone-700 dark:bg-stone-950"
          @change="onProvinceChange">
          <option value="">Semua Provinsi</option>
          <option v-for="p in regionOptions.provinces" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </label>
      <label class="flex flex-col gap-1 text-xs text-stone-500">
        Kota/Kabupaten
        <select v-model="filters.regency_id" :disabled="!regencyEnabled || regionsLoading"
          class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm disabled:opacity-50 dark:border-stone-700 dark:bg-stone-950"
          @change="onRegencyChange">
          <option value="">Semua Kota/Kabupaten</option>
          <option v-for="r in regionOptions.regencies" :key="r.id" :value="r.id">{{ r.name }}</option>
        </select>
      </label>
      <label class="flex flex-col gap-1 text-xs text-stone-500">
        Kecamatan
        <select v-model="filters.district_id" :disabled="!districtEnabled || regionsLoading"
          class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm disabled:opacity-50 dark:border-stone-700 dark:bg-stone-950"
          @change="onDistrictChange">
          <option value="">Semua Kecamatan</option>
          <option v-for="d in regionOptions.districts" :key="d.id" :value="d.id">{{ d.name }}</option>
        </select>
      </label>
      <label class="flex flex-col gap-1 text-xs text-stone-500">
        Kelurahan
        <select v-model="filters.village_id" :disabled="!villageEnabled || regionsLoading"
          class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm disabled:opacity-50 dark:border-stone-700 dark:bg-stone-950">
          <option value="">Semua Kelurahan</option>
          <option v-for="v in regionOptions.villages" :key="v.id" :value="v.id">{{ v.name }}</option>
        </select>
      </label>
      <label class="flex flex-col gap-1 text-xs text-stone-500">
        Status pembayaran
        <select v-model="filters.paid"
          class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950">
          <option value="">Semua</option>
          <option value="paid">Lunas</option>
          <option value="unpaid">Belum lunas</option>
        </select>
      </label>
      <AppButton size="sm" variant="secondary" @click="applyFilters">Terapkan</AppButton>
    </div>

    <div v-if="loading" class="space-y-3">
      <div v-for="i in 3" :key="i" class="h-24 animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
    </div>

    <div v-else-if="!rows.length" class="flex flex-col items-center gap-3 rounded-2xl bg-stone-100 py-16 text-center dark:bg-stone-900">
      <AppIcon name="truck" :size="40" class="text-stone-300 dark:text-stone-700" />
      <p class="text-sm text-stone-500 dark:text-stone-400">Belum ada pengiriman yang bisa ditugaskan.</p>
    </div>

    <div v-else class="space-y-3">
      <div v-for="row in rows" :key="row.shipment_id" class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <button type="button" class="text-left text-sm font-semibold text-brand-700 hover:underline dark:text-brand-300" @click="openOrder(row.order_id)">
              {{ row.order_no }}
            </button>
            <!-- UAT-005 LOCKED: the Shipment IS the delivery-date unit, so the date leads the card.
                 `delivery_date` comes straight from the canonical dispatch row (server-derived from
                 that shipment's own items) — never computed or guessed in the UI. -->
            <p class="text-sm font-semibold text-stone-800 dark:text-stone-100">
              {{ row.delivery_date.length === 1
                ? t('orders.shipmentDeliveryLabel', { date: formatDate(row.delivery_date[0]!) })
                : t('orders.anyDate') }}
            </p>
            <span class="ml-2 rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-medium text-stone-600 dark:bg-stone-800 dark:text-stone-400">
              Pengiriman #{{ row.shipment_id }}
            </span>
            <span class="ml-2 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-400">
              {{ row.status }}
            </span>
            <span :class="row.paid_in_full ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-rose-50 text-rose-700 dark:bg-rose-950 dark:text-rose-400'"
              class="ml-2 rounded-full px-2.5 py-0.5 text-xs font-medium">
              {{ row.paid_in_full ? 'Lunas' : 'Belum lunas' }}
            </span>
          </div>
          <AppButton size="sm" variant="secondary" @click="openOrder(row.order_id)">Buka order</AppButton>
        </div>

        <div class="mt-2 grid gap-1 text-xs text-stone-600 dark:text-stone-400 sm:grid-cols-2">
          <p>{{ row.recipient_name ?? '—' }} · {{ row.recipient_phone ?? '' }}</p>
          <p>{{ row.address_line ?? '' }}</p>
          <p class="sm:col-span-2">
            {{ [row.village_name, row.district_name, row.regency_name, row.province_name].filter(Boolean).join(', ') || '—' }}
          </p>
        </div>

        <div class="mt-2 flex flex-wrap items-end gap-2 text-xs">
          <span class="rounded-lg bg-stone-100 px-2 py-1 text-stone-600 dark:bg-stone-800 dark:text-stone-400">
            Kirim: {{ row.delivery_date.length ? row.delivery_date.map((d) => formatDate(d)).join(', ') : 'Tanggal apa pun' }}
          </span>
        </div>

        <!-- UAT-005: canonical items of THIS shipment — product + quantity + date + status. -->
        <ul class="mt-2 space-y-1 text-xs text-stone-600 dark:text-stone-300">
          <li v-for="item in row.items ?? []" :key="item.id" class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 rounded-lg bg-stone-50 px-2 py-1 dark:bg-stone-800/60">
            <span class="min-w-0">
              <span class="font-medium">{{ item.product_name }}</span>
              <span v-if="item.variation_label" class="text-stone-500"> — {{ item.variation_label }}</span>
              <span class="text-stone-500"> × {{ item.quantity }}</span>
              <span class="ml-1 text-stone-400">({{ item.status }})</span>
            </span>
            <span class="shrink-0 text-stone-500 dark:text-stone-400">
              Kirim: {{ item.requested_delivery_date ? formatDate(item.requested_delivery_date) : '—' }}
            </span>
          </li>
          <li v-if="!(row.items ?? []).length" class="text-stone-400">Tidak ada rincian item untuk pengiriman ini.</li>
        </ul>

        <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-stone-100 pt-3 dark:border-stone-800">
          <select v-model="selectedCourier[row.shipment_id]"
            class="rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950">
            <option value="">— Pilih kurir —</option>
            <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <AppButton size="sm" :disabled="!selectedCourier[row.shipment_id] || assigningShipmentId !== null" @click="assign(row)">
            {{ assigningShipmentId === row.shipment_id ? 'Menugaskan...' : 'Tugaskan Kurir' }}
          </AppButton>
          <!-- UAT-006: explicit self-assignment for the Koordinator — separate from assigning another
               courier. Calls the existing canonical contract (courier_id: 0 sentinel); the server
               resolves the Koordinator's own executor profile, validates eligibility under lock, and
               the shipment then appears in Pengiriman Saya. -->
          <AppButton
            v-if="isKoordinator && row.self_assignable"
            size="sm"
            variant="secondary"
            :disabled="assigningShipmentId !== null"
            @click="selfAssign(row)"
          >
            {{ assigningShipmentId === row.shipment_id ? 'Mengambil...' : 'Ambil Pengiriman' }}
          </AppButton>
          <span v-if="!couriers.length" class="text-xs text-stone-400">Belum ada kurir aktif di cabang ini.</span>
        </div>
      </div>

      <div v-if="meta.last_page > 1" class="flex items-center justify-between gap-2 text-sm">
        <AppButton size="sm" variant="secondary" :disabled="meta.current_page <= 1" @click="onPage(-1)">Prev</AppButton>
        <span class="text-stone-500">Halaman {{ meta.current_page }} / {{ meta.last_page }} ({{ meta.total }})</span>
        <AppButton size="sm" variant="secondary" :disabled="meta.current_page >= meta.last_page" @click="onPage(1)">Next</AppButton>
      </div>
    </div>
  </DashboardLayout>
</template>