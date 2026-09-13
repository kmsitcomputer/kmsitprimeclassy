<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AgentPicker from '@/components/ui/AgentPicker.vue'
import { useAuthStore } from '@/stores/auth'
import { listReturns, reviewReturn, markReturnItemRefunded, type ReturnRequestRecord } from '@/api/returns'
import { formatRupiah, formatDate, skuLabel } from '@/utils/format'

/** Admin dashboard: return list — item/quantity/amount/customer/order/reason/status/refund status. */

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')
const agentId = ref<number | undefined>(undefined)

const returns = ref<ReturnRequestRecord[]>([])
const loading = ref(true)
const busyId = ref<number | null>(null)

const STATUS_LABEL: Record<string, string> = {
  requested: 'Diajukan', under_review: 'Ditinjau', approved: 'Disetujui',
  rejected: 'Ditolak', processing: 'Diproses', completed: 'Selesai',
}
const REFUND_LABEL: Record<string, string> = { not_required: '-', pending: 'Menunggu', processed: 'Sudah Dikembalikan', failed: 'Gagal' }

async function load() {
  loading.value = true
  const { returns: data } = await listReturns(1, { agent_id: agentId.value })
  returns.value = data
  loading.value = false
}
onMounted(load)

async function review(returnRequest: ReturnRequestRecord, approved: boolean) {
  busyId.value = returnRequest.id
  try {
    await reviewReturn(returnRequest.id, approved)
    await load()
  } finally {
    busyId.value = null
  }
}

async function markRefunded(itemId: number) {
  busyId.value = itemId
  try {
    await markReturnItemRefunded(itemId)
    await load()
  } finally {
    busyId.value = null
  }
}
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">Return</h1>
    <p class="mb-4 text-sm text-stone-500 dark:text-stone-400">Pengajuan pengembalian per item dari konsumen.</p>

    <div v-if="isSuperAdmin" class="mb-4 flex items-end gap-3">
      <AgentPicker v-model="agentId" />
      <button type="button" class="rounded-lg bg-stone-800 px-3 py-2 text-xs font-medium text-white hover:bg-stone-900 dark:bg-stone-100 dark:text-stone-900" @click="load">
        Terapkan
      </button>
    </div>

    <div v-if="loading" class="space-y-2">
      <div v-for="i in 3" :key="i" class="h-32 animate-pulse rounded-xl bg-stone-100 dark:bg-stone-800" />
    </div>
    <p v-else-if="!returns.length" class="py-10 text-center text-sm text-stone-400">Belum ada pengajuan return.</p>

    <div v-else class="space-y-3">
      <div v-for="r in returns" :key="r.id" class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
        <div class="flex items-start justify-between gap-2">
          <div>
            <p class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ r.order_no }} &middot; {{ r.customer_name }}</p>
            <p class="text-xs text-stone-400">{{ r.reason }}</p>
          </div>
          <div class="text-right">
            <p class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ formatRupiah(r.total_refund_amount) }}</p>
            <span class="rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-medium text-stone-600 dark:bg-stone-800 dark:text-stone-300">
              {{ STATUS_LABEL[r.status] }}
            </span>
          </div>
        </div>

        <ul class="mt-2 space-y-1.5 border-t border-stone-100 pt-2 dark:border-stone-800">
          <li v-for="item in r.items" :key="item.id" class="text-xs">
            <div class="flex items-center justify-between">
              <span class="text-stone-600 dark:text-stone-300">
                {{ item.product_name }}<span v-if="item.variation_label"> ({{ item.variation_label }})</span> &times;{{ item.quantity_returned }} — {{ formatRupiah(item.refund_amount) }}
                <span class="block text-xs text-stone-400 dark:text-stone-500">{{ skuLabel(item.sku) }}</span>
              </span>
              <span class="flex items-center gap-2">
                <span class="text-stone-400">{{ REFUND_LABEL[item.refund_status] }}</span>
                <AppButton v-if="item.status === 'approved' && item.refund_status === 'pending'" size="sm" :disabled="busyId === item.id" @click="markRefunded(item.id)">
                  Tandai Sudah Dikembalikan
                </AppButton>
              </span>
            </div>
            <p v-if="item.condition_note" class="mt-0.5 text-stone-400">Catatan kurir: {{ item.condition_note }}</p>
          </li>
        </ul>

        <div v-if="r.status === 'requested' || r.status === 'under_review'" class="mt-2 flex gap-2 border-t border-stone-100 pt-2 dark:border-stone-800">
          <AppButton size="sm" :disabled="busyId === r.id" @click="review(r, true)">Setujui</AppButton>
          <AppButton size="sm" variant="danger" :disabled="busyId === r.id" @click="review(r, false)">Tolak</AppButton>
        </div>
        <p class="mt-1.5 text-[11px] text-stone-400">{{ formatDate(r.created_at) }}</p>
      </div>
    </div>
  </DashboardLayout>
</template>
