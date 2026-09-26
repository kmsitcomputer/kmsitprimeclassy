<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { listProducts } from '@/api/catalog'
import type { Product, ProductVariation } from '@/api/types'
import {
  approveStockAdditionRequest,
  createStockAdditionRequest,
  getSellableSummary,
  getWarehouseSetting,
  listStockAdditionRequests,
  listStockCards,
  rejectStockAdditionRequest,
  setWarehouseSetting,
  type SellableSummary,
  type StockAdditionRequest,
  type StockCard,
} from '@/api/warehouse'
import { useAuthStore } from '@/stores/auth'
import { skuLabel } from '@/utils/format'
import WarehouseStockCardGrid from '@/components/ui/WarehouseStockCardGrid.vue'

const auth = useAuthStore()
const isAdmin = computed(() => auth.user?.role === 'admin')

/* ---------- shared: lower stock cards ---------- */
const cards = ref<StockCard[]>([])
const cardMeta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 12 })
const cardSearch = ref('')
const planEnabled = ref(false)
const error = ref('')
const success = ref('')

async function loadCards() {
  const { cards: rows, meta } = await listStockCards(cardSearch.value.trim() || undefined, cardMeta.value.current_page)
  cards.value = rows
  cardMeta.value = meta
}

/* ---------- gudang POS state ---------- */
const catalog = ref<Product[]>([])
const catalogMeta = ref({ current_page: 1, last_page: 1, total: 0 })
const catalogSearch = ref('')
const catalogLoading = ref(false)
let searchTimer: ReturnType<typeof setTimeout> | null = null

const selectedProduct = ref<Product | null>(null)
const selectedVariation = ref<ProductVariation | null>(null)
const stockInfo = ref<SellableSummary | null>(null)
const stockLoading = ref(false)
const source = ref<'transit' | 'factory_plan'>('transit')
const quantity = ref(1)
const reference = ref('')
const note = ref('')
const showMeta = ref(false)

type CartStatus = 'idle' | 'sending' | 'done' | 'failed'
interface CartLine {
  key: string
  productId: number | null
  variationId: number | null
  productName: string
  variationLabel: string | null
  sku: string | null
  imageUrl: string | null
  source: 'transit' | 'factory_plan'
  quantity: number
  reference?: string
  note?: string
  status: CartStatus
  message?: string
}
const cart = ref<CartLine[]>([])
const submitting = ref(false)

const requests = ref<StockAdditionRequest[]>([])
const requestMeta = ref({ current_page: 1, last_page: 1, total: 0 })
const rejectReason = ref<Record<number, string>>({})
const requestFilter = ref<'pending' | 'approved' | 'rejected' | ''>('')

function thumb(product: Product): string | null {
  return product.images[0]?.url ?? null
}

