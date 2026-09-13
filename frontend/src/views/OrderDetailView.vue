<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import ShopLayout from '@/layouts/ShopLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { getOrder, cancelOrder, submitBankTransferProof, updateOrderStatus } from '@/api/orders'
import { verifyBankTransfer, markCodPayment, submitCodPaymentProof, confirmCodPayment } from '@/api/payments'
import { updateShipmentStatus, assignCourier } from '@/api/shipments'
import { getCourierReport } from '@/api/reports'
import { adjustItemFulfillment, rescheduleOrderItem } from '@/api/orderAdjustments'
import { requestReturn } from '@/api/returns'
import { useAuthStore } from '@/stores/auth'
import type { Order, OrderItem } from '@/api/types'
import { formatRupiah, formatDate, orderStatusLabel, paymentStatusLabel } from '@/utils/format'
import { ApiError } from '@/api/client'

const props = defineProps<{ id: number }>()
const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()

const order = ref<Order | null>(null)
const loading = ref(true)
const cancelling = ref(false)
const cancelReason = ref('')
const showCancelForm = ref(false)
const cancelError = ref<string | null>(null)

const proofFile = ref<File | null>(null)
const uploadingProof = ref(false)
const paymentActionError = ref<string | null>(null)
const verifying = ref(false)
const codUpdating = ref(false)
const rejectionReason = ref('')

async function load() {
  loading.value = true
  order.value = await getOrder(props.id)
  loading.value = false
}

onMounted(load)
onMounted(loadActiveCouriers)

/* ---------- Payment verification/settlement — Keuangan's authority (separation of duties), super_admin override.
   Admin/agen may submit proof and view status but must never settle it (backend routes are super_admin,keuangan only). */
const canVerifyBankTransfer = computed(() => auth.user?.role === 'super_admin' || auth.can('finance.payment.verify'))
const canSettleCod = computed(() => auth.user?.role === 'super_admin' || auth.can('finance.cod.settle'))

/* ---------- Office: advance the order out of 'diterima' into 'diproses' ---------- */
const canManageStatus = computed(() => auth.can('orders.manage.status'))
const processingOrder = ref(false)
const processOrderError = ref<string | null>(null)

async function processOrder() {
  if (!order.value) return
  processingOrder.value = true
  processOrderError.value = null
  try {
    order.value = await updateOrderStatus(order.value.id, 'diproses')
  } catch (e) {
    processOrderError.value = e instanceof ApiError ? e.message : 'Gagal memproses pesanan.'
  } finally {
    processingOrder.value = false
  }
}

/* ---------- Map: only for admin/agen/kurir, only when shipping used "Kurir Online" (openroute) ---------- */
const canSeeDeliveryMap = computed(() => auth.can('orders.manage.shipment'))
const showDeliveryMap = computed(
  () => canSeeDeliveryMap.value && order.value?.shipping_provider === 'openroute' && !!order.value?.latitude && !!order.value?.longitude,
)
const mapEmbedUrl = computed(() => {
  if (!order.value?.latitude || !order.value?.longitude) return ''
  const lat = Number(order.value.latitude)
  const lng = Number(order.value.longitude)
  const d = 0.003
  return `https://www.openstreetmap.org/export/embed.html?bbox=${lng - d}%2C${lat - d}%2C${lng + d}%2C${lat + d}&marker=${lat}%2C${lng}`
})
const googleMapsUrl = computed(() => {
  if (!order.value?.latitude || !order.value?.longitude) return ''
  return `https://www.google.com/maps?q=${order.value.latitude},${order.value.longitude}`
})

/* ---------- Shipment status: kurir picks up (diproses -> dikirim), then delivers with proof (dikirim -> terkirim) ---------- */
const canManageShipment = computed(() => auth.can('orders.manage.shipment'))
const shipmentBusy = ref<number | null>(null)
const shipmentError = ref<string | null>(null)
const deliveryProofFiles = reactive<Record<number, File | null>>({})

/** One action row per distinct shipment, never per item — several items can share one shipment. */
const shipmentGroups = computed(() => {
  if (!order.value) return []
  const groups = new Map<number, { shipmentId: number; status: string; productNames: string[]; courierUserId: number | null }>()
  for (const item of order.value.items ?? []) {
    if (!item.shipment_id || !['diproses', 'dikirim'].includes(item.status)) continue
    const existing = groups.get(item.shipment_id)
    if (existing) {
      existing.productNames.push(item.product_name)
    } else {
      groups.set(item.shipment_id, {
        shipmentId: item.shipment_id,
        status: item.status,
        productNames: [item.product_name],
        courierUserId: item.courier?.user_id ?? null,
      })
    }
  }
  return Array.from(groups.values())
})

/** A kurir must never see another kurir's already-picked-up shipment rendered as actionable — office roles still can. */
function shipmentActionableByViewer(group: { courierUserId: number | null }): boolean {
  if (auth.user?.role !== 'kurir') return true
  return group.courierUserId === null || group.courierUserId === auth.user?.id
}

function onDeliveryProofSelected(shipmentId: number, e: Event) {
  deliveryProofFiles[shipmentId] = (e.target as HTMLInputElement).files?.[0] ?? null
}

