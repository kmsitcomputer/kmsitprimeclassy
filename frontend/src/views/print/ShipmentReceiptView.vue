<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { getShipmentReceipt } from '@/api/shipments'
import type { ShipmentReceipt } from '@/api/shipments'
import { formatRupiah } from '@/utils/format'
import { ApiError } from '@/api/client'

const props = defineProps<{ id: number }>()

const receipt = ref<ShipmentReceipt | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

// Thermal paper width is a property of the physical printer at this counter,
// not of the agent's business config — two counters in the same branch can
// have different printer hardware, so this stays a per-browser preference
// rather than a server-side setting.
const PAPER_WIDTH_KEY = 'thermal-receipt-paper-width'
function readStoredPaperWidth(): '58mm' | '80mm' {
  try {
    return (localStorage.getItem(PAPER_WIDTH_KEY) as '58mm' | '80mm') || '58mm'
  } catch {
    return '58mm'
  }
}
const paperWidth = ref<'58mm' | '80mm'>(readStoredPaperWidth())
function setPaperWidth(width: '58mm' | '80mm') {
  paperWidth.value = width
  try {
    localStorage.setItem(PAPER_WIDTH_KEY, width)
  } catch {
    // Private browsing / storage blocked — the toggle still works for this view session.
  }
}

// @page can't be scoped by a CSS class, so the printed page size is pushed
// into a plain <style> tag in <head> and kept in sync with the toggle above.
let pageSizeStyleEl: HTMLStyleElement | null = null
watch(
  paperWidth,
  (width) => {
    if (!pageSizeStyleEl) {
      pageSizeStyleEl = document.createElement('style')
      document.head.appendChild(pageSizeStyleEl)
    }
    pageSizeStyleEl.textContent = `@page { size: ${width} auto; margin: 2mm; }`
  },
  { immediate: true },
)

