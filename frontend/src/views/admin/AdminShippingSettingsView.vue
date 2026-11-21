<script setup lang="ts">
import PrimeFileUpload from '@/components/ui/PrimeFileUpload.vue'
import { ref, onMounted } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import {
  listShippingProviders,
  toggleShippingProvider,
  listShippingCouriers,
  createShippingCourier,
  toggleShippingCourier,
  importRegionsCsv,
  regionsExportUrl,
  type ShippingProviderAdmin,
  type ShippingCourierAdmin,
} from '@/api/shippingAdmin'
import { ApiError } from '@/api/client'
import { getCouriersPerAgentReport, type CourierPerAgentRow } from '@/api/reports'

/**
 * Super Admin only (route meta requiresPermission: 'system.shipping.manage').
 * GLOBAL on/off switch only — "Jika keduanya disable maka Free Shipping".
 * Credentials and per-km rates are configured per-agen instead, on each
 * agen's own dashboard (Agen > Shipping Providers).
 */

const providers = ref<ShippingProviderAdmin[]>([])
const loading = ref(true)

async function load() {
  loading.value = true
  providers.value = await listShippingProviders()
  loading.value = false
}
onMounted(load)

/* Kurir per agen — internal staffing count, unrelated to the rate providers above. */
const couriersPerAgent = ref<CourierPerAgentRow[]>([])
const couriersLoading = ref(true)
onMounted(async () => {
  couriersPerAgent.value = await getCouriersPerAgentReport()
  couriersLoading.value = false
})

/* Master kurir ekspedisi (kode/nama yang bisa dipilih per provider) */
const courierMaster = ref<ShippingCourierAdmin[]>([])
const courierMasterLoading = ref(true)
const courierError = ref<string | null>(null)
const newCourier = ref({ code: '', name: '' })
const courierSaving = ref(false)

async function loadCourierMaster() {
  courierMasterLoading.value = true
  try {
    courierMaster.value = await listShippingCouriers()
    courierError.value = null
  } catch (e) {
    courierError.value = e instanceof ApiError ? e.message : 'Gagal memuat master kurir.'
  } finally {
    courierMasterLoading.value = false
  }
}
onMounted(loadCourierMaster)

async function submitCourier() {
  courierSaving.value = true
  courierError.value = null
  try {
    await createShippingCourier({ code: newCourier.value.code.trim(), name: newCourier.value.name.trim() })
    newCourier.value = { code: '', name: '' }
    await loadCourierMaster()
  } catch (e) {
    courierError.value = e instanceof ApiError ? e.message : 'Gagal menambah kurir.'
  } finally {
    courierSaving.value = false
  }
}

async function toggleCourier(courier: ShippingCourierAdmin) {
  courierError.value = null
  try {
    await toggleShippingCourier(courier.id)
    await loadCourierMaster()
  } catch (e) {
    courierError.value = e instanceof ApiError ? e.message : 'Gagal mengubah status kurir.'
  }
}

async function toggle(provider: ShippingProviderAdmin) {
  await toggleShippingProvider(provider.id)
  await load()
}

/* Region CSV import */
const importFile = ref<File | null>(null)
const importing = ref(false)
const importResult = ref<{ provinces: number; regencies: number; districts: number; villages: number } | null>(null)
const importError = ref<string | null>(null)

