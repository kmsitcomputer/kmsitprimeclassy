<script setup lang="ts">
/**
 * G17-D3 Assisted Order from a CRM customer. The server decides who may place it and recomputes price,
 * stock, fees and shipping; this form only collects lines + destination. The CRM customer (not an account)
 * becomes the buyer origin, and `Idempotency-Key` makes a retried submit replay instead of duplicating.
 */
import { ref, computed } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { formatRupiah } from '@/utils/format'
import { getCrmCustomer, placeAssistedOrder, type CrmCustomer } from '@/api/crm'
import { listProducts } from '@/api/catalog'
import { getCheckoutSteps } from '@/api/checkout'
import { listProvinces, listRegencies, listDistricts, listVillages } from '@/api/regions'
import type { Product, PaymentMethod, RegionOption } from '@/api/types'

const route = useRoute()
const router = useRouter()
const customerId = Number(route.params.id)

const customer = ref<CrmCustomer | null>(null)
const loading = ref(true)
const loadError = ref('')
const paymentMethods = ref<PaymentMethod[]>([])
/**
 * Shipping methods the agent offers (same server list the checkout uses). "Ekspedisi" (RajaOngkir) needs the
 * courier/service picker, which is not part of the assisted-order slice, so it is not offered here.
 */
const shippingMethods = ref<{ code: string; label: string }[]>([])
const rajaongkirOnly = ref(false)

/* ---------- product picker ---------- */
interface Line { product: Product; variationId: number | null; quantity: number }
const search = ref('')
const results = ref<Product[]>([])
const searching = ref(false)
const lines = ref<Line[]>([])

async function runSearch() {
  searching.value = true
  try {
    results.value = (await listProducts({ search: search.value || undefined, per_page: 12 })).products
  } catch {
    results.value = []
  } finally {
    searching.value = false
  }
}

function addLine(product: Product, variationId: number | null) {
  const existing = lines.value.find((l) => l.product.id === product.id && l.variationId === variationId)
  if (existing) existing.quantity += 1
  else lines.value.push({ product, variationId, quantity: 1 })
}

const lineLabel = (l: Line) => {
  const v = l.product.variations.find((x) => x.id === l.variationId)

  return v ? `${l.product.name} · ${v.label}` : l.product.name
}

/* ---------- destination ---------- */
const provinces = ref<RegionOption[]>([])
const regencies = ref<RegionOption[]>([])
const districts = ref<RegionOption[]>([])
const villages = ref<RegionOption[]>([])
const form = ref({
  recipient_name: '', recipient_phone: '', address_line: '', province_id: '', regency_id: '', district_id: '',
  village_id: '', postal_code: '', latitude: '' as number | '', longitude: '' as number | '',
  payment_method_code: '', dp_amount: '' as number | '', delivery_date: '', shipping_method: '',
})

async function onProvince() {
  form.value.regency_id = form.value.district_id = form.value.village_id = ''
  regencies.value = districts.value = villages.value = []
  if (form.value.province_id) regencies.value = await listRegencies(form.value.province_id)
}
async function onRegency() {
  form.value.district_id = form.value.village_id = ''
  districts.value = villages.value = []
  if (form.value.regency_id) districts.value = await listDistricts(form.value.regency_id)
}
async function onDistrict() {
  form.value.village_id = ''
  villages.value = []
  if (form.value.district_id) villages.value = await listVillages(form.value.district_id)
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const [c, steps, prov] = await Promise.all([getCrmCustomer(customerId), getCheckoutSteps(), listProvinces()])
    customer.value = c
    paymentMethods.value = steps.payment_methods ?? []
    const offered = steps.shipping_methods ?? []
    shippingMethods.value = offered.filter((m) => m.code !== 'rajaongkir')
    rajaongkirOnly.value = offered.length > 0 && shippingMethods.value.length === 0
    if (shippingMethods.value.length === 1) form.value.shipping_method = shippingMethods.value[0]?.code ?? ''
    provinces.value = prov
    form.value.recipient_name = c.name
    form.value.recipient_phone = c.phone ?? ''
    form.value.address_line = c.address ?? ''
    await runSearch()
  } catch (e) {
    loadError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat data pesanan.'
  } finally {
    loading.value = false
  }
}
load()

const isDp = computed(() => form.value.payment_method_code === 'down_payment')
const canSubmit = computed(() =>
  lines.value.length > 0 && !!form.value.village_id && form.value.latitude !== '' && form.value.longitude !== ''
  && !!form.value.payment_method_code && !!form.value.recipient_name.trim() && !!form.value.address_line.trim()
  && (!isDp.value || Number(form.value.dp_amount) > 0)
  && !rajaongkirOnly.value && (shippingMethods.value.length === 0 || !!form.value.shipping_method),
)

