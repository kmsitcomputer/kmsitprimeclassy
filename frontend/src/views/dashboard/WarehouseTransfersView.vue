<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import WarehouseStockCardGrid from '@/components/ui/WarehouseStockCardGrid.vue'
import { listProducts } from '@/api/catalog'
import type { Product, ProductVariation } from '@/api/types'
import {
  approveTransfer,
  createPlanTransfer,
  listTransfers,
  rejectTransfer,
  type StockTransfer,
} from '@/api/transfers'
import {
  getSellableSummary,
  getWarehouseSetting,
  listStockCards,
  type SellableSummary,
  type StockCard,
} from '@/api/warehouse'
import { useAuthStore } from '@/stores/auth'
import { skuLabel } from '@/utils/format'

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
const quantity = ref(1)
const reference = ref('')
const note = ref('')
const showMeta = ref(false)

interface CartLine {
  key: string
  productId: number | null
  variationId: number | null
  productName: string
  variationLabel: string | null
  sku: string | null
  imageUrl: string | null
  planAvailable: number
  quantity: number
}
const cart = ref<CartLine[]>([])
const submitting = ref(false)

const transfers = ref<StockTransfer[]>([])
const transferMeta = ref({ current_page: 1, last_page: 1, total: 0 })
const adminFilter = ref<'pending' | 'completed' | 'rejected' | ''>('pending')
const rejectReason = ref<Record<number, string>>({})

function thumb(product: Product): string | null {
  return product.images[0]?.url ?? null
}

function transferStatusLabel(status: string): string {
  return status === 'pending' ? 'Menunggu' : status === 'completed' ? 'Selesai' : status === 'rejected' ? 'Ditolak' : status === 'cancelled' ? 'Dibatalkan' : status
}

function transferStatusClass(status: string): string {
  return status === 'pending'
    ? 'bg-amber-100 text-amber-800'
    : status === 'completed'
      ? 'bg-emerald-100 text-emerald-800'
      : 'bg-red-100 text-red-700'
}

function itemName(product?: { name: string } | null, variationId?: number | null, productId?: number | null): string {
  return product?.name ?? (variationId != null ? `Varian #${variationId}` : `Produk #${productId}`)
}

function cartKey(productId: number | null, variationId: number | null): string {
  return `${productId ?? 0}:${variationId ?? 0}`
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
  if (stockInfo.value) quantity.value = Math.min(quantity.value, Math.max(1, stockInfo.value.factory_plan))
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
  )
  const existing = cart.value.find((line) => line.key === key)
  if (existing) {
    existing.quantity = Math.min(existing.quantity + quantity.value, Math.max(1, stockInfo.value?.factory_plan ?? existing.quantity + quantity.value))
  } else {
    cart.value.push({
      key,
      productId: selectedProduct.value.has_variations ? null : selectedProduct.value.id,
      variationId: selectedVariation.value?.id ?? null,
      productName: selectedProduct.value.name,
      variationLabel: selectedVariation.value?.label ?? null,
      sku: selectedVariation.value?.sku ?? selectedProduct.value.sku ?? null,
      imageUrl: thumb(selectedProduct.value),
      planAvailable: stockInfo.value?.factory_plan ?? 0,
      quantity: quantity.value,
    })
  }
  quantity.value = 1; reference.value = ''; note.value = ''
  success.value = 'Ditambahkan ke daftar transfer.'
}

function removeLine(key: string) {
  if (submitting.value) return
  cart.value = cart.value.filter((line) => line.key !== key)
}

function bumpLine(line: CartLine, delta: number) {
  if (submitting.value) return
  line.quantity = Math.max(1, Math.floor(line.quantity + delta) || 1)
}

/* One transfer header with many items — mirrors the backend contract. */
async function submitCart() {
  error.value = ''; success.value = ''
  if (!cart.value.length || submitting.value) return
  submitting.value = true
  try {
    await createPlanTransfer({
      reference: reference.value.trim() || undefined,
      note: note.value.trim() || undefined,
      items: cart.value.map((line) => ({
        product_id: line.productId ?? undefined,
        product_variation_id: line.variationId ?? undefined,
        quantity: line.quantity,
      })),
    })
    cart.value = []
    reference.value = ''; note.value = ''
    success.value = 'Permintaan transfer berhasil dikirim ke Admin.'
    await Promise.all([loadTransfers(true), loadCards()])
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Gagal mengirim permintaan transfer.'
  } finally {
    submitting.value = false
  }
}

async function loadTransfers(reset = false) {
  if (reset) transferMeta.value.current_page = 1
  const status = isAdmin.value ? adminFilter.value || undefined : undefined
  const { transfers: rows, meta } = await listTransfers(transferMeta.value.current_page, 10, status)
  transfers.value = rows
  transferMeta.value = { current_page: meta.current_page, last_page: meta.last_page, total: meta.total }
}

function currentCardFor(productId?: number | null, variationId?: number | null): StockCard | null {
  if (variationId != null) return cards.value.find((c) => c.product_variation_id === variationId) ?? null
  if (productId != null) return cards.value.find((c) => c.product_id === productId && c.product_variation_id == null) ?? null
  return null
}

function itemImage(item: { product?: { images?: { url: string }[] } | null }): string | null {
  return item.product?.images?.[0]?.url ?? null
}

async function approve(id: number) {
  error.value = ''; success.value = ''
  try {
    await approveTransfer(id)
    success.value = 'Transfer disetujui. Plan berkurang, Transit bertambah.'
    await Promise.all([loadTransfers(), loadCards()])
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal menyetujui transfer.' }
}

async function reject(id: number) {
  error.value = ''; success.value = ''
  try {
    const reason = (rejectReason.value[id] ?? '').trim()
    if (!reason) throw new Error('Isi alasan penolakan.')
    await rejectTransfer(id, reason)
    success.value = 'Transfer ditolak.'
    delete rejectReason.value[id]
    await loadTransfers()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Gagal menolak transfer.' }
}

async function load() {
  planEnabled.value = (await getWarehouseSetting()).factory_plan_enabled
  await Promise.all([loadCatalog(), loadTransfers(), loadCards()])
}

function onCardSearch() {
  cardMeta.value.current_page = 1
  void loadCards()
}

function onTransferPage(delta: number) {
  transferMeta.value.current_page = Math.max(1, transferMeta.value.current_page + delta)
  void loadTransfers()
}

onMounted(load)
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold">Stock Transfer</h1>
    <div class="mb-5 flex items-center gap-3 text-sm text-stone-500">
      <span>Plan Pabrik → Transit</span>
      <span class="rounded-full bg-stone-100 px-3 py-1 text-xs">Plan Pabrik: {{ planEnabled ? 'Aktif' : 'Nonaktif' }}</span>
    </div>
    <p v-if="error" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>
    <p v-if="success" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-600">{{ success }}</p>

    <div v-if="!planEnabled" class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
      Plan Pabrik sedang nonaktif. Aktifkan Plan Pabrik melalui pengaturan yang berwenang sebelum membuat Stock Transfer.
    </div>

    <!-- ================= GUDANG POS WORKSPACE ================= -->
    <section v-if="!isAdmin && planEnabled" class="mb-8">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-display text-xl font-semibold">Transfer Plan ke Transit</h2>
        <span class="rounded-full bg-stone-100 px-3 py-1 text-xs text-stone-500">PLAN PABRIK ↓ TRANSIT</span>
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

        <!-- RIGHT: selected transfer item -->
        <div class="min-w-0 lg:sticky lg:top-4 lg:self-start">
          <div class="rounded-xl border border-stone-200 bg-white p-4">
            <div v-if="!selectedProduct" class="py-8 text-center text-sm text-stone-400">Pilih produk dari katalog untuk mulai transfer.</div>
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
                  <div class="col-span-2 rounded-lg bg-brand-50 p-2"><dt class="text-xs font-semibold uppercase text-brand-700">Plan Pabrik Tersedia</dt><dd class="text-lg font-bold text-brand-800">{{ stockInfo.factory_plan }}</dd></div>
                  <div><dt class="text-xs text-stone-400">Transit</dt><dd class="font-semibold">{{ stockInfo.transit }}</dd></div>
                  <div><dt class="text-xs text-stone-400">Reserved</dt><dd class="font-semibold">{{ stockInfo.reserved }}</dd></div>
                  <div class="col-span-2"><dt class="text-xs text-stone-400">Stok Jual</dt><dd class="font-semibold text-brand-700">{{ stockInfo.available }}</dd></div>
                </dl>
              </div>

              <div class="mb-4 flex items-center justify-center gap-2 rounded-xl bg-stone-900 py-2 text-sm font-bold text-white">
                <span>PLAN PABRIK</span><span>↓</span><span>TRANSIT</span>
              </div>

              <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500">Jumlah Transfer</p>
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
              >+ Tambah ke Daftar Transfer</button>
            </div>
          </div>
        </div>
      </div>

      <!-- BOTTOM/WIDE: transfer cart -->
      <div class="mt-4 rounded-xl border border-stone-200 bg-white p-4">
        <h3 class="mb-3 font-semibold">Daftar Transfer ({{ cart.length }})</h3>
        <div v-if="!cart.length" class="text-sm text-stone-400">Daftar transfer masih kosong. Pilih produk lalu tambah ke daftar.</div>
        <ul v-else class="grid gap-2">
          <li v-for="line in cart" :key="line.key" class="flex flex-wrap items-center gap-3 rounded-xl border border-stone-100 p-2">
            <img v-if="line.imageUrl" :src="line.imageUrl" :alt="line.productName" class="h-10 w-10 shrink-0 rounded-lg object-cover" />
            <div class="min-w-0 flex-1 basis-40">
              <div class="truncate text-sm font-medium">{{ line.productName }}</div>
              <div class="truncate text-xs text-stone-400">
                <span v-if="line.variationLabel">{{ line.variationLabel }} · </span>{{ skuLabel(line.sku) }} · Plan tersedia: {{ line.planAvailable }}
              </div>
              <div class="text-xs font-semibold text-stone-500">PLAN → TRANSIT × {{ line.quantity }}</div>
            </div>
            <div class="flex shrink-0 items-center gap-1">
              <button type="button" :disabled="submitting" class="h-8 w-8 rounded-lg bg-stone-100 font-bold disabled:opacity-40" @click="bumpLine(line, -1)">−</button>
              <span class="w-10 text-center text-sm font-semibold">{{ line.quantity }}</span>
              <button type="button" :disabled="submitting" class="h-8 w-8 rounded-lg bg-stone-100 font-bold disabled:opacity-40" @click="bumpLine(line, 1)">+</button>
            </div>
            <button type="button" :disabled="submitting" class="shrink-0 text-xs text-red-600 underline disabled:opacity-40" @click="removeLine(line.key)">Hapus</button>
          </li>
        </ul>
        <button
          v-if="cart.length"
          type="button"
          :disabled="submitting"
          class="mt-3 w-full rounded-xl bg-stone-900 px-4 py-3 font-semibold text-white disabled:opacity-40 sm:w-auto"
          @click="submitCart"
        >{{ submitting ? 'Mengirim...' : 'Kirim Permintaan Transfer ke Admin' }}</button>
      </div>
    </section>

    <!-- ================= GUDANG HISTORY ================= -->
    <section v-if="!isAdmin" class="mb-6 rounded-xl border border-stone-200 bg-white p-4">
      <h2 class="mb-3 font-display text-xl font-semibold">Riwayat Transfer</h2>
      <div v-if="!transfers.length" class="text-sm text-stone-400">Belum ada transfer.</div>
      <ul v-else class="grid gap-2">
        <li v-for="transfer in transfers" :key="transfer.id" class="rounded-xl border border-stone-100 p-3 text-sm">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="font-medium">{{ transfer.transfer_number }}</span>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="transferStatusClass(transfer.status)">{{ transferStatusLabel(transfer.status) }}</span>
          </div>
          <ul class="mt-2 grid gap-1">
            <li v-for="item in transfer.items" :key="item.id" class="text-stone-500">
              {{ itemName(item.product, item.product_variation_id, item.product_id) }}
              <span v-if="item.variation?.label"> ({{ item.variation.label }})</span>
              · {{ skuLabel(item.variation?.sku ?? item.product?.sku ?? null) }} · × {{ item.quantity }} · Plan → Transit
            </li>
          </ul>
        </li>
      </ul>
      <div v-if="transferMeta.last_page > 1" class="mt-3 flex items-center gap-2 text-sm">
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="transferMeta.current_page <= 1" @click="onTransferPage(-1)">Prev</button>
        <span>Halaman {{ transferMeta.current_page }} / {{ transferMeta.last_page }} ({{ transferMeta.total }})</span>
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="transferMeta.current_page >= transferMeta.last_page" @click="onTransferPage(1)">Next</button>
      </div>
    </section>

    <!-- ================= ADMIN QUEUE ================= -->
    <section v-if="isAdmin" class="mb-6 rounded-xl border border-stone-200 bg-white p-4">
      <h2 class="mb-3 font-display text-xl font-semibold">Permintaan Stock Transfer — Plan Pabrik → Transit</h2>
      <div class="mb-3 flex max-w-xl gap-2">
        <select v-model="adminFilter" class="rounded-lg border border-stone-200 px-3 py-2 text-sm" @change="loadTransfers(true)">
          <option value="pending">Menunggu</option>
          <option value="completed">Selesai</option>
          <option value="rejected">Ditolak</option>
          <option value="">Semua</option>
        </select>
      </div>
      <div v-if="!transfers.length" class="text-sm text-stone-400">Belum ada permintaan.</div>
      <ul class="grid gap-3">
        <li v-for="transfer in transfers" :key="transfer.id" class="rounded-xl border border-stone-100 p-3">
          <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm">
            <span class="font-medium">{{ transfer.transfer_number }}</span>
            <span class="text-xs text-stone-400">{{ transfer.creator?.name ?? '' }} · {{ transfer.created_at ?? '' }}</span>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="transferStatusClass(transfer.status)">{{ transferStatusLabel(transfer.status) }}</span>
          </div>
          <ul class="grid gap-2">
            <li v-for="item in transfer.items" :key="item.id" class="flex flex-wrap items-center gap-3 rounded-lg bg-stone-50 p-2 text-sm">
              <img v-if="itemImage(item)" :src="itemImage(item) ?? ''" :alt="itemName(item.product, item.product_variation_id, item.product_id)" class="h-12 w-12 shrink-0 rounded-xl object-cover" />
              <div v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-stone-200 text-[10px] text-stone-400">Tanpa foto</div>
              <div class="min-w-0 flex-1 basis-48">
                <div class="font-medium">{{ itemName(item.product, item.product_variation_id, item.product_id) }}</div>
                <div v-if="item.variation?.label" class="text-xs text-stone-500">Varian: {{ item.variation.label }}</div>
                <div class="truncate text-xs text-stone-400">{{ skuLabel(item.variation?.sku ?? item.product?.sku ?? null) }}</div>
                <div class="mt-1 grid grid-cols-3 gap-1 text-xs">
                  <span class="rounded bg-white px-2 py-1">Plan saat ini: <strong>{{ currentCardFor(item.product_id, item.product_variation_id)?.factory_plan ?? '—' }}</strong></span>
                  <span class="rounded bg-white px-2 py-1">Transit saat ini: <strong>{{ currentCardFor(item.product_id, item.product_variation_id)?.transit ?? '—' }}</strong></span>
                  <span class="rounded bg-white px-2 py-1">Minta: <strong>+{{ item.quantity }}</strong></span>
                </div>
                <div class="mt-1 text-xs font-semibold text-stone-500">PLAN PABRIK → TRANSIT</div>
              </div>
              <div v-if="transfer.status === 'pending'" class="flex shrink-0 flex-wrap items-center gap-2">
                <button type="button" class="rounded-lg bg-emerald-600 px-3 py-1 text-sm text-white" @click="approve(transfer.id)">Setujui</button>
                <input v-model="rejectReason[transfer.id]" placeholder="Alasan tolak" class="rounded-lg border border-stone-200 bg-white px-2 py-1 text-sm" />
                <button type="button" class="rounded-lg bg-red-600 px-3 py-1 text-sm text-white" @click="reject(transfer.id)">Tolak</button>
              </div>
            </li>
          </ul>
        </li>
      </ul>
      <div v-if="transferMeta.last_page > 1" class="mt-3 flex items-center gap-2 text-sm">
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="transferMeta.current_page <= 1" @click="onTransferPage(-1)">Prev</button>
        <span>Halaman {{ transferMeta.current_page }} / {{ transferMeta.last_page }} ({{ transferMeta.total }})</span>
        <button type="button" class="rounded-lg border border-stone-200 px-3 py-1" :disabled="transferMeta.current_page >= transferMeta.last_page" @click="onTransferPage(1)">Next</button>
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