async function runImport() {
  if (!importFile.value) return
  importing.value = true
  importError.value = null
  try {
    importResult.value = await importRegionsCsv(importFile.value)
  } catch (e) {
    importError.value = e instanceof ApiError ? e.message : 'Gagal mengimpor data wilayah.'
  } finally {
    importing.value = false
  }
}
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">Pengaturan Pengiriman</h1>
    <p class="mb-1 text-sm text-stone-500 dark:text-stone-400">
      Aktifkan/nonaktifkan RajaOngkir dan OpenRoute secara global. KMSIT Routing Server adalah cadangan rute untuk Kurir Toko (bukan metode tersendiri); endpoint diatur oleh masing-masing Agen. Jika semua nonaktif, semua order gratis ongkir.
    </p>
    <p class="mb-4 text-xs font-medium text-amber-600 dark:text-amber-400">
      Kredensial dan tarif per-km kini diatur oleh masing-masing agen di dashboard mereka sendiri — bukan di sini.
      Setiap agen bisa memiliki pengaturan yang berbeda.
    </p>

    <div v-if="loading" class="space-y-3">
      <div v-for="i in 2" :key="i" class="h-16 animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
    </div>

    <div v-else class="space-y-4">
      <div v-for="provider in providers" :key="provider.id" class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <div class="flex items-center justify-between">
          <div>
            <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">{{ provider.name }}</h2>
            <p class="text-xs text-stone-400">{{ provider.code }}</p>
          </div>
          <div class="flex items-center gap-2">
            <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="provider.is_active ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-stone-100 text-stone-500 dark:bg-stone-800'">
              {{ provider.is_active ? 'Aktif' : 'Nonaktif' }}
            </span>
            <AppButton size="sm" variant="secondary" @click="toggle(provider)">{{ provider.is_active ? 'Nonaktifkan' : 'Aktifkan' }}</AppButton>
          </div>
        </div>
      </div>

      <!-- Region CSV -->
      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">Data Wilayah (Provinsi/Kota/Kecamatan/Kelurahan)</h2>
        <p class="mt-1 text-xs text-stone-400">
          Digunakan oleh input alamat di checkout. Ekspor untuk membuat/mengedit data, lalu impor kembali — format CSV keduanya sama persis.
        </p>
        <div class="mt-3 flex flex-wrap items-center gap-3">
          <a :href="regionsExportUrl()" class="flex items-center gap-2 rounded-lg border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 dark:border-stone-700 dark:text-stone-200">
            <AppIcon name="box" :size="16" /> Ekspor CSV
          </a>
          <PrimeFileUpload
            :model-value="importFile ? [importFile] : []"
            accept=".csv,text/csv"
            :max-size-mb="10"
            hint="CSV, maks 10MB"
            :uploading="importing"
            test-id="shipping-import-csv-upload"
            @update:model-value="importFile = $event[0] ?? null"
          />
          <AppButton size="sm" :disabled="!importFile || importing" @click="runImport">{{ importing ? 'Mengimpor...' : 'Impor CSV' }}</AppButton>
        </div>
        <p v-if="importError" class="mt-2 text-xs text-red-600">{{ importError }}</p>
        <p v-if="importResult" class="mt-2 text-xs text-emerald-600">
          Berhasil: {{ importResult.provinces }} provinsi, {{ importResult.regencies }} kota/kabupaten,
          {{ importResult.districts }} kecamatan, {{ importResult.villages }} kelurahan/desa.
        </p>
      </div>

      <!-- Master Kurir Ekspedisi -->
      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">Master Kurir Ekspedisi</h2>
        <p class="mt-1 text-xs text-stone-400">
          Daftar kurir (kode + nama) yang bisa dipilih saat agen mengatur pengiriman. Kode harus unik, huruf kecil, tanpa spasi.
        </p>

        <form class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="submitCourier">
          <label class="text-xs text-stone-500 dark:text-stone-400">
            Kode
            <input
              v-model="newCourier.code"
              required
              maxlength="30"
              pattern="[a-z0-9_\-]+"
              placeholder="jne"
              class="mt-1 block w-32 rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950"
            />
          </label>
          <label class="text-xs text-stone-500 dark:text-stone-400">
            Nama
            <input
              v-model="newCourier.name"
              required
              maxlength="100"
              placeholder="JNE Express"
              class="mt-1 block w-56 rounded-lg border border-stone-200 px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950"
            />
          </label>
          <AppButton size="sm" :disabled="courierSaving || !newCourier.code.trim() || !newCourier.name.trim()" type="submit">
            {{ courierSaving ? 'Menyimpan...' : 'Tambah Kurir' }}
          </AppButton>
        </form>

        <p v-if="courierError" class="mt-2 text-xs text-red-600">{{ courierError }}</p>

        <div v-if="courierMasterLoading" class="mt-3 text-sm text-stone-500">Memuat...</div>
        <div v-else class="overflow-x-auto">
          <table class="mt-3 w-full text-left text-sm">
            <thead class="text-stone-500 dark:text-stone-400">
              <tr>
                <th class="py-1.5">Kode</th>
                <th class="py-1.5">Nama</th>
                <th class="py-1.5">Status</th>
                <th class="py-1.5 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
              <tr v-for="courier in courierMaster" :key="courier.id">
                <td class="py-1.5 font-mono text-xs">{{ courier.code }}</td>
                <td class="py-1.5">{{ courier.name }}</td>
                <td class="py-1.5">
                  <span
                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="courier.is_active ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-stone-100 text-stone-500 dark:bg-stone-800'"
                  >
                    {{ courier.is_active ? 'Aktif' : 'Nonaktif' }}
                  </span>
                </td>
                <td class="py-1.5 text-right">
                  <AppButton size="sm" variant="secondary" @click="toggleCourier(courier)">
                    {{ courier.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                  </AppButton>
                </td>
              </tr>
              <tr v-if="courierMaster.length === 0">
                <td colspan="4" class="py-3 text-center text-stone-400">Belum ada kurir.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Jumlah Kurir per Agen -->
      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">Jumlah Kurir per Agen</h2>
        <p class="mt-1 text-xs text-stone-400">Staf kurir internal yang terdaftar di masing-masing agen.</p>
        <div v-if="couriersLoading" class="mt-3 text-sm text-stone-500">Memuat...</div>
        <div v-else class="overflow-x-auto">
        <table class="mt-3 w-full text-left text-sm">
          <thead class="text-stone-500 dark:text-stone-400">
            <tr><th class="py-1.5">Agen</th><th class="py-1.5 text-right">Jumlah Kurir</th></tr>
          </thead>
          <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
            <tr v-for="row in couriersPerAgent" :key="row.agent_id">
              <td class="py-1.5">{{ row.agent_name }}</td>
              <td class="py-1.5 text-right">{{ row.courier_count }}</td>
            </tr>
            <tr v-if="couriersPerAgent.length === 0"><td colspan="2" class="py-3 text-center text-stone-400">Tidak ada data.</td></tr>
          </tbody>
        </table>
        </div>
      </div>
    </div>
  </DashboardLayout>
</template>