async function pickupShipment(shipmentId: number) {
  shipmentBusy.value = shipmentId
  shipmentError.value = null
  try {
    order.value = await updateShipmentStatus(shipmentId, 'dikirim')
  } catch (e) {
    shipmentError.value = e instanceof ApiError ? e.message : t('orders.errors.shipmentStatus')
  } finally {
    shipmentBusy.value = null
  }
}

async function deliverShipment(shipmentId: number) {
  const proof = deliveryProofFiles[shipmentId]
  if (!proof) return
  shipmentBusy.value = shipmentId
  shipmentError.value = null
  try {
    order.value = await updateShipmentStatus(shipmentId, 'terkirim', proof)
  } catch (e) {
    shipmentError.value = e instanceof ApiError ? e.message : t('orders.errors.delivered')
  } finally {
    shipmentBusy.value = null
  }
}

/* ---------- Office: proactively assign/reassign a courier to a shipment (a kurir otherwise self-assigns via pickupShipment) ---------- */
const isOfficeRole = computed(() => ['super_admin', 'agen', 'admin'].includes(auth.user?.role ?? ''))
const activeCouriers = ref<{ id: number; name: string }[]>([])
const assignTargets = reactive<Record<number, number | null>>({})
const assigningCourierId = ref<number | null>(null)
const assignCourierError = ref<string | null>(null)

async function loadActiveCouriers() {
  if (!isOfficeRole.value) return
  const report = await getCourierReport({ per_page: 100 })
  activeCouriers.value = report.items.filter((c) => c.is_active).map((c) => ({ id: c.courier_id, name: c.courier_name }))
}

async function submitAssignCourier(shipmentId: number) {
  const courierId = assignTargets[shipmentId]
  if (!courierId) return
  assigningCourierId.value = shipmentId
  assignCourierError.value = null
  try {
    order.value = await assignCourier(shipmentId, courierId)
  } catch (e) {
    assignCourierError.value = e instanceof ApiError ? e.message : t('orders.errors.assignCourier')
  } finally {
    assigningCourierId.value = null
  }
}

/* ---------- COD payment proof: konsumen uploads photo, requests Admin mark it paid ---------- */
const codProofFile = ref<File | null>(null)
const submittingCodProof = ref(false)
const confirmingCodProof = ref(false)
const codRejectionReason = ref('')

function onCodProofSelected(e: Event) {
  codProofFile.value = (e.target as HTMLInputElement).files?.[0] ?? null
}

async function uploadCodProof() {
  if (!codProofFile.value) return
  submittingCodProof.value = true
  paymentActionError.value = null
  try {
    await submitCodPaymentProof(props.id, codProofFile.value)
    await load()
  } catch (e) {
    paymentActionError.value = e instanceof ApiError ? e.message : t('orders.errors.codProofUpload')
  } finally {
    submittingCodProof.value = false
  }
}

async function actOnCodProof(proofId: number, confirmed: boolean) {
  confirmingCodProof.value = true
  paymentActionError.value = null
  try {
    order.value = await confirmCodPayment(proofId, confirmed, confirmed ? undefined : codRejectionReason.value)
  } catch (e) {
    paymentActionError.value = e instanceof ApiError ? e.message : t('orders.errors.codConfirm')
  } finally {
    confirmingCodProof.value = false
  }
}

async function submitCancel() {
  if (!cancelReason.value) return
  cancelling.value = true
  cancelError.value = null
  try {
    order.value = await cancelOrder(props.id, cancelReason.value)
    showCancelForm.value = false
  } catch (e) {
    cancelError.value = e instanceof ApiError ? e.message : t('orders.errors.cancel')
  } finally {
    cancelling.value = false
  }
}

function onProofSelected(e: Event) {
  proofFile.value = (e.target as HTMLInputElement).files?.[0] ?? null
}

async function uploadProof() {
  if (!proofFile.value) return
  uploadingProof.value = true
  paymentActionError.value = null
  try {
    await submitBankTransferProof(props.id, proofFile.value)
    await load()
  } catch (e) {
    paymentActionError.value = e instanceof ApiError ? e.message : t('orders.errors.transferProofUpload')
  } finally {
    uploadingProof.value = false
  }
}

async function actOnVerification(approved: boolean) {
  verifying.value = true
  paymentActionError.value = null
  try {
    await verifyBankTransfer(props.id, approved, approved ? undefined : rejectionReason.value)
    await load()
  } catch (e) {
    paymentActionError.value = e instanceof ApiError ? e.message : t('orders.errors.verify')
  } finally {
    verifying.value = false
  }
}

async function toggleCodPaid(paid: boolean) {
  codUpdating.value = true
  paymentActionError.value = null
  try {
    order.value = await markCodPayment(props.id, paid)
  } catch (e) {
    paymentActionError.value = e instanceof ApiError ? e.message : t('orders.errors.codToggle')
  } finally {
    codUpdating.value = false
  }
}