function sourceLabel(src: string): string {
  return src === 'factory_plan' ? 'Plan Pabrik' : 'Transit'
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

function cartKey(productId: number | null, variationId: number | null, src: string): string {
  return `${productId ?? 0}:${variationId ?? 0}:${src}`
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

function chooseProduct(product: Product) {
  selectedProduct.value = product
  selectedVariation.value = null
  stockInfo.value = null
}

function chooseVariation(variation: ProductVariation) {
  selectedVariation.value = variation
}

function clearSelection() {
  selectedProduct.value = null
  selectedVariation.value = null
  stockInfo.value = null
}

const canAdd = computed(() => {
  if (!selectedProduct.value) return false
  if (selectedProduct.value.has_variations && !selectedVariation.value) return false
  return true
})

watch([selectedProduct, selectedVariation], async () => {
  stockInfo.value = null
  if (!selectedProduct.value) return
  if (selectedProduct.value.has_variations && !selectedVariation.value) return
  stockLoading.value = true
  try {
    stockInfo.value = await getSellableSummary(
      selectedVariation.value
        ? { variation_id: selectedVariation.value.id }
        : { product_id: selectedProduct.value.id },
    )
  } catch {
    stockInfo.value = null
  } finally {
    stockLoading.value = false
  }
})

function clampQuantity() {
  if (!Number.isInteger(quantity.value) || quantity.value < 1) quantity.value = 1
}

function addToCart() {
  if (submitting.value) return
  error.value = ''; success.value = ''
  if (!canAdd.value || !selectedProduct.value) {
    error.value = selectedProduct.value?.has_variations ? 'Pilih varian terlebih dahulu.' : 'Pilih produk terlebih dahulu.'
    return
  }
  clampQuantity()
  const key = cartKey(
    selectedProduct.value.has_variations ? null : selectedProduct.value.id,
    selectedVariation.value?.id ?? null,
    source.value,
  )
  const existing = cart.value.find((line) => line.key === key && line.status !== 'done')
  if (existing) {
    existing.quantity += quantity.value
    if (reference.value.trim()) existing.reference = reference.value.trim()
    if (note.value.trim()) existing.note = note.value.trim()
  } else {
    cart.value.push({
      key,
      productId: selectedProduct.value.has_variations ? null : selectedProduct.value.id,
      variationId: selectedVariation.value?.id ?? null,
      productName: selectedProduct.value.name,
      variationLabel: selectedVariation.value?.label ?? null,
      sku: selectedVariation.value?.sku ?? selectedProduct.value.sku ?? null,
      imageUrl: thumb(selectedProduct.value),
      source: source.value,
      quantity: quantity.value,
      reference: reference.value.trim() || undefined,
      note: note.value.trim() || undefined,
      status: 'idle',
    })
  }
  quantity.value = 1; reference.value = ''; note.value = ''
  success.value = 'Ditambahkan ke daftar ajuan.'
}

function removeLine(key: string) {
  if (submitting.value) return
  cart.value = cart.value.filter((line) => line.key !== key)
}

function bumpLine(line: CartLine, delta: number) {
  if (submitting.value) return
  line.quantity = Math.max(1, Math.floor(line.quantity + delta) || 1)
}

const pendingLines = computed(() => cart.value.filter((line) => line.status !== 'done'))

async function submitCart() {
  error.value = ''; success.value = ''
  const targets = cart.value.filter((line) => line.status === 'idle' || line.status === 'failed')
  if (!targets.length || submitting.value) return
  submitting.value = true
  try {
    for (const line of targets) {
      line.status = 'sending'; line.message = undefined
      try {
        await createStockAdditionRequest({
          product_id: line.productId ?? undefined,
          variation_id: line.variationId ?? undefined,
          quantity: line.quantity,
          target_stock_type: line.source,
          reference: line.reference,
          note: line.note,
        })
        line.status = 'done'
      } catch (e) {
        line.status = 'failed'
        line.message = e instanceof Error ? e.message : 'Gagal dikirim.'
      }
    }
    const failed = cart.value.filter((line) => line.status === 'failed').length
    const doneKeys = new Set(targets.filter((line) => line.status === 'done').map((line) => line.key))
    const done = doneKeys.size
    cart.value = cart.value.filter((line) => !doneKeys.has(line.key))
    if (failed === 0) {
      success.value = 'Ajuan stok berhasil dikirim ke Admin.'
    } else {
      error.value = `${done} ajuan terkirim, ${failed} gagal. Baris yang gagal dapat dicoba ulang tanpa mengulang yang berhasil.`
    }
    requestMeta.value.current_page = 1
    await Promise.all([loadRequests(), loadCards()])
  } finally {
    submitting.value = false
  }
}

async function loadRequests() {
  const { requests: rows, meta } = await listStockAdditionRequests({
    scope: 'mine',
    page: requestMeta.value.current_page,
    per_page: 10,
  })
  requests.value = rows
  requestMeta.value = meta
}

function onMyRequestsPage(delta: number) {
  requestMeta.value.current_page = Math.max(1, requestMeta.value.current_page + delta)
  void loadRequests()
}

const adminRequests = ref<StockAdditionRequest[]>([])
const adminMeta = ref({ current_page: 1, last_page: 1, total: 0 })

async function loadAdminQueue() {
  const { requests: rows, meta } = await listStockAdditionRequests({
    ...(requestFilter.value ? { status: requestFilter.value } : {}),
    page: adminMeta.value.current_page,
  })
  adminRequests.value = rows
  adminMeta.value = meta
}

async function approve(id: number) {
  error.value = ''; success.value = ''
  try {
    await approveStockAdditionRequest(id)
    success.value = 'Permintaan disetujui.'
    await Promise.all([loadAdminQueue(), loadCards()])
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal menyetujui permintaan.' }
}

async function reject(id: number) {
  error.value = ''; success.value = ''
  try {
    const reason = (rejectReason.value[id] ?? '').trim()
    if (!reason) throw new Error('Isi alasan penolakan.')
    await rejectStockAdditionRequest(id, reason)
    success.value = 'Permintaan ditolak.'
    delete rejectReason.value[id]
    await loadAdminQueue()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal menolak permintaan.' }
}

async function togglePlan() {
  try {
    planEnabled.value = (await setWarehouseSetting(!planEnabled.value)).factory_plan_enabled
    await loadCards()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal mengubah status Plan Pabrik.' }
}

async function load() {
  planEnabled.value = (await getWarehouseSetting()).factory_plan_enabled
  if (isAdmin.value) await Promise.all([loadAdminQueue(), loadCards()])
  else await Promise.all([loadCatalog(), loadRequests(), loadCards()])
}

function onCardSearch() {
  cardMeta.value.current_page = 1
  void loadCards()
}

function onAdminFilter() {
  adminMeta.value.current_page = 1
  void loadAdminQueue()
}

onMounted(load)
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold">Warehouse Stock</h1>
    <div class="mb-5 flex items-center gap-3 text-sm text-stone-500">
      <span>Plan Pabrik: {{ planEnabled ? 'ACTIVE' : 'INACTIVE' }}</span>
      <button v-if="isAdmin" type="button" class="rounded-lg bg-stone-200 px-3 py-1" @click="togglePlan">Toggle</button>
    </div>
    <p v-if="error" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>
    <p v-if="success" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-600">{{ success }}</p>

    <!-- ================= GUDANG POS WORKSPACE ================= -->
    <section v-if="!isAdmin" class="mb-8">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-display text-xl font-semibold">Ajuan Penambahan Stok</h2>
        <span class="rounded-full bg-stone-100 px-3 py-1 text-xs text-stone-500">Plan Pabrik: {{ planEnabled ? 'Aktif' : 'Nonaktif' }}</span>
      </div>

      <div class="grid gap-4 lg:grid-cols-[1fr_380px]">
        <!-- LEFT: catalog -->
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

        <!-- RIGHT: selected item / request entry -->
        <div class="min-w-0 lg:sticky lg:top-4 lg:self-start">
          <div class="rounded-xl border border-stone-200 bg-white p-4">
            <div v-if="!selectedProduct" class="py-8 text-center text-sm text-stone-400">Pilih produk dari katalog untuk mulai mengajukan stok.</div>
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
                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-stone-500">Stok Saat Ini</p>
                <p v-if="stockLoading" class="text-sm text-stone-400">Memuat...</p>
                <p v-else-if="!stockInfo" class="text-sm text-stone-400">{{ selectedProduct.has_variations && !selectedVariation ? 'Pilih varian untuk melihat stok.' : 'Stok tidak tersedia.' }}</p>
                <dl v-else class="grid grid-cols-2 gap-1 text-sm">
                  <div><dt class="text-xs text-stone-400">Transit</dt><dd class="font-semibold">{{ stockInfo.transit }}</dd></div>
                  <div><dt class="text-xs text-stone-400">Plan Pabrik</dt><dd class="font-semibold">{{ stockInfo.factory_plan }}</dd></div>
                  <div><dt class="text-xs text-stone-400">Reserved</dt><dd class="font-semibold">{{ stockInfo.reserved }}</dd></div>
                  <div><dt class="text-xs text-stone-400">Stok Jual</dt><dd class="font-semibold text-brand-700">{{ stockInfo.available }}</dd></div>
                </dl>
              </div>

              <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500">Sumber Stok</p>
              <div class="mb-4 grid grid-cols-2 gap-2">
                <button
                  type="button"
                  class="rounded-xl border-2 px-3 py-3 text-sm font-semibold"
                  :class="source === 'transit' ? 'border-brand-600 bg-brand-50 text-brand-800' : 'border-stone-100 text-stone-500'"
                  @click="source = 'transit'"
                >TRANSIT</button>
                <button
                  type="button"
                  class="rounded-xl border-2 px-3 py-3 text-sm font-semibold"
                  :class="source === 'factory_plan' ? 'border-brand-600 bg-brand-50 text-brand-800' : 'border-stone-100 text-stone-500'"
                  @click="source = 'factory_plan'"
                >PLAN PABRIK</button>
              </div>
              <p v-if="source === 'factory_plan' && !planEnabled" class="mb-3 text-xs text-amber-700">Plan sedang nonaktif: ajuan tetap tersimpan, namun belum menambah Stok Jual sampai Plan diaktifkan Admin.</p>

              <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500">Jumlah yang Diajukan</p>
              <div class="mb-4 flex items-center gap-2">
                <button type="button" class="h-11 w-11 shrink-0 rounded-xl bg-stone-100 text-xl font-bold" @click="quantity = Math.max(1, quantity - 1)">−</button>
                <input v-model.number="quantity" type="number" min="1" step="1" class="h-11 w-full min-w-0 rounded-xl border border-stone-200 text-center text-lg font-semibold" @change="clampQuantity" />
                <button type="button" class="h-11 w-11 shrink-0 rounded-xl bg-stone-100 text-xl font-bold" @click="quantity = quantity + 1">+</button>
              </div>

              <button type="button" class="mb-3 text-xs text-stone-500 underline" @click="showMeta = !showMeta">{{ showMeta ? 'Sembunyikan' : 'Tambah referensi / catatan (opsional)' }}</button>
              <div v-if="showMeta" class="mb-3 grid gap-2">
                <input v-model="reference" placeholder="Referensi dokumen (opsional)" class="rounded-lg border border-stone-200 px-3 py-2 text-sm" />
                <input v-model="note" placeholder="Catatan (opsional)" class="rounded-lg border border-stone-200 px-3 py-2 text-sm" />
              </div>

              <button
                type="button"
                :disabled="!canAdd || submitting"
                class="w-full rounded-xl bg-brand-600 px-4 py-3 font-semibold text-white disabled:opacity-40"
                @click="addToCart"
              >+ Tambah ke Daftar Ajuan</button>
            </div>
          </div>
        </div>
      </div>

      <!-- BOTTOM/WIDE: request cart -->
      <div class="mt-4 rounded-xl border border-stone-200 bg-white p-4">
        <h3 class="mb-3 font-semibold">Daftar Ajuan ({{ pendingLines.length }})</h3>
        <div v-if="!cart.length" class="text-sm text-stone-400">Daftar ajuan masih kosong. Pilih produk lalu tambah ke daftar.</div>
        <ul v-else class="grid gap-2">
          <li v-for="line in cart" :key="line.key" class="flex flex-wrap items-center gap-3 rounded-xl border border-stone-100 p-2">
            <img v-if="line.imageUrl" :src="line.imageUrl" :alt="line.productName" class="h-10 w-10 shrink-0 rounded-lg object-cover" />
            <div class="min-w-0 flex-1 basis-40">
              <div class="truncate text-sm font-medium">{{ line.productName }}</div>
              <div class="truncate text-xs text-stone-400">
                <span v-if="line.variationLabel">{{ line.variationLabel }} · </span>{{ skuLabel(line.sku) }} · {{ sourceLabel(line.source) }}
              </div>
              <div v-if="line.status === 'failed'" class="text-xs text-red-600">Gagal: {{ line.message }}</div>
              <div v-else-if="line.status === 'sending'" class="text-xs text-stone-400">Mengirim...</div>
            </div>
            <div class="flex shrink-0 items-center gap-1">
              <button type="button" :disabled="submitting || line.status === 'sending'" class="h-8 w-8 rounded-lg bg-stone-100 font-bold disabled:opacity-40" @click="bumpLine(line, -1)">−</button>
              <span class="w-10 text-center text-sm font-semibold">{{ line.quantity }}</span>
              <button type="button" :disabled="submitting || line.status === 'sending'" class="h-8 w-8 rounded-lg bg-stone-100 font-bold disabled:opacity-40" @click="bumpLine(line, 1)">+</button>
            </div>
            <button type="button" :disabled="submitting || line.status === 'sending'" class="shrink-0 text-xs text-red-600 underline disabled:opacity-40" @click="removeLine(line.key)">Hapus</button>
          </li>
        </ul>
        <button
          v-if="cart.length"
          type="button"
          :disabled="submitting || !pendingLines.length"
          class="mt-3 w-full rounded-xl bg-stone-900 px-4 py-3 font-semibold text-white disabled:opacity-40 sm:w-auto"
          @click="submitCart"
        >{{ submitting ? 'Mengirim...' : 'Kirim Ajuan Stok ke Admin' }}</button>
      </div>

      <!-- Ajuan saya -->
      <div class="mt-4 rounded-xl border border-stone-200 bg-white p-4">
        <h3 class="mb-3 font-semibold">Ajuan Saya</h3>
        <div v-if="!requests.length" class="text-sm text-stone-400">Belum ada ajuan.</div>
        <ul v-else class="grid gap-2">
          <li v-for="req in requests" :key="req.id" class="flex flex-wrap items-center gap-3 rounded-xl border border-stone-100 p-2 text-sm">
            <img v-if="req.product_image_url" :src="req.product_image_url" :alt="req.product_name ?? ''" class="h-10 w-10 shrink-0 rounded-lg object-cover" />
            <div class="min-w-0 flex-1 basis-40">
              <span class="font-medium">{{ req.product_name ?? 'Produk' }}</span>
              <span v-if="req.variation_label"> ({{ req.variation_label }})</span>
              <span class="text-stone-400"> · {{ skuLabel(req.sku ?? null) }} · +{{ req.quantity }} {{ sourceLabel(req.target_stock_type) }}</span>
              <div class="text-xs text-stone-400">{{ req.created_at ?? '' }}</div>
            </div>
            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(req.status)">{{ statusLabel(req.status) }}</span>
          </li>
        </ul>
        <div v-if="requestMeta.last_page > 1" class="mt-3 flex items-center gap-2 text-sm">
          <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="requestMeta.current_page <= 1" @click="onMyRequestsPage(-1)">Prev</button>
          <span>Halaman {{ requestMeta.current_page }} / {{ requestMeta.last_page }} ({{ requestMeta.total }})</span>
          <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="requestMeta.current_page >= requestMeta.last_page" @click="onMyRequestsPage(1)">Next</button>
        </div>
      </div>
    </section>

    <!-- ================= ADMIN QUEUE ================= -->
    <section v-if="isAdmin" class="mb-6 rounded-xl border border-stone-200 bg-white p-4">
      <h2 class="mb-3 font-display text-xl font-semibold">Daftar Ajuan Penambahan Stok</h2>
      <div class="mb-3 flex max-w-xl gap-2">
        <select v-model="requestFilter" class="rounded-lg border border-stone-200 px-3 py-2 text-sm" @change="onAdminFilter">
          <option value="">Semua</option>
          <option value="pending">Menunggu</option>
          <option value="approved">Disetujui</option>
          <option value="rejected">Ditolak</option>
        </select>
      </div>
      <div v-if="!adminRequests.length" class="text-sm text-stone-400">Belum ada permintaan.</div>
      <ul class="grid gap-3">
        <li v-for="req in adminRequests" :key="req.id" class="flex flex-wrap items-center gap-3 rounded-xl border border-stone-100 p-3">
          <img v-if="req.product_image_url" :src="req.product_image_url" :alt="req.product_name ?? ''" class="h-12 w-12 shrink-0 rounded-xl object-cover" />
          <div class="min-w-0 flex-1 basis-48">
            <div class="font-medium text-stone-700">{{ req.product_name ?? 'Produk' }}</div>
            <div v-if="req.variation_label" class="text-xs text-stone-500">Varian: {{ req.variation_label }}</div>
            <div class="truncate text-xs text-stone-400">{{ skuLabel(req.sku ?? null) }}</div>
            <div class="mt-1 text-sm">Minta <strong>+{{ req.quantity }}</strong> ke <strong>{{ sourceLabel(req.target_stock_type) }}</strong> · stok saat ini: {{ req.current_stock ?? 0 }}</div>
            <div class="text-xs text-stone-400">{{ req.requester?.name ?? '' }} · {{ req.created_at ?? '' }} · <span class="rounded-full px-2 py-0.5 font-medium" :class="statusClass(req.status)">{{ statusLabel(req.status) }}</span></div>
          </div>
          <div v-if="req.status === 'pending'" class="flex shrink-0 flex-wrap items-center gap-2">
            <button type="button" class="rounded-lg bg-emerald-600 px-3 py-1 text-sm text-white" @click="approve(req.id)">Approve</button>
            <input v-model="rejectReason[req.id]" placeholder="Alasan tolak" class="rounded-lg border border-stone-200 px-2 py-1 text-sm" />
            <button type="button" class="rounded-lg bg-red-600 px-3 py-1 text-sm text-white" @click="reject(req.id)">Reject</button>
          </div>
        </li>
      </ul>
      <div v-if="adminMeta.last_page > 1" class="mt-3 flex items-center gap-2 text-sm">
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="adminMeta.current_page <= 1" @click="adminMeta.current_page--; loadAdminQueue()">Prev</button>
        <span>Halaman {{ adminMeta.current_page }} / {{ adminMeta.last_page }}</span>
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="adminMeta.current_page >= adminMeta.last_page" @click="adminMeta.current_page++; loadAdminQueue()">Next</button>
      </div>
    </section>

    <!-- ================= LOWER: approved stock cards ================= -->
    <h2 class="mb-3 font-display text-xl font-semibold">Semua Stok Produk</h2>
    <div class="mb-5 flex max-w-xl gap-2">
      <input v-model="cardSearch" type="text" placeholder="Cari nama produk / SKU..." class="min-w-0 flex-1 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm" @keyup.enter="onCardSearch" />
      <button type="button" class="shrink-0 rounded-lg bg-stone-800 px-4 py-2 text-sm text-white" @click="onCardSearch">Cari</button>
    </div>
    <WarehouseStockCardGrid :cards="cards" />
    <div v-if="cardMeta.last_page > 1" class="mt-4 flex items-center gap-2 text-sm">
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="cardMeta.current_page <= 1" @click="cardMeta.current_page--; loadCards()">Prev</button>
      <span>Halaman {{ cardMeta.current_page }} / {{ cardMeta.last_page }} ({{ cardMeta.total }})</span>
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="cardMeta.current_page >= cardMeta.last_page" @click="cardMeta.current_page++; loadCards()">Next</button>
    </div>
  </DashboardLayout>
</template>