/* ---------- submit (one key per logical submission) ---------- */
const submitting = ref(false)
const submitError = ref('')
let idempotencyKey = crypto.randomUUID()

async function submit() {
  submitting.value = true
  submitError.value = ''
  try {
    const f = form.value
    const order = await placeAssistedOrder(customerId, {
      items: lines.value.map((l) => ({ product_id: l.product.id, product_variation_id: l.variationId, quantity: l.quantity })),
      payment_method_code: f.payment_method_code,
      shipping_method: f.shipping_method || null,
      recipient_name: f.recipient_name,
      recipient_phone: f.recipient_phone,
      address_line: f.address_line,
      village_id: f.village_id,
      latitude: Number(f.latitude),
      longitude: Number(f.longitude),
      postal_code: f.postal_code,
      delivery_date: f.delivery_date,
      dp_amount: isDp.value ? Number(f.dp_amount) : null,
    }, idempotencyKey)
    idempotencyKey = crypto.randomUUID()
    await router.push({ name: 'order-detail', params: { id: order.id } })
  } catch (e) {
    // The key is intentionally kept: a retry of this same submission must replay, not duplicate.
    submitError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal membuat pesanan.'
  } finally {
    submitting.value = false
  }
}

const inputClass =
  'mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950'
</script>

<template>
  <DashboardLayout>
    <RouterLink :to="{ name: 'crm-customer-detail', params: { id: customerId } }" class="mb-3 inline-block text-sm text-brand-700 hover:underline dark:text-brand-400">
      &larr; Kembali ke pelanggan
    </RouterLink>

    <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>
    <p v-else-if="loadError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ loadError }} <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
    </p>

    <template v-else-if="customer">
      <h1 class="font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Pesanan untuk {{ customer.name }}</h1>
      <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
        Pelanggan CRM menjadi pemilik transaksi. Komisi tetap mengikuti seller pemilik pelanggan, bukan pembuat pesanan.
        Harga, stok, ongkir, dan total dihitung server.
      </p>

      <p v-if="customer.archived_at" class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-300">
        Pelanggan ini diarsipkan; pesanan tidak dapat dibuat.
      </p>

      <form class="space-y-6" @submit.prevent="submit">
        <p v-if="submitError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ submitError }}</p>

        <section class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
          <h2 class="mb-3 text-sm font-semibold text-stone-800 dark:text-stone-100">1. Produk</h2>
          <div class="mb-3 flex gap-2">
            <input v-model="search" type="search" placeholder="Cari produk" class="min-w-0 flex-1 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" @keydown.enter.prevent="runSearch" />
            <button type="button" class="rounded-lg bg-stone-800 px-3 py-2 text-xs font-medium text-white dark:bg-stone-100 dark:text-stone-900" @click="runSearch">Cari</button>
          </div>
          <p v-if="searching" class="text-sm text-stone-500">Mencari...</p>
          <ul v-else class="grid gap-2 sm:grid-cols-2">
            <li v-for="p in results" :key="p.id" class="rounded-xl border border-stone-100 p-2 text-sm dark:border-stone-800">
              <p class="truncate font-medium">{{ p.name }}</p>
              <div v-if="p.has_variations" class="mt-1 flex flex-wrap gap-1">
                <button v-for="v in p.variations" :key="v.id" type="button" class="rounded-lg border border-stone-200 px-2 py-1 text-xs dark:border-stone-700" @click="addLine(p, v.id)">
                  + {{ v.label }} · {{ formatRupiah(v.price) }}
                </button>
              </div>
              <button v-else type="button" class="mt-1 rounded-lg border border-stone-200 px-2 py-1 text-xs dark:border-stone-700" @click="addLine(p, null)">
                + Tambah · {{ formatRupiah(p.base_price ?? 0) }}
              </button>
            </li>
            <li v-if="!results.length" class="text-sm text-stone-400">Tidak ada produk ditemukan.</li>
          </ul>

          <ul v-if="lines.length" class="mt-4 space-y-2 border-t border-stone-100 pt-3 dark:border-stone-800">
            <li v-for="(l, i) in lines" :key="i" class="flex items-center gap-3 text-sm">
              <span class="min-w-0 flex-1 truncate">{{ lineLabel(l) }}</span>
              <input v-model.number="l.quantity" type="number" min="1" max="1000" class="w-20 rounded-lg border border-stone-200 bg-white px-2 py-1 text-right dark:border-stone-700 dark:bg-stone-950" :aria-label="'Jumlah ' + lineLabel(l)" />
              <button type="button" class="text-xs text-red-600" @click="lines.splice(i, 1)">Hapus</button>
            </li>
          </ul>
          <p v-else class="mt-3 text-sm text-stone-400">Belum ada produk dipilih.</p>
        </section>

        <section class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
          <h2 class="mb-3 text-sm font-semibold text-stone-800 dark:text-stone-100">2. Alamat Pengiriman</h2>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="text-sm text-stone-600 dark:text-stone-300">Nama penerima
              <input v-model="form.recipient_name" required maxlength="150" :class="inputClass" />
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Telepon penerima
              <input v-model="form.recipient_phone" type="tel" required maxlength="20" :class="inputClass" />
            </label>
            <label class="text-sm text-stone-600 sm:col-span-2 dark:text-stone-300">Alamat
              <textarea v-model="form.address_line" required rows="2" maxlength="1000" :class="inputClass" />
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Provinsi
              <select v-model="form.province_id" required :class="inputClass" @change="onProvince">
                <option value="" disabled>Pilih...</option>
                <option v-for="o in provinces" :key="o.id" :value="o.id">{{ o.name }}</option>
              </select>
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Kabupaten/Kota
              <select v-model="form.regency_id" required :disabled="!regencies.length" :class="inputClass" @change="onRegency">
                <option value="" disabled>Pilih...</option>
                <option v-for="o in regencies" :key="o.id" :value="o.id">{{ o.name }}</option>
              </select>
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Kecamatan
              <select v-model="form.district_id" required :disabled="!districts.length" :class="inputClass" @change="onDistrict">
                <option value="" disabled>Pilih...</option>
                <option v-for="o in districts" :key="o.id" :value="o.id">{{ o.name }}</option>
              </select>
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Kelurahan/Desa
              <select v-model="form.village_id" required :disabled="!villages.length" :class="inputClass">
                <option value="" disabled>Pilih...</option>
                <option v-for="o in villages" :key="o.id" :value="o.id">{{ o.name }}</option>
              </select>
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Kode pos
              <input v-model="form.postal_code" maxlength="10" :class="inputClass" />
            </label>
            <div class="grid grid-cols-2 gap-3">
              <label class="text-sm text-stone-600 dark:text-stone-300">Latitude
                <input v-model.number="form.latitude" type="number" step="any" min="-90" max="90" required :class="inputClass" />
              </label>
              <label class="text-sm text-stone-600 dark:text-stone-300">Longitude
                <input v-model.number="form.longitude" type="number" step="any" min="-180" max="180" required :class="inputClass" />
              </label>
            </div>
          </div>
          <p class="mt-2 text-xs text-stone-400">Koordinat dipakai untuk ongkir; salin dari peta lokasi pelanggan.</p>
        </section>

        <section class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
          <h2 class="mb-3 text-sm font-semibold text-stone-800 dark:text-stone-100">3. Pembayaran</h2>
          <div class="grid gap-3 sm:grid-cols-3">
            <label class="text-sm text-stone-600 dark:text-stone-300">Metode
              <select v-model="form.payment_method_code" required :class="inputClass">
                <option value="" disabled>Pilih...</option>
                <option v-for="m in paymentMethods" :key="m.code" :value="m.code">{{ m.name }}</option>
              </select>
            </label>
            <label v-if="isDp" class="text-sm text-stone-600 dark:text-stone-300">Nominal DP
              <input v-model.number="form.dp_amount" type="number" min="1" step="any" required :class="inputClass" />
            </label>
            <label class="text-sm text-stone-600 dark:text-stone-300">Tanggal kirim (opsional)
              <input v-model="form.delivery_date" type="date" :class="inputClass" />
            </label>
            <label v-if="shippingMethods.length > 1" class="text-sm text-stone-600 dark:text-stone-300">Metode pengiriman
              <select v-model="form.shipping_method" required :class="inputClass">
                <option value="" disabled>Pilih...</option>
                <option v-for="m in shippingMethods" :key="m.code" :value="m.code">{{ m.label }}</option>
              </select>
            </label>
            <p v-else-if="shippingMethods.length === 1" class="text-sm text-stone-600 dark:text-stone-300">
              Metode pengiriman: <strong>{{ shippingMethods[0]?.label }}</strong>
            </p>
          </div>
          <p v-if="rajaongkirOnly" role="alert" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">
            Cabang ini hanya menawarkan Ekspedisi, yang belum didukung untuk pesanan bantuan. Aktifkan Kurir Online atau Pickup untuk cabang ini.
          </p>
        </section>

        <div class="flex items-center gap-3">
          <button type="submit" :disabled="submitting || !canSubmit || !!customer.archived_at" class="rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50">
            {{ submitting ? 'Membuat pesanan...' : 'Buat Pesanan' }}
          </button>
          <span class="text-xs text-stone-400">Total akhir ditampilkan di detail pesanan setelah dihitung server.</span>
        </div>
      </form>
    </template>
  </DashboardLayout>
</template>