/** COD may still be cancelled while 'diproses'; every other method only while 'diterima'. */
const canShowCancel = computed(() => {
  if (!order.value) return false
  if (order.value.status === 'diterima') return true
  return order.value.status === 'diproses' && order.value.payment_method?.type === 'cod'
})

/* ---------- Admin: per-item fulfillment adjustment (only while order is 'diproses') ---------- */
const adjustingItemId = ref<number | null>(null)
const adjustForm = reactive({ fulfilled_quantity: 0, reason: '', additional_payment_method: 'transfer' as 'transfer' | 'cod' })
const adjusting = ref(false)
const adjustError = ref<string | null>(null)

function openAdjustForm(item: OrderItem) {
  adjustingItemId.value = item.id
  adjustForm.fulfilled_quantity = item.fulfilled_quantity
  adjustForm.reason = ''
  adjustError.value = null
}

async function submitAdjust(item: OrderItem) {
  adjusting.value = true
  adjustError.value = null
  try {
    await adjustItemFulfillment(props.id, item.id, adjustForm.fulfilled_quantity, adjustForm.reason, adjustForm.additional_payment_method)
    adjustingItemId.value = null
    await load()
  } catch (e) {
    adjustError.value = e instanceof ApiError ? e.message : t('orders.errors.adjustFulfillment')
  } finally {
    adjusting.value = false
  }
}

/* ---------- Office: reschedule an item's requested delivery date (only while order is 'diproses') ---------- */
const reschedulingItemId = ref<number | null>(null)
const rescheduleForm = reactive({ requested_delivery_date: '', reason: '', quantity: 1 })
const rescheduling = ref(false)
const rescheduleError = ref<string | null>(null)

function openRescheduleForm(item: OrderItem) {
  reschedulingItemId.value = item.id
  rescheduleForm.requested_delivery_date = item.requested_delivery_date ?? ''
  rescheduleForm.reason = ''
  rescheduleForm.quantity = item.fulfilled_quantity
  rescheduleError.value = null
}

async function submitReschedule(item: OrderItem) {
  rescheduling.value = true
  rescheduleError.value = null
  try {
    await rescheduleOrderItem(props.id, item.id, rescheduleForm.requested_delivery_date, rescheduleForm.reason, rescheduleForm.quantity)
    reschedulingItemId.value = null
    await load()
  } catch (e) {
    rescheduleError.value = e instanceof ApiError ? e.message : t('orders.errors.reschedule')
  } finally {
    rescheduling.value = false
  }
}

/* ---------- Konsumen: request a return (only once the item is 'terkirim') ---------- */
const returningItemId = ref<number | null>(null)
const returnForm = reactive({ quantity: 1, reason: '', restock: true })
const returnEvidence = ref<File | null>(null)
const submittingReturn = ref(false)
const returnError = ref<string | null>(null)
const returnSubmitted = ref<Set<number>>(new Set())

function openReturnForm(item: OrderItem) {
  returningItemId.value = item.id
  returnForm.quantity = 1
  returnForm.reason = ''
  returnError.value = null
}

function onEvidenceSelected(e: Event) {
  returnEvidence.value = (e.target as HTMLInputElement).files?.[0] ?? null
}

async function submitReturn(item: OrderItem) {
  if (!returnEvidence.value) {
    returnError.value = t('orders.returnReasonRequired')
    return
  }
  submittingReturn.value = true
  returnError.value = null
  try {
    await requestReturn(
      props.id, returnForm.reason,
      [{ order_item_id: item.id, quantity: returnForm.quantity, restock: returnForm.restock }],
      returnEvidence.value,
    )
    returningItemId.value = null
    returnSubmitted.value.add(item.id)
    await load()
  } catch (e) {
    returnError.value = e instanceof ApiError ? e.message : t('orders.errors.requestReturn')
  } finally {
    submittingReturn.value = false
  }
}
</script>

