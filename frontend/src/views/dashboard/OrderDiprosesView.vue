<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { listWarehouseDiprosesOrders, getWarehouseStockRequestByOrder, submitWarehouseHandover, type HandoverLine, type QueueOrder, type WarehouseStockRequest } from '@/api/warehouseFulfillment'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { formatDate, orderStatusLabel } from '@/utils/format'

/**
 * IMP-001 UAT remediation (Gap 2) — Gudang's "Order Diproses" work queue.
 *
 * The user-facing "Stock Request" feature is gone. Gudang now works orders:
 * the queue lists ONLY orders with status === 'diproses' AND no courier
 * assigned (server-side scope — this page simply renders what the API
 * returns; it cannot bypass it). Gudang opens an order, sees its items
 * (operational projection — no financial figures for Gudang), and approves what it can hand over to the
 * Koordinator Kurir who asked for it: quantity per item, a reason for every short item, and the handover
 * evidence. The Koordinator confirms receipt and assigns the courier — the Admin takes no part in this step
 * and only decides on a reported shortage afterwards (Order page). When the order gets a courier or leaves
 * 'diproses', it disappears from this list.
 */
const router = useRouter()
const orders = ref<Awaited<ReturnType<typeof listWarehouseDiprosesOrders>>['orders']>([])
const meta = ref<{ current_page: number; last_page: number; total: number; requested_total?: number }>({ current_page: 1, last_page: 1, total: 0 })
/** Default: what the Koordinator Kurir asked for. The Gudang can still switch to every order in the queue. */
const filter = ref<'requested' | 'all'>('requested')
const loading = ref(true)
const errorMessage = ref('')
const successMessage = ref('')

// Proposal drawer per order. `targetOrderItemId` pins the drawer to ONE OrderItem when Gudang
// proposes per item (B) — only that item's stock-request lines are pre-filled; submitting an
// empty drawer is refused by the same validation as before.
const openRequestId = ref<number | null>(null)
const targetOrderItemId = ref<number | null>(null)
const targetOrderItemName = ref<string | null>(null)
const request = ref<WarehouseStockRequest | null>(null)
const quantities = ref<Record<number, number | string>>({})
/** Reason per item handed over for less than its outstanding quantity (or not at all). */
const reasons = ref<Record<number, string>>({})
const proofFile = ref<File | null>(null)
const submitting = ref(false)
const proposalError = ref('')

const paginated = computed(() => meta.value)

async function load(page = 1) {
  loading.value = true
  errorMessage.value = ''
  try {
    const result = await listWarehouseDiprosesOrders(page, filter.value === 'requested')
    orders.value = result.orders
    meta.value = result.meta
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat antrean order diproses.'
  } finally {
    loading.value = false
  }
}

async function openOrder(orderId: number) {
  await router.push({ name: 'order-detail', params: { id: orderId } })
}

/**
 * Load the order's internal stock request (the canonical persistence
 * behind the proposal flow) through the scoped endpoint — the backend
 * enforces the warehouse-queue invariant, so a stale/out-of-queue order
 * returns 404 and the drawer cannot be used to bypass the scope rule.
 */
/**
 * UAT consolidated B — per-item proposal. The same canonical endpoint is reused; only the
 * pre-fill changes: when an OrderItem is targeted, only its own stock-request lines
 * (matched by the canonical `order_item_id`) are proposed. Proposing Item A never touches Item B.
 */
async function openProposalDrawer(orderId: number, orderItemId: number | null = null, orderItemName: string | null = null) {
  proposalError.value = ''
  successMessage.value = ''
  openRequestId.value = orderId
  targetOrderItemId.value = orderItemId
  targetOrderItemName.value = orderItemName
  request.value = null
  quantities.value = {}
  reasons.value = {}
  proofFile.value = null
  try {
    request.value = await getWarehouseStockRequestByOrder(orderId)
    if (orderItemId !== null && request.value) {
      for (const line of request.value.items) {
        if (line.order_item_id === orderItemId && line.remaining_qty > 0) {
          quantities.value[line.id] = line.remaining_qty
        }
      }
    }
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) {
      proposalError.value = 'Order ini sudah tidak berada dalam antrean gudang (status berubah / kurir ditugaskan).'
      openRequestId.value = null
      await load(meta.value.current_page)
      return
    }
    proposalError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat detail order.'
  }
}

function closeProposalDrawer() {
  openRequestId.value = null
  targetOrderItemId.value = null
  targetOrderItemName.value = null
  request.value = null
}

function itemName(item: WarehouseStockRequest['items'][number]): string {
  return item.product_name ?? `Produk #${item.product_id}`
}

