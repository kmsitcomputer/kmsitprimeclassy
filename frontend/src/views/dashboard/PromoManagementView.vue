<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AgentPicker from '@/components/ui/AgentPicker.vue'
import {
  listDiscounts,
  createDiscount,
  updateDiscount,
  deleteDiscount,
  listVouchers,
  createVoucher,
  updateVoucher,
  deleteVoucher,
  type ProductDiscountRow,
  type VoucherRow,
} from '@/api/promo'
import { listProducts, type ProductPayload } from '@/api/catalog'
import { useAuthStore } from '@/stores/auth'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'

/**
 * IMP-002 — Promotions: product/variation discounts + vouchers.
 *
 * Super Admin / Agen / Admin manage their OWN branch (agent_id resolved
 * server-side; super_admin must pick an explicit branch via AgentPicker —
 * A1-18 — and every read/write carries that agent_id). Pricing stays
 * server-authoritative: the checkout Quote/Order re-validates every
 * discount/voucher; this UI only configures them.
 *
 * A1-17: a discount/voucher targets a specific product or product-variation
 * (targetless "Semua produk" was a saved-but-never-effective no-op and is
 * rejected by the backend; the UI no longer offers it).
 */
const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')
const loading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const activeTab = ref<'discounts' | 'vouchers'>('discounts')

/** A1-18: super_admin branch selector — required for every read/write. */
const agentId = ref<number | null>(null)

const discounts = ref<ProductDiscountRow[]>([])
const vouchers = ref<VoucherRow[]>([])
const products = ref<Array<{ id: number; name: string; sku: string; has_variations?: boolean; variations?: Array<{ id: number; sku: string; label?: string }> }>>([])

const discountForm = ref({
  name: '',
  product_id: null as number | null,
  product_variation_id: null as number | null,
  percentage: 10,
  is_active: true,
  starts_at: '' as string | null,
  ends_at: '' as string | null,
})

const voucherForm = ref({
  code: '',
  name: '',
  type: 'fixed' as 'percentage' | 'fixed',
  value: 10000,
  is_active: true,
  valid_from: '' as string | null,
  valid_until: '' as string | null,
  product_id: null as number | null,
  product_variation_id: null as number | null,
  max_uses: null as number | null,
})

/** Variations of the discount-form's currently selected product. */
const discountVariations = computed(() =>
  products.value.find((p) => p.id === discountForm.value.product_id)?.variations ?? [],
)
/** Variations of the voucher-form's currently selected product. */
const voucherVariations = computed(() =>
  products.value.find((p) => p.id === voucherForm.value.product_id)?.variations ?? [],
)

// Changing the product resets the previously chosen variation.
watch(() => discountForm.value.product_id, () => { discountForm.value.product_variation_id = null })
watch(() => voucherForm.value.product_id, () => { voucherForm.value.product_variation_id = null })

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const [d, v, p] = await Promise.all([
      listDiscounts(agentId.value ?? undefined),
      listVouchers(agentId.value ?? undefined),
      listProducts().catch(() => ({ products: [] as ProductPayload[] })),
    ])
    discounts.value = d.rows
    vouchers.value = v.rows
    products.value = (p as { products: ProductPayload[] }).products as unknown as typeof products.value
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat data promosi.'
  } finally {
    loading.value = false
  }
}

/** A1-18: branch change reloads the branch's own promo data. */
async function onAgentChange() {
  successMessage.value = ''
  await load()
}

async function saveDiscount() {
  saving.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    // A1-17: always send the explicit target (product + optional variation).
    // A targetless discount is rejected server-side.
    await createDiscount({
      name: discountForm.value.name,
      product_id: discountForm.value.product_id,
      product_variation_id: discountForm.value.product_variation_id,
      percentage: discountForm.value.percentage,
      is_active: discountForm.value.is_active,
      starts_at: discountForm.value.starts_at || null,
      ends_at: discountForm.value.ends_at || null,
    }, agentId.value ?? undefined)
    successMessage.value = 'Diskon disimpan.'
    discountForm.value.name = ''
    discountForm.value.percentage = 10
    discountForm.value.product_variation_id = null
    await load()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menyimpan diskon.'
  } finally {
    saving.value = false
  }
}

