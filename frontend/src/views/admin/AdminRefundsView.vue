<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AgentPicker from '@/components/ui/AgentPicker.vue'
import { useAuthStore } from '@/stores/auth'
import { listOrderRefunds, markOrderRefundStatus, type OrderRefund } from '@/api/orderAdjustments'
import { formatRupiah, formatDate, skuLabel } from '@/utils/format'

/** Admin dashboard: fulfillment-shortfall refunds (Blueprint: "menu khusus untuk refund"). */

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')
/** Settling a refund is Keuangan's authority — Admin/agen may only view this ledger (see OrderAdjustmentController). */
const canManage = computed(() => auth.user?.role === 'super_admin' || auth.can('finance.refund.manage'))
const agentId = ref<number | undefined>(undefined)

const refunds = ref<OrderRefund[]>([])
const loading = ref(true)
const updatingId = ref<number | null>(null)

const STATUS_LABEL: Record<string, string> = { pending: 'Menunggu', processed: 'Sudah Dikembalikan', failed: 'Gagal' }

async function load() {
  loading.value = true
  const { refunds: data } = await listOrderRefunds(1, { agent_id: agentId.value })
  refunds.value = data
  loading.value = false
}
onMounted(load)

async function markProcessed(refund: OrderRefund) {
  updatingId.value = refund.id
  try {
    await markOrderRefundStatus(refund.id, 'processed')
    await load()
  } finally {
    updatingId.value = null
  }
}
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">Refund</h1>
    <p class="mb-4 text-sm text-stone-500 dark:text-stone-400">
      Refund akibat pengurangan jumlah pemenuhan pesanan (fulfillment shortfall).
    </p>

    <div v-if="isSuperAdmin" class="mb-4 flex items-end gap-3">
      <AgentPicker v-model="agentId" />
      <button type="button" class="rounded-lg bg-stone-800 px-3 py-2 text-xs font-medium text-white hover:bg-stone-900 dark:bg-stone-100 dark:text-stone-900" @click="load">
        Terapkan
      </button>
    </div>

    <div v-if="loading" class="space-y-2">
      <div v-for="i in 4" :key="i" class="h-16 animate-pulse rounded-xl bg-stone-100 dark:bg-stone-800" />
    </div>
    <p v-else-if="!refunds.length" class="py-10 text-center text-sm text-stone-400">Belum ada refund.</p>

    <div v-else class="space-y-2">
      <div v-for="r in refunds" :key="r.id" class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
        <div class="flex items-start justify-between gap-2">
          <div>
            <p class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ r.order_no }} &middot; {{ r.customer_name }}</p>
            <p class="text-xs text-stone-500 dark:text-stone-400">{{ r.item }}<span v-if="r.variation_label"> ({{ r.variation_label }})</span> &times;{{ r.quantity_reduced }}</p>
            <p class="text-xs text-stone-400 dark:text-stone-500">{{ skuLabel(r.sku) }}</p>
            <p class="text-xs text-stone-400">{{ r.reason }}</p>
          </div>
          <div class="text-right">
            <p class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ formatRupiah(r.refund_amount) }}</p>
            <span
              class="rounded-full px-2 py-0.5 text-[11px] font-medium"
              :class="r.refund_status === 'processed' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-400'"
            >
              {{ STATUS_LABEL[r.refund_status] }}
            </span>
          </div>
        </div>
        <div class="mt-2 flex items-center justify-between text-[11px] text-stone-400">
          <span>{{ formatDate(r.created_at) }} &middot; oleh {{ r.adjusted_by }}</span>
          <AppButton v-if="r.refund_status === 'pending' && canManage" size="sm" :disabled="updatingId === r.id" @click="markProcessed(r)">
            Tandai Sudah Dikembalikan
          </AppButton>
        </div>
      </div>
    </div>
  </DashboardLayout>
</template>
