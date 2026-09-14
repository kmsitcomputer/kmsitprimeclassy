<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { updateShipmentStatus } from '@/api/shipments'
import {
  listCourierOrders,
  listCourierReturns,
  pickupReturnItem,
  confirmReturnItem,
  courierDeliveredReport,
  type CourierOrder,
  type CourierReturn,
} from '@/api/courier'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { formatDate, formatRupiah, skuLabel } from '@/utils/format'

const tab = ref<'orders' | 'returns' | 'selesai'>('orders')
const loading = ref(false)
const orders = ref<CourierOrder[]>([])
const returns = ref<CourierReturn[]>([])
const delivered = ref<CourierOrder[]>([])
const ordersPage = ref(1)
const ordersLastPage = ref(1)
const returnsPage = ref(1)
const returnsLastPage = ref(1)
const deliveredPage = ref(1)
const deliveredLastPage = ref(1)
const deliveredFrom = ref('')
const deliveredTo = ref('')
const busyShipmentId = ref<number | null>(null)
const busyReturnItemId = ref<number | null>(null)
const pickupNoteDraft = ref<Record<number, string>>({})
const pickingUpItemId = ref<number | null>(null)
const proofInputs = ref<Record<number, HTMLInputElement | null>>({})
const errorMessage = ref('')

async function loadOrders(page = ordersPage.value) {
  loading.value = true
  errorMessage.value = ''
  try {
    const { orders: rows, meta } = await listCourierOrders(page)
    orders.value = rows
    ordersPage.value = meta.current_page
    ordersLastPage.value = meta.last_page
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat daftar order.'
  } finally {
    loading.value = false
  }
}

async function loadReturns(page = returnsPage.value) {
  loading.value = true
  errorMessage.value = ''
  try {
    const { returns: rows, meta } = await listCourierReturns(page)
    returns.value = rows
    returnsPage.value = meta.current_page
    returnsLastPage.value = meta.last_page
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat daftar retur.'
  } finally {
    loading.value = false
  }
}

async function loadDelivered(page = deliveredPage.value) {
  loading.value = true
  errorMessage.value = ''
  try {
    const { orders: rows, meta } = await courierDeliveredReport(
      page,
      deliveredFrom.value || undefined,
      deliveredTo.value || undefined,
    )
    delivered.value = rows
    deliveredPage.value = meta.current_page
    deliveredLastPage.value = meta.last_page
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat daftar order selesai.'
  } finally {
    loading.value = false
  }
}

function switchTab(next: 'orders' | 'returns' | 'selesai') {
  tab.value = next
  if (next === 'orders') loadOrders(1)
  else if (next === 'returns') loadReturns(1)
  else loadDelivered(1)
}

async function pickup(shipmentId: number | null) {
  if (shipmentId === null) return
  busyShipmentId.value = shipmentId
  try {
    await updateShipmentStatus(shipmentId, 'dikirim')
    await loadOrders()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal mengambil order ini.'
  } finally {
    busyShipmentId.value = null
  }
}

function triggerProofPicker(shipmentId: number | null) {
  if (shipmentId === null) return
  proofInputs.value[shipmentId]?.click()
}

async function markDelivered(shipmentId: number | null, event: Event) {
  if (shipmentId === null) return
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  busyShipmentId.value = shipmentId
  try {
    await updateShipmentStatus(shipmentId, 'terkirim', file)
    await loadOrders()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menandai order terkirim. Pastikan foto bukti terlampir.'
  } finally {
    busyShipmentId.value = null
    input.value = ''
  }
}

function openPickupNote(itemId: number) {
  pickingUpItemId.value = itemId
  pickupNoteDraft.value[itemId] = ''
}

async function pickupReturn(itemId: number) {
  busyReturnItemId.value = itemId
  try {
    await pickupReturnItem(itemId, pickupNoteDraft.value[itemId]?.trim() || undefined)
    pickingUpItemId.value = null
    await loadReturns()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal mengambil barang retur ini.'
  } finally {
    busyReturnItemId.value = null
  }
}