// A1-16: toggle calls carry error handling + the agent scope, so a failed
// toggle is visible instead of silently succeeding.
async function toggleDiscount(row: ProductDiscountRow) {
  errorMessage.value = ''
  successMessage.value = ''
  try {
    await updateDiscount(row.id, { is_active: !row.is_active }, agentId.value ?? undefined)
    successMessage.value = row.is_active ? 'Diskon dinonaktifkan.' : 'Diskon diaktifkan.'
    await load()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal mengubah status diskon.'
  }
}

async function removeDiscount(row: ProductDiscountRow) {
  errorMessage.value = ''
  successMessage.value = ''
  try {
    await deleteDiscount(row.id, agentId.value ?? undefined)
    successMessage.value = 'Diskon dihapus.'
    await load()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menghapus diskon.'
  }
}

async function saveVoucher() {
  saving.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    await createVoucher({
      code: voucherForm.value.code,
      name: voucherForm.value.name,
      type: voucherForm.value.type,
      value: voucherForm.value.value,
      is_active: voucherForm.value.is_active,
      valid_from: voucherForm.value.valid_from || null,
      valid_until: voucherForm.value.valid_until || null,
      product_id: voucherForm.value.product_id,
      // A1-17: variation targeting wired through.
      product_variation_id: voucherForm.value.product_variation_id,
      max_uses: voucherForm.value.max_uses,
    }, agentId.value ?? undefined)
    successMessage.value = 'Voucher disimpan.'
    voucherForm.value.code = ''
    voucherForm.value.name = ''
    voucherForm.value.product_variation_id = null
    await load()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menyimpan voucher.'
  } finally {
    saving.value = false
  }
}

async function toggleVoucher(row: VoucherRow) {
  errorMessage.value = ''
  successMessage.value = ''
  try {
    await updateVoucher(row.id, { is_active: !row.is_active }, agentId.value ?? undefined)
    successMessage.value = row.is_active ? 'Voucher dinonaktifkan.' : 'Voucher diaktifkan.'
    await load()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal mengubah status voucher.'
  }
}

async function removeVoucher(row: VoucherRow) {
  errorMessage.value = ''
  successMessage.value = ''
  try {
    await deleteVoucher(row.id, agentId.value ?? undefined)
    successMessage.value = 'Voucher dihapus.'
    await load()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menghapus voucher.'
  }
}

onMounted(load)
</script>

