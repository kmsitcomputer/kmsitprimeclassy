<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import PasswordInput from '@/components/ui/PasswordInput.vue'
import {
  getAgentShippingProviders,
  toggleAgentShippingProvider,
  saveAgentShippingProviderConfig,
  searchRajaOngkirDestinations,
  testRajaOngkirConnection,
  testOpenRouteConnection,
  getAgentShippingCouriers,
  saveAgentShippingCouriers,
  type RajaOngkirDestination,
  type AgentShippingProviderRow,
  type AgentCourierRow,
} from '@/api/agentSettings'
import { ApiError } from '@/api/client'

/**
 * Agen-only: enable/disable RajaOngkir & OpenRoute for this agen's own
 * branch, plus the agen's own credentials (and, for OpenRoute, per-km rate
 * settings) — never another agen's branch, and never shared with them. A
 * provider super_admin has disabled globally stays disabled here regardless
 * of this toggle.
 */

const providers = ref<AgentShippingProviderRow[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)
const togglingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const rajaOngkirForm = reactive({ api_key: '', origin_destination_id: '', origin_label: '', origin_search: 'Kota Bandung' })
const destinationResults = ref<RajaOngkirDestination[]>([])
const searchingDestination = ref(false)
const connectionStatus = ref<'idle' | 'connected' | 'failed'>('idle')
const openRouteForm = reactive({ api_key: '', profile: 'driving-car', price_per_km: '', minimum_distance_km: '', minimum_charge: '', test_latitude: '', test_longitude: '' })
const openRouteConnection = ref<{ distance_km: number; duration_seconds: number } | null>(null)
const openRouteConnectionStatus = ref<'idle' | 'connected' | 'failed'>('idle')

/* ---------- RajaOngkir courier checkboxes: provider-supported list ∩ this agen's own enabled set ---------- */
const courierList = ref<AgentCourierRow[]>([])
const loadingCouriers = ref(false)
const courierSaving = ref(false)
const courierSuccess = ref(false)
const courierError = ref<string | null>(null)

async function loadCouriers(providerId: number) {
  loadingCouriers.value = true
  try {
    courierList.value = await getAgentShippingCouriers(providerId)
  } catch {
    courierList.value = []
  } finally {
    loadingCouriers.value = false
  }
}

function selectAllCouriers() {
  courierList.value = courierList.value.map((c) => ({ ...c, enabled: true }))
}
function selectNoCouriers() {
  courierList.value = courierList.value.map((c) => ({ ...c, enabled: false }))
}

async function saveCouriers() {
  const provider = rajaOngkir()
  if (!provider) return
  courierSaving.value = true
  courierSuccess.value = false
  courierError.value = null
  try {
    const enabledCodes = courierList.value.filter((c) => c.enabled).map((c) => c.code)
    await saveAgentShippingCouriers(provider.id, enabledCodes)
    courierSuccess.value = true
    setTimeout(() => (courierSuccess.value = false), 3000)
  } catch (e) {
    courierError.value = e instanceof ApiError ? e.message : 'Pengaturan kurir gagal disimpan.'
  } finally {
    courierSaving.value = false
  }
}

async function load() {
  loading.value = true
  loadError.value = null
  try {
    providers.value = await getAgentShippingProviders()
    const savedRajaOngkir = providers.value.find((provider) => provider.code === 'rajaongkir')
    if (savedRajaOngkir?.origin && !rajaOngkirForm.origin_label) {
      rajaOngkirForm.origin_label = savedRajaOngkir.origin
    }
    if (savedRajaOngkir) {
      await loadCouriers(savedRajaOngkir.id)
    }
    const savedOpenRoute = providers.value.find((provider) => provider.code === 'openroute')
    if (savedOpenRoute) {
      openRouteForm.profile = savedOpenRoute.profile || 'driving-car'
      openRouteForm.price_per_km = savedOpenRoute.pricing?.price_per_km?.toString() || openRouteForm.price_per_km
      openRouteForm.minimum_distance_km = savedOpenRoute.pricing?.minimum_distance_km?.toString() || openRouteForm.minimum_distance_km
      openRouteForm.minimum_charge = savedOpenRoute.pricing?.minimum_charge?.toString() || openRouteForm.minimum_charge
    }
  } catch (e) {
    loadError.value = e instanceof ApiError ? e.message : 'Gagal memuat penyedia ekspedisi.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function toggle(provider: AgentShippingProviderRow) {
  togglingId.value = provider.id
  try {
    await toggleAgentShippingProvider(provider.id)
    await load()
  } finally {
    togglingId.value = null
  }
}

async function saveRajaOngkir() {
  const rajaOngkir = providers.value.find((p) => p.code === 'rajaongkir')
  if (!rajaOngkir) return
  submitting.value = true
  errors.value = {}
  generalError.value = null
  try {
    const enabledCodes = courierList.value.filter((c) => c.enabled).map((c) => c.code)
    await saveAgentShippingProviderConfig(rajaOngkir.id, {
      config: {
        api_key: rajaOngkirForm.api_key,
        api_version: 'komerce_v2',
        origin_destination_id: rajaOngkirForm.origin_destination_id,
        origin_label: rajaOngkirForm.origin_label,
        origin_search: rajaOngkirForm.origin_search,
        couriers: enabledCodes,
      },
    })
    await load()
  } catch (e) {
    if (e instanceof ApiError) {
      generalError.value = e.message
      errors.value = e.errors ?? {}
    }
  } finally {
    submitting.value = false
  }
}

async function searchOrigins() {
  const provider = rajaOngkir()
  if (!provider || rajaOngkirForm.origin_search.trim().length < 3) return
  searchingDestination.value = true
  try { destinationResults.value = await searchRajaOngkirDestinations(provider.id, rajaOngkirForm.origin_search, rajaOngkirForm.api_key) }
  catch { destinationResults.value = []; generalError.value = 'Pencarian origin RajaOngkir gagal.' }
  finally { searchingDestination.value = false }
}
function selectOrigin(origin: RajaOngkirDestination) {
  rajaOngkirForm.origin_destination_id = origin.id
  rajaOngkirForm.origin_label = origin.label
  destinationResults.value = []
}
async function testConnection() {
  const provider = rajaOngkir()
  if (!provider) return
  connectionStatus.value = 'idle'
  try { await testRajaOngkirConnection(provider.id); connectionStatus.value = 'connected' }
  catch { connectionStatus.value = 'failed' }
}

async function saveOpenRoute() {
  const openRoute = providers.value.find((p) => p.code === 'openroute')
  if (!openRoute) return
  submitting.value = true
  errors.value = {}
  generalError.value = null
  try {
    await saveAgentShippingProviderConfig(openRoute.id, {
      config: { api_key: openRouteForm.api_key, profile: openRouteForm.profile },
      price_per_km: Number(openRouteForm.price_per_km),
      minimum_distance_km: Number(openRouteForm.minimum_distance_km),
      minimum_charge: openRouteForm.minimum_charge ? Number(openRouteForm.minimum_charge) : 0,
    })
    await load()
  } catch (e) {
    if (e instanceof ApiError) {
      generalError.value = e.message
      errors.value = e.errors ?? {}
    }
  } finally {
    submitting.value = false
  }
}

async function testOpenRoute() {
  const provider = openRoute()
  if (!provider) return
  openRouteConnectionStatus.value = 'idle'
  openRouteConnection.value = null
  try {
    openRouteConnection.value = await testOpenRouteConnection(provider.id, Number(openRouteForm.test_latitude), Number(openRouteForm.test_longitude))
    openRouteConnectionStatus.value = 'connected'
  } catch {
    openRouteConnectionStatus.value = 'failed'
  }
}

const rajaOngkir = () => providers.value.find((p) => p.code === 'rajaongkir')
const openRoute = () => providers.value.find((p) => p.code === 'openroute')
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">Ekspedisi Saya</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
      Aktifkan/nonaktifkan RajaOngkir dan OpenRoute serta atur kredensial dan tarif Anda sendiri — terpisah dari agen
      lain, dan tidak memengaruhi status aktif global.
    </p>

    <div v-if="loading" class="space-y-3">
      <div v-for="i in 2" :key="i" class="h-32 animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
    </div>
    <p v-else-if="loadError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ loadError }}</p>

    <div v-else class="space-y-4">
      <!-- RajaOngkir -->
      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <div class="flex items-center justify-between">
          <div>
            <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">RajaOngkir</h2>
            <p class="text-xs text-stone-400">RajaOngkir/Komerce V2 · tarif ekspedisi berdasarkan destination ID resmi.</p>
            <p v-if="rajaOngkir() && !rajaOngkir()!.globally_active" class="mt-1 text-xs font-medium text-amber-600 dark:text-amber-400">
              Dinonaktifkan secara global oleh Super Admin — toggle di sini tidak akan mengaktifkannya.
            </p>
          </div>
          <div class="flex items-center gap-2">
            <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="rajaOngkir()?.is_active ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-stone-100 text-stone-500 dark:bg-stone-800'">
              {{ rajaOngkir()?.is_active ? 'Aktif' : 'Nonaktif' }}
            </span>
            <AppButton size="sm" variant="secondary" :disabled="togglingId === rajaOngkir()?.id" @click="toggle(rajaOngkir()!)">{{ rajaOngkir()?.is_active ? 'Nonaktifkan' : 'Aktifkan' }}</AppButton>
          </div>
        </div>

        <form class="mt-3 space-y-2.5" @submit.prevent="saveRajaOngkir">
          <p v-if="generalError" class="rounded-lg bg-red-50 p-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-400">{{ generalError }}</p>
          <div>
            <label class="mb-1 block text-xs font-medium text-stone-600 dark:text-stone-300">API Key</label>
            <PasswordInput v-model="rajaOngkirForm.api_key" autocomplete="off" />
          </div>
          <div>
            <label class="mb-1 block text-xs font-medium text-stone-600 dark:text-stone-300">Cari origin resmi</label>
            <div class="flex gap-2"><input v-model="rajaOngkirForm.origin_search" type="text" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" /><AppButton size="sm" variant="secondary" type="button" :disabled="searchingDestination" @click="searchOrigins">Cari</AppButton></div>
            <button v-for="origin in destinationResults" :key="origin.id" type="button" class="mt-1 block w-full rounded border p-2 text-left text-xs" @click="selectOrigin(origin)">{{ origin.label }}</button>
            <p v-if="rajaOngkirForm.origin_label" class="mt-1 text-xs text-emerald-600">Origin terpilih: {{ rajaOngkirForm.origin_label }} (ID {{ rajaOngkirForm.origin_destination_id }})</p>
          </div>
          <div class="flex gap-2"><AppButton size="sm" type="submit" :disabled="submitting">{{ submitting ? 'Menyimpan...' : 'Simpan' }}</AppButton><AppButton size="sm" variant="secondary" type="button" @click="testConnection">Test Connection</AppButton></div>
          <p v-if="connectionStatus === 'connected'" class="text-xs text-emerald-600">Connected ✓</p><p v-if="connectionStatus === 'failed'" class="text-xs text-red-600">Failed — periksa API key dan origin.</p>
        </form>
        <p v-if="rajaOngkir()?.configured" class="mt-2 text-xs text-emerald-600">Sudah dikonfigurasi ✓</p>

        <!-- Kurir Aktif: provider-supported courier checkboxes ∩ this agen's own enabled set -->
        <div class="mt-4 border-t border-stone-100 pt-4 dark:border-stone-800">
          <div class="mb-2 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-stone-800 dark:text-stone-100">Kurir Aktif</h3>
            <div class="flex gap-2 text-xs">
              <button type="button" class="font-medium text-brand-600 dark:text-brand-400" @click="selectAllCouriers">Aktifkan Semua</button>
              <span class="text-stone-300">|</span>
              <button type="button" class="font-medium text-stone-500 dark:text-stone-400" @click="selectNoCouriers">Nonaktifkan Semua</button>
            </div>
          </div>
          <p class="mb-2 text-xs text-stone-400">Hanya kurir yang dicentang di sini yang akan muncul saat konsumen checkout menggunakan Ekspedisi.</p>

          <div v-if="loadingCouriers" class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <div v-for="i in 6" :key="i" class="h-9 animate-pulse rounded-lg bg-stone-100 dark:bg-stone-800" />
          </div>
          <p v-else-if="!courierList.length" class="text-xs text-stone-400">Belum ada daftar kurir yang didukung.</p>
          <div v-else class="grid grid-cols-2 gap-x-3 gap-y-2 sm:grid-cols-3">
            <label
              v-for="courier in courierList"
              :key="courier.code"
              class="flex cursor-pointer items-center gap-2 rounded-lg border border-stone-200 px-2.5 py-2 text-sm dark:border-stone-700"
              :class="courier.enabled ? 'bg-brand-50 dark:bg-brand-950/30' : ''"
            >
              <input type="checkbox" v-model="courier.enabled" class="h-4 w-4 accent-brand-600" />
              <span class="text-stone-700 dark:text-stone-200">{{ courier.name }}</span>
            </label>
          </div>

          <p v-if="courierSuccess" class="mt-2 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
            Pengaturan kurir berhasil disimpan.
          </p>
          <p v-if="courierError" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-300">
            Pengaturan kurir gagal disimpan.
          </p>

          <AppButton size="sm" class="mt-3" :disabled="courierSaving || loadingCouriers" @click="saveCouriers">
            {{ courierSaving ? 'Menyimpan...' : 'Simpan Pengaturan' }}
          </AppButton>
        </div>
      </div>

      <!-- OpenRoute -->
      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <div class="flex items-center justify-between">
          <div>
            <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">OpenRoute</h2>
            <p class="text-xs text-stone-400">Ongkir berdasarkan jarak tempuh (per km), plus picker peta di checkout.</p>
            <p v-if="openRoute() && !openRoute()!.globally_active" class="mt-1 text-xs font-medium text-amber-600 dark:text-amber-400">
              Dinonaktifkan secara global oleh Super Admin — toggle di sini tidak akan mengaktifkannya.
            </p>
          </div>
          <div class="flex items-center gap-2">
            <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="openRoute()?.is_active ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-stone-100 text-stone-500 dark:bg-stone-800'">
              {{ openRoute()?.is_active ? 'Aktif' : 'Nonaktif' }}
            </span>
            <AppButton size="sm" variant="secondary" :disabled="togglingId === openRoute()?.id" @click="toggle(openRoute()!)">{{ openRoute()?.is_active ? 'Nonaktifkan' : 'Aktifkan' }}</AppButton>
          </div>
        </div>

        <form class="mt-3 space-y-2.5" @submit.prevent="saveOpenRoute">
          <div>
            <label class="mb-1 block text-xs font-medium text-stone-600 dark:text-stone-300">API Key</label>
            <PasswordInput v-model="openRouteForm.api_key" autocomplete="off" />
          </div>
          <div>
            <label class="mb-1 block text-xs font-medium text-stone-600 dark:text-stone-300">Routing profile</label>
            <select v-model="openRouteForm.profile" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950">
              <option value="driving-car">Driving car</option>
              <option value="driving-hgv">Driving HGV</option>
              <option value="cycling-regular">Cycling regular</option>
              <option value="cycling-electric">Cycling electric</option>
            </select>
          </div>
          <p v-if="openRoute()?.origin_coordinates" class="text-xs text-stone-500">
            Origin Agent: {{ openRoute()!.origin_coordinates!.latitude }}, {{ openRoute()!.origin_coordinates!.longitude }}
          </p>
          <div class="grid grid-cols-3 gap-2.5">
            <div>
              <label class="mb-1 block text-xs font-medium text-stone-600 dark:text-stone-300">Harga/km (Rp)</label>
              <input v-model="openRouteForm.price_per_km" type="number" min="0" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </div>
            <div>
              <label class="mb-1 block text-xs font-medium text-stone-600 dark:text-stone-300">Jarak Minimum (km)</label>
              <input v-model="openRouteForm.minimum_distance_km" type="number" min="0" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
              <p class="mt-0.5 text-[11px] text-stone-400">Di bawah jarak ini: gratis ongkir.</p>
            </div>
            <div>
              <label class="mb-1 block text-xs font-medium text-stone-600 dark:text-stone-300">Biaya Minimum (opsional)</label>
              <input v-model="openRouteForm.minimum_charge" type="number" min="0" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </div>
          </div>
          <div class="grid grid-cols-2 gap-2.5">
            <input v-model="openRouteForm.test_latitude" type="number" step="any" min="-90" max="90" placeholder="Latitude tujuan uji" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            <input v-model="openRouteForm.test_longitude" type="number" step="any" min="-180" max="180" placeholder="Longitude tujuan uji" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          </div>
          <div class="flex gap-2">
            <AppButton size="sm" type="submit" :disabled="submitting">{{ submitting ? 'Menyimpan...' : 'Simpan' }}</AppButton>
            <AppButton size="sm" variant="secondary" type="button" :disabled="!openRouteForm.test_latitude || !openRouteForm.test_longitude" @click="testOpenRoute">Test Connection</AppButton>
          </div>
          <p v-if="openRouteConnectionStatus === 'connected' && openRouteConnection" class="text-xs text-emerald-600">Connected ✓ · {{ openRouteConnection.distance_km }} km · estimasi {{ Math.ceil(openRouteConnection.duration_seconds / 60) }} menit</p>
          <p v-if="openRouteConnectionStatus === 'failed'" class="text-xs text-red-600">Failed — periksa API key, profile, dan koordinat tujuan.</p>
        </form>
        <p v-if="openRoute()?.configured" class="mt-2 text-xs text-emerald-600">Sudah dikonfigurasi ✓</p>
      </div>
      <p v-if="providers.length === 0" class="text-sm text-stone-400">Tidak ada penyedia ekspedisi yang tersedia.</p>
    </div>
  </DashboardLayout>
</template>
