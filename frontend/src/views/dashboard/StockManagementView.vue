<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AgentPicker from '@/components/ui/AgentPicker.vue'
import { useAuthStore } from '@/stores/auth'
import {
  listProductStocks,
  listVariationStocks,
  adjustStock,
  type ProductStockRow,
  type ProductVariationStockRow,
} from '@/api/stock'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'

const auth = useAuthStore()
const isSuperAdmin = auth.user?.role === 'super_admin'

const tab = ref<'products' | 'variations'>('products')
const loading = ref(false)
const errorMessage = ref('')
const agentIdFilter = ref<number | undefined>(undefined)

const productRows = ref<ProductStockRow[]>([])
const variationRows = ref<ProductVariationStockRow[]>([])

const adjusting = ref<{ kind: 'product' | 'variation'; id: number } | null>(null)
const delta = ref<number>(0)
const reason = ref('')

async function loadProducts() {
  if (isSuperAdmin && !agentIdFilter.value) return
  loading.value = true
  errorMessage.value = ''
  try {
    const { rows } = await listProductStocks(1, agentIdFilter.value)
    productRows.value = rows
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat stok produk.'
  } finally {
    loading.value = false
  }
}

async function loadVariations() {
  if (isSuperAdmin && !agentIdFilter.value) return
  loading.value = true
  errorMessage.value = ''
  try {
    const { rows } = await listVariationStocks(1, agentIdFilter.value)
    variationRows.value = rows
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat stok varian.'
  } finally {
    loading.value = false
  }
}

function switchTab(next: 'products' | 'variations') {
  tab.value = next
  if (next === 'products') loadProducts()
  else loadVariations()
}

function reload() {
  if (tab.value === 'products') loadProducts()
  else loadVariations()
}

function openAdjust(kind: 'product' | 'variation', id: number) {
  adjusting.value = { kind, id }
  delta.value = 0
  reason.value = ''
}

function closeAdjust() {
  adjusting.value = null
}

async function submitAdjust() {
  if (!adjusting.value || !reason.value.trim() || delta.value === 0) return
  try {
    await adjustStock({
      product_id: adjusting.value.kind === 'product' ? adjusting.value.id : undefined,
      product_variation_id: adjusting.value.kind === 'variation' ? adjusting.value.id : undefined,
      delta: delta.value,
      reason: reason.value.trim(),
      agent_id: agentIdFilter.value,
    })
    closeAdjust()
    reload()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menyesuaikan stok. Periksa kembali input Anda.'
  }
}