<template>
  <DashboardLayout>
    <div class="space-y-6">
      <div>
        <h1 class="text-2xl font-bold text-slate-800 dark:text-slate-100">Promosi &amp; Voucher</h1>
        <p class="text-sm text-slate-500">
          Diskon produk/variasi dan voucher berlaku di cabang Anda. Harga final selalu dihitung server saat checkout.
        </p>
      </div>

      <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <AgentPicker v-if="isSuperAdmin" v-model="agentId" :allow-all="false" label="Agen" @update:model-value="onAgentChange" />
      </div>

      <div v-if="errorMessage" class="rounded border border-red-300 bg-red-50 p-3 text-red-700">{{ errorMessage }}</div>
      <div v-if="successMessage" class="rounded border border-emerald-300 bg-emerald-50 p-3 text-emerald-700">{{ successMessage }}</div>

      <div class="flex gap-2">
        <AppButton variant="secondary" @click="activeTab = 'discounts'">Diskon</AppButton>
        <AppButton variant="secondary" @click="activeTab = 'vouchers'">Voucher</AppButton>
      </div>

      <!-- Discounts -->
      <div v-if="activeTab === 'discounts'" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <h2 class="mb-3 font-semibold">Buat Diskon Produk</h2>
        <div class="grid gap-3 md:grid-cols-2">
          <input v-model="discountForm.name" placeholder="Nama diskon" class="rounded border border-slate-300 p-2 dark:bg-slate-900" />
          <select v-model.number="discountForm.product_id" class="rounded border border-slate-300 p-2 dark:bg-slate-900">
            <option :value="null">— Pilih produk —</option>
            <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
          <select
            v-if="discountVariations.length"
            v-model.number="discountForm.product_variation_id"
            class="rounded border border-slate-300 p-2 dark:bg-slate-900"
          >
            <option :value="null">Semua variasi produk ini</option>
            <option v-for="v in discountVariations" :key="v.id" :value="v.id">{{ v.label ?? v.sku }}</option>
          </select>
          <input v-model.number="discountForm.percentage" type="number" min="1" max="100" placeholder="Persen (1-100)" class="rounded border border-slate-300 p-2 dark:bg-slate-900" />
          <label class="flex items-center gap-2 text-sm">
            <input v-model="discountForm.is_active" type="checkbox" /> Aktif
          </label>
        </div>
        <div class="mt-3"><AppButton :loading="saving" @click="saveDiscount">Simpan Diskon</AppButton></div>

        <table class="mt-6 w-full text-sm">
          <thead>
            <tr class="border-b text-left text-slate-500">
              <th class="py-1">Nama</th><th class="py-1">Produk</th><th class="py-1">Persen</th><th class="py-1">Aktif</th><th class="py-1"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in discounts" :key="row.id" class="border-b">
              <td class="py-1">{{ row.name }}</td>
              <td class="py-1">{{ row.product?.name ?? 'Semua' }}</td>
              <td class="py-1">{{ row.percentage }}%</td>
              <td class="py-1">
                <button class="text-sky-600" @click="toggleDiscount(row)">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</button>
              </td>
              <td class="py-1"><button class="text-red-600" @click="removeDiscount(row)">Hapus</button></td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Vouchers -->
      <div v-else class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <h2 class="mb-3 font-semibold">Buat Voucher</h2>
        <div class="grid gap-3 md:grid-cols-2">
          <input v-model="voucherForm.code" placeholder="KODE (huruf besar otomatis)" class="rounded border border-slate-300 p-2 dark:bg-slate-900" />
          <input v-model="voucherForm.name" placeholder="Nama voucher" class="rounded border border-slate-300 p-2 dark:bg-slate-900" />
          <select v-model="voucherForm.type" class="rounded border border-slate-300 p-2 dark:bg-slate-900">
            <option value="fixed">Nominal (Rp)</option>
            <option value="percentage">Persen (%)</option>
          </select>
          <input v-model.number="voucherForm.value" type="number" min="1" :max="voucherForm.type === 'percentage' ? 100 : undefined" placeholder="Nilai" class="rounded border border-slate-300 p-2 dark:bg-slate-900" />
          <input v-model.number="voucherForm.max_uses" type="number" min="1" placeholder="Maks. pemakaian (kosong = tak terbatas)" class="rounded border border-slate-300 p-2 dark:bg-slate-900" />
          <select v-model.number="voucherForm.product_id" class="rounded border border-slate-300 p-2 dark:bg-slate-900">
            <option :value="null">Semua produk</option>
            <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
          <select
            v-if="voucherVariations.length"
            v-model.number="voucherForm.product_variation_id"
            class="rounded border border-slate-300 p-2 dark:bg-slate-900"
          >
            <option :value="null">Semua variasi produk ini</option>
            <option v-for="v in voucherVariations" :key="v.id" :value="v.id">{{ v.label ?? v.sku }}</option>
          </select>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="voucherForm.is_active" type="checkbox" /> Aktif
          </label>
        </div>
        <div class="mt-3"><AppButton :loading="saving" @click="saveVoucher">Simpan Voucher</AppButton></div>

        <table class="mt-6 w-full text-sm">
          <thead>
            <tr class="border-b text-left text-slate-500">
              <th class="py-1">Kode</th><th class="py-1">Nama</th><th class="py-1">Nilai</th><th class="py-1">Dipakai</th><th class="py-1">Aktif</th><th class="py-1"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in vouchers" :key="row.id" class="border-b">
              <td class="py-1 font-mono">{{ row.code }}</td>
              <td class="py-1">{{ row.name }}</td>
              <td class="py-1">{{ row.type === 'fixed' ? `Rp ${Number(row.value).toLocaleString('id-ID')}` : `${row.value}%` }}</td>
              <td class="py-1">{{ row.used_count }}<span v-if="row.max_uses"> / {{ row.max_uses }}</span></td>
              <td class="py-1">
                <button class="text-sky-600" @click="toggleVoucher(row)">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</button>
              </td>
              <td class="py-1"><button class="text-red-600" @click="removeVoucher(row)">Hapus</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </DashboardLayout>
</template>