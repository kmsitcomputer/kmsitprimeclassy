<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { ApiError } from '@/api/client'
import {
  approveFulfillmentProposal,
  getFulfillmentProposal,
  rejectFulfillmentProposal,
  resolveStockRequestDiscrepancy,
  type WarehouseFulfillmentProposal,
  type WarehouseProposalItem,
} from '@/api/warehouseFulfillment'
import { useAuthStore } from '@/stores/auth'
import { formatDateTime, skuLabel } from '@/utils/format'

// Route registers this view with `props: true` (route name: warehouse-proposal-detail),
// so the id arrives as a prop and a change re-loads the proposal below.
const props = defineProps<{ id: number }>()

const router = useRouter()
const auth = useAuthStore()
// Approve / reject / resolve-discrepancy are admin-only on the backend — UI gate only, backend authoritative.
const isAdmin = computed(() => auth.user?.role === 'admin')

// The show endpoint carries a few keys the list-oriented types omit (renderProposal / renderProposalItems).
type ProposalDetailItem = WarehouseProposalItem & { accepted_qty?: number | null; short_qty?: number | null; damaged_qty?: number | null }
type ProposalDetail = Omit<WarehouseFulfillmentProposal, 'items'> & {
  approved_at?: string | null
  items: ProposalDetailItem[]
}

const proposal = ref<ProposalDetail | null>(null)
const state = ref<'loading' | 'error' | 'ready'>('loading')
const loadError = ref('')
const error = ref('')
const success = ref('')
const busy = ref<'approve' | 'reject' | number | null>(null)

const showApproveForm = ref(false)
const approveReason = ref('')
const showRejectForm = ref(false)
const rejectReason = ref('')

interface ResolveForm {
  action: 'restock' | 'write_off'
  quantity: number
  reason: string
}
const resolveForms = ref<Record<number, ResolveForm>>({})
const openResolveId = ref<number | null>(null)

const STATUS_LABEL: Record<string, string> = { pending: 'Menunggu', approved: 'Disetujui', rejected: 'Ditolak' }
const STATUS_CLASS: Record<string, string> = {
  pending: 'bg-amber-100 text-amber-800',
  approved: 'bg-emerald-100 text-emerald-800',
  rejected: 'bg-red-100 text-red-700',
}

function statusLabel(status: string): string {
  return STATUS_LABEL[status] ?? status
}

function statusClass(status: string): string {
  return STATUS_CLASS[status] ?? 'bg-stone-100 text-stone-600'
}

// An item needs settlement when the Koordinator recorded a shortage or damage on receipt.
function unresolvedQty(item: ProposalDetailItem): number {
  return (item.short_qty ?? 0) + (item.damaged_qty ?? 0)
}

function defaultResolveQuantity(item: ProposalDetailItem): number {
  return Math.max(1, unresolvedQty(item))
}

function messageOf(e: unknown, fallback: string): string {
  return e instanceof ApiError || e instanceof Error ? e.message : fallback
}

async function load() {
  state.value = 'loading'
  loadError.value = ''
  showApproveForm.value = false
  showRejectForm.value = false
  approveReason.value = ''
  rejectReason.value = ''
  resolveForms.value = {}
  openResolveId.value = null
  try {
    proposal.value = await getFulfillmentProposal(props.id)
    state.value = 'ready'
  } catch (e) {
    proposal.value = null
    state.value = 'error'
    loadError.value = messageOf(e, 'Gagal memuat proposal pengiriman.')
  }
}

watch(() => props.id, load, { immediate: true })

async function approve() {
  if (!proposal.value || busy.value) return
  error.value = ''
  success.value = ''
  const reason = approveReason.value.trim()
  if (reason.length < 5) {
    error.value = 'Alasan persetujuan wajib diisi (minimal 5 karakter).'
    return
  }
  busy.value = 'approve'
  try {
    await approveFulfillmentProposal(proposal.value.id, reason)
    success.value = 'Proposal disetujui.'
    showApproveForm.value = false
    approveReason.value = ''
    await load()
  } catch (e) {
    error.value = messageOf(e, 'Gagal menyetujui proposal.')
  } finally {
    busy.value = null
  }
}

async function reject() {
  if (!proposal.value || busy.value) return
  error.value = ''
  success.value = ''
  const reason = rejectReason.value.trim()
  if (!reason) {
    error.value = 'Alasan penolakan wajib diisi.'
    return
  }
  busy.value = 'reject'
  try {
    await rejectFulfillmentProposal(proposal.value.id, reason)
    success.value = 'Proposal ditolak.'
    showRejectForm.value = false
    rejectReason.value = ''
    await load()
  } catch (e) {
    error.value = messageOf(e, 'Gagal menolak proposal.')
  } finally {
    busy.value = null
  }
}

function openResolve(item: ProposalDetailItem) {
  error.value = ''
  success.value = ''
  openResolveId.value = item.id
  resolveForms.value[item.id] = { action: 'restock', quantity: defaultResolveQuantity(item), reason: '' }
}

async function submitResolve(item: ProposalDetailItem) {
  if (busy.value) return
  error.value = ''
  success.value = ''
  const form = resolveForms.value[item.id]
  if (!form) return
  if (!Number.isInteger(form.quantity) || form.quantity < 1) {
    error.value = 'Jumlah harus bilangan bulat minimal 1.'
    return
  }
  if (form.reason.trim().length < 5) {
    error.value = 'Alasan wajib diisi (minimal 5 karakter).'
    return
  }
  busy.value = item.id
  try {
    // Resolve against the STOCK REQUEST item, not the proposal item — that is the canonical inventory line.
    await resolveStockRequestDiscrepancy(item.stock_request_item_id, {
      action: form.action,
      quantity: form.quantity,
      reason: form.reason.trim(),
    })
    success.value = 'Selisih diselesaikan.'
    openResolveId.value = null
    delete resolveForms.value[item.id]
    await load()
  } catch (e) {
    error.value = messageOf(e, 'Gagal menyelesaikan selisih.')
  } finally {
    busy.value = null
  }
}

function goBack() {
  router.back()
}
</script>

<template>
  <DashboardLayout>
    <div class="mb-4 flex flex-wrap items-center gap-3">
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1 text-sm text-stone-600" @click="goBack">Kembali</button>
      <span v-if="proposal" class="text-sm text-stone-400">Detail proposal pengiriman gudang</span>
    </div>

    <p v-if="error" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{{ error }}</p>
    <p v-if="success" class="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-600">{{ success }}</p>

    <div v-if="state === 'loading'" class="text-sm text-stone-400">Memuat...</div>

    <div v-else-if="state === 'error'" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-600">
      {{ loadError }}
      <button type="button" class="ml-2 underline" @click="load()">Coba lagi</button>
    </div>

    <template v-else-if="proposal">
      <!-- ================= Header ================= -->
      <section class="mb-6 rounded-xl border border-stone-200 bg-white p-4">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
          <h1 class="font-display text-2xl font-semibold">Proposal #{{ proposal.id }}</h1>
          <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(proposal.status)">{{ statusLabel(proposal.status) }}</span>
        </div>

        <dl class="grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-xs text-stone-400">Pengirim</dt>
            <dd class="font-medium">{{ proposal.requester?.name ?? '-' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-stone-400">Permintaan Stok</dt>
            <dd class="font-medium">
              {{ proposal.request?.request_number ?? '-' }}
              <span v-if="proposal.request?.order?.order_no" class="text-stone-500">· Order {{ proposal.request.order.order_no }}</span>
            </dd>
          </div>
          <div class="sm:col-span-2">
            <dt class="text-xs text-stone-400">Bukti Serah Terima</dt>
            <dd>
              <img v-if="proposal.handover_proof_url" :src="proposal.handover_proof_url" alt="Bukti serah terima" class="mt-1 max-h-64 rounded-xl border border-stone-200 object-contain" />
              <span v-else class="text-stone-400">Tidak ada bukti.</span>
            </dd>
          </div>
          <div v-if="proposal.rejection_reason" class="sm:col-span-2">
            <dt class="text-xs text-stone-400">Alasan Penolakan</dt>
            <dd class="text-red-600">{{ proposal.rejection_reason }}</dd>
          </div>
        </dl>

        <div class="mt-4 grid gap-2 border-t border-stone-100 pt-3 text-xs text-stone-500 sm:grid-cols-3">
          <p>Dibuat: <span class="text-stone-700">{{ formatDateTime(proposal.created_at) }}</span></p>
          <p>Disetujui: <span class="text-stone-700">{{ formatDateTime(proposal.approved_at ?? null) }}</span></p>
          <p>Diterima: <span class="text-stone-700">{{ formatDateTime(proposal.received_at) }}</span></p>
        </div>

        <!-- ================= Admin decision (pending only) ================= -->
        <div v-if="isAdmin && proposal.status === 'pending'" class="mt-4 flex flex-wrap items-center gap-2 border-t border-stone-100 pt-4">
          <AppButton variant="primary" size="sm" :disabled="busy !== null" @click="showApproveForm = !showApproveForm">Setujui</AppButton>
          <AppButton variant="danger" size="sm" :disabled="busy !== null" @click="showRejectForm = !showRejectForm">Tolak</AppButton>
        </div>

        <!-- Inline approve form (reason min 5 chars, then confirm) -->
        <div v-if="isAdmin && proposal.status === 'pending' && showApproveForm" class="mt-3 rounded-lg bg-stone-50 p-3">
          <label class="mb-2 block text-xs text-stone-500" for="proposal-approve-reason">Alasan persetujuan (minimal 5 karakter)</label>
          <div class="flex flex-wrap items-center gap-2">
            <input
              id="proposal-approve-reason"
              v-model="approveReason"
              type="text"
              maxlength="255"
              placeholder="Alasan persetujuan"
              class="min-w-0 flex-1 rounded-lg border border-stone-200 px-3 py-2 text-sm"
              :disabled="busy !== null"
            />
            <AppButton variant="primary" size="sm" :disabled="busy !== null" @click="approve">
              {{ busy === 'approve' ? 'Memproses...' : 'Konfirmasi Persetujuan' }}
            </AppButton>
          </div>
        </div>

        <!-- Inline reject form (reason required, then confirm) -->
        <div v-if="isAdmin && proposal.status === 'pending' && showRejectForm" class="mt-3 rounded-lg bg-stone-50 p-3">
          <label class="mb-2 block text-xs text-stone-500" for="proposal-reject-reason">Alasan penolakan (wajib)</label>
          <div class="flex flex-wrap items-center gap-2">
            <input
              id="proposal-reject-reason"
              v-model="rejectReason"
              type="text"
              maxlength="255"
              placeholder="Alasan penolakan"
              class="min-w-0 flex-1 rounded-lg border border-stone-200 px-3 py-2 text-sm"
              :disabled="busy !== null"
            />
            <AppButton variant="danger" size="sm" :disabled="busy !== null" @click="reject">
              {{ busy === 'reject' ? 'Memproses...' : 'Konfirmasi Penolakan' }}
            </AppButton>
          </div>
        </div>
      </section>

      <!-- ================= Items ================= -->
      <section class="mb-6 rounded-xl border border-stone-200 bg-white p-4">
        <h2 class="mb-3 font-display text-xl font-semibold">Item Pengiriman</h2>
        <div v-if="!proposal.items.length" class="text-sm text-stone-400">Tidak ada item pada proposal ini.</div>
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[44rem] text-left text-sm">
            <thead class="text-xs uppercase tracking-wide text-stone-400">
              <tr class="border-b border-stone-200">
                <th class="py-2 pr-3 font-medium">Produk</th>
                <th class="py-2 pr-3 font-medium">Varian</th>
                <th class="py-2 pr-3 font-medium">SKU</th>
                <th class="py-2 pr-3 text-right font-medium">Diminta</th>
                <th class="py-2 pr-3 text-right font-medium">Dikirim</th>
                <th class="py-2 pr-3 text-right font-medium">Diterima</th>
                <th class="py-2 pr-3 text-right font-medium">Kurang</th>
                <th class="py-2 pr-3 text-right font-medium">Rusak</th>
                <th class="py-2 pr-3 font-medium"></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="item in proposal.items" :key="item.id">
                <tr class="border-b border-stone-100 last:border-0">
                  <td class="py-2 pr-3">{{ item.request_item?.product_name ?? '-' }}</td>
                  <td class="py-2 pr-3 text-stone-500">{{ item.request_item?.variation_label ?? '-' }}</td>
                  <td class="py-2 pr-3 text-xs text-stone-400">{{ skuLabel(item.request_item?.sku ?? null) }}</td>
                  <td class="py-2 pr-3 text-right">{{ item.request_item?.requested_qty ?? '-' }}</td>
                  <td class="py-2 pr-3 text-right font-medium">{{ item.quantity }}</td>
                  <td class="py-2 pr-3 text-right">{{ item.accepted_qty ?? '-' }}</td>
                  <td class="py-2 pr-3 text-right" :class="(item.short_qty ?? 0) > 0 ? 'font-semibold text-red-600' : ''">{{ item.short_qty ?? '-' }}</td>
                  <td class="py-2 pr-3 text-right" :class="(item.damaged_qty ?? 0) > 0 ? 'font-semibold text-red-600' : ''">{{ item.damaged_qty ?? '-' }}</td>
                  <td class="py-2 pr-3">
                    <AppButton
                      v-if="isAdmin && unresolvedQty(item) > 0 && openResolveId !== item.id"
                      variant="secondary"
                      size="sm"
                      :disabled="busy !== null"
                      @click="openResolve(item)"
                    >
                      Selesaikan Selisih
                    </AppButton>
                  </td>
                </tr>
                <!-- Per-item inline discrepancy settlement form -->
                <tr v-if="openResolveId === item.id" class="border-b border-stone-100 bg-stone-50 last:border-0">
                  <td :colspan="9" class="p-3">
                    <div v-if="resolveForms[item.id]" class="grid gap-2 md:grid-cols-[160px_120px_1fr_auto] md:items-end">
                      <div>
                        <label class="mb-1 block text-xs text-stone-500" :for="`resolve-action-${item.id}`">Tindakan</label>
                        <select :id="`resolve-action-${item.id}`" v-model="resolveForms[item.id]!.action" class="w-full rounded-lg border border-stone-200 px-2 py-2 text-sm" :disabled="busy !== null">
                          <option value="restock">Restock (kembali ke Transit)</option>
                          <option value="write_off">Write-off (rusak/hilang)</option>
                        </select>
                      </div>
                      <div>
                        <label class="mb-1 block text-xs text-stone-500" :for="`resolve-qty-${item.id}`">Jumlah (min 1)</label>
                        <input :id="`resolve-qty-${item.id}`" v-model.number="resolveForms[item.id]!.quantity" type="number" min="1" step="1" class="w-full rounded-lg border border-stone-200 px-2 py-2 text-sm" :disabled="busy !== null" />
                      </div>
                      <div>
                        <label class="mb-1 block text-xs text-stone-500" :for="`resolve-reason-${item.id}`">Alasan (minimal 5 karakter)</label>
                        <input :id="`resolve-reason-${item.id}`" v-model="resolveForms[item.id]!.reason" type="text" maxlength="255" placeholder="Alasan penyelesaian selisih" class="w-full rounded-lg border border-stone-200 px-3 py-2 text-sm" :disabled="busy !== null" />
                      </div>
                      <div class="flex items-center gap-2">
                        <AppButton variant="primary" size="sm" :disabled="busy !== null" @click="submitResolve(item)">
                          {{ busy === item.id ? 'Memproses...' : 'Simpan' }}
                        </AppButton>
                        <AppButton variant="ghost" size="sm" :disabled="busy !== null" @click="openResolveId = null">Batal</AppButton>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ================= Shortages reported by Gudang ================= -->
      <section v-if="proposal.shortages?.length" class="rounded-xl border border-stone-200 bg-white p-4">
        <h2 class="mb-3 font-display text-xl font-semibold">Kekurangan yang Dilaporkan</h2>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[32rem] text-left text-sm">
            <thead class="text-xs uppercase tracking-wide text-stone-400">
              <tr class="border-b border-stone-200">
                <th class="py-2 pr-3 font-medium">Item Permintaan</th>
                <th class="py-2 pr-3 font-medium">Alasan</th>
                <th class="py-2 pr-3 text-right font-medium">Diserahkan</th>
                <th class="py-2 pr-3 text-right font-medium">Kurang</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in proposal.shortages" :key="row.stock_request_item_id" class="border-b border-stone-100 last:border-0">
                <td class="py-2 pr-3">#{{ row.stock_request_item_id }}</td>
                <td class="py-2 pr-3 text-stone-500">{{ row.reason ?? '-' }}</td>
                <td class="py-2 pr-3 text-right">{{ row.handed_over_qty }}</td>
                <td class="py-2 pr-3 text-right font-semibold text-red-600">{{ row.short_qty }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </DashboardLayout>
</template>
