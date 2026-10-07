<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { listWarehouseDiprosesOrders, getWarehouseStockRequestByOrder, proposeWarehouseFulfillment, type WarehouseStockRequest } from '@/api/warehouseFulfillment'
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
 * (operational projection — no financial figures for Gudang), and submits a
 * fulfillment proposal for the order's internal stock request. Admin reviews
 * it (approve/reject). When the order gets a courier or leaves 'diproses',
 * it disappears from this list.
 */
const router = useRouter()
const orders = ref<Awaited<ReturnType<typeof listWarehouseDiprosesOrders>>['orders']>([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
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
const quantities = ref<Record<number, number>>({})
const submitting = ref(false)
const proposalError = ref('')

const paginated = computed(() => meta.value)

async function load(page = 1) {
  loading.value = true
  errorMessage.value = ''
  try {
    const result = await listWarehouseDiprosesOrders(page)
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

async function submitProposal() {
  if (!request.value || submitting.value) return
  proposalError.value = ''
  successMessage.value = ''
  const items = request.value.items
    .filter((item) => (quantities.value[item.id] ?? 0) > 0)
    .map((item) => ({ item_id: item.id, quantity: Math.min(quantities.value[item.id] ?? 0, item.remaining_qty) }))
  if (!items.length) {
    proposalError.value = 'Isi jumlah usulan untuk minimal satu item.'
    return
  }
  submitting.value = true
  try {
    await proposeWarehouseFulfillment(request.value.id, items)
    for (const item of request.value.items) delete quantities.value[item.id]
    successMessage.value = 'Usulan fulfillment dikirim ke Admin. Stok belum berubah sampai disetujui.'
    closeProposalDrawer()
    await load(meta.value.current_page)
  } catch (e) {
    proposalError.value = e instanceof ApiError ? formatApiError(e) : 'Gagal mengirim usulan.'
  } finally {
    submitting.value = false
  }
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
      Antrean pekerjaan gudang — order berstatus diproses yang belum memiliki kurir. Usulkan fulfillment untuk
      diproses Admin.
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

    <div v-if="loading" class="space-y-3">
      <div v-for="i in 3" :key="i" class="h-20 animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
    </div>

    <div v-else-if="!orders.length" class="flex flex-col items-center gap-3 rounded-2xl bg-stone-100 py-16 text-center dark:bg-stone-900">
      <AppIcon name="box" :size="40" class="text-stone-300 dark:text-stone-700" />
      <p class="text-sm text-stone-500 dark:text-stone-400">Belum ada order diproses tanpa kurir.</p>
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
            <AppButton size="sm" @click="openProposalDrawer(order.id)">Usulkan fulfillment</AppButton>
          </div>
        </div>
        <p v-if="order.konsumen" class="mt-1 text-xs text-stone-400">Konsumen: {{ order.konsumen.name }}</p>
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
            <!-- UAT consolidated B — every eligible OrderItem is its own work unit. Proposing one
                 item never proposes its siblings; the drawer pre-fills only that item's lines. -->
            <AppButton size="sm" variant="secondary" type="button" @click="openProposalDrawer(order.id, item.id, item.product_name)">
              Usulkan Pemenuhan
            </AppButton>
          </li>
        </ul>

        <div v-if="openRequestId === order.id" class="mt-3 rounded-xl bg-stone-50 p-3 dark:bg-stone-800/60">
          <p v-if="targetOrderItemName" class="mb-2 text-xs font-medium text-stone-600 dark:text-stone-300">
            Mengusulkan untuk: {{ targetOrderItemName }} — item lain tidak ikut diusulkan.
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
                class="w-24 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </div>
            <div class="flex gap-2 pt-1">
              <AppButton size="sm" type="button" :disabled="submitting" @click="submitProposal">
                {{ submitting ? 'Mengirim...' : 'Ajukan ke Admin' }}
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