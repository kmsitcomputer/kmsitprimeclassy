<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import {
  listFulfillmentProposals,
  approveFulfillmentProposal,
  rejectFulfillmentProposal,
  type WarehouseFulfillmentProposal,
} from '@/api/warehouseFulfillment'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { skuLabel } from '@/utils/format'

/**
 * IMP-001 UAT remediation (Gap 2) — Admin reviews the Gudang's fulfillment
 * proposals (approve / reject). This is the canonical warehouse workflow:
 * Gudang proposes -> Admin reviews -> the internal proposal approval path
 * moves inventory (Transit -> Shipping, releases reservations) exactly as
 * before. Admin only sees same-branch proposals (backend `agent_id` scope).
 */
const proposals = ref<WarehouseFulfillmentProposal[]>([])
const meta = ref({ current_page: 1, last_page: 1, total: 0 })
const status = ref<'pending' | 'approved' | 'rejected' | ''>('pending')
const rejectReason = ref<Record<number, string>>({})
const loading = ref(true)
const errorMessage = ref('')
const successMessage = ref('')
const acting = ref(false)

function itemName(item: WarehouseFulfillmentProposal['items'][number]): string {
  return item.request_item?.product_name ?? (item.request_item?.product_id != null ? `Produk #${item.request_item.product_id}` : 'Item')
}

function statusLabel(s: string): string {
  return s === 'pending' ? 'Menunggu' : s === 'approved' ? 'Disetujui' : s === 'rejected' ? 'Ditolak' : s
}

function statusClass(s: string): string {
  return s === 'pending' ? 'bg-amber-100 text-amber-800' : s === 'approved' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-700'
}

async function load(reset = false) {
  if (reset) meta.value.current_page = 1
  loading.value = true
  errorMessage.value = ''
  try {
    const result = await listFulfillmentProposals({
      ...(status.value ? { status: status.value } : {}),
      page: meta.value.current_page,
    })
    proposals.value = result.proposals
    meta.value = result.meta
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat proposal fulfillment.'
  } finally {
    loading.value = false
  }
}

async function approve(id: number) {
  acting.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    await approveFulfillmentProposal(id)
    successMessage.value = 'Proposal disetujui. Transit berkurang, Shipping bertambah, Reservasi dilepas.'
    await load()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menyetujui proposal.'
  } finally {
    acting.value = false
  }
}

async function reject(id: number) {
  acting.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const reason = (rejectReason.value[id] ?? '').trim()
    if (!reason) {
      errorMessage.value = 'Isi alasan penolakan.'
      acting.value = false
      return
    }
    await rejectFulfillmentProposal(id, reason)
    successMessage.value = 'Proposal ditolak.'
    delete rejectReason.value[id]
    await load()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menolak proposal.'
  } finally {
    acting.value = false
  }
}

function onPage(d: number) {
  const next = Math.max(1, Math.min(meta.value.last_page, meta.value.current_page + d))
  meta.value.current_page = next
  void load()
}

