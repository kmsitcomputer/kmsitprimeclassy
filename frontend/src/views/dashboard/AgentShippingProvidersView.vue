<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import PasswordInput from '@/components/ui/PasswordInput.vue'
import {
  getAgentShippingProviders,
  toggleAgentShippingProvider,
  saveAgentShippingProviderConfig,
  type AgentShippingProviderRow,
} from '@/api/agentSettings'
import { ApiError } from '@/api/client'

/**
 * Agen-only: enable/disable RajaOngkir & OpenRoute for this agen's own
 * branch, plus the agen's own credentials (and, for OpenRoute, per-km rate
 * settings) — never another agen's branch, and never shared with them. A
 * provider super_admin has disabled globally stays disabled here regardless
 * of this toggle.
 */

const COURIER_OPTIONS = ['jne', 'pos', 'tiki']

const providers = ref<AgentShippingProviderRow[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)
const togglingId = ref<number | null>(null)
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const rajaOngkirForm = reactive({ api_key: '', account_type: 'starter' as 'starter' | 'basic' | 'pro', origin_city_id: '', couriers: [] as string[] })
const openRouteForm = reactive({ api_key: '', price_per_km: '', minimum_distance_km: '', minimum_charge: '' })

async function load() {
  loading.value = true
  loadError.value = null
  try {
    providers.value = await getAgentShippingProviders()
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
    await saveAgentShippingProviderConfig(rajaOngkir.id, {
      config: {
        api_key: rajaOngkirForm.api_key,
        account_type: rajaOngkirForm.account_type,
        origin_city_id: rajaOngkirForm.origin_city_id,
        couriers: rajaOngkirForm.couriers,
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

async function saveOpenRoute() {
  const openRoute = providers.value.find((p) => p.code === 'openroute')
  if (!openRoute) return
  submitting.value = true
  errors.value = {}
  generalError.value = null
  try {
    await saveAgentShippingProviderConfig(openRoute.id, {
      config: { api_key: openRouteForm.api_key },
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
            <p class="text-xs text-stone-400">Perhitungan ongkir berdasarkan tarif kurir (JNE/POS/TIKI).</p>
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
          <div class="grid grid-cols-2 gap-2.5">
            <div>
              <label class="mb-1 block text-xs font-medium text-stone-600 dark:text-stone-300">Tipe Akun</label>
              <select v-model="rajaOngkirForm.account_type" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950">
                <option value="starter">Starter</option>
                <option value="basic">Basic</option>
                <option value="pro">Pro</option>
              </select>
            </div>
            <div>
              <label class="mb-1 block text-xs font-medium text-stone-600 dark:text-stone-300">Origin City ID (RajaOngkir)</label>
              <input v-model="rajaOngkirForm.origin_city_id" type="text" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </div>
          </div>
          <div>
            <label class="mb-1 block text-xs font-medium text-stone-600 dark:text-stone-300">Kurir</label>
            <div class="flex gap-3">
              <label v-for="c in COURIER_OPTIONS" :key="c" class="flex items-center gap-1.5 text-sm">
                <input v-model="rajaOngkirForm.couriers" type="checkbox" :value="c" class="accent-brand-600" /> {{ c.toUpperCase() }}
              </label>
            </div>
          </div>
          <AppButton size="sm" type="submit" :disabled="submitting">{{ submitting ? 'Menyimpan...' : 'Simpan' }}</AppButton>
        </form>
        <p v-if="rajaOngkir()?.configured" class="mt-2 text-xs text-emerald-600">Sudah dikonfigurasi ✓</p>
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
          <AppButton size="sm" type="submit" :disabled="submitting">{{ submitting ? 'Menyimpan...' : 'Simpan' }}</AppButton>
        </form>
        <p v-if="openRoute()?.configured" class="mt-2 text-xs text-emerald-600">Sudah dikonfigurasi ✓</p>
      </div>
      <p v-if="providers.length === 0" class="text-sm text-stone-400">Tidak ada penyedia ekspedisi yang tersedia.</p>
    </div>
  </DashboardLayout>
</template>
