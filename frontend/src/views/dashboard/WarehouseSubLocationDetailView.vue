<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import SubStockCardGrid from '@/components/ui/SubStockCardGrid.vue'
import { listProducts } from '@/api/catalog'
import type { Product, ProductVariation } from '@/api/types'
import {
  approveStockAdditionRequest,
  createSubAdjustmentRequest,
  listStockAdditionRequests,
  listSubLocations,
  listSubStockCards,
  rejectStockAdditionRequest,
  type StockAdditionRequest,
  type SubLocation,
  type SubStockCard,
} from '@/api/warehouse'
import { useAuthStore } from '@/stores/auth'
import { skuLabel } from '@/utils/format'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const isAdmin = computed(() => auth.user?.role === 'admin')
const locationId = computed(() => Number(route.params.id))
const location = ref<SubLocation | null>(null)

/* ---------- catalog + sub cards ---------- */
const catalog = ref<Product[]>([])
const catalogMeta = ref({ current_page: 1, last_page: 1, total: 0 })
const catalogSearch = ref('')
const catalogLoading = ref(false)
let searchTimer: ReturnType<typeof setTimeout> | null = null

const cards = ref<SubStockCard[]>([])
const cardMeta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 12 })
const cardSearch = ref('')

/* ---------- adjustment entry ---------- */
const selectedProduct = ref<Product | null>(null)
const selectedVariation = ref<ProductVariation | null>(null)
const proposed = ref(0)
const reference = ref('')
const note = ref('')
const showMeta = ref(false)

interface AdjustLine {
  key: string
  productId: number | null
  variationId: number | null
  productName: string
  variationLabel: string | null
  sku: string | null
  imageUrl: string | null
  current: number
  proposed: number
  delta: number
}
const lines = ref<AdjustLine[]>([])
const submitting = ref(false)
const error = ref('')
const success = ref('')

/* ---------- requests ---------- */
const requests = ref<StockAdditionRequest[]>([])
const requestMeta = ref({ current_page: 1, last_page: 1, total: 0 })
const adminFilter = ref<'pending' | 'approved' | 'rejected' | ''>('pending')
const rejectReason = ref<Record<number, string>>({})

function thumb(product: Product): string | null {
  return product.images[0]?.url ?? null
}

function subOf(productId: number | null, variationId: number | null): number {
  if (variationId != null) return cards.value.find((c) => c.product_variation_id === variationId)?.sub_stock ?? 0
  return cards.value.find((c) => c.product_id === productId && c.product_variation_id == null)?.sub_stock ?? 0
}

function statusLabel(status: string): string {
  return status === 'pending' ? 'Menunggu' : status === 'approved' ? 'Disetujui' : status === 'rejected' ? 'Ditolak' : status
}

function statusClass(status: string): string {
  return status === 'pending'
    ? 'bg-amber-100 text-amber-800'
    : status === 'approved'
      ? 'bg-emerald-100 text-emerald-800'
      : 'bg-red-100 text-red-700'
}

async function loadLocation() {
  const all = await listSubLocations()
  location.value = all.find((l) => l.id === locationId.value) ?? null
}

async function loadCatalog() {
  catalogLoading.value = true
  try {
    const { products, meta } = await listProducts({ search: catalogSearch.value.trim() || undefined, per_page: 12, page: catalogMeta.value.current_page })
    catalog.value = products
    catalogMeta.value = { current_page: meta.current_page, last_page: meta.last_page, total: meta.total }
  } finally {
    catalogLoading.value = false
  }
}

function onCatalogInput() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    catalogMeta.value.current_page = 1
    void loadCatalog()
  }, 350)
}

async function loadCards() {
  const { cards: rows, meta } = await listSubStockCards(locationId.value, cardSearch.value.trim() || undefined, cardMeta.value.current_page)
  cards.value = rows
  cardMeta.value = meta
}

function chooseProduct(product: Product) {
  selectedProduct.value = product
  selectedVariation.value = null
  syncProposed()
}

function chooseVariation(variation: ProductVariation) {
  selectedVariation.value = variation
  syncProposed()
}

function clearSelection() {
  selectedProduct.value = null
  selectedVariation.value = null
}

const currentSub = computed(() => {
  if (!selectedProduct.value) return 0
  return subOf(selectedProduct.value.has_variations ? null : selectedProduct.value.id, selectedVariation.value?.id ?? null)
})