onMounted(() => load())
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Fulfillment Proposal</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
      Usulan fulfillment dari Gudang untuk order diproses. Setujui untuk memindahkan stok Transit ke Shipping dan
      melepas reservasi; tolak dengan alasan untuk mengembalikan ke Gudang.
    </p>

    <p v-if="errorMessage" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ errorMessage }}
    </p>
    <p v-if="successMessage" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
      {{ successMessage }}
    </p>

    <div class="mb-4 flex max-w-xl gap-2">
      <select v-model="status" class="rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" @change="load(true)">
        <option value="pending">Menunggu</option>
        <option value="approved">Disetujui</option>
        <option value="rejected">Ditolak</option>
        <option value="">Semua</option>
      </select>
    </div>

    <div v-if="loading" class="space-y-3">
      <div v-for="i in 3" :key="i" class="h-24 animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
    </div>

    <div v-else-if="!proposals.length" class="rounded-2xl bg-stone-100 px-4 py-12 text-center text-sm text-stone-500 dark:bg-stone-900 dark:text-stone-400">
      Belum ada proposal fulfillment.
    </div>

    <div v-else class="space-y-3">
      <div v-for="proposal in proposals" :key="proposal.id" class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm">
          <span class="font-medium">{{ proposal.request?.order?.order_no ?? proposal.request?.request_number ?? `Request #${proposal.stock_request_id}` }}</span>
          <span class="text-xs text-stone-400">{{ proposal.requester?.name ?? '' }} · {{ proposal.created_at ?? '' }}</span>
          <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(proposal.status)">{{ statusLabel(proposal.status) }}</span>
        </div>

        <ul class="grid gap-2">
          <li v-for="line in proposal.items" :key="line.id" class="flex flex-wrap items-center gap-3 rounded-lg bg-stone-50 p-2 text-sm dark:bg-stone-800/60">
            <img v-if="line.request_item?.product_image_url" :src="line.request_item.product_image_url" :alt="itemName(line)" class="h-12 w-12 shrink-0 rounded-xl object-cover" />
            <div class="min-w-0 flex-1 basis-48">
              <div class="font-medium">{{ itemName(line) }}</div>
              <div v-if="line.request_item?.variation_label" class="text-xs text-stone-500">Varian: {{ line.request_item.variation_label }}</div>
              <div class="truncate text-xs text-stone-400">{{ skuLabel(line.request_item?.sku ?? null) }}</div>
              <div class="mt-1 grid grid-cols-3 gap-1 text-xs">
                <span class="rounded bg-white px-2 py-1 dark:bg-stone-900">Diminta: <strong>{{ line.request_item?.requested_qty ?? '—' }}</strong></span>
                <span class="rounded bg-white px-2 py-1 dark:bg-stone-900">Terpenuhi: <strong>{{ line.request_item?.fulfilled_qty ?? '—' }}</strong></span>
                <span class="rounded bg-white px-2 py-1 dark:bg-stone-900">Sisa: <strong>{{ line.request_item?.remaining_qty ?? '—' }}</strong></span>
              </div>
              <div class="mt-1 grid grid-cols-3 gap-1 text-xs">
                <span class="rounded bg-white px-2 py-1 dark:bg-stone-900">Transit: <strong>{{ line.request_item?.current_stock?.transit ?? '—' }}</strong></span>
                <span class="rounded bg-white px-2 py-1 dark:bg-stone-900">Shipping: <strong>{{ line.request_item?.current_stock?.shipping ?? '—' }}</strong></span>
                <span class="rounded bg-white px-2 py-1 dark:bg-stone-900">Reserved: <strong>{{ line.request_item?.current_stock?.reserved ?? '—' }}</strong></span>
              </div>
            </div>
            <div class="shrink-0 text-sm font-medium">Usulan: <strong>+{{ line.quantity }}</strong></div>
            <div v-if="proposal.status === 'pending'" class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
              <AppButton size="sm" :disabled="acting" @click="approve(proposal.id)">Setujui</AppButton>
              <input v-model="rejectReason[proposal.id]" placeholder="Alasan tolak" class="min-w-0 flex-1 rounded-lg border border-stone-200 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-950" />
              <AppButton size="sm" variant="danger" :disabled="acting" @click="reject(proposal.id)">Tolak</AppButton>
            </div>
            <p v-else-if="proposal.status === 'rejected' && proposal.rejection_reason" class="w-full text-xs text-red-500">
              Alasan: {{ proposal.rejection_reason }}
            </p>
          </li>
        </ul>
      </div>

      <div v-if="meta.last_page > 1" class="flex items-center justify-between gap-2 text-sm">
        <AppButton size="sm" variant="secondary" :disabled="meta.current_page <= 1" @click="onPage(-1)">Prev</AppButton>
        <span class="text-stone-500">Halaman {{ meta.current_page }} / {{ meta.last_page }} ({{ meta.total }})</span>
        <AppButton size="sm" variant="secondary" :disabled="meta.current_page >= meta.last_page" @click="onPage(1)">Next</AppButton>
      </div>
    </div>
  </DashboardLayout>
</template>