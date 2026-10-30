<script setup lang="ts">
/**
 * Laporan Kunjungan CRM — funnel 0–7/N persis semantik SimpleV2
 * (0% belum dikunjungi, BS/MS/IP/IH/MK/MKK/Deal, Nolak). Latest visit
 * menentukan posisi customer; JML KJ = jumlah kunjungan; JML RK = customer
 * dengan rencana kunjungan berikutnya (follow-up = plan_next_date, BUKAN
 * status — CI-07). Scope mengikuti hak baca CRM (owner/downline/cabang).
 */
import { computed, onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import AgentPicker from '@/components/ui/AgentPicker.vue'
import { useAuthStore } from '@/stores/auth'
import { getCrmVisitsReport, downloadReportXlsx, type CrmFunnelReport } from '@/api/reports'

const auth = useAuthStore()
const isSuperAdmin = computed(() => auth.user?.role === 'super_admin')
const loading = ref(false)
const error = ref('')
const report = ref<CrmFunnelReport | null>(null)
const filters = ref({ from: '', to: '', agent_id: undefined as number | undefined })

const BUCKET_ORDER = ['0', '1', '2', '3', '4', '5', '6', '7', 'N'] as const

async function load() {
  loading.value = true
  error.value = ''
  try {
    report.value = await getCrmVisitsReport({
      from: filters.value.from || undefined,
      to: filters.value.to || undefined,
      agent_id: isSuperAdmin.value ? filters.value.agent_id : undefined,
    })
  } catch {
    error.value = 'Gagal memuat laporan kunjungan.'
  } finally {
    loading.value = false
  }
}
onMounted(load)
</script>

<template>
  <DashboardLayout>
    <p class="pc-eyebrow mb-1">Laporan CRM</p>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Funnel Kunjungan 0–7 / N</h1>
    <p class="mb-4 text-sm text-stone-500 dark:text-stone-400">
      Posisi tiap customer diambil dari kunjungan terbaru — persis semantik funnel SimpleV2.
    </p>

    <div class="mb-4 flex flex-wrap items-end gap-3">
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Dari
        <input v-model="filters.from" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" @change="load()" />
      </label>
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Sampai
        <input v-model="filters.to" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" @change="load()" />
      </label>
      <AgentPicker v-if="isSuperAdmin" v-model="filters.agent_id" />
      <button type="button" class="rounded-lg bg-stone-800 px-3 py-2 text-xs font-medium text-white hover:bg-stone-900 dark:bg-stone-100 dark:text-stone-900" @click="load()">Terapkan</button>
      <button type="button" class="ml-auto flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-xs font-medium text-white hover:bg-brand-700" @click="downloadReportXlsx('crm-visits', filters)">
        <AppIcon name="download" :size="14" /> Unduh .xlsx
      </button>
    </div>

    <div v-if="loading" class="h-40 animate-pulse rounded-xl border border-stone-200 bg-stone-100 dark:border-stone-800" />
    <p v-else-if="error" class="pc-notice mb-4">{{ error }}</p>

    <template v-else-if="report">
      <div class="mb-4 flex flex-wrap gap-3">
        <div v-for="b in BUCKET_ORDER" :key="b" class="min-w-[72px] flex-1 rounded-xl border border-stone-200 bg-white p-2 text-center dark:border-stone-800 dark:bg-stone-900">
          <p class="text-[10px] font-semibold uppercase tracking-wide text-stone-400">{{ report.bucket_labels[b] }}</p>
          <p class="font-display text-lg font-semibold text-stone-900 dark:text-stone-50">{{ report.totals.buckets[b] ?? 0 }}</p>
        </div>
        <div class="min-w-[90px] flex-1 rounded-xl border border-brand-200 bg-brand-50 p-2 text-center dark:border-brand-800 dark:bg-brand-950">
          <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-600 dark:text-brand-300">Konversi</p>
          <p class="font-display text-lg font-semibold text-brand-700 dark:text-brand-300">{{ report.totals.conversion_percent }}%</p>
        </div>
      </div>

      <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900">
        <table class="w-full text-sm">
          <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
            <tr class="text-left">
              <th class="px-4 py-2">Sales / Pemilik</th>
              <th class="px-4 py-2">JML Kons</th>
              <th class="px-4 py-2">JML KJ</th>
              <th class="px-4 py-2">JML RK</th>
              <th v-for="b in BUCKET_ORDER" :key="b" class="px-3 py-2 text-right">{{ report.bucket_labels[b] }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
            <tr v-for="row in report.rows" :key="row.owner_id">
              <td class="px-4 py-2">{{ row.owner_name }}</td>
              <td class="px-4 py-2">{{ row.customers }}</td>
              <td class="px-4 py-2">{{ row.visits }}</td>
              <td class="px-4 py-2">{{ row.rk }}</td>
              <td v-for="b in BUCKET_ORDER" :key="b" class="px-3 py-2 text-right">{{ row.buckets[b] ?? 0 }}</td>
            </tr>
            <tr v-if="!report.rows.length"><td :colspan="4 + BUCKET_ORDER.length" class="px-4 py-6 text-center text-stone-400">Belum ada customer/kunjungan pada cakupan ini.</td></tr>
          </tbody>
          <tfoot>
            <tr class="border-t border-stone-200 font-semibold dark:border-stone-800">
              <td class="px-4 py-2">TOTAL</td>
              <td class="px-4 py-2">{{ report.totals.customers }}</td>
              <td class="px-4 py-2">{{ report.totals.visits }}</td>
              <td class="px-4 py-2">{{ report.totals.rk }}</td>
              <td v-for="b in BUCKET_ORDER" :key="b" class="px-3 py-2 text-right">{{ report.totals.buckets[b] ?? 0 }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </template>
  </DashboardLayout>
</template>