onMounted(loadProducts)
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Stok Produk</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">Pantau dan sesuaikan stok produk & varian di gudang Anda.</p>

    <div v-if="isSuperAdmin" class="mb-4 flex items-end gap-2">
      <AgentPicker v-model="agentIdFilter" :allow-all="false" />
      <button
        type="button"
        class="rounded-lg bg-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-300 dark:bg-stone-700 dark:text-stone-200"
        @click="reload"
      >
        Terapkan
      </button>
    </div>

    <div class="mb-5 flex gap-2 border-b border-stone-200 dark:border-stone-800">
      <button
        type="button"
        class="border-b-2 px-3 py-2 text-sm font-medium"
        :class="tab === 'products' ? 'border-brand-600 text-brand-700 dark:border-brand-400 dark:text-brand-300' : 'border-transparent text-stone-500 dark:text-stone-400'"
        @click="switchTab('products')"
      >
        Produk
      </button>
      <button
        type="button"
        class="border-b-2 px-3 py-2 text-sm font-medium"
        :class="tab === 'variations' ? 'border-brand-600 text-brand-700 dark:border-brand-400 dark:text-brand-300' : 'border-transparent text-stone-500 dark:text-stone-400'"
        @click="switchTab('variations')"
      >
        Varian
      </button>
    </div>

    <p v-if="errorMessage" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ errorMessage }}
    </p>
    <p v-if="loading" class="text-sm text-stone-500 dark:text-stone-400">Memuat...</p>
    <p v-else-if="isSuperAdmin && !agentIdFilter" class="text-sm text-stone-500 dark:text-stone-400">
      Masukkan ID Agen di atas untuk melihat stok gudangnya.
    </p>

    <div v-else class="overflow-x-auto rounded-2xl border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900">
      <table class="w-full text-sm">
        <thead class="border-b border-stone-200 text-left text-xs uppercase text-stone-400 dark:border-stone-800 dark:text-stone-500">
          <tr>
            <th class="px-4 py-3">{{ tab === 'products' ? 'Produk' : 'SKU / Varian' }}</th>
            <th class="px-4 py-3 text-right">Ada</th>
            <th class="px-4 py-3 text-right">Ditahan</th>
            <th class="px-4 py-3 text-right">Tersedia</th>
            <th v-if="!isSuperAdmin" class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="tab === 'products' && productRows.length === 0">
            <td colspan="5" class="px-4 py-6 text-center text-stone-400 dark:text-stone-500">Tidak ada data.</td>
          </tr>
          <tr
            v-for="row in productRows"
            v-show="tab === 'products'"
            :key="`p-${row.id}`"
            class="border-b border-stone-100 last:border-0 dark:border-stone-800"
          >
            <td class="px-4 py-3">
              <div class="font-medium text-stone-700 dark:text-stone-200">{{ row.product_name }}</div>
              <div class="text-xs text-stone-400 dark:text-stone-500">SKU: {{ row.sku || '-' }}</div>
            </td>
            <td class="px-4 py-3 text-right tabular-nums">{{ row.quantity_on_hand }}</td>
            <td class="px-4 py-3 text-right tabular-nums">{{ row.quantity_reserved }}</td>
            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ row.quantity_available }}</td>
            <td v-if="!isSuperAdmin" class="px-4 py-3 text-right">
              <button
                type="button"
                class="rounded-lg bg-stone-100 px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200"
                @click="openAdjust('product', row.product_id)"
              >
                Sesuaikan
              </button>
            </td>
          </tr>

          <tr v-if="tab === 'variations' && variationRows.length === 0">
            <td colspan="5" class="px-4 py-6 text-center text-stone-400 dark:text-stone-500">Tidak ada data.</td>
          </tr>
          <tr
            v-for="row in variationRows"
            v-show="tab === 'variations'"
            :key="`v-${row.id}`"
            class="border-b border-stone-100 last:border-0 dark:border-stone-800"
          >
            <td class="px-4 py-3 font-medium text-stone-700 dark:text-stone-200">
              {{ row.sku ?? '—' }}<span v-if="row.variation_label"> · {{ row.variation_label }}</span>
            </td>
            <td class="px-4 py-3 text-right tabular-nums">{{ row.quantity_on_hand }}</td>
            <td class="px-4 py-3 text-right tabular-nums">{{ row.quantity_reserved }}</td>
            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ row.quantity_available }}</td>
            <td v-if="!isSuperAdmin" class="px-4 py-3 text-right">
              <button
                type="button"
                class="rounded-lg bg-stone-100 px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200"
                @click="openAdjust('variation', row.product_variation_id)"
              >
                Sesuaikan
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Adjust modal -->
    <div v-if="adjusting" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="closeAdjust">
      <div class="w-full max-w-sm rounded-2xl bg-white p-5 dark:bg-stone-900">
        <h2 class="mb-4 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">Sesuaikan Stok</h2>
        <label class="mb-3 block text-sm text-stone-600 dark:text-stone-300">
          Perubahan (+/-)
          <input
            v-model.number="delta"
            type="number"
            class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
          />
        </label>
        <label class="mb-4 block text-sm text-stone-600 dark:text-stone-300">
          Alasan
          <input
            v-model="reason"
            type="text"
            class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
            placeholder="mis. stok opname, retur, koreksi"
          />
        </label>
        <div class="flex justify-end gap-2">
          <button type="button" class="rounded-lg px-3 py-2 text-sm text-stone-500 dark:text-stone-400" @click="closeAdjust">
            Batal
          </button>
          <button
            type="button"
            class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50"
            :disabled="!reason.trim() || delta === 0"
            @click="submitAdjust"
          >
            Simpan
          </button>
        </div>
      </div>
    </div>
  </DashboardLayout>
</template>
