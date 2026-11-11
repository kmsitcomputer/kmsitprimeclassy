<script setup lang="ts">
import { computed } from 'vue'
import AppButton from '@/components/ui/AppButton.vue'
import { formatDate } from '@/utils/format'
import type { FulfillmentShortage, FulfillmentShortageLine } from '@/api/types'

/**
 * PEMENUHAN KURANG — shown on the Admin's Order detail when Gudang reported that it could not hand over
 * everything. Every figure comes from the server (accepted fulfilment, not browser arithmetic). The Admin's
 * only decisions here are for the REMAINDER of a line: reschedule, or cancel the remainder. Both reuse the
 * order page's existing item forms (the parent opens them); delivered/dispatched units are never offered.
 */
const props = defineProps<{ shortage: FulfillmentShortage; canDecide: boolean }>()
const emit = defineEmits<{ reschedule: [orderItemId: number]; cancel: [orderItemId: number] }>()

const statusLabel: Record<FulfillmentShortageLine['status'], string> = {
  pending_decision: 'Menunggu keputusan Admin',
  rescheduled: 'Dijadwalkan ulang',
  cancelled: 'Sisa dibatalkan',
  resolved: 'Terpenuhi',
}
const statusClass: Record<FulfillmentShortageLine['status'], string> = {
  pending_decision: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  rescheduled: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
  cancelled: 'bg-stone-200 text-stone-700 dark:bg-stone-800 dark:text-stone-300',
  resolved: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
}

const open = computed(() => props.shortage.has_open_shortage)
function productLabel(line: FulfillmentShortageLine): string {
  return [line.product_name ?? 'Produk', line.variation_label].filter(Boolean).join(' — ')
}
</script>

<template>
  <section
    data-testid="fulfillment-shortage"
    class="rounded-2xl border p-4"
    :class="open ? 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950/40' : 'border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900'"
    role="region"
    aria-label="Pemenuhan kurang"
  >
    <div class="flex flex-wrap items-center gap-2">
      <span v-if="open" class="rounded-full bg-amber-600 px-2.5 py-0.5 text-xs font-bold tracking-wide text-white">PEMENUHAN KURANG</span>
      <span v-else class="rounded-full bg-stone-200 px-2.5 py-0.5 text-xs font-semibold text-stone-700 dark:bg-stone-800 dark:text-stone-300">Penanganan kekurangan selesai</span>
      <span class="text-sm font-semibold text-stone-800 dark:text-stone-100">{{ shortage.order_no }}</span>
      <span class="text-xs text-stone-500">status pemenuhan: {{ shortage.fulfillment_status }}</span>
    </div>

    <dl class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
      <div><dt class="text-xs text-stone-500">Dipesan</dt><dd class="font-semibold" data-testid="shortage-ordered">{{ shortage.ordered_qty }}</dd></div>
      <div><dt class="text-xs text-stone-500">Dipenuhi / diserahkan</dt><dd class="font-semibold" data-testid="shortage-handed">{{ shortage.handed_over_qty }}</dd></div>
      <div><dt class="text-xs text-stone-500">Kurang</dt><dd class="font-semibold text-amber-700 dark:text-amber-400" data-testid="shortage-short">{{ shortage.short_qty }}</dd></div>
      <div v-if="shortage.last_reported_at"><dt class="text-xs text-stone-500">Waktu pencatatan</dt><dd class="font-medium">{{ formatDate(shortage.last_reported_at) }}</dd></div>
    </dl>
    <p class="mt-2 text-xs text-stone-600 dark:text-stone-300">
      Petugas Gudang: <b>{{ shortage.items[0]?.reported_by?.name ?? '—' }}</b>
      · Koordinator Kurir: <b>{{ shortage.coordinator?.name ?? '—' }}</b>
    </p>

    <ul v-if="shortage.proofs.length" class="mt-1 flex flex-wrap gap-3 text-xs">
      <li v-for="p in shortage.proofs" :key="p.proposal_id">
        Bukti serah terima ({{ formatDate(p.at) }}):
        <a :href="p.url" target="_blank" rel="noopener" class="text-brand-700 underline dark:text-brand-300">lihat foto</a>
        <span v-if="p.koordinator" class="text-stone-500"> · diterima {{ p.koordinator.name }}</span>
      </li>
    </ul>
    <p v-else class="mt-1 text-xs text-stone-500">Belum ada barang yang diserahkan, sehingga belum ada bukti serah terima.</p>

    <div class="mt-3 overflow-x-auto">
      <table class="w-full min-w-[40rem] text-left text-sm">
        <thead class="text-xs text-stone-500">
          <tr>
            <th class="py-1 pr-2 font-medium">Produk</th>
            <th class="px-2 py-1 text-right font-medium">Dipesan</th>
            <th class="px-2 py-1 text-right font-medium">Diserahkan</th>
            <th class="px-2 py-1 text-right font-medium">Kurang</th>
            <th class="px-2 py-1 font-medium">Alasan</th>
            <th class="px-2 py-1 font-medium">Penanganan sisa</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="line in shortage.items" :key="line.stock_request_item_id" class="border-t border-stone-200 align-top dark:border-stone-700" :data-testid="`shortage-line-${line.stock_request_item_id}`">
            <td class="py-2 pr-2">
              <span class="font-medium">{{ productLabel(line) }}</span>
              <span v-if="line.sku" class="block text-xs text-stone-500">SKU {{ line.sku }}</span>
            </td>
            <td class="px-2 py-2 text-right">{{ line.ordered_qty }}<span class="ml-0.5 text-xs text-stone-400">{{ line.unit ?? 'unit' }}</span></td>
            <td class="px-2 py-2 text-right">{{ line.handed_over_qty }}<span v-if="line.awaiting_receipt_qty" class="block text-[11px] text-stone-400">+{{ line.awaiting_receipt_qty }} menunggu penerimaan</span></td>
            <td class="px-2 py-2 text-right font-semibold" :class="line.short_qty > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-stone-400'">{{ line.short_qty }}</td>
            <td class="px-2 py-2 text-xs">{{ line.reason ?? '—' }}</td>
            <td class="px-2 py-2">
              <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass[line.status]">{{ statusLabel[line.status] }}</span>
              <p v-if="line.decision" class="mt-0.5 text-[11px] text-stone-500">
                <template v-if="line.decision.action === 'reschedule'">ke {{ line.decision.date ? formatDate(line.decision.date) : '—' }}</template>
                <template v-else>{{ line.decision.quantity ?? '' }} unit dibatalkan</template>
                · {{ line.decision.by?.name ?? '—' }} · {{ line.decision.at ? formatDate(line.decision.at) : '' }}
              </p>
              <div v-if="canDecide && line.short_qty > 0 && line.order_item_id" class="mt-1.5 flex flex-wrap gap-2">
                <AppButton size="sm" variant="secondary" :data-testid="`shortage-reschedule-${line.order_item_id}`" @click="emit('reschedule', line.order_item_id)">Reschedule sisa</AppButton>
                <AppButton size="sm" variant="secondary" :data-testid="`shortage-cancel-${line.order_item_id}`" @click="emit('cancel', line.order_item_id)">Cancel sisa</AppButton>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p class="mt-2 text-xs text-stone-500">
      Keputusan hanya berlaku untuk sisa yang belum terpenuhi. Barang yang sudah diserahkan atau dikirim tidak terpengaruh dan tetap dapat dikirim Koordinator Kurir.
    </p>
  </section>
</template>
