<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { listPaymentGateways, togglePaymentGateway, type PaymentGateway } from '@/api/payments'

/**
 * Super Admin only (route meta requiresPermission: 'system.payment.manage').
 * GLOBAL on/off switch only — credentials (bank account details, gateway
 * API keys) are configured per-agen instead, on each agen's own dashboard
 * (Agen > Payment Methods). Super Admin never sees or sets them.
 */

const gateways = ref<PaymentGateway[]>([])
const loading = ref(true)

async function load() {
  loading.value = true
  gateways.value = await listPaymentGateways()
  loading.value = false
}
onMounted(load)

async function toggle(gateway: PaymentGateway) {
  await togglePaymentGateway(gateway.id)
  await load()
}
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">Payment Gateway</h1>
    <p class="mb-1 text-sm text-stone-500 dark:text-stone-400">
      Aktifkan atau nonaktifkan metode pembayaran secara global untuk seluruh agen.
    </p>
    <p class="mb-4 text-xs font-medium text-amber-600 dark:text-amber-400">
      Kredensial (rekening bank, API key gateway) kini diatur oleh masing-masing agen di dashboard mereka sendiri —
      bukan di sini. Setiap agen bisa memiliki pengaturan yang berbeda.
    </p>

    <div v-if="loading" class="space-y-3">
      <div v-for="i in 3" :key="i" class="h-16 animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
    </div>

    <div v-else class="space-y-3">
      <div v-for="gateway in gateways" :key="gateway.id" class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">{{ gateway.name }}</h2>
            <p class="text-xs text-stone-400">{{ gateway.code }} &middot; {{ gateway.type }}</p>
          </div>
          <div class="flex items-center gap-2">
            <span
              class="rounded-full px-2.5 py-1 text-xs font-medium"
              :class="gateway.is_active ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-stone-100 text-stone-500 dark:bg-stone-800'"
            >
              {{ gateway.is_active ? 'Aktif' : 'Nonaktif' }}
            </span>
            <AppButton size="sm" variant="secondary" @click="toggle(gateway)">
              {{ gateway.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
            </AppButton>
          </div>
        </div>

        <p v-if="gateway.webhook_url" class="mt-2 rounded-lg bg-stone-50 p-2 text-xs text-stone-500 dark:bg-stone-800/60 dark:text-stone-400">
          Webhook URL (dipakai bersama oleh semua agen untuk metode ini): <code class="break-all">{{ gateway.webhook_url }}</code>
        </p>
      </div>
    </div>
  </DashboardLayout>
</template>
