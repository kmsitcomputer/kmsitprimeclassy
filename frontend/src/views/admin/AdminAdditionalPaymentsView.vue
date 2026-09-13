<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AgentPicker from '@/components/ui/AgentPicker.vue'
import { useAuthStore } from '@/stores/auth'
import { listAdditionalPayments, markAdditionalPaymentStatus, type AdditionalPayment } from '@/api/orderAdjustments'
import { formatRupiah, formatDate } from '@/utils/format'

/** Admin dashboard: fulfillment-increase additional payments (transfer or COD). */

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')
/** Settling an additional payment is Keuangan's authority — Admin/agen may only view this ledger (see OrderAdjustmentController). */
const canManage = computed(() => auth.user?.role === 'super_admin' || auth.can('finance.additional.manage'))
const agentId = ref<number | undefined>(undefined)

const payments = ref<AdditionalPayment[]>([])
const loading = ref(true)
const updatingId = ref<number | null>(null)

const STATUS_LABEL: Record<string, string> = { pending: 'Menunggu', paid: 'Lunas', failed: 'Gagal', cancelled: 'Dibatalkan' }

async function load() {
  loading.value = true
  const { payments: data } = await listAdditionalPayments(1, { agent_id: agentId.value })
  payments.value = data
  loading.value = false
}
onMounted(load)

async function markPaid(payment: AdditionalPayment) {
  updatingId.value = payment.id
  try {
    await markAdditionalPaymentStatus(payment.id, true)
    await load()
  } finally {
    updatingId.value = null
  }
}
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">Additional Payment</h1>
    <p class="mb-4 text-sm text-stone-500 dark:text-stone-400">
      Tagihan tambahan akibat penambahan jumlah pemenuhan pesanan — transfer atau COD.
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
    <p v-else-if="!payments.length" class="py-10 text-center text-sm text-stone-400">Belum ada additional payment.</p>

    <div v-else class="space-y-2">
      <div v-for="p in payments" :key="p.id" class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
        <div class="flex items-start justify-between gap-2">
          <div>
            <p class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ p.order_no }} &middot; {{ p.customer_name }}</p>
            <p class="text-xs text-stone-500 dark:text-stone-400">{{ p.method === 'transfer' ? 'Transfer Bank' : 'Cash on Delivery' }}</p>
            <p v-if="p.reason" class="text-xs text-stone-400">{{ p.reason }}</p>
          </div>
          <div class="text-right">
            <p class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ formatRupiah(p.amount) }}</p>
            <span
              class="rounded-full px-2 py-0.5 text-[11px] font-medium"
              :class="p.status === 'paid' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-400'"
            >
              {{ STATUS_LABEL[p.status] }}
            </span>
          </div>
        </div>
        <p v-if="p.method === 'transfer' && p.payment_instructions" class="mt-1.5 rounded-lg bg-stone-50 p-2 text-[11px] text-stone-500 dark:bg-stone-800/60 dark:text-stone-400">
          {{ p.payment_instructions.bank_name }} — {{ p.payment_instructions.account_number }} a/n {{ p.payment_instructions.account_name }}
        </p>
        <div class="mt-2 flex items-center justify-between text-[11px] text-stone-400">
          <span>{{ formatDate(p.created_at) }} &middot; diminta oleh {{ p.requested_by }}</span>
          <AppButton v-if="p.status === 'pending' && canManage" size="sm" :disabled="updatingId === p.id" @click="markPaid(p)">
            Tandai Lunas
          </AppButton>
        </div>
      </div>
    </div>
  </DashboardLayout>
</template>
