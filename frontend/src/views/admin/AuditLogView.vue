<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { listAuditLogs, type AuditLogEntry } from '@/api/auditLogs'
import { formatDateTime } from '@/utils/format'

const items = ref<AuditLogEntry[]>([])
const loading = ref(true)
const currentPage = ref(1)
const lastPage = ref(1)
const filters = ref({ event: '', from: '', to: '' })
const expandedId = ref<number | null>(null)

async function load(page = 1) {
  loading.value = true
  const result = await listAuditLogs({
    event: filters.value.event || undefined,
    from: filters.value.from || undefined,
    to: filters.value.to || undefined,
    page,
  })
  items.value = result.items
  currentPage.value = result.currentPage
  lastPage.value = result.lastPage
  loading.value = false
}

onMounted(() => load())

function toggle(id: number) {
  expandedId.value = expandedId.value === id ? null : id
}
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 text-xl font-semibold text-stone-900 dark:text-stone-50">Audit Log</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">Riwayat aktivitas admin, moderasi, keamanan, dan perubahan sensitif lainnya.</p>

    <div class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Event
        <input v-model="filters.event" type="text" placeholder="mis. user.created" class="mt-1 block w-48 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Dari
        <input v-model="filters.from" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <label class="text-sm text-stone-600 dark:text-stone-300">
        Sampai
        <input v-model="filters.to" type="date" class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </label>
      <button type="button" class="rounded-lg bg-stone-800 px-3 py-2 text-xs font-medium text-white hover:bg-stone-900 dark:bg-stone-100 dark:text-stone-900" @click="load()">
        Terapkan
      </button>
    </div>

    <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>

    <div v-else class="overflow-hidden rounded-xl border border-stone-200 dark:border-stone-800">
      <table class="w-full text-left text-sm">
        <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
          <tr>
            <th class="px-4 py-2">Waktu</th>
            <th class="px-4 py-2">Aktor</th>
            <th class="px-4 py-2">Event</th>
            <th class="px-4 py-2">Target</th>
            <th class="px-4 py-2">IP</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
          <template v-for="log in items" :key="log.id">
            <tr class="cursor-pointer hover:bg-stone-50 dark:hover:bg-stone-800/50" @click="toggle(log.id)">
              <!-- Audit trail keeps the exact time — precision matters here. -->
              <td class="px-4 py-2 whitespace-nowrap">{{ formatDateTime(log.created_at) }}</td>
              <td class="px-4 py-2">{{ log.causer?.name ?? 'Sistem' }}</td>
              <td class="px-4 py-2 font-mono text-xs">{{ log.event }}</td>
              <td class="px-4 py-2">{{ log.subject_type ? `${log.subject_type.split('\\').pop()} #${log.subject_id}` : '-' }}</td>
              <td class="px-4 py-2 text-xs text-stone-400">{{ log.ip_address ?? '-' }}</td>
            </tr>
            <tr v-if="expandedId === log.id" class="bg-stone-50 dark:bg-stone-800/50">
              <td colspan="5" class="px-4 py-3">
                <pre class="whitespace-pre-wrap break-all text-xs text-stone-600 dark:text-stone-300">{{ JSON.stringify(log.properties, null, 2) }}</pre>
                <p class="mt-1 text-xs text-stone-400">{{ log.user_agent }}</p>
              </td>
            </tr>
          </template>
          <tr v-if="items.length === 0"><td colspan="5" class="px-4 py-6 text-center text-stone-400">Tidak ada data.</td></tr>
        </tbody>
      </table>
    </div>

    <div v-if="lastPage > 1" class="mt-6 flex items-center justify-center gap-2 text-sm">
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700" :disabled="currentPage <= 1" @click="load(currentPage - 1)">
        Sebelumnya
      </button>
      <span class="text-stone-500">{{ currentPage }} / {{ lastPage }}</span>
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700" :disabled="currentPage >= lastPage" @click="load(currentPage + 1)">
        Berikutnya
      </button>
    </div>
  </DashboardLayout>
</template>