async function load() {
  loading.value = true
  error.value = null
  try {
    receipt.value = await getShipmentReceipt(props.id)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Gagal memuat resi pengiriman.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

function pad(n: number): string {
  return n < 10 ? `0${n}` : `${n}`
}
/** dd/mm/yyyy — the compact numeric format thermal resi use, distinct from the word-form dates used elsewhere in the dashboard. */
function formatReceiptDate(value: string | null | undefined): string {
  if (!value) return '-'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '-'
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`
}
/** dd/mm/yyyy HH:mm — for the authoritative pickup timestamp only. */
function formatReceiptDateTime(value: string | null | undefined): string {
  if (!value) return '-'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '-'
  return `${formatReceiptDate(value)} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

const statusLabel = computed(() => (receipt.value?.mode === 'post_pickup' ? 'SUDAH DIPICKUP' : 'MENUNGGU PICKUP'))
const addressParts = computed(() => {
  if (!receipt.value) return []
  return [
    receipt.value.village ? `Kel. ${receipt.value.village}` : null,
    receipt.value.district ? `Kec. ${receipt.value.district}` : null,
    receipt.value.regency,
  ].filter(Boolean) as string[]
})

function doPrint() {
  window.print()
}
function closeTab() {
  window.close()
}
</script>

<template>
  <div class="receipt-page">
    <div v-if="loading" class="no-print status-message">Memuat resi...</div>
    <div v-else-if="error" class="no-print status-message error">{{ error }}</div>

    <template v-else-if="receipt">
      <!-- Screen-only controls — never printed. -->
      <div class="no-print toolbar">
        <div class="paper-toggle">
          <span>Ukuran Kertas:</span>
          <button type="button" :class="{ active: paperWidth === '58mm' }" @click="setPaperWidth('58mm')">58mm</button>
          <button type="button" :class="{ active: paperWidth === '80mm' }" @click="setPaperWidth('80mm')">80mm</button>
        </div>
        <div class="toolbar-actions">
          <button type="button" class="btn-print" @click="doPrint">Print</button>
          <button type="button" class="btn-close" @click="closeTab">Tutup</button>
        </div>
      </div>

      <!-- The actual printable resi. -->
      <div class="resi" :class="paperWidth">
        <div class="center bold big">PRIME CLASSY</div>
        <div class="center bold">RESI PENGIRIMAN</div>
        <div class="divider"></div>

        <div class="bold">Status:</div>
        <div class="status-value">{{ statusLabel }}</div>

        <div class="bold">Order:</div>
        <div>{{ receipt.order_no }}</div>

        <div class="bold">Tanggal Order:</div>
        <div>{{ formatReceiptDate(receipt.order_date) }}</div>

        <div class="divider"></div>
        <div class="bold">PENERIMA</div>
        <div>{{ receipt.recipient_name }}</div>
        <div>{{ receipt.recipient_phone }}</div>

        <div class="divider"></div>
        <div class="bold">ALAMAT</div>
        <div class="wrap">{{ receipt.address_line }}</div>
        <div v-for="part in addressParts" :key="part">{{ part }}</div>

        <div class="divider"></div>
        <div class="bold">BARANG</div>
        <div class="divider thin"></div>
        <div v-for="(item, idx) in receipt.items" :key="idx" class="item-row">
          <div v-if="item.sku" class="sku">{{ item.sku }}</div>
          <div class="wrap item-name">{{ item.product_name }}<span v-if="item.variation_label"> — {{ item.variation_label }}</span></div>
          <div>{{ item.quantity }} x</div>
        </div>
        <div class="divider thin"></div>
        <div>Total Item: {{ receipt.total_item_count }}</div>

        <div v-if="receipt.payment.is_cod || receipt.payment.is_down_payment || receipt.payment.is_fully_paid" class="divider"></div>
        <template v-if="receipt.payment.is_cod">
          <div class="bold">COD</div>
          <div v-if="receipt.payment.cod_amount_due !== null">Tagihan: {{ formatRupiah(receipt.payment.cod_amount_due) }}</div>
        </template>
        <template v-else-if="receipt.payment.is_down_payment">
          <div v-if="receipt.payment.dp_paid_amount !== null">DP Dibayar: {{ formatRupiah(receipt.payment.dp_paid_amount) }}</div>
          <div v-if="receipt.payment.dp_outstanding_amount !== null">Sisa Pembayaran: {{ formatRupiah(receipt.payment.dp_outstanding_amount) }}</div>
          <div v-else-if="receipt.payment.is_fully_paid">LUNAS</div>
        </template>
        <template v-else-if="receipt.payment.is_fully_paid">
          <div class="bold">LUNAS</div>
        </template>

        <div class="divider"></div>
        <div class="bold">Metode:</div>
        <div>{{ receipt.shipping_method_label }}</div>
        <div v-if="!receipt.is_official_carrier_label" class="fine-print">Bukan label resmi ekspedisi — resi internal Prime Classy.</div>

        <template v-if="receipt.courier_name">
          <div class="bold">Kurir:</div>
          <div>{{ receipt.courier_name }}</div>
        </template>

        <template v-if="receipt.mode === 'post_pickup'">
          <div class="bold">Pickup:</div>
          <div>{{ formatReceiptDateTime(receipt.picked_up_at) }}</div>
        </template>

        <div class="bold">Tgl Kirim:</div>
        <div>{{ formatReceiptDate(receipt.delivery_date) }}</div>

        <div v-if="receipt.notes" class="divider"></div>
        <div v-if="receipt.notes" class="bold">Catatan:</div>
        <div v-if="receipt.notes" class="wrap">{{ receipt.notes }}</div>

        <div class="divider"></div>
        <div class="center">Terima kasih</div>
        <div class="center">Prime Classy</div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* Screen chrome — never printed. */
.receipt-page {
  min-height: 100vh;
  background: #f5f5f4;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 16px;
}
.status-message {
  padding: 24px;
  font-size: 14px;
}
.status-message.error {
  color: #b91c1c;
}
.toolbar {
  width: 100%;
  max-width: 420px;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 14px;
}
.paper-toggle {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #57534e;
}
.paper-toggle button {
  border: 1px solid #d6d3d1;
  background: #fff;
  border-radius: 6px;
  padding: 4px 10px;
  font-size: 12px;
  cursor: pointer;
}
.paper-toggle button.active {
  background: #1c1917;
  color: #fff;
  border-color: #1c1917;
}
.toolbar-actions {
  display: flex;
  gap: 8px;
}
.btn-print,
.btn-close {
  border-radius: 6px;
  padding: 6px 14px;
  font-size: 13px;
  cursor: pointer;
  border: 1px solid #d6d3d1;
}
.btn-print {
  background: #1c1917;
  color: #fff;
  border-color: #1c1917;
}
.btn-close {
  background: #fff;
  color: #1c1917;
}

/* The resi itself — thermal-friendly: black on white, no gradients/images, narrow layout. */
.resi {
  background: #fff;
  color: #000;
  font-family: 'Courier New', ui-monospace, monospace;
  font-size: 12px;
  line-height: 1.4;
  padding: 10px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
}
.resi.58mm {
  width: 58mm;
}
.resi.80mm {
  width: 80mm;
  font-size: 13px;
}
.center {
  text-align: center;
}
.bold {
  font-weight: 700;
}
.big {
  font-size: 1.15em;
}
.divider {
  border-top: 1px dashed #000;
  margin: 6px 0;
}
.divider.thin {
  margin: 3px 0;
}
.status-value {
  font-weight: 700;
  margin-bottom: 4px;
}
.wrap {
  overflow-wrap: break-word;
  word-break: break-word;
}
.item-row {
  margin-bottom: 4px;
}
.item-row .sku {
  font-size: 0.9em;
  color: #333;
}
.item-name {
  font-weight: 600;
}
.fine-print {
  font-size: 0.85em;
  color: #444;
}

@media print {
  .no-print {
    display: none !important;
  }
  .receipt-page {
    background: #fff;
    padding: 0;
    display: block;
  }
  .resi {
    box-shadow: none;
    padding: 0;
    width: auto;
  }
}
@media print and (width: 58mm) {
  .resi {
    width: 100%;
  }
}
</style>
