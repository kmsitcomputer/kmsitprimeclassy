<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import {
  listShippingProviders,
  toggleShippingProvider,
  importRegionsCsv,
  regionsExportUrl,
  type ShippingProviderAdmin,
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

async function toggle(provider: ShippingProviderAdmin) {
  await toggleShippingProvider(provider.id)
  await load()
}

/* Region CSV import */
const importFile = ref<File | null>(null)
const importing = ref(false)
const importResult = ref<{ provinces: number; regencies: number; districts: number; villages: number } | null>(null)
const importError = ref<string | null>(null)

function onImportFileSelected(e: Event) {
  importFile.value = (e.target as HTMLInputElement).files?.[0] ?? null
}

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
      Aktifkan/nonaktifkan RajaOngkir dan OpenRoute secara global. Jika keduanya nonaktif, semua order gratis ongkir.
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
          <input type="file" accept=".csv" class="text-xs" @change="onImportFileSelected" />
          <AppButton size="sm" :disabled="!importFile || importing" @click="runImport">{{ importing ? 'Mengimpor...' : 'Impor CSV' }}</AppButton>
        </div>
        <p v-if="importError" class="mt-2 text-xs text-red-600">{{ importError }}</p>
        <p v-if="importResult" class="mt-2 text-xs text-emerald-600">
          Berhasil: {{ importResult.provinces }} provinsi, {{ importResult.regencies }} kota/kabupaten,
          {{ importResult.districts }} kecamatan, {{ importResult.villages }} kelurahan/desa.
        </p>
      </div>

      <!-- Jumlah Kurir per Agen -->
      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">Jumlah Kurir per Agen</h2>
        <p class="mt-1 text-xs text-stone-400">Staf kurir internal yang terdaftar di masing-masing agen.</p>
        <div v-if="couriersLoading" class="mt-3 text-sm text-stone-500">Memuat...</div>
        <table v-else class="mt-3 w-full text-left text-sm">
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
  </DashboardLayout>
</template>
