<script setup lang="ts">
import { computed, ref, onMounted } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useAuthStore } from '@/stores/auth'
import { flattenNavItems } from '@/dashboard/navConfig'
import { getDashboardSummary, type DashboardSummary } from '@/api/reports'
import { formatRupiah } from '@/utils/format'

const auth = useAuthStore()

const ROLE_LABELS: Record<string, string> = {
  super_admin: 'Super Admin',
  agen: 'Agen',
  korsal: 'Korsal',
  sales: 'Sales',
  admin: 'Admin',
  keuangan: 'Keuangan',
  kurir: 'Kurir',
  konsumen: 'Konsumen',
}

const quickLinks = computed(() => flattenNavItems().filter((item) => item.show(auth)))

// Summary widget only makes sense for office roles with a branch to summarize.
const showSummary = computed(() => ['super_admin', 'agen', 'admin', 'korsal', 'sales'].includes(auth.user?.role ?? ''))
const summary = ref<DashboardSummary | null>(null)

onMounted(async () => {
  if (showSummary.value) {
    summary.value = await getDashboardSummary().catch(() => null)
  }
})
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">
      Halo, {{ auth.user?.name }}
    </h1>
    <p class="mb-6 text-sm text-stone-500 dark:text-stone-400">
      Kamu masuk sebagai <strong>{{ ROLE_LABELS[auth.user?.role ?? ''] ?? auth.user?.role }}</strong
      >. Pilih menu di bawah untuk mulai bekerja.
    </p>

    <div v-if="showSummary && summary" class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
      <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
        <p class="text-[11px] font-medium uppercase tracking-wide text-stone-400">Order</p>
        <p class="font-display text-lg font-semibold text-stone-900 dark:text-stone-50">{{ summary.total_orders }}</p>
      </div>
      <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
        <p class="text-[11px] font-medium uppercase tracking-wide text-stone-400">Pendapatan</p>
        <p class="font-display text-lg font-semibold text-emerald-600 dark:text-emerald-400">{{ formatRupiah(summary.total_revenue) }}</p>
      </div>
      <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
        <p class="text-[11px] font-medium uppercase tracking-wide text-stone-400">Pelanggan</p>
        <p class="font-display text-lg font-semibold text-stone-900 dark:text-stone-50">{{ summary.total_customers }}</p>
      </div>
      <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
        <p class="text-[11px] font-medium uppercase tracking-wide text-stone-400">Korsal</p>
        <p class="font-display text-lg font-semibold text-stone-900 dark:text-stone-50">{{ summary.total_korsal }}</p>
      </div>
      <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
        <p class="text-[11px] font-medium uppercase tracking-wide text-stone-400">Sales</p>
        <p class="font-display text-lg font-semibold text-stone-900 dark:text-stone-50">{{ summary.total_sales }}</p>
      </div>
      <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
        <p class="text-[11px] font-medium uppercase tracking-wide text-stone-400">Kurir</p>
        <p class="font-display text-lg font-semibold text-stone-900 dark:text-stone-50">{{ summary.total_kurir }}</p>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <RouterLink
        v-for="item in quickLinks"
        :key="item.key"
        :to="{ name: item.routeName }"
        class="flex items-center gap-3 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm transition hover:border-brand-300 hover:shadow-md dark:border-stone-800 dark:bg-stone-900 dark:hover:border-brand-700"
      >
        <span
          class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950 dark:text-brand-400"
        >
          <AppIcon :name="item.icon" :size="20" />
        </span>
        <span class="font-medium text-stone-800 dark:text-stone-100">{{ item.label }}</span>
      </RouterLink>
    </div>
  </DashboardLayout>
</template>