function syncProposed() {
  proposed.value = currentSub.value
}

const delta = computed(() => proposed.value - currentSub.value)
const canAdd = computed(() => {
  if (!selectedProduct.value) return false
  if (selectedProduct.value.has_variations && !selectedVariation.value) return false
  return delta.value !== 0
})

function clampProposed() {
  if (!Number.isInteger(proposed.value) || proposed.value < 0) proposed.value = 0
}

function lineKey(productId: number | null, variationId: number | null): string {
  return `${productId ?? 0}:${variationId ?? 0}`
}

function addLine() {
  if (submitting.value) return
  error.value = ''; success.value = ''
  if (!canAdd.value || !selectedProduct.value) {
    error.value = selectedProduct.value?.has_variations && !selectedVariation.value
      ? 'Pilih varian terlebih dahulu.'
      : 'Ubah jumlah hitung fisik agar selisih tidak nol.'
    return
  }
  const key = lineKey(selectedProduct.value.has_variations ? null : selectedProduct.value.id, selectedVariation.value?.id ?? null)
  const existing = lines.value.find((l) => l.key === key)
  if (existing) {
    existing.proposed = proposed.value
    existing.delta = delta.value
    existing.current = currentSub.value
  } else {
    lines.value.push({
      key,
      productId: selectedProduct.value.has_variations ? null : selectedProduct.value.id,
      variationId: selectedVariation.value?.id ?? null,
      productName: selectedProduct.value.name,
      variationLabel: selectedVariation.value?.label ?? null,
      sku: selectedVariation.value?.sku ?? selectedProduct.value.sku ?? null,
      imageUrl: thumb(selectedProduct.value),
      current: currentSub.value,
      proposed: proposed.value,
      delta: delta.value,
    })
  }
  success.value = 'Ditambahkan ke daftar penyesuaian.'
}

function removeLine(key: string) {
  if (submitting.value) return
  lines.value = lines.value.filter((l) => l.key !== key)
}

async function submitLines() {
  error.value = ''; success.value = ''
  if (!lines.value.length || submitting.value) return
  submitting.value = true
  const snapshot = [...lines.value]
  try {
    for (const line of snapshot) {
      await createSubAdjustmentRequest({
        product_id: line.productId ?? undefined,
        variation_id: line.variationId ?? undefined,
        sub_location_id: locationId.value,
        delta: line.delta,
        reference: reference.value.trim() || undefined,
        note: note.value.trim() || undefined,
      })
    }
    lines.value = lines.value.filter((l) => !snapshot.some((s) => s.key === l.key))
    reference.value = ''; note.value = ''
    success.value = 'Permintaan penyesuaian berhasil dikirim ke Admin.'
    await Promise.all([loadRequests(true), loadCards()])
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Gagal mengirim permintaan.'
  } finally {
    submitting.value = false
  }
}

async function loadRequests(reset = false) {
  if (reset) requestMeta.value.current_page = 1
  const params: Record<string, string | number> = {
    request_type: 'sub_adjustment',
    sub_location_id: locationId.value,
    page: requestMeta.value.current_page,
    per_page: 10,
  }
  if (isAdmin.value && adminFilter.value) params.status = adminFilter.value
  if (!isAdmin.value) params.scope = 'mine'
  const { requests: rows, meta } = await listStockAdditionRequests(params)
  requests.value = rows
  requestMeta.value = meta
}

