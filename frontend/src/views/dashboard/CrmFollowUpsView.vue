<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { formatDate } from '@/utils/format'
import { listCrmFollowUps, VISIT_STATUS_LABEL, type CrmVisit } from '@/api/crm'

const todayIso = new Date().toISOString().slice(0, 10)
const until = ref(todayIso)
const items = ref<CrmVisit[]>([])
const loading = ref(true)
const errorMessage = ref('')

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    items.value = await listCrmFollowUps(until.value || undefined)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat tindak lanjut.'
  } finally {
    loading.value = false
  }
}
load()

const isOverdue = (v: CrmVisit) => !!v.plan_next_date && v.plan_next_date.slice(0, 10) < todayIso
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Tindak Lanjut (FU)</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
      FU = Follow Up. Rencana tindak lanjut terbaru per pelanggan CRM yang jatuh tempo pada atau sebelum tanggal yang dipilih.
    </p>

    <div class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Sampai tanggal
        <input v-model="until" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <button type="button" class="rounded-lg bg-stone-800 px-3 py-2 text-xs font-medium text-white hover:bg-stone-900 dark:bg-stone-100 dark:text-stone-900" @click="load">
        Terapkan
      </button>
    </div>

    <p v-if="errorMessage" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ errorMessage }} <button type="button" class="ml-2 underline" @click="load">Coba lagi</button>
    </p>

    <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>

    <ul v-else-if="items.length" class="space-y-3">
      <li v-for="v in items" :key="v.id" class="flex flex-wrap items-center gap-3 rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
        <div class="min-w-0 flex-1">
          <RouterLink
            v-if="v.customer"
            :to="{ name: 'crm-customer-detail', params: { id: v.crm_customer_id } }"
            class="font-medium text-brand-700 hover:underline dark:text-brand-400"
          >
            {{ v.customer.name }}
          </RouterLink>
          <p class="text-xs text-stone-500">
            {{ v.customer?.phone ?? '-' }} · kunjungan terakhir {{ formatDate(v.visit_date) }} ({{ VISIT_STATUS_LABEL[v.status] }})
          </p>
          <p v-if="v.note" class="mt-1 line-clamp-2 text-sm text-stone-600 dark:text-stone-300">{{ v.note }}</p>
        </div>
        <div class="text-right text-sm">
          <p class="font-medium" :class="isOverdue(v) ? 'text-red-600 dark:text-red-400' : 'text-stone-700 dark:text-stone-200'">
            {{ formatDate(v.plan_next_date) }}
          </p>
          <p v-if="isOverdue(v)" class="text-xs text-red-600 dark:text-red-400">Terlambat</p>
        </div>
      </li>
    </ul>

    <p v-else-if="!errorMessage" class="rounded-xl border border-dashed border-stone-300 p-8 text-center text-sm text-stone-400 dark:border-stone-700">
      Tidak ada tindak lanjut yang jatuh tempo.
    </p>
  </DashboardLayout>
</template>
