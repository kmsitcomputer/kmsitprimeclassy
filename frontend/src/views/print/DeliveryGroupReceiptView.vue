<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { getDeliveryGroupReceipt, type DeliveryGroupReceipt } from '@/api/shipments'
import { formatDate, formatRupiah } from '@/utils/format'
import { ApiError } from '@/api/client'

const props = defineProps<{ orderId: number; deliveryDate: string }>()
const receipt = ref<DeliveryGroupReceipt | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

async function load() {
  try {
    receipt.value = await getDeliveryGroupReceipt(props.orderId, props.deliveryDate)
  } catch (cause) {
    error.value = cause instanceof ApiError ? cause.message : 'Gagal memuat resi pengiriman.'
  } finally {
    loading.value = false
  }
}

function shipmentStatusLabel(status: string): string {
  const labels: Record<string, string> = {
    pending: 'MENUNGGU PICKUP',
    picked_up: 'SEDANG DIKIRIM',
    in_transit: 'SEDANG DIKIRIM',
    delivered: 'DITERIMA',
    failed: 'GAGAL DIKIRIM',
  }
  return labels[status] ?? status
}

onMounted(load)

function printResi() {
  window.print()
}

function closeTab() {
  window.close()
}
</script>

<template>
  <main class="receipt-page">
    <p v-if="loading" class="no-print">Memuat resi...</p>
    <p v-else-if="error" class="no-print error">{{ error }}</p>
    <template v-else-if="receipt">
      <div class="no-print toolbar">
        <button type="button" @click="printResi">Print</button>
        <button type="button" @click="closeTab">Tutup</button>
      </div>
      <article class="receipt">
        <header>
          <strong>PRIME CLASSY</strong>
          <strong>RESI PENGIRIMAN</strong>
        </header>
        <hr />
        <p><b>Order:</b> {{ receipt.order_no }}</p>
        <p><b>Tanggal Pengiriman:</b> {{ formatDate(receipt.delivery_date) }}</p>
        <p><b>Status:</b> {{ receipt.shipment_statuses.map(shipmentStatusLabel).join(' · ') }}</p>
        <hr />
        <p><b>Penerima:</b> {{ receipt.recipient_name }} · {{ receipt.recipient_phone }}</p>
        <p class="wrap">{{ receipt.address_line }}</p>
        <hr />
        <p><b>Pengirim:</b> {{ receipt.shipping_methods.join(' · ') || '-' }}</p>
        <p v-if="receipt.courier_names.length"><b>Nama Kurir:</b> {{ receipt.courier_names.join(', ') }}</p>
        <p v-if="receipt.tracking_numbers.length"><b>No. Resi:</b> {{ receipt.tracking_numbers.join(', ') }}</p>
        <hr />
        <ul>
          <li v-for="(item, index) in receipt.items" :key="index">
            <span>{{ item.product_name }}<template v-if="item.variation_label"> — {{ item.variation_label }}</template></span>
            <small v-if="item.sku">{{ item.sku }}</small>
            <span>{{ item.quantity }}x</span>
          </li>
        </ul>
        <hr />
        <p><b>Total Item Grup:</b> {{ receipt.total_item_count }}</p>
        <p><b>Ongkir Grup:</b> {{ formatRupiah(receipt.shipping_fee_amount) }}</p>
        <template v-if="receipt.payment.is_cod && receipt.payment.cod_amount_due !== null">
          <p><b>Tagihan COD Order:</b> {{ formatRupiah(receipt.payment.cod_amount_due) }}</p>
        </template>
        <p v-else-if="receipt.payment.is_cod && receipt.payment.order_has_multiple_delivery_groups" class="fine-print">
          Tagihan COD berlaku untuk seluruh order dan tidak dialokasikan per grup.
        </p>
        <template v-if="receipt.payment.initial_dp_amount !== null">
          <p><b>DP Awal (grup paling awal):</b> {{ formatRupiah(receipt.payment.initial_dp_amount) }}</p>
          <p v-if="receipt.payment.initial_dp_credit !== null"><b>DP Terverifikasi:</b> {{ formatRupiah(receipt.payment.initial_dp_credit) }}</p>
          <p v-else>DP belum terverifikasi.</p>
        </template>
        <hr />
        <p class="center">Resi internal Prime Classy</p>
      </article>
    </template>
  </main>
</template>

<style scoped>
.receipt-page { min-height: 100vh; background: #f5f5f4; padding: 16px; color: #262626; font: 13px/1.45 "DejaVu Sans", sans-serif; }
.toolbar { display: flex; justify-content: center; gap: 8px; margin: 0 auto 14px; }
.toolbar button { border: 1px solid #a8a29e; background: white; padding: 6px 12px; }
.receipt { width: min(100%, 360px); margin: 0 auto; background: white; padding: 16px; }
header { display: grid; gap: 3px; text-align: center; }
hr { border: 0; border-top: 1px dashed #78716c; margin: 10px 0; }
p { margin: 4px 0; }
ul { list-style: none; margin: 8px 0; padding: 0; }
li { display: grid; grid-template-columns: 1fr auto; gap: 2px 8px; margin: 6px 0; }
li small { color: #78716c; }
.wrap { overflow-wrap: anywhere; }
.center { text-align: center; }
.error { color: #b91c1c; text-align: center; }
.fine-print { color: #78716c; font-size: 11px; }
@media print {
  @page { size: 80mm auto; margin: 3mm; }
  .no-print { display: none !important; }
  .receipt-page { min-height: auto; background: white; padding: 0; }
  .receipt { width: 74mm; margin: 0; padding: 0; }
}
</style>