<template>
  <ShopLayout>
    <div v-if="route.query.justPlaced" class="mb-4 flex items-center gap-2 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400">
      <AppIcon name="check" :size="18" />
      {{ t('orders.justPlaced') }}
    </div>

    <div v-if="loading" class="h-64 animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />

    <div v-else-if="order" class="space-y-4">
      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <div class="flex items-center justify-between">
          <h1 class="font-display text-lg font-semibold text-stone-800 dark:text-stone-100">{{ order.order_no }}</h1>
          <span class="rounded-full bg-brand-50 px-3 py-1 text-xs font-medium text-brand-700 dark:bg-brand-950 dark:text-brand-300">
            {{ orderStatusLabel(order.status) }}
          </span>
        </div>
        <p class="mt-1 text-xs text-stone-400">{{ t('orders.orderedOn', { date: formatDate(order.created_at) }) }}</p>

        <div v-if="order.status === 'diterima' && canManageStatus" class="mt-3 border-t border-stone-100 pt-3 dark:border-stone-800">
          <p v-if="processOrderError" class="mb-2 text-xs text-red-600 dark:text-red-400">{{ processOrderError }}</p>
          <p class="mb-2 text-xs text-stone-400">{{ t('orders.processOrderHint') }}</p>
          <AppButton size="sm" :disabled="processingOrder" @click="processOrder">
            {{ t('orders.processOrder') }}
          </AppButton>
        </div>
      </div>

      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="mb-3 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('orders.items') }}</h2>
        <ul class="divide-y divide-stone-100 dark:divide-stone-800">
          <li v-for="item in order.items" :key="item.id" class="py-2.5 text-sm">
            <div class="flex items-center justify-between">
              <div>
                <p class="font-medium text-stone-700 dark:text-stone-200">{{ item.product_name }}</p>
                <p v-if="item.variation_label" class="text-xs text-stone-400">{{ item.variation_label }}</p>
                <p class="text-xs text-stone-400">SKU: {{ item.sku || '-' }}</p>
                <p class="text-xs text-stone-400">
                  {{ item.fulfilled_quantity }}<span v-if="item.fulfilled_quantity !== item.original_quantity">/{{ item.original_quantity }}</span>
                  &times; {{ formatRupiah(item.unit_price) }}
                  <span v-if="item.status !== order.status" class="ml-1 rounded-full bg-stone-100 px-1.5 py-0.5 text-[10px] dark:bg-stone-800">{{ orderStatusLabel(item.status) }}</span>
                </p>
                <!-- This product's own delivery date — items on the same order can differ once rescheduled. -->
                <p v-if="item.requested_delivery_date" class="mt-0.5 text-xs text-stone-400">
                  {{ t('orders.deliveryDateEstimate') }}: {{ formatDate(item.requested_delivery_date) }}
                </p>
                <!-- Which courier is handling this specific product, once one is assigned. -->
                <p v-if="item.courier" class="mt-0.5 text-xs text-stone-400">
                  {{ t('orders.itemCourier', { name: item.courier.name }) }}
                </p>
              </div>
              <span class="font-medium text-stone-700 dark:text-stone-200">{{ formatRupiah(item.subtotal) }}</span>
            </div>

            <!-- Admin: adjust fulfilled quantity, only while order is 'diproses' -->
            <div v-if="order.status === 'diproses' && auth.can('orders.manage.fulfillment')" class="mt-1.5">
              <button v-if="adjustingItemId !== item.id" type="button" class="text-xs font-medium text-brand-600 dark:text-brand-400" @click="openAdjustForm(item)">
                {{ t('orders.changeFulfillment') }}
              </button>
              <div v-else class="mt-1.5 space-y-2 rounded-lg bg-stone-50 p-2.5 dark:bg-stone-800/60">
                <p v-if="adjustError" class="text-xs text-red-600">{{ adjustError }}</p>
                <div class="flex items-center gap-2">
                  <label class="text-xs text-stone-500 dark:text-stone-400">{{ t('orders.fulfilledQuantity') }}</label>
                  <input v-model.number="adjustForm.fulfilled_quantity" type="number" min="0" class="w-20 rounded-lg border border-stone-200 bg-white px-2 py-1 text-xs dark:border-stone-700 dark:bg-stone-950" />
                </div>
                <input v-model="adjustForm.reason" type="text" :placeholder="t('orders.reason')" class="w-full rounded-lg border border-stone-200 bg-white px-2 py-1 text-xs dark:border-stone-700 dark:bg-stone-950" />
                <select
                  v-if="adjustForm.fulfilled_quantity > item.fulfilled_quantity && order.payment_method?.type !== 'cod'"
                  v-model="adjustForm.additional_payment_method"
                  class="w-full rounded-lg border border-stone-200 bg-white px-2 py-1 text-xs dark:border-stone-700 dark:bg-stone-950"
                >
                  <option value="transfer">{{ t('orders.additionalPaymentTransfer') }}</option>
                  <option value="cod">{{ t('orders.additionalPaymentCod') }}</option>
                </select>
                <p v-else-if="adjustForm.fulfilled_quantity > item.fulfilled_quantity" class="text-[11px] text-stone-400">
                  {{ t('orders.additionalCodNote') }}
                </p>
                <div class="flex gap-2">
                  <AppButton size="sm" :disabled="!adjustForm.reason || adjusting" @click="submitAdjust(item)">{{ t('orders.save') }}</AppButton>
                  <AppButton size="sm" variant="ghost" @click="adjustingItemId = null">{{ t('orders.cancel') }}</AppButton>
                </div>
              </div>
            </div>

            <!-- Admin: reschedule requested delivery date, only while order is 'diproses' -->
            <div v-if="order.status === 'diproses' && auth.can('orders.manage.fulfillment')" class="mt-1.5">
              <button v-if="reschedulingItemId !== item.id" type="button" class="text-xs font-medium text-brand-600 dark:text-brand-400" @click="openRescheduleForm(item)">
                {{ t('orders.rescheduleItem') }}
              </button>
              <div v-else class="mt-1.5 space-y-2 rounded-lg bg-stone-50 p-2.5 dark:bg-stone-800/60">
                <p v-if="rescheduleError" class="text-xs text-red-600">{{ rescheduleError }}</p>
                <div class="flex items-center gap-2">
                  <label class="text-xs text-stone-500 dark:text-stone-400">{{ t('orders.newDeliveryDate') }}</label>
                  <input v-model="rescheduleForm.requested_delivery_date" type="date" class="rounded-lg border border-stone-200 bg-white px-2 py-1 text-xs dark:border-stone-700 dark:bg-stone-950" />
                </div>
                <div v-if="item.fulfilled_quantity > 1" class="flex items-center gap-2">
                  <label class="text-xs text-stone-500 dark:text-stone-400">{{ t('orders.rescheduleQuantity') }}</label>
                  <input
                    v-model.number="rescheduleForm.quantity"
                    type="number"
                    min="1"
                    :max="item.fulfilled_quantity"
                    class="w-20 rounded-lg border border-stone-200 bg-white px-2 py-1 text-xs dark:border-stone-700 dark:bg-stone-950"
                  />
                  <span class="text-[11px] text-stone-400">/ {{ item.fulfilled_quantity }}</span>
                </div>
                <p v-if="item.fulfilled_quantity > 1 && rescheduleForm.quantity < item.fulfilled_quantity" class="text-[11px] text-stone-400">
                  {{ t('orders.rescheduleSplitNote') }}
                </p>
                <input v-model="rescheduleForm.reason" type="text" :placeholder="t('orders.reason')" class="w-full rounded-lg border border-stone-200 bg-white px-2 py-1 text-xs dark:border-stone-700 dark:bg-stone-950" />
                <div class="flex gap-2">
                  <AppButton
                    size="sm"
                    :disabled="!rescheduleForm.reason || !rescheduleForm.requested_delivery_date || rescheduleForm.quantity < 1 || rescheduleForm.quantity > item.fulfilled_quantity || rescheduling"
                    @click="submitReschedule(item)"
                  >
                    {{ t('orders.save') }}
                  </AppButton>
                  <AppButton size="sm" variant="ghost" @click="reschedulingItemId = null">{{ t('orders.cancel') }}</AppButton>
                </div>
              </div>
            </div>

            <!-- Konsumen: request a return, only once the item is 'terkirim' -->
            <div v-if="item.status === 'terkirim' && auth.user?.role === 'konsumen' && !returnSubmitted.has(item.id)" class="mt-1.5">
              <button v-if="returningItemId !== item.id" type="button" class="text-xs font-medium text-brand-600 dark:text-brand-400" @click="openReturnForm(item)">
                {{ t('orders.requestReturn') }}
              </button>
              <div v-else class="mt-1.5 space-y-2 rounded-lg bg-stone-50 p-2.5 dark:bg-stone-800/60">
                <p v-if="returnError" class="text-xs text-red-600">{{ returnError }}</p>
                <div class="flex items-center gap-2">
                  <label class="text-xs text-stone-500 dark:text-stone-400">{{ t('orders.quantity') }}</label>
                  <input v-model.number="returnForm.quantity" type="number" min="1" :max="item.fulfilled_quantity" class="w-20 rounded-lg border border-stone-200 bg-white px-2 py-1 text-xs dark:border-stone-700 dark:bg-stone-950" />
                </div>
                <input v-model="returnForm.reason" type="text" :placeholder="t('orders.returnReason')" class="w-full rounded-lg border border-stone-200 bg-white px-2 py-1 text-xs dark:border-stone-700 dark:bg-stone-950" />
                <div>
                  <label class="mb-1 block text-[11px] font-medium text-stone-500 dark:text-stone-400">{{ t('orders.evidencePhoto') }}</label>
                  <input type="file" accept="image/*" class="block w-full text-xs" @change="onEvidenceSelected" />
                </div>
                <div class="flex gap-2">
                  <AppButton size="sm" :disabled="!returnForm.reason || !returnEvidence || submittingReturn" @click="submitReturn(item)">{{ t('orders.sendRequest') }}</AppButton>
                  <AppButton size="sm" variant="ghost" @click="returningItemId = null">{{ t('orders.cancel') }}</AppButton>
                </div>
              </div>
            </div>

            <!-- Delivery proof photo, once the kurir has submitted one -->
            <a
              v-if="item.delivery_proof_url"
              :href="item.delivery_proof_url"
              target="_blank"
              rel="noopener noreferrer"
              class="mt-1.5 inline-block text-xs font-medium text-brand-600 dark:text-brand-400"
            >
              {{ t('orders.viewDeliveryProof') }}
            </a>
          </li>
        </ul>

        <div class="mt-3 space-y-1.5 border-t border-stone-100 pt-3 text-sm dark:border-stone-800">
          <div class="flex justify-between text-stone-500 dark:text-stone-400">
            <span>{{ t('orders.subtotal') }}</span><span>{{ formatRupiah(order.subtotal_amount) }}</span>
          </div>
          <div class="flex justify-between text-stone-500 dark:text-stone-400">
            <span>{{ t('orders.shippingFee') }}</span><span>{{ formatRupiah(order.shipping_fee_amount) }}</span>
          </div>
          <div class="flex justify-between text-stone-500 dark:text-stone-400">
            <span>{{ t('orders.adminFee') }}</span><span>{{ formatRupiah(order.admin_fee_amount) }}</span>
          </div>
          <div class="flex justify-between border-t border-stone-100 pt-1.5 font-display font-semibold text-stone-800 dark:border-stone-800 dark:text-stone-100">
            <span>{{ t('orders.total') }}</span><span>{{ formatRupiah(order.total_amount) }}</span>
          </div>
        </div>
      </div>

      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="mb-2 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('orders.shippingAddress') }}</h2>
        <p class="text-sm text-stone-600 dark:text-stone-300">{{ order.recipient_name }} · {{ order.recipient_phone }}</p>
        <p class="text-sm text-stone-500 dark:text-stone-400">{{ order.address }}</p>
        <p v-if="order.konsumen" class="mt-1 text-xs text-stone-400">{{ t('orders.customer', { name: order.konsumen.name, phone: order.konsumen.phone }) }}</p>
        <p v-if="order.sales" class="mt-1 text-xs text-stone-400">Sales: {{ order.sales.name }}</p>
        <p v-if="order.korsal" class="mt-1 text-xs text-stone-400">Korsal: {{ order.korsal.name }}</p>

        <!-- Map: admin/agen/kurir only, only for "Kurir Online" (openroute) shipments — click opens Google Maps. -->
        <a
          v-if="showDeliveryMap"
          :href="googleMapsUrl"
          target="_blank"
          rel="noopener noreferrer"
          class="mt-3 block overflow-hidden rounded-xl border border-stone-200 dark:border-stone-700"
          :title="t('orders.openInGoogleMaps')"
        >
          <iframe :src="mapEmbedUrl" class="h-48 w-full pointer-events-none" loading="lazy" :title="t('orders.deliveryLocation')" />
          <span class="block bg-stone-50 px-3 py-1.5 text-center text-xs font-medium text-brand-600 dark:bg-stone-800 dark:text-brand-400">
            {{ t('orders.openInGoogleMaps') }}
          </span>
        </a>
      </div>

      <!-- Delivery info: courier name/phone + estimated date — only rendered once a courier is actually assigned. -->
      <div v-if="order.couriers?.length" class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="mb-2 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('orders.deliveryInfo') }}</h2>
        <p v-if="order.delivery_date_estimate" class="text-sm text-stone-600 dark:text-stone-300">
          {{ t('orders.deliveryDateEstimate') }}: {{ formatDate(order.delivery_date_estimate) }}
        </p>
        <div v-for="(courier, i) in order.couriers" :key="i" class="mt-1 text-sm text-stone-600 dark:text-stone-300">
          {{ courier.name }}<span v-if="courier.phone"> · {{ courier.phone }}</span>
        </div>
      </div>

      <!-- Return status: only rendered once at least one return has been requested on this order. -->
      <div v-if="order.returns?.length" class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="mb-2 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('orders.returnStatus') }}</h2>
        <div v-for="ret in order.returns" :key="ret.id" class="mb-3 rounded-lg bg-stone-50 p-3 text-sm last:mb-0 dark:bg-stone-800/60">
          <div class="flex items-center justify-between">
            <span class="font-medium text-stone-700 dark:text-stone-200">{{ ret.reason }}</span>
            <span class="rounded-full bg-stone-200 px-2 py-0.5 text-xs font-medium text-stone-700 dark:bg-stone-700 dark:text-stone-200">{{ ret.status }}</span>
          </div>
          <p v-if="ret.total_refund_amount" class="mt-1 text-xs text-stone-500 dark:text-stone-400">
            {{ t('orders.refundAmount') }}: {{ formatRupiah(ret.total_refund_amount) }}
          </p>
          <ul class="mt-1.5 space-y-0.5 text-xs text-stone-500 dark:text-stone-400">
            <li v-for="ri in ret.items" :key="ri.order_item_id">
              {{ t('orders.quantity') }}: {{ ri.quantity_returned }} — {{ ri.status }} ({{ ri.refund_status }})
            </li>
          </ul>
        </div>
      </div>

      <!-- Shipment status: kurir picks up (diproses -> dikirim) then delivers with proof (dikirim -> terkirim). -->
      <div v-if="canManageShipment && shipmentGroups.length" class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="mb-2 text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('orders.shipmentStatus') }}</h2>
        <p v-if="shipmentError" class="mb-2 rounded-lg bg-red-50 p-2.5 text-xs text-red-700 dark:bg-red-950 dark:text-red-400">{{ shipmentError }}</p>

        <div v-for="group in shipmentGroups" :key="group.shipmentId" class="mb-2 rounded-lg bg-stone-50 p-3 last:mb-0 dark:bg-stone-800/60">
          <p class="text-xs text-stone-500 dark:text-stone-400">{{ group.productNames.join(', ') }}</p>

          <div v-if="group.status === 'diproses'" class="mt-2 space-y-2">
            <AppButton size="sm" :disabled="shipmentBusy === group.shipmentId" @click="pickupShipment(group.shipmentId)">
              {{ t('orders.pickupAndDeliver') }}
            </AppButton>

            <div v-if="isOfficeRole" class="flex flex-wrap items-center gap-2">
              <p v-if="assignCourierError" class="w-full text-xs text-red-600">{{ assignCourierError }}</p>
              <label class="text-[11px] font-medium text-stone-500 dark:text-stone-400">{{ t('orders.assignCourierLabel') }}</label>
              <select v-model.number="assignTargets[group.shipmentId]" class="rounded-lg border border-stone-200 bg-white px-2 py-1 text-xs dark:border-stone-700 dark:bg-stone-950">
                <option :value="null">{{ t('orders.chooseCourier') }}</option>
                <option v-for="c in activeCouriers" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
              <AppButton
                size="sm"
                variant="secondary"
                :disabled="!assignTargets[group.shipmentId] || assigningCourierId === group.shipmentId"
                @click="submitAssignCourier(group.shipmentId)"
              >
                {{ t('orders.assignCourierButton') }}
              </AppButton>
            </div>
          </div>

          <div v-else-if="group.status === 'dikirim' && shipmentActionableByViewer(group)" class="mt-2 space-y-2">
            <label class="block text-[11px] font-medium text-stone-500 dark:text-stone-400">{{ t('orders.deliveryProofRequired') }}</label>
            <input type="file" accept="image/*" class="block w-full text-xs" @change="onDeliveryProofSelected(group.shipmentId, $event)" />
            <AppButton
              size="sm"
              :disabled="!deliveryProofFiles[group.shipmentId] || shipmentBusy === group.shipmentId"
              @click="deliverShipment(group.shipmentId)"
            >
              {{ t('orders.markDelivered') }}
            </AppButton>
          </div>
          <p v-else-if="group.status === 'dikirim'" class="mt-2 text-xs text-stone-400">{{ t('orders.shipmentHeldByOtherCourier') }}</p>
        </div>
      </div>

      <div v-if="order.cancellation_reason" class="rounded-2xl bg-stone-100 p-4 text-sm text-stone-600 dark:bg-stone-900 dark:text-stone-300">
        {{ t('orders.cancelledReason', { reason: order.cancellation_reason }) }}
      </div>

      <!-- Payment -->
      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <div class="flex items-center justify-between">
          <h2 class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ t('orders.payment') }}</h2>
          <span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-medium text-stone-600 dark:bg-stone-800 dark:text-stone-300">
            {{ paymentStatusLabel(order.payment_status) }}
          </span>
        </div>
        <p class="mt-1 text-sm text-stone-500 dark:text-stone-400">{{ t('orders.method', { name: order.payment_method?.name ?? '-' }) }}</p>

        <p v-if="paymentActionError" class="mt-2 rounded-lg bg-red-50 p-2.5 text-xs text-red-700 dark:bg-red-950 dark:text-red-400">{{ paymentActionError }}</p>

        <!-- Manual transfer: bank details + proof upload (konsumen) -->
        <template v-if="order.payment_method?.type === 'manual'">
          <div v-if="order.payment_transaction?.instructions" class="mt-3 rounded-lg bg-stone-50 p-2.5 text-sm dark:bg-stone-800/60">
            {{ order.payment_transaction.instructions.bank_name }} — {{ order.payment_transaction.instructions.account_number }}
            a/n {{ order.payment_transaction.instructions.account_name }}
          </div>

          <div v-if="!order.payment_transaction?.bank_transfer_verification" class="mt-3">
            <label class="mb-1 block text-xs font-medium text-stone-600 dark:text-stone-300">{{ t('orders.uploadTransferProof') }}</label>
            <input type="file" accept="image/*" class="block w-full text-xs" @change="onProofSelected" />
            <AppButton class="mt-2" size="sm" :disabled="!proofFile || uploadingProof" @click="uploadProof">
              {{ uploadingProof ? t('orders.uploading') : t('orders.sendTransferProof') }}
            </AppButton>
          </div>

          <div v-else-if="order.payment_transaction?.bank_transfer_verification" class="mt-3 text-sm">
            <p>
              {{ t('orders.verificationStatusLabel') }}
              <strong>{{ { pending: t('orders.verificationPending'), verified: t('orders.verificationVerified'), rejected: t('orders.verificationRejected') }[order.payment_transaction.bank_transfer_verification.status] }}</strong>
            </p>
            <a
              v-if="order.payment_transaction.bank_transfer_verification.proof_url"
              :href="order.payment_transaction.bank_transfer_verification.proof_url"
              target="_blank" rel="noopener noreferrer"
              class="text-xs font-medium text-brand-600 dark:text-brand-400"
            >
              {{ t('orders.viewTransferProof') }}
            </a>
            <p v-if="order.payment_transaction.bank_transfer_verification.rejection_reason" class="text-xs text-red-600">
              {{ t('orders.rejectionReason', { reason: order.payment_transaction.bank_transfer_verification.rejection_reason }) }}
            </p>

            <!-- Staff verification action -->
            <div
              v-if="canVerifyBankTransfer && order.payment_transaction.bank_transfer_verification.status === 'pending'"
              class="mt-2 space-y-2 rounded-lg bg-stone-50 p-3 dark:bg-stone-800/60"
            >
              <p class="text-xs font-medium text-stone-600 dark:text-stone-300">{{ t('orders.verifyTransfer') }}</p>
              <input
                v-model="rejectionReason"
                type="text"
                :placeholder="t('orders.rejectionReasonPlaceholder')"
                class="w-full rounded-lg border border-stone-200 bg-white px-3 py-1.5 text-xs dark:border-stone-700 dark:bg-stone-950"
              />
              <div class="flex gap-2">
                <AppButton size="sm" :disabled="verifying" @click="actOnVerification(true)">{{ t('orders.approve') }}</AppButton>
                <AppButton size="sm" variant="danger" :disabled="verifying" @click="actOnVerification(false)">{{ t('orders.reject') }}</AppButton>
              </div>
            </div>
          </div>
        </template>

        <!-- COD -->
        <template v-else-if="order.payment_method?.type === 'cod'">
          <!-- Keuangan-only direct paid/unpaid toggle -->
          <div v-if="canSettleCod" class="mt-3">
            <AppButton
              size="sm"
              :variant="order.payment_status === 'paid' ? 'secondary' : 'primary'"
              :disabled="codUpdating"
              @click="toggleCodPaid(order.payment_status !== 'paid')"
            >
              {{ order.payment_status === 'paid' ? t('orders.markUnpaid') : t('orders.markPaid') }}
            </AppButton>
          </div>

          <!-- Konsumen: upload a photo of the cash handed to the kurir, request Admin mark it paid -->
          <div v-if="auth.isKonsumen && order.payment_status !== 'paid'" class="mt-3">
            <template v-if="!order.payment_transaction?.cod_payment_proof || order.payment_transaction.cod_payment_proof.status === 'rejected'">
              <p v-if="order.payment_transaction?.cod_payment_proof?.status === 'rejected'" class="mb-2 text-xs text-red-600">
                {{ t('orders.previousProofRejected', { reason: order.payment_transaction.cod_payment_proof.rejection_reason ? t('orders.rejectedSuffix', { reason: order.payment_transaction.cod_payment_proof.rejection_reason }) : '' }) }}
              </p>
              <label class="mb-1 block text-xs font-medium text-stone-600 dark:text-stone-300">{{ t('orders.uploadCodProof') }}</label>
              <input type="file" accept="image/*" class="block w-full text-xs" @change="onCodProofSelected" />
              <AppButton class="mt-2" size="sm" :disabled="!codProofFile || submittingCodProof" @click="uploadCodProof">
                {{ submittingCodProof ? t('orders.uploading') : t('orders.sendAndRequestConfirm') }}
              </AppButton>
            </template>
            <p v-else-if="order.payment_transaction.cod_payment_proof.status === 'pending'" class="text-sm text-stone-500 dark:text-stone-400">
              {{ t('orders.waitingAdminConfirm') }}
            </p>
          </div>

          <!-- Keuangan: confirm or reject the konsumen's COD proof -->
          <div
            v-if="canSettleCod && order.payment_transaction?.cod_payment_proof?.status === 'pending'"
            class="mt-3 space-y-2 rounded-lg bg-stone-50 p-3 dark:bg-stone-800/60"
          >
            <p class="text-xs font-medium text-stone-600 dark:text-stone-300">{{ t('orders.confirmCodProof') }}</p>
            <a
              v-if="order.payment_transaction.cod_payment_proof.proof_url"
              :href="order.payment_transaction.cod_payment_proof.proof_url"
              target="_blank" rel="noopener noreferrer"
              class="block text-xs font-medium text-brand-600 dark:text-brand-400"
            >
              {{ t('orders.viewProofPhoto') }}
            </a>
            <input
              v-model="codRejectionReason"
              type="text"
              :placeholder="t('orders.rejectionReasonPlaceholder')"
              class="w-full rounded-lg border border-stone-200 bg-white px-3 py-1.5 text-xs dark:border-stone-700 dark:bg-stone-950"
            />
            <div class="flex gap-2">
              <AppButton size="sm" :disabled="confirmingCodProof" @click="actOnCodProof(order.payment_transaction.cod_payment_proof.id, true)">{{ t('orders.approve') }}</AppButton>
              <AppButton size="sm" variant="danger" :disabled="confirmingCodProof" @click="actOnCodProof(order.payment_transaction.cod_payment_proof.id, false)">{{ t('orders.reject') }}</AppButton>
            </div>
          </div>
        </template>
      </div>

      <div v-if="canShowCancel">
        <AppButton v-if="!showCancelForm" variant="secondary" @click="showCancelForm = true">{{ t('orders.cancelOrder') }}</AppButton>
        <div v-else class="space-y-2 rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
          <label class="block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('orders.cancelReasonLabel') }}</label>
          <p v-if="cancelError" class="text-xs text-red-600">{{ cancelError }}</p>
          <textarea v-model="cancelReason" rows="2" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          <div class="flex gap-2">
            <AppButton variant="danger" :disabled="!cancelReason || cancelling" @click="submitCancel">{{ t('orders.confirmCancel') }}</AppButton>
            <AppButton variant="ghost" @click="showCancelForm = false">{{ t('common.close') }}</AppButton>
          </div>
        </div>
      </div>
    </div>
  </ShopLayout>
</template>