function enteredQuantity(id: number): number | null {
  const q = quantities.value[id]
  return typeof q === 'number' && Number.isFinite(q) ? q : null
}

/**
 * Lines this submission answers for. Opening the whole order means EVERY outstanding line is answered: a line left
 * blank counts as "nothing handed over" and therefore needs a reason, so a product Gudang cannot supply is always
 * reported (never silently skipped). Opening one product answers for that product only, plus any other line the
 * petugas explicitly typed a quantity for.
 */
function inScope(item: WarehouseStockRequest['items'][number]): boolean {
  if (item.remaining_qty <= 0) return false
  return targetOrderItemId.value === null || item.order_item_id === targetOrderItemId.value || enteredQuantity(item.id) !== null
}

function effectiveQuantity(item: WarehouseStockRequest['items'][number]): number {
  return enteredQuantity(item.id) ?? 0
}

/** Item is short when what Gudang hands over (blank = 0) is below what is still outstanding. */
function isShort(item: WarehouseStockRequest['items'][number]): boolean {
  return inScope(item) && effectiveQuantity(item) < item.remaining_qty
}

function onProof(ev: Event) {
  proofFile.value = (ev.target as HTMLInputElement).files?.[0] ?? null
}

async function submitHandover() {
  if (!request.value || submitting.value) return
  proposalError.value = ''
  successMessage.value = ''
  const items: HandoverLine[] = []
  const shortages: { item_id: number; reason: string }[] = []
  for (const item of request.value.items) {
    if (!inScope(item)) continue
    const q = effectiveQuantity(item)
    if (q < 0 || q > item.remaining_qty) {
      proposalError.value = `Jumlah ${itemName(item)} harus 0 sampai ${item.remaining_qty}.`
      return
    }
    const reason = (reasons.value[item.id] ?? '').trim()
    if (q < item.remaining_qty && reason.length < 3) {
      proposalError.value = `Isi alasan kekurangan untuk ${itemName(item)} (min. 3 karakter).`
      return
    }
    if (q === 0) shortages.push({ item_id: item.id, reason })
    else items.push({ item_id: item.id, quantity: q, ...(q < item.remaining_qty ? { shortage_reason: reason } : {}) })
  }
  if (!items.length && !shortages.length) {
    proposalError.value = 'Tidak ada item yang dapat diproses pada order ini.'
    return
  }
  if (items.length && !proofFile.value) {
    proposalError.value = 'Lampirkan bukti serah terima barang.'
    return
  }
  submitting.value = true
  try {
    await submitWarehouseHandover(request.value.id, { items, shortages, proof: proofFile.value })
    successMessage.value = items.length
      ? 'Penyerahan disetujui dan dicatat dengan bukti. Menunggu Koordinator Kurir mengonfirmasi penerimaan.'
      : 'Kekurangan tercatat. Admin diberi tahu pada halaman Order.'
    closeProposalDrawer()
    await load(meta.value.current_page)
  } catch (e) {
    proposalError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menyimpan penyerahan.'
  } finally {
    submitting.value = false
  }
}

function setFilter(next: 'requested' | 'all') {
  if (filter.value === next) return
  filter.value = next
  closeProposalDrawer()
  void load(1)
}

function requestStatusLabel(r: NonNullable<QueueOrder['fulfillment_request']>): string {
  if (r.awaiting_receipt_qty > 0) return 'Diserahkan, menunggu konfirmasi penerimaan Koordinator'
  if (r.outstanding_qty === 0) return 'Seluruh permintaan terpenuhi'
  if (r.handed_over_qty > 0) return 'Sebagian terpenuhi, sisa masih diminta'
  return 'Menunggu Gudang menyetujui & menyerahkan'
}

function onPage(d: number) {
  const next = Math.max(1, Math.min(meta.value.last_page, meta.value.current_page + d))
  void load(next)
}