async function confirmReturn(itemId: number, received: boolean) {
  busyReturnItemId.value = itemId
  try {
    await confirmReturnItem(itemId, received)
    await loadReturns()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal mengonfirmasi barang retur ini.'
  } finally {
    busyReturnItemId.value = null
  }
}

onMounted(() => loadOrders(1))
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Pengiriman Saya</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
      Ambil order untuk dikirim, tandai terkirim dengan foto bukti, dan kelola pengambilan retur.
    </p>

    <div class="mb-5 flex gap-2 border-b border-stone-200 dark:border-stone-800">
      <button
        type="button"
        class="border-b-2 px-3 py-2 text-sm font-medium"
        :class="tab === 'orders' ? 'border-brand-600 text-brand-700 dark:border-brand-400 dark:text-brand-300' : 'border-transparent text-stone-500 dark:text-stone-400'"
        @click="switchTab('orders')"
      >
        Order
      </button>
      <button
        type="button"
        class="border-b-2 px-3 py-2 text-sm font-medium"
        :class="tab === 'returns' ? 'border-brand-600 text-brand-700 dark:border-brand-400 dark:text-brand-300' : 'border-transparent text-stone-500 dark:text-stone-400'"
        @click="switchTab('returns')"
      >
        Retur
      </button>
      <button
        type="button"
        class="border-b-2 px-3 py-2 text-sm font-medium"
        :class="tab === 'selesai' ? 'border-brand-600 text-brand-700 dark:border-brand-400 dark:text-brand-300' : 'border-transparent text-stone-500 dark:text-stone-400'"
        @click="switchTab('selesai')"
      >
        Selesai
      </button>
    </div>

    <p v-if="errorMessage" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ errorMessage }}
    </p>

    <p v-if="loading" class="text-sm text-stone-500 dark:text-stone-400">Memuat...</p>

    <template v-else-if="tab === 'orders'">
      <p v-if="orders.length === 0" class="text-sm text-stone-500 dark:text-stone-400">Tidak ada order saat ini.</p>
      <div v-else class="space-y-3">
        <div
          v-for="order in orders"
          :key="order.id"
          class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm dark:border-stone-800 dark:bg-stone-900"
        >
          <div class="mb-2 flex items-start justify-between gap-2">
            <div>
              <RouterLink :to="{ name: 'order-detail', params: { id: order.id } }" class="font-semibold text-stone-800 hover:underline dark:text-stone-100">
                {{ order.order_no }}
              </RouterLink>
              <p class="text-xs text-stone-400 dark:text-stone-500">{{ order.status }}</p>
            </div>
          </div>
          <div class="mb-3 text-sm text-stone-600 dark:text-stone-300">
            <p class="font-medium">{{ order.recipient_name }} · {{ order.recipient_phone }}</p>
            <p class="text-stone-500 dark:text-stone-400">
              {{ order.address }}, {{ order.village }}, {{ order.district }}, {{ order.regency }}, {{ order.province }}
            </p>
          </div>
          <ul class="mb-3 space-y-1 text-sm text-stone-600 dark:text-stone-300">
            <li v-for="item in order.items" :key="item.id" class="flex items-center justify-between gap-2">
              <span>
                {{ item.product_name }}<span v-if="item.variation_label"> — {{ item.variation_label }}</span> × {{ item.quantity }}
                <span class="block text-xs text-stone-400 dark:text-stone-500">{{ skuLabel(item.sku) }}</span>
                <span v-if="item.requested_delivery_date" class="block text-xs text-stone-400 dark:text-stone-500">
                  Kirim: {{ formatDate(item.requested_delivery_date) }}
                </span>
              </span>
              <span class="flex shrink-0 items-center gap-2">
                <span class="text-xs text-stone-400 dark:text-stone-500">{{ item.status }}</span>
                <button
                  v-if="item.shipment_id && item.status === 'diproses'"
                  type="button"
                  class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-700 disabled:opacity-50"
                  :disabled="busyShipmentId === item.shipment_id"
                  @click="pickup(item.shipment_id)"
                >
                  Ambil
                </button>
                <template v-else-if="item.shipment_id && item.status === 'dikirim'">
                  <input
                    :ref="(el) => (proofInputs[item.shipment_id as number] = el as HTMLInputElement)"
                    type="file"
                    accept="image/*"
                    capture="environment"
                    class="hidden"
                    @change="markDelivered(item.shipment_id, $event)"
                  />
                  <button
                    type="button"
                    class="flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                    :disabled="busyShipmentId === item.shipment_id"
                    @click="triggerProofPicker(item.shipment_id)"
                  >
                    <AppIcon name="upload" :size="14" /> Terkirim
                  </button>
                </template>
              </span>
            </li>
          </ul>
        </div>
      </div>

      <div v-if="ordersLastPage > 1" class="mt-6 flex items-center justify-center gap-2 text-sm">
        <button
          type="button"
          class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700"
          :disabled="ordersPage <= 1"
          @click="loadOrders(ordersPage - 1)"
        >
          Sebelumnya
        </button>
        <span class="text-stone-500">{{ ordersPage }} / {{ ordersLastPage }}</span>
        <button
          type="button"
          class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700"
          :disabled="ordersPage >= ordersLastPage"
          @click="loadOrders(ordersPage + 1)"
        >
          Berikutnya
        </button>
      </div>
    </template>

    <template v-else-if="tab === 'returns'">
      <p v-if="returns.length === 0" class="text-sm text-stone-500 dark:text-stone-400">Tidak ada retur saat ini.</p>
      <div v-else class="space-y-3">
        <div
          v-for="ret in returns"
          :key="ret.id"
          class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm dark:border-stone-800 dark:bg-stone-900"
        >
          <div class="mb-2">
            <RouterLink :to="{ name: 'order-detail', params: { id: ret.order_id } }" class="font-semibold text-stone-800 hover:underline dark:text-stone-100">
              {{ ret.order_no }}
            </RouterLink>
            <p class="text-xs text-stone-400 dark:text-stone-500">Alasan: {{ ret.reason }}</p>
          </div>
          <ul class="space-y-2 text-sm text-stone-600 dark:text-stone-300">
            <li v-for="item in ret.items" :key="item.id" class="space-y-1.5">
              <div class="flex items-center justify-between gap-2">
                <span>
                  {{ item.product_name }}<span v-if="item.variation_label"> — {{ item.variation_label }}</span> × {{ item.quantity_returned }}
                  <span class="block text-xs text-stone-400 dark:text-stone-500">{{ skuLabel(item.sku) }}</span>
                </span>
                <span class="flex shrink-0 items-center gap-2">
                  <button
                    v-if="!item.picked_up_by_me && pickingUpItemId !== item.id"
                    type="button"
                    class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-700 disabled:opacity-50"
                    :disabled="busyReturnItemId === item.id"
                    @click="openPickupNote(item.id)"
                  >
                    Ambil Barang
                  </button>
                  <template v-else-if="item.picked_up_by_me">
                    <button
                      type="button"
                      class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                      :disabled="busyReturnItemId === item.id"
                      @click="confirmReturn(item.id, true)"
                    >
                      Refund
                    </button>
                    <button
                      type="button"
                      class="rounded-lg bg-stone-200 px-3 py-1.5 text-xs font-medium text-stone-700 hover:bg-stone-300 disabled:opacity-50 dark:bg-stone-700 dark:text-stone-200"
                      :disabled="busyReturnItemId === item.id"
                      @click="confirmReturn(item.id, false)"
                    >
                      Terkirim
                    </button>
                  </template>
                </span>
              </div>

              <!-- Pickup note: attached once, before the item is claimed. -->
              <div v-if="pickingUpItemId === item.id" class="flex items-center gap-2">
                <input
                  v-model="pickupNoteDraft[item.id]"
                  type="text"
                  placeholder="Catatan kondisi barang (opsional)"
                  class="flex-1 rounded-lg border border-stone-200 bg-white px-2 py-1 text-xs dark:border-stone-700 dark:bg-stone-950"
                />
                <button
                  type="button"
                  class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-700 disabled:opacity-50"
                  :disabled="busyReturnItemId === item.id"
                  @click="pickupReturn(item.id)"
                >
                  Konfirmasi Ambil
                </button>
                <button type="button" class="text-xs text-stone-400" @click="pickingUpItemId = null">Batal</button>
              </div>
              <p v-else-if="item.condition_note" class="text-xs text-stone-400">Catatan: {{ item.condition_note }}</p>
            </li>
          </ul>
        </div>
      </div>

      <div v-if="returnsLastPage > 1" class="mt-6 flex items-center justify-center gap-2 text-sm">
        <button
          type="button"
          class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700"
          :disabled="returnsPage <= 1"
          @click="loadReturns(returnsPage - 1)"
        >
          Sebelumnya
        </button>
        <span class="text-stone-500">{{ returnsPage }} / {{ returnsLastPage }}</span>
        <button
          type="button"
          class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700"
          :disabled="returnsPage >= returnsLastPage"
          @click="loadReturns(returnsPage + 1)"
        >
          Berikutnya
        </button>
      </div>
    </template>

    <template v-else>
      <div class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
        <label class="text-sm text-stone-600 dark:text-stone-300">
          Dari
          <input v-model="deliveredFrom" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
        </label>
        <label class="text-sm text-stone-600 dark:text-stone-300">
          Sampai
          <input v-model="deliveredTo" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
        </label>
        <button
          type="button"
          class="rounded-lg bg-stone-800 px-3 py-2 text-xs font-medium text-white hover:bg-stone-900 dark:bg-stone-100 dark:text-stone-900"
          @click="loadDelivered(1)"
        >
          Terapkan
        </button>
      </div>

      <p v-if="delivered.length === 0" class="text-sm text-stone-500 dark:text-stone-400">Belum ada order selesai.</p>
      <div v-else class="space-y-3">
        <div
          v-for="order in delivered"
          :key="order.id"
          class="rounded-2xl border border-stone-200 bg-white p-4 shadow-sm dark:border-stone-800 dark:bg-stone-900"
        >
          <div class="flex items-start justify-between gap-2">
            <div>
              <RouterLink :to="{ name: 'order-detail', params: { id: order.id } }" class="font-semibold text-stone-800 hover:underline dark:text-stone-100">
                {{ order.order_no }}
              </RouterLink>
              <p class="text-xs text-stone-500 dark:text-stone-400">{{ order.recipient_name }}</p>
            </div>
            <span class="shrink-0 font-display text-sm font-semibold text-emerald-700 dark:text-emerald-400">
              {{ formatRupiah(order.fee_amount ?? 0) }}
            </span>
          </div>
        </div>
      </div>

      <div v-if="deliveredLastPage > 1" class="mt-6 flex items-center justify-center gap-2 text-sm">
        <button
          type="button"
          class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700"
          :disabled="deliveredPage <= 1"
          @click="loadDelivered(deliveredPage - 1)"
        >
          Sebelumnya
        </button>
        <span class="text-stone-500">{{ deliveredPage }} / {{ deliveredLastPage }}</span>
        <button
          type="button"
          class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700"
          :disabled="deliveredPage >= deliveredLastPage"
          @click="loadDelivered(deliveredPage + 1)"
        >
          Berikutnya
        </button>
      </div>
    </template>
  </DashboardLayout>
</template>
