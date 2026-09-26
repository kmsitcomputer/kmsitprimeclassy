<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { listProducts } from '@/api/catalog'
import type { Product, ProductVariation } from '@/api/types'
import {
  approveOpname,
  cancelOpname,
  countOpname,
  createOpname,
  listOpnames,
  rejectOpname,
  submitOpname,
  type StockOpname,
} from '@/api/opnames'
import { listSubLocations, type SubLocation } from '@/api/warehouse'
import { useAuthStore } from '@/stores/auth'
import { skuLabel } from '@/utils/format'

const auth = useAuthStore()
const isAdmin = computed(() => auth.user?.role === 'admin')

const opnames = ref<StockOpname[]>([])
const opnameMeta = ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
const catalog = ref<Product[]>([])
const catalogSearch = ref('')
const catalogLoading = ref(false)
let searchTimer: ReturnType<typeof setTimeout> | null = null
const locations = ref<SubLocation[]>([])

const selectedProduct = ref<Product | null>(null)
const selectedVariation = ref<ProductVariation | null>(null)
const stockType = ref<'transit' | 'factory_plan' | 'sub'>('transit')
const subLocationId = ref<number | null>(null)
const newCount = ref(0)
const counts = ref<Record<number, number>>({})
const rejectReason = ref<Record<number, string>>({})
const error = ref('')
const success = ref('')
const working = ref(false)
const expandedId = ref<number | null>(null)

const STOCK_LABEL: Record<string, string> = { transit: 'Transit', factory_plan: 'Plan Pabrik', sub: 'Sub' }
const STATUS_LABEL: Record<string, string> = { draft: 'Draf', submitted: 'Diajukan', approved: 'Disetujui', rejected: 'Ditolak', cancelled: 'Dibatalkan' }

function thumb(product: Product): string | null {
  return product.images[0]?.url ?? null
}

function itemName(product?: { name: string } | null, variationId?: number | null, productId?: number | null): string {
  return product?.name ?? (variationId != null ? `Varian #${variationId}` : `Produk #${productId}`)
}

async function loadOpnames() {
  const { opnames: rows, meta } = await listOpnames(opnameMeta.value.current_page)
  opnames.value = rows
  opnameMeta.value = meta
}

async function loadCatalog() {
  catalogLoading.value = true
  try {
    const { products } = await listProducts({ search: catalogSearch.value.trim() || undefined, per_page: 12, page: 1 })
    catalog.value = products
  } finally {
    catalogLoading.value = false
  }
}

function onCatalogInput() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => void loadCatalog(), 350)
}

function chooseProduct(product: Product) {
  selectedProduct.value = product
  selectedVariation.value = null
}

function chooseVariation(variation: ProductVariation) {
  selectedVariation.value = variation
}

const canCreate = computed(() => {
  if (!selectedProduct.value) return false
  if (selectedProduct.value.has_variations && !selectedVariation.value) return false
  if (stockType.value === 'sub' && !subLocationId.value) return false
  return true
})

async function create() {
  if (working.value || !canCreate.value || !selectedProduct.value) return
  error.value = ''; success.value = ''
  working.value = true
  try {
    const opname = await createOpname({
      opname_type: stockType.value === 'factory_plan' ? 'plan_reconciliation' : 'physical_opname',
      stock_type: stockType.value,
      sub_location_id: stockType.value === 'sub' ? (subLocationId.value ?? undefined) : undefined,
      items: [{
        product_id: selectedProduct.value.has_variations ? undefined : selectedProduct.value.id,
        product_variation_id: selectedVariation.value?.id,
      }],
    })
    const item = opname.items[0]
    if (!item) throw new Error('Opname gagal dibuat.')
    await countOpname(opname.id, [{ item_id: item.id, counted_quantity: Math.max(0, Math.floor(newCount.value)) }])
    await submitOpname(opname.id)
    success.value = 'Opname diajukan ke Admin.'
    newCount.value = 0
    selectedProduct.value = null
    selectedVariation.value = null
    await loadOpnames()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Gagal membuat opname.'
  } finally {
    working.value = false
  }
}

async function approve(id: number) {
  error.value = ''; success.value = ''
  try {
    await approveOpname(id)
    success.value = 'Opname disetujui.'
    await loadOpnames()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Approval gagal, mungkin snapshot sudah stale.' }
}

async function reject(id: number) {
  error.value = ''; success.value = ''
  try {
    const reason = (rejectReason.value[id] ?? '').trim()
    if (!reason) throw new Error('Isi alasan penolakan.')
    await rejectOpname(id, reason)
    success.value = 'Opname ditolak.'
    delete rejectReason.value[id]
    await loadOpnames()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal menolak opname.' }
}

async function cancel(id: number) {
  error.value = ''; success.value = ''
  try {
    await cancelOpname(id)
    success.value = 'Opname dibatalkan.'
    await loadOpnames()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal membatalkan opname.' }
}

function toggleItems(id: number) {
  expandedId.value = expandedId.value === id ? null : id
}

onMounted(async () => {
  await Promise.all([loadOpnames(), loadCatalog()])
  try {
    locations.value = await listSubLocations()
  } catch { locations.value = [] }
})
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold">Stock Opname</h1>
    <p class="mb-5 text-sm text-stone-500">Kebenaran fisik disetujui Admin. Rekonsiliasi Plan Pabrik memakai tipe terpisah.</p>
    <p v-if="error" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>
    <p v-if="success" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-600">{{ success }}</p>

    <!-- ================= GUDANG: create ================= -->
    <section v-if="!isAdmin" class="mb-6 grid gap-4 lg:grid-cols-[1fr_380px]">
      <div class="min-w-0 rounded-xl border border-stone-200 bg-white p-4">
        <input
          v-model="catalogSearch"
          type="text"
          placeholder="Cari produk, variasi, atau SKU..."
          autocomplete="off"
          class="mb-3 w-full rounded-xl border border-stone-200 px-4 py-3 text-base"
          @input="onCatalogInput"
        />
        <p v-if="catalogLoading" class="mb-2 text-sm text-stone-400">Mencari...</p>
        <div v-else-if="!catalog.length" class="py-8 text-center text-sm text-stone-400">Ketik untuk mencari produk.</div>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <button
            v-for="product in catalog"
            :key="product.id"
            type="button"
            class="min-w-0 rounded-xl border-2 p-2 text-left transition"
            :class="selectedProduct?.id === product.id ? 'border-brand-600 bg-brand-50' : 'border-stone-100 hover:border-stone-300'"
            @click="chooseProduct(product)"
          >
            <img v-if="thumb(product)" :src="thumb(product) ?? ''" :alt="product.name" class="mb-2 aspect-square w-full rounded-lg object-cover" />
            <div v-else class="mb-2 flex aspect-square w-full items-center justify-center rounded-lg bg-stone-100 text-xs text-stone-400">Tanpa foto</div>
            <div class="truncate text-sm font-medium text-stone-700">{{ product.name }}</div>
            <div class="truncate text-xs text-stone-400">{{ skuLabel(product.sku ?? null) }}</div>
            <div v-if="product.has_variations" class="mt-1 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800">
              Pilih Varian · {{ product.variations.length }} varian
            </div>
            <div v-else class="mt-1 inline-block rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800">Tanpa Varian</div>
          </button>
        </div>
      </div>

      <div class="min-w-0 lg:sticky lg:top-4 lg:self-start">
        <div class="rounded-xl border border-stone-200 bg-white p-4">
          <div v-if="!selectedProduct" class="py-8 text-center text-sm text-stone-400">Pilih produk dari katalog.</div>
          <div v-else>
            <div class="mb-3 flex items-start gap-3">
              <img v-if="thumb(selectedProduct)" :src="thumb(selectedProduct) ?? ''" :alt="selectedProduct.name" class="h-16 w-16 shrink-0 rounded-xl object-cover" />
              <div class="min-w-0 flex-1">
                <div class="font-semibold text-stone-800">{{ selectedProduct.name }}</div>
                <div class="truncate text-xs text-stone-400">{{ skuLabel(selectedVariation?.sku ?? selectedProduct.sku ?? null) }}</div>
                <div v-if="selectedVariation" class="text-xs text-stone-500">Varian: {{ selectedVariation.label }}</div>
              </div>
              <button type="button" class="shrink-0 text-xs text-stone-400 underline" @click="selectedProduct = null; selectedVariation = null">Ganti</button>
            </div>

            <div v-if="selectedProduct.has_variations" class="mb-4">
              <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500">Pilih Varian</p>
              <div class="grid gap-2">
                <button
                  v-for="variation in selectedProduct.variations"
                  :key="variation.id"
                  type="button"
                  class="min-w-0 rounded-xl border-2 px-3 py-2 text-left text-sm"
                  :class="selectedVariation?.id === variation.id ? 'border-brand-600 bg-brand-50 font-semibold' : 'border-stone-100 hover:border-stone-300'"
                  @click="chooseVariation(variation)"
                >
                  {{ variation.label }} <span class="text-xs text-stone-400">· {{ skuLabel(variation.sku) }}</span>
                </button>
              </div>
            </div>

            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500">Jenis Stok</p>
            <div class="mb-3 grid grid-cols-3 gap-2">
              <button type="button" class="rounded-xl border-2 px-2 py-2 text-xs font-semibold" :class="stockType === 'transit' ? 'border-brand-600 bg-brand-50 text-brand-800' : 'border-stone-100 text-stone-500'" @click="stockType = 'transit'">TRANSIT</button>
              <button type="button" class="rounded-xl border-2 px-2 py-2 text-xs font-semibold" :class="stockType === 'factory_plan' ? 'border-brand-600 bg-brand-50 text-brand-800' : 'border-stone-100 text-stone-500'" @click="stockType = 'factory_plan'">PLAN</button>
              <button type="button" class="rounded-xl border-2 px-2 py-2 text-xs font-semibold" :class="stockType === 'sub' ? 'border-brand-600 bg-brand-50 text-brand-800' : 'border-stone-100 text-stone-500'" @click="stockType = 'sub'">SUB</button>
            </div>
            <select v-if="stockType === 'sub'" v-model="subLocationId" class="mb-3 w-full rounded-lg border border-stone-200 px-3 py-2 text-sm">
              <option :value="null" disabled>Pilih Sub Location...</option>
              <option v-for="loc in locations.filter((l) => l.is_active)" :key="loc.id" :value="loc.id">{{ loc.name }} ({{ loc.code }})</option>
            </select>

            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500">Jumlah Hitung Fisik</p>
            <input v-model.number="newCount" type="number" min="0" step="1" placeholder="Hasil hitung" class="mb-4 h-11 w-full min-w-0 rounded-xl border border-stone-200 px-3 text-lg font-semibold" />

            <button type="button" :disabled="!canCreate || working" class="w-full rounded-xl bg-brand-600 px-4 py-3 font-semibold text-white disabled:opacity-40" @click="create">
              {{ working ? 'Mengirim...' : 'Ajukan Opname' }}
            </button>
          </div>
        </div>
      </div>
    </section>

    <!-- ================= LIST ================= -->
    <section class="rounded-xl border border-stone-200 bg-white p-4">
      <h2 class="mb-3 font-semibold">Daftar Opname</h2>
      <div v-if="!opnames.length" class="text-sm text-stone-400">Belum ada opname.</div>
      <ul class="grid gap-3">
        <li v-for="opname in opnames" :key="opname.id" class="rounded-xl border border-stone-100 p-3">
          <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
            <span class="font-medium">{{ opname.opname_number }}</span>
            <span class="text-xs text-stone-400">{{ STOCK_LABEL[opname.stock_type ?? ''] ?? opname.stock_type }} · {{ STATUS_LABEL[opname.status] ?? opname.status }}</span>
          </div>
          <ul class="mt-2 grid gap-2">
            <li v-for="item in opname.items" :key="item.id" class="flex flex-wrap items-center gap-3 rounded-lg bg-stone-50 p-2 text-sm">
              <div class="min-w-0 flex-1 basis-48">
                <div class="font-medium">{{ itemName(item.product, item.product_variation_id, item.product_id) }}</div>
                <div v-if="item.variation?.label" class="text-xs text-stone-500">Varian: {{ item.variation.label }}</div>
                <div class="truncate text-xs text-stone-400">{{ skuLabel(item.variation?.sku ?? item.product?.sku ?? null) }}</div>
                <div class="mt-1 text-xs">Sistem <strong>{{ item.system_quantity }}</strong> · Hitung <strong>{{ item.counted_quantity ?? '—' }}</strong> · Selisih <strong>{{ item.difference ?? (item.counted_quantity != null ? item.counted_quantity - item.system_quantity : '—') }}</strong></div>
              </div>
              <div v-if="!isAdmin && opname.status === 'draft'" class="flex shrink-0 items-center gap-2">
                <input v-model.number="counts[item.id]" type="number" min="0" placeholder="Hitung" class="w-24 min-w-0 rounded-lg border border-stone-200 px-2 py-1 text-sm" />
              </div>
              <div v-if="isAdmin && opname.status === 'submitted'" class="flex shrink-0 flex-wrap items-center gap-2">
                <button type="button" class="rounded-lg bg-emerald-600 px-3 py-1 text-sm text-white" @click="approve(opname.id)">Setujui</button>
                <input v-model="rejectReason[opname.id]" placeholder="Alasan tolak" class="rounded-lg border border-stone-200 bg-white px-2 py-1 text-sm" />
                <button type="button" class="rounded-lg bg-red-600 px-3 py-1 text-sm text-white" @click="reject(opname.id)">Tolak</button>
              </div>
              <div v-if="!isAdmin && opname.status === 'draft'" class="flex shrink-0">
                <button type="button" class="rounded-lg bg-stone-200 px-3 py-1 text-sm" @click="cancel(opname.id)">Batalkan</button>
              </div>
            </li>
          </ul>
          <button type="button" class="mt-2 text-xs text-stone-400 underline" @click="toggleItems(opname.id)">{{ expandedId === opname.id ? 'Sembunyi' : 'Detail' }}</button>
        </li>
      </ul>
      <div v-if="opnameMeta.last_page > 1" class="mt-3 flex items-center gap-2 text-sm">
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="opnameMeta.current_page <= 1" @click="opnameMeta.current_page--; loadOpnames()">Prev</button>
        <span>Halaman {{ opnameMeta.current_page }} / {{ opnameMeta.last_page }} ({{ opnameMeta.total }})</span>
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="opnameMeta.current_page >= opnameMeta.last_page" @click="opnameMeta.current_page++; loadOpnames()">Next</button>
      </div>
    </section>
  </DashboardLayout>
</template>