onMounted(() => load())
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Order Diproses</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
      Antrean pekerjaan gudang — order berstatus diproses yang belum memiliki kurir. Setujui jumlah yang dapat
      diserahkan kepada Koordinator Kurir, catat alasan jika kurang, dan lampirkan bukti serah terima.
    </p>

    <p v-if="errorMessage" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ errorMessage }}
    </p>
    <p v-if="successMessage" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
      {{ successMessage }}
    </p>
    <p v-if="proposalError" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ proposalError }}
    </p>

    <div class="mb-4 flex flex-wrap gap-2" role="tablist" aria-label="Filter antrean">
      <button type="button" role="tab" data-testid="filter-requested" :aria-selected="filter === 'requested'"
        class="rounded-full border px-3 py-1 text-sm" :class="filter === 'requested' ? 'border-brand-600 bg-brand-50 font-semibold text-brand-700 dark:bg-brand-950 dark:text-brand-300' : 'border-stone-200 text-stone-600 dark:border-stone-700 dark:text-stone-300'"
        @click="setFilter('requested')">
        Permintaan Koordinator<span v-if="meta.requested_total !== undefined"> ({{ meta.requested_total }})</span>
      </button>
      <button type="button" role="tab" data-testid="filter-all" :aria-selected="filter === 'all'"
        class="rounded-full border px-3 py-1 text-sm" :class="filter === 'all' ? 'border-brand-600 bg-brand-50 font-semibold text-brand-700 dark:bg-brand-950 dark:text-brand-300' : 'border-stone-200 text-stone-600 dark:border-stone-700 dark:text-stone-300'"
        @click="setFilter('all')">
        Semua order diproses
      </button>
    </div>

    <div v-if="loading" class="space-y-3">
      <div v-for="i in 3" :key="i" class="h-20 animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
    </div>

    <div v-else-if="!orders.length" class="flex flex-col items-center gap-3 rounded-2xl bg-stone-100 py-16 text-center dark:bg-stone-900">
      <AppIcon name="box" :size="40" class="text-stone-300 dark:text-stone-700" />
      <p class="text-sm text-stone-500 dark:text-stone-400">
        {{ filter === 'requested' ? 'Belum ada permintaan pemenuhan dari Koordinator Kurir.' : 'Belum ada order diproses tanpa kurir.' }}
      </p>
      <AppButton v-if="filter === 'requested'" size="sm" variant="secondary" @click="setFilter('all')">Lihat semua order diproses</AppButton>
    </div>

    <div v-else class="space-y-3">
      <div v-for="order in orders" :key="order.id" class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <button type="button" class="text-left text-sm font-semibold text-brand-700 hover:underline dark:text-brand-300" @click="openOrder(order.id)">
              {{ order.order_no }}
            </button>
            <span class="ml-2 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-400">
              {{ orderStatusLabel(order.status) }}
            </span>
          </div>
          <div class="flex items-center gap-2">
            <AppButton size="sm" variant="secondary" @click="openOrder(order.id)">Buka order</AppButton>
            <AppButton size="sm" @click="openProposalDrawer(order.id)">Setujui &amp; Serahkan</AppButton>
          </div>
        </div>
        <p v-if="order.konsumen" class="mt-1 text-xs text-stone-400">Konsumen: {{ order.konsumen.name }}</p>
        <!-- The Koordinator Kurir's request: real identity/time/status from the stock request, never inferred. -->
        <div v-if="order.fulfillment_request?.requested_by_coordinator" data-testid="coordinator-request"
          class="mt-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100">
          <p>
            <b>Diminta Koordinator Kurir:</b> {{ order.fulfillment_request.coordinator?.name ?? '—' }}
            <span v-if="order.fulfillment_request.requested_at"> · {{ formatDate(order.fulfillment_request.requested_at) }}</span>
            · {{ order.fulfillment_request.request_number }}
          </p>
          <p v-if="order.fulfillment_request.note" class="mt-0.5">Catatan: {{ order.fulfillment_request.note }}</p>
          <p class="mt-0.5">
            Diminta {{ order.fulfillment_request.requested_qty }} · diserahkan {{ order.fulfillment_request.handed_over_qty }}
            <span v-if="order.fulfillment_request.awaiting_receipt_qty"> (+{{ order.fulfillment_request.awaiting_receipt_qty }} menunggu penerimaan)</span>
            · sisa {{ order.fulfillment_request.outstanding_qty }} — <b>{{ requestStatusLabel(order.fulfillment_request) }}</b>
          </p>
        </div>
        <p v-else-if="order.fulfillment_request" data-testid="no-coordinator-request" class="mt-2 text-xs text-stone-400">Belum diminta Koordinator Kurir.</p>
        <!-- UAT-005: the actual item/quantity/date demand Gudang is fulfilling —
             never only an opaque order-level count. Dates stay distinguishable
             per item (canonical OrderItem.requested_delivery_date). -->
        <ul class="mt-2 space-y-1 text-xs text-stone-600 dark:text-stone-300">
          <li v-for="item in order.items" :key="item.id" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5 rounded-lg bg-stone-50 px-2 py-1 dark:bg-stone-800/60">
            <span class="min-w-0 flex-1">
              <span class="font-medium">{{ item.product_name }}</span>
              <span v-if="item.variation_label" class="text-stone-500"> — {{ item.variation_label }}</span>
              <span class="text-stone-500"> × {{ item.fulfilled_quantity }}</span>
              <span class="ml-1 text-stone-400">({{ item.status }})</span>
              <span class="block text-stone-500 dark:text-stone-400">
                Kirim: {{ item.requested_delivery_date ? formatDate(item.requested_delivery_date) : '—' }}
              </span>
            </span>
            <!-- Every eligible OrderItem is its own work unit. Handing over one item never touches its
                 siblings; the drawer pre-fills only that item's lines. -->
            <AppButton size="sm" variant="secondary" type="button" @click="openProposalDrawer(order.id, item.id, item.product_name)">
              Setujui &amp; Serahkan
            </AppButton>
          </li>
        </ul>

        <div v-if="openRequestId === order.id" class="mt-3 rounded-xl bg-stone-50 p-3 dark:bg-stone-800/60">
          <p v-if="targetOrderItemName" class="mb-2 text-xs font-medium text-stone-600 dark:text-stone-300">
            Menyerahkan untuk: {{ targetOrderItemName }} — item lain tidak ikut diserahkan.
          </p>
          <p v-else class="mb-2 text-xs text-stone-600 dark:text-stone-300" data-testid="whole-order-hint">
            Isi jumlah yang dapat diserahkan untuk setiap produk. Produk yang dikosongkan dianggap tidak ada yang diserahkan
            dan wajib diberi alasan, sehingga kekurangannya tercatat.
          </p>
          <div v-if="request" class="space-y-2">
            <div v-for="item in request.items" :key="item.id" class="flex flex-wrap items-center gap-3 text-sm">
              <img v-if="item.product_image_url" :src="item.product_image_url" :alt="itemName(item)" class="h-10 w-10 shrink-0 rounded-lg object-cover" />
              <span class="min-w-0 flex-1 basis-48">
                <span class="font-medium">{{ itemName(item) }}</span>
                <span v-if="item.variation_label" class="text-stone-500"> — {{ item.variation_label }}</span>
                <span class="block text-xs text-stone-400">{{ item.sku ?? item.sku_snapshot }} · diminta {{ item.requested_qty }} · terpenuhi {{ item.fulfilled_qty }} · sisa {{ item.remaining_qty }}</span>
                <span class="block text-xs text-stone-400">
                  Transit {{ item.current_stock?.transit ?? '—' }} · Shipping {{ item.current_stock?.shipping ?? '—' }} · Reserved {{ item.current_stock?.reserved ?? '—' }}
                </span>
              </span>
              <input v-model.number="quantities[item.id]" type="number" min="0" :max="item.remaining_qty" :disabled="item.remaining_qty === 0 || submitting"
                :aria-label="`Jumlah diserahkan ${itemName(item)}`"
                class="w-24 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
              <input v-if="isShort(item)" v-model="reasons[item.id]" type="text" maxlength="255" :disabled="submitting"
                :aria-label="`Alasan kekurangan ${itemName(item)}`"
                :placeholder="`Alasan kurang ${item.remaining_qty - effectiveQuantity(item)} unit (wajib)`"
                class="min-w-[14rem] flex-1 basis-full rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm dark:border-amber-700 dark:bg-stone-950" />
            </div>
            <label class="mt-1 block text-xs text-stone-600 dark:text-stone-300">
              Bukti serah terima (foto) — wajib jika ada barang diserahkan
              <input type="file" accept="image/*" :disabled="submitting" class="mt-1 block text-xs" @change="onProof" />
            </label>
            <div class="flex gap-2 pt-1">
              <AppButton size="sm" type="button" :disabled="submitting" @click="submitHandover">
                {{ submitting ? 'Menyimpan...' : 'Setujui & Serahkan ke Koordinator' }}
              </AppButton>
              <AppButton size="sm" variant="ghost" type="button" @click="closeProposalDrawer">Batal</AppButton>
            </div>
          </div>
          <p v-else class="text-sm text-stone-500 dark:text-stone-400">Memuat detail...</p>
        </div>
      </div>

      <div v-if="paginated.last_page > 1" class="flex items-center justify-between gap-2 text-sm">
        <AppButton size="sm" variant="secondary" :disabled="paginated.current_page <= 1" @click="onPage(-1)">Prev</AppButton>
        <span class="text-stone-500">Halaman {{ paginated.current_page }} / {{ paginated.last_page }} ({{ paginated.total }})</span>
        <AppButton size="sm" variant="secondary" :disabled="paginated.current_page >= paginated.last_page" @click="onPage(1)">Next</AppButton>
      </div>
    </div>
  </DashboardLayout>
</template>