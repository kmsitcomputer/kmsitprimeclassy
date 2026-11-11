<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { formatDate } from '@/utils/format'
import { listFulfillmentProposals, receiveFulfillmentProposal, type WarehouseFulfillmentProposal } from '@/api/warehouseFulfillment'
import { recordExpeditionHandover, updateShipmentStatus } from '@/api/shipments'
import {
  disposeRefusal, listExpeditions, listRefusals, receiveRefusal, requestFulfillment,
  type ExpeditionRow, type RefusalRow,
} from '@/api/koordinatorLogistics'

/**
 * REM-002/003 — Koordinator Kurir working panels inside Dispatch:
 *  - receipts:    confirm physical receipt of what Gudang prepared (accepted / short / damaged per item)
 *  - refusals:    reverse custody of refused deliveries (receive → redelivery | return to Gudang)
 *  - expeditions: RajaOngkir handover (carrier, tracking number, evidence) with the dispatch readiness gates
 * Actions are server-authoritative; the panel only reloads from the API after each action.
 */
const props = defineProps<{ section: 'receipts' | 'refusals' | 'expeditions' }>()

const loading = ref(false)
const error = ref('')
const success = ref('')
const busyId = ref<number | null>(null)
const page = ref(1)
const lastPage = ref(1)

const proposals = ref<WarehouseFulfillmentProposal[]>([])
const refusals = ref<RefusalRow[]>([])
const expeditions = ref<ExpeditionRow[]>([])

// receipts: per proposal-item counts keyed by proposal item id
const counts = ref<Record<number, { accepted: number; short: number; damaged: number }>>({})
const notes = ref<Record<number, string>>({})
// expedition form state keyed by shipment id
const trackingNo = ref<Record<number, string>>({})
const evidence = ref<Record<number, File | null>>({})

function fail(e: unknown, fallback: string) {
  error.value = e instanceof ApiError ? formatApiError(e) : fallback
}

async function load(target = page.value) {
  page.value = target
  loading.value = true
  error.value = ''
  try {
    if (props.section === 'receipts') {
      const res = await listFulfillmentProposals({ status: 'pending', page: page.value })
      proposals.value = res.proposals
      lastPage.value = res.meta?.last_page ?? 1
      for (const p of res.proposals) {
        for (const it of p.items) {
          if (!counts.value[it.id]) counts.value[it.id] = { accepted: it.quantity, short: 0, damaged: 0 }
        }
      }
    } else if (props.section === 'refusals') {
      const res = await listRefusals(page.value)
      refusals.value = res.rows
      lastPage.value = res.meta?.last_page ?? 1
    } else {
      const res = await listExpeditions(page.value)
      expeditions.value = res.rows
      lastPage.value = res.meta?.last_page ?? 1
    }
  } catch (e) {
    fail(e, 'Gagal memuat data.')
  } finally {
    loading.value = false
  }
}

function sumOk(p: WarehouseFulfillmentProposal) {
  return p.items.every((it) => {
    const c = counts.value[it.id]
    return !!c && c.accepted >= 0 && c.short >= 0 && c.damaged >= 0 && c.accepted + c.short + c.damaged === it.quantity
  })
}

async function confirmReceipt(p: WarehouseFulfillmentProposal) {
  if (!sumOk(p) || busyId.value !== null) return
  busyId.value = p.id
  error.value = ''
  success.value = ''
  try {
    await receiveFulfillmentProposal(
      p.id,
      p.items.map((it) => ({ item_id: it.id, ...counts.value[it.id]! })),
      notes.value[p.id] || undefined,
    )
    success.value = 'Penerimaan barang tercatat.'
    await load()
  } catch (e) {
    fail(e, 'Gagal mengonfirmasi penerimaan.')
  } finally {
    busyId.value = null
  }
}

async function receive(r: RefusalRow) {
  if (busyId.value !== null) return
  busyId.value = r.id
  error.value = ''
  try {
    await receiveRefusal(r.id)
    await load()
  } catch (e) {
    fail(e, 'Gagal mengonfirmasi penerimaan barang tolakan.')
  } finally {
    busyId.value = null
  }
}

async function dispose(r: RefusalRow, action: 'redelivery' | 'return_to_warehouse') {
  if (busyId.value !== null) return
  busyId.value = r.id
  error.value = ''
  try {
    await disposeRefusal(r.id, action)
    success.value = action === 'redelivery' ? 'Barang dijadwalkan kirim ulang.' : 'Barang dikembalikan ke Gudang.'
    await load()
  } catch (e) {
    fail(e, 'Gagal menyimpan keputusan.')
  } finally {
    busyId.value = null
  }
}

function onEvidence(id: number, ev: Event) {
  evidence.value[id] = (ev.target as HTMLInputElement).files?.[0] ?? null
}

async function submitHandover(row: ExpeditionRow) {
  const file = evidence.value[row.shipment_id]
  const no = (trackingNo.value[row.shipment_id] ?? '').trim()
  if (!row.carrier || !file || no.length < 6 || busyId.value !== null) return
  busyId.value = row.shipment_id
  error.value = ''
  success.value = ''
  try {
    await recordExpeditionHandover(row.shipment_id, { carrier: row.carrier, tracking_number: no, evidence: file })
    success.value = 'Serah terima ekspedisi dan resi tercatat.'
    await load()
  } catch (e) {
    fail(e, 'Gagal mencatat serah terima ekspedisi.')
  } finally {
    busyId.value = null
  }
}

async function askGudang(row: ExpeditionRow) {
  if (busyId.value !== null) return
  busyId.value = row.shipment_id
  error.value = ''
  success.value = ''
  try {
    await requestFulfillment(row.order_id)
    success.value = 'Permintaan pemenuhan dikirim ke Gudang.'
    await load()
  } catch (e) {
    fail(e, 'Gagal meminta barang ke Gudang.')
  } finally {
    busyId.value = null
  }
}

/** Whole-shipment progression: the server refuses unless paid in full, complete, one shipment and handover recorded. */
async function dispatchShipment(row: ExpeditionRow) {
  if (busyId.value !== null) return
  busyId.value = row.shipment_id
  error.value = ''
  success.value = ''
  try {
    await updateShipmentStatus(row.shipment_id, 'dikirim')
    success.value = `Pengiriman ${row.order_no} ditandai dikirim.`
    await load()
  } catch (e) {
    fail(e, 'Gagal menandai dikirim.')
  } finally {
    busyId.value = null
  }
}

function go(delta: number) {
  void load(Math.max(1, Math.min(lastPage.value, page.value + delta)))
}

const gateLabels: Record<string, string> = { paid_in_full: 'Lunas penuh', goods_complete: 'Barang lengkap', single_shipment: 'Satu pengiriman' }

onMounted(load)
watch(() => props.section, () => load(1))
</script>

<template>
  <section class="space-y-3" :aria-label="section">
    <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
    <p v-if="success" role="status" class="text-sm text-emerald-600">{{ success }}</p>
    <AppButton size="sm" variant="secondary" :disabled="loading" @click="load(page)">Muat ulang</AppButton>
    <p v-if="loading" role="status" class="text-sm text-stone-500">Memuat…</p>

    <!-- Serah terima Gudang → Koordinator: konfirmasi penerimaan fisik -->
    <template v-if="section === 'receipts'">
      <p v-if="!loading && !proposals.length" class="text-sm text-stone-500">Tidak ada penyerahan dari Gudang yang menunggu konfirmasi penerimaan Anda.</p>
      <div v-for="p in proposals" :key="p.id" class="rounded-xl border border-stone-200 p-4 dark:border-stone-800">
        <p class="text-sm font-medium">{{ p.request?.order?.order_no ?? `Permintaan #${p.stock_request_id}` }}
          <span class="ml-2 text-xs text-stone-500">diserahkan Gudang {{ p.created_at ? formatDate(p.created_at) : '' }}<template v-if="p.requester"> · {{ p.requester.name }}</template></span>
        </p>
        <p v-if="p.handover_proof_url" class="mt-1 text-xs">
          Bukti serah terima Gudang:
          <a :href="p.handover_proof_url" target="_blank" rel="noopener" class="text-brand-700 underline dark:text-brand-300">lihat foto</a>
        </p>
        <ul v-if="p.shortages?.length" class="mt-1 list-inside list-disc text-xs text-amber-700 dark:text-amber-400">
          <li v-for="sh in p.shortages" :key="sh.stock_request_item_id">Gudang mencatat kurang {{ sh.short_qty }} unit: {{ sh.reason }}</li>
        </ul>
        <div v-for="it in p.items" :key="it.id" class="mt-3 grid grid-cols-2 items-end gap-2 text-sm sm:grid-cols-5">
          <span class="col-span-2">{{ (it as any).request_item?.product?.name ?? (it as any).request_item?.sku_snapshot ?? 'Item' }} · diserahkan <b>{{ it.quantity }}</b></span>
          <label class="text-xs">Diterima
            <input v-model.number="counts[it.id]!.accepted" type="number" min="0" class="w-full rounded-lg border border-stone-200 px-2 py-1 dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="text-xs">Kurang
            <input v-model.number="counts[it.id]!.short" type="number" min="0" class="w-full rounded-lg border border-stone-200 px-2 py-1 dark:border-stone-700 dark:bg-stone-950" />
          </label>
          <label class="text-xs">Rusak
            <input v-model.number="counts[it.id]!.damaged" type="number" min="0" class="w-full rounded-lg border border-stone-200 px-2 py-1 dark:border-stone-700 dark:bg-stone-950" />
          </label>
        </div>
        <p v-if="!sumOk(p)" class="mt-2 text-xs text-amber-600">Diterima + kurang + rusak harus sama dengan jumlah yang diserahkan Gudang.</p>
        <p class="mt-2 text-xs text-stone-500">Hanya jumlah "diterima" yang siap ditugaskan ke kurir; kurang/rusak tetap menjadi kebutuhan outstanding.</p>
        <div class="mt-3 flex flex-wrap items-center gap-2">
          <input v-model="notes[p.id]" type="text" maxlength="255" placeholder="Catatan (opsional)" class="min-w-[12rem] flex-1 rounded-lg border border-stone-200 px-2 py-1 text-sm dark:border-stone-700 dark:bg-stone-950" />
          <AppButton size="sm" :disabled="!sumOk(p) || busyId !== null" @click="confirmReceipt(p)">
            {{ busyId === p.id ? 'Menyimpan…' : 'Konfirmasi Penerimaan' }}
          </AppButton>
        </div>
      </div>
    </template>

    <!-- Penolakan pelanggan / reverse custody -->
    <template v-else-if="section === 'refusals'">
      <p v-if="!loading && !refusals.length" class="text-sm text-stone-500">Tidak ada penolakan yang sedang berjalan.</p>
      <div v-for="r in refusals" :key="r.id" class="rounded-xl border border-stone-200 p-4 text-sm dark:border-stone-800">
        <p class="font-medium">{{ r.order_no }} · {{ r.product_name }} × {{ r.quantity }}</p>
        <p class="text-stone-500">Alasan: {{ r.reason }}</p>
        <p class="my-1 text-xs">
          Status:
          <b v-if="r.status === 'with_courier'">Masih di kurir — menunggu serah terima balik kurir</b>
          <b v-else-if="r.status === 'handed_back'">Kurir sudah menyerahkan — menunggu konfirmasi Anda</b>
          <b v-else-if="r.status === 'received'">Diterima Koordinator — tentukan tindak lanjut</b>
          <b v-else>{{ r.status }}</b>
        </p>
        <div class="mt-2 flex flex-wrap gap-2">
          <AppButton v-if="r.status === 'handed_back'" size="sm" :disabled="busyId !== null" @click="receive(r)">Konfirmasi Terima Barang</AppButton>
          <template v-if="r.status === 'received'">
            <AppButton size="sm" :disabled="busyId !== null" @click="dispose(r, 'redelivery')">Kirim Ulang</AppButton>
            <AppButton size="sm" variant="secondary" :disabled="busyId !== null" @click="dispose(r, 'return_to_warehouse')">Kembalikan ke Gudang</AppButton>
          </template>
        </div>
      </div>
    </template>

    <!-- Ekspedisi RajaOngkir -->
    <template v-else>
      <p v-if="!loading && !expeditions.length" class="text-sm text-stone-500">Tidak ada pengiriman ekspedisi yang menunggu.</p>
      <div v-for="row in expeditions" :key="row.shipment_id" class="rounded-xl border border-stone-200 p-4 text-sm dark:border-stone-800">
        <p class="font-medium">{{ row.order_no }} · {{ row.recipient_name }}
          <span class="ml-2 text-xs uppercase text-stone-500">{{ row.carrier }} {{ row.service }}</span></p>
        <ul class="my-2 flex flex-wrap gap-2 text-xs">
          <li v-for="(ok, key) in row.ready" :key="key" class="rounded-full px-2 py-0.5" :class="ok ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'">
            {{ ok ? '✓' : '✗' }} {{ gateLabels[key] }}
          </li>
        </ul>
        <p class="text-xs text-stone-500">
          Outstanding di Gudang: <b>{{ row.fulfillment_outstanding_qty }}</b>
          <span v-if="row.coordinator_requested_at"> · diminta {{ formatDate(row.coordinator_requested_at) }}</span>
        </p>
        <div class="my-2 flex flex-wrap gap-2">
          <AppButton v-if="row.fulfillment_outstanding_qty > 0" size="sm" variant="secondary" :disabled="busyId !== null" @click="askGudang(row)">
            {{ row.coordinator_requested_at ? 'Minta Lagi ke Gudang' : 'Minta ke Gudang' }}
          </AppButton>
          <AppButton v-if="row.handover_recorded" size="sm" :disabled="busyId !== null || !row.ready.paid_in_full || !row.ready.goods_complete || !row.ready.single_shipment" @click="dispatchShipment(row)">
            {{ busyId === row.shipment_id ? 'Memproses…' : 'Tandai Dikirim (seluruh pengiriman)' }}
          </AppButton>
        </div>
        <p v-if="row.handover_recorded" class="text-emerald-700">Resi tercatat: <b>{{ row.tracking_number }}</b>. Pengiriman ekspedisi hanya dapat dikirim sekaligus.</p>
        <div v-else class="grid gap-2 sm:grid-cols-3">
          <input v-model="trackingNo[row.shipment_id]" type="text" maxlength="40" placeholder="Nomor resi (min. 6 karakter)" class="rounded-lg border border-stone-200 px-2 py-1 dark:border-stone-700 dark:bg-stone-950" />
          <input type="file" accept="image/*" class="text-xs" @change="onEvidence(row.shipment_id, $event)" />
          <AppButton size="sm" :disabled="busyId !== null || !row.ready.paid_in_full || !row.ready.goods_complete || !row.ready.single_shipment || !evidence[row.shipment_id] || (trackingNo[row.shipment_id] ?? '').trim().length < 6" @click="submitHandover(row)">
            {{ busyId === row.shipment_id ? 'Menyimpan…' : 'Catat Serah Terima & Resi' }}
          </AppButton>
        </div>
        <p v-if="!row.handover_recorded" class="mt-1 text-xs text-stone-500">Ekspedisi pada resi harus sama dengan pilihan checkout ({{ row.carrier }}); bukti foto serah terima wajib.</p>
      </div>
    </template>
    <div v-if="lastPage > 1" class="flex items-center justify-between gap-2 text-sm">
      <AppButton size="sm" variant="secondary" :disabled="page <= 1 || loading" @click="go(-1)">Prev</AppButton>
      <span class="text-stone-500">Halaman {{ page }} / {{ lastPage }}</span>
      <AppButton size="sm" variant="secondary" :disabled="page >= lastPage || loading" @click="go(1)">Next</AppButton>
    </div>
  </section>
</template>