async function approve(id: number) {
  error.value = ''; success.value = ''
  try {
    await approveStockAdditionRequest(id)
    success.value = 'Penyesuaian disetujui. Hanya stok Sub lokasi ini yang berubah.'
    await Promise.all([loadRequests(), loadCards()])
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal menyetujui penyesuaian.' }
}

async function reject(id: number) {
  error.value = ''; success.value = ''
  try {
    const reason = (rejectReason.value[id] ?? '').trim()
    if (!reason) throw new Error('Isi alasan penolakan.')
    await rejectStockAdditionRequest(id, reason)
    success.value = 'Penyesuaian ditolak.'
    delete rejectReason.value[id]
    await loadRequests()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal menolak penyesuaian.' }
}

async function load() {
  await loadLocation()
  if (!location.value) {
    error.value = 'Sub Location tidak ditemukan.'
    return
  }
  await Promise.all([loadCatalog(), loadCards(), loadRequests()])
}

function onCardSearch() {
  cardMeta.value.current_page = 1
  void loadCards()
}

function onRequestPage(d: number) {
  requestMeta.value.current_page = Math.max(1, requestMeta.value.current_page + d)
  void loadRequests()
}

function back() {
  void router.push({ name: 'warehouse-sub-locations' })
}

onMounted(load)
</script>

<template>
  <DashboardLayout>
    <button type="button" class="mb-2 text-sm text-stone-500 underline" @click="back">← Kembali ke Sub Locations</button>
    <h1 class="mb-1 font-display text-2xl font-semibold">Sub Location — {{ location?.name ?? '...' }}</h1>
    <p class="mb-5 text-sm text-stone-500">{{ location?.address ?? '' }}<span v-if="location?.contact_number"> · {{ location.contact_number }}</span></p>
    <p v-if="error" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>
    <p v-if="success" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-600">{{ success }}</p>

    <!-- ================= GUDANG WORKSPACE ================= -->
    <section v-if="!isAdmin" class="mb-8">
      <div class="grid gap-4 lg:grid-cols-[1fr_380px]">
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
          <div v-else-if="!catalog.length" class="py-8 text-center text-sm text-stone-400">
            {{ catalogSearch.trim() ? 'Tidak ada produk yang cocok.' : 'Ketik untuk mencari produk.' }}
          </div>
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
          <div v-if="catalogMeta.last_page > 1" class="mt-3 flex items-center justify-center gap-2 text-sm">
            <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="catalogMeta.current_page <= 1" @click="catalogMeta.current_page--; loadCatalog()">‹</button>
            <span>{{ catalogMeta.current_page }} / {{ catalogMeta.last_page }}</span>
            <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="catalogMeta.current_page >= catalogMeta.last_page" @click="catalogMeta.current_page++; loadCatalog()">›</button>
          </div>
        </div>

        <div class="min-w-0 lg:sticky lg:top-4 lg:self-start">
          <div class="rounded-xl border border-stone-200 bg-white p-4">
            <div v-if="!selectedProduct" class="py-8 text-center text-sm text-stone-400">Pilih produk dari katalog untuk mengajukan penyesuaian.</div>
            <div v-else>
              <div class="mb-3 flex items-start gap-3">
                <img v-if="thumb(selectedProduct)" :src="thumb(selectedProduct) ?? ''" :alt="selectedProduct.name" class="h-16 w-16 shrink-0 rounded-xl object-cover" />
                <div class="min-w-0 flex-1">
                  <div class="font-semibold text-stone-800">{{ selectedProduct.name }}</div>
                  <div class="truncate text-xs text-stone-400">{{ skuLabel(selectedVariation?.sku ?? selectedProduct.sku ?? null) }}</div>
                  <div v-if="selectedVariation" class="text-xs text-stone-500">Varian: {{ selectedVariation.label }}</div>
                </div>
                <button type="button" class="shrink-0 text-xs text-stone-400 underline" @click="clearSelection">Ganti</button>
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

              <div class="mb-4 rounded-xl bg-stone-50 p-3">
                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-stone-500">Stok Sub Saat Ini</p>
                <p class="text-2xl font-bold">{{ currentSub }}</p>
              </div>

              <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500">Jumlah Hitung Fisik</p>
              <div class="mb-2 flex items-center gap-2">
                <button type="button" class="h-11 w-11 shrink-0 rounded-xl bg-stone-100 text-xl font-bold" @click="proposed = Math.max(0, proposed - 1)">−</button>
                <input v-model.number="proposed" type="number" min="0" step="1" class="h-11 w-full min-w-0 rounded-xl border border-stone-200 text-center text-lg font-semibold" @change="clampProposed" />
                <button type="button" class="h-11 w-11 shrink-0 rounded-xl bg-stone-100 text-xl font-bold" @click="proposed = proposed + 1">+</button>
              </div>
              <p class="mb-4 text-sm">Selisih: <strong :class="delta > 0 ? 'text-emerald-700' : delta < 0 ? 'text-red-700' : 'text-stone-400'">{{ delta > 0 ? `+${delta}` : delta }}</strong></p>

              <button type="button" class="mb-3 text-xs text-stone-500 underline" @click="showMeta = !showMeta">{{ showMeta ? 'Sembunyikan' : 'Tambah referensi / catatan (opsional)' }}</button>
              <div v-if="showMeta" class="mb-3 grid gap-2">
                <input v-model="reference" placeholder="Referensi dokumen (opsional)" class="rounded-lg border border-stone-200 px-3 py-2 text-sm" />
                <input v-model="note" placeholder="Catatan (opsional)" class="rounded-lg border border-stone-200 px-3 py-2 text-sm" />
              </div>

              <button
                type="button"
                :disabled="!canAdd || submitting"
                class="w-full rounded-xl bg-brand-600 px-4 py-3 font-semibold text-white disabled:opacity-40"
                @click="addLine"
              >+ Tambah ke Daftar Penyesuaian</button>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-4 rounded-xl border border-stone-200 bg-white p-4">
        <h3 class="mb-3 font-semibold">Daftar Penyesuaian ({{ lines.length }})</h3>
        <div v-if="!lines.length" class="text-sm text-stone-400">Daftar masih kosong.</div>
        <ul v-else class="grid gap-2">
          <li v-for="line in lines" :key="line.key" class="flex flex-wrap items-center gap-3 rounded-xl border border-stone-100 p-2">
            <img v-if="line.imageUrl" :src="line.imageUrl" :alt="line.productName" class="h-10 w-10 shrink-0 rounded-lg object-cover" />
            <div class="min-w-0 flex-1 basis-40">
              <div class="truncate text-sm font-medium">{{ line.productName }}</div>
              <div class="truncate text-xs text-stone-400"><span v-if="line.variationLabel">{{ line.variationLabel }} · </span>{{ skuLabel(line.sku) }}</div>
              <div class="text-xs">Saat ini {{ line.current }} → Usulan {{ line.proposed }} · <strong>{{ line.delta > 0 ? `+${line.delta}` : line.delta }}</strong></div>
            </div>
            <button type="button" :disabled="submitting" class="shrink-0 text-xs text-red-600 underline disabled:opacity-40" @click="removeLine(line.key)">Hapus</button>
          </li>
        </ul>
        <button
          v-if="lines.length"
          type="button"
          :disabled="submitting"
          class="mt-3 w-full rounded-xl bg-stone-900 px-4 py-3 font-semibold text-white disabled:opacity-40 sm:w-auto"
          @click="submitLines"
        >{{ submitting ? 'Mengirim...' : 'Kirim Permintaan ke Admin' }}</button>
      </div>

      <div class="mt-4 rounded-xl border border-stone-200 bg-white p-4">
        <h3 class="mb-3 font-semibold">Ajuan Saya</h3>
        <div v-if="!requests.length" class="text-sm text-stone-400">Belum ada ajuan.</div>
        <ul v-else class="grid gap-2">
          <li v-for="req in requests" :key="req.id" class="flex flex-wrap items-center gap-3 rounded-xl border border-stone-100 p-2 text-sm">
            <img v-if="req.product_image_url" :src="req.product_image_url" :alt="req.product_name ?? ''" class="h-10 w-10 shrink-0 rounded-lg object-cover" />
            <div class="min-w-0 flex-1 basis-40">
              <span class="font-medium">{{ req.product_name ?? 'Produk' }}</span>
              <span v-if="req.variation_label"> ({{ req.variation_label }})</span>
              <span class="text-stone-400"> · {{ skuLabel(req.sku ?? null) }} · {{ req.quantity > 0 ? `+${req.quantity}` : req.quantity }}</span>
              <div class="text-xs text-stone-400">{{ req.created_at ?? '' }}</div>
            </div>
            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(req.status)">{{ statusLabel(req.status) }}</span>
          </li>
        </ul>
        <div v-if="requestMeta.last_page > 1" class="mt-3 flex items-center gap-2 text-sm">
          <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="requestMeta.current_page <= 1" @click="onRequestPage(-1)">Prev</button>
          <span>Halaman {{ requestMeta.current_page }} / {{ requestMeta.last_page }} ({{ requestMeta.total }})</span>
          <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="requestMeta.current_page >= requestMeta.last_page" @click="onRequestPage(1)">Next</button>
        </div>
      </div>
    </section>

    <!-- ================= ADMIN QUEUE ================= -->
    <section v-if="isAdmin" class="mb-6 rounded-xl border border-stone-200 bg-white p-4">
      <h2 class="mb-3 font-display text-xl font-semibold">Pending Sub Stock Adjustments</h2>
      <div class="mb-3 flex max-w-xl gap-2">
        <select v-model="adminFilter" class="rounded-lg border border-stone-200 px-3 py-2 text-sm" @change="loadRequests(true)">
          <option value="pending">Menunggu</option>
          <option value="approved">Disetujui</option>
          <option value="rejected">Ditolak</option>
          <option value="">Semua</option>
        </select>
      </div>
      <div v-if="!requests.length" class="text-sm text-stone-400">Belum ada permintaan.</div>
      <ul class="grid gap-3">
        <li v-for="req in requests" :key="req.id" class="flex flex-wrap items-center gap-3 rounded-xl border border-stone-100 p-3">
          <img v-if="req.product_image_url" :src="req.product_image_url" :alt="req.product_name ?? ''" class="h-12 w-12 shrink-0 rounded-xl object-cover" />
          <div class="min-w-0 flex-1 basis-48">
            <div class="font-medium text-stone-700">{{ req.product_name ?? 'Produk' }}</div>
            <div v-if="req.variation_label" class="text-xs text-stone-500">Varian: {{ req.variation_label }}</div>
            <div class="truncate text-xs text-stone-400">{{ skuLabel(req.sku ?? null) }}</div>
            <div class="mt-1 text-sm">Sub saat ini <strong>{{ req.current_stock ?? 0 }}</strong> · selisih <strong>{{ (req.quantity ?? 0) > 0 ? `+${req.quantity}` : req.quantity }}</strong> → hasil <strong>{{ (req.current_stock ?? 0) + (req.quantity ?? 0) }}</strong></div>
            <div class="text-xs text-stone-400">{{ req.requester?.name ?? '' }} · {{ req.created_at ?? '' }} · <span class="rounded-full px-2 py-0.5 font-medium" :class="statusClass(req.status)">{{ statusLabel(req.status) }}</span></div>
          </div>
          <div v-if="req.status === 'pending'" class="flex shrink-0 flex-wrap items-center gap-2">
            <button type="button" class="rounded-lg bg-emerald-600 px-3 py-1 text-sm text-white" @click="approve(req.id)">Approve</button>
            <input v-model="rejectReason[req.id]" placeholder="Alasan tolak" class="rounded-lg border border-stone-200 px-2 py-1 text-sm" />
            <button type="button" class="rounded-lg bg-red-600 px-3 py-1 text-sm text-white" @click="reject(req.id)">Reject</button>
          </div>
        </li>
      </ul>
      <div v-if="requestMeta.last_page > 1" class="mt-3 flex items-center gap-2 text-sm">
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="requestMeta.current_page <= 1" @click="onRequestPage(-1)">Prev</button>
        <span>Halaman {{ requestMeta.current_page }} / {{ requestMeta.last_page }} ({{ requestMeta.total }})</span>
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="requestMeta.current_page >= requestMeta.last_page" @click="onRequestPage(1)">Next</button>
      </div>
    </section>

    <!-- ================= BOTTOM: all sub stock ================= -->
    <h2 class="mb-3 font-display text-xl font-semibold">Semua Stok Sub Lokasi Ini</h2>
    <div class="mb-5 flex max-w-xl gap-2">
      <input v-model="cardSearch" type="text" placeholder="Cari nama produk / SKU..." class="min-w-0 flex-1 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm" @keyup.enter="onCardSearch" />
      <button type="button" class="shrink-0 rounded-lg bg-stone-800 px-4 py-2 text-sm text-white" @click="onCardSearch">Cari</button>
    </div>
    <SubStockCardGrid :cards="cards" />
    <div v-if="cardMeta.last_page > 1" class="mt-4 flex items-center gap-2 text-sm">
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="cardMeta.current_page <= 1" @click="cardMeta.current_page--; loadCards()">Prev</button>
      <span>Halaman {{ cardMeta.current_page }} / {{ cardMeta.last_page }} ({{ cardMeta.total }})</span>
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="cardMeta.current_page >= cardMeta.last_page" @click="cardMeta.current_page++; loadCards()">Next</button>
    </div>
  </DashboardLayout>
</template>
