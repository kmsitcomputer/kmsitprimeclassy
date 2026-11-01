<script setup lang="ts">
/**
 * Notifikasi operator (Super Admin, Agen, Admin): pesanan baru, pembayaran ditolak, retur/refund yang perlu tindakan,
 * kegagalan pengiriman notifikasi, dan status perangkat WhatsApp. The backend returns only the caller's own rows.
 */
import { computed, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import {
  listAdminNotifications,
  markAdminNotificationRead,
  markAllAdminNotificationsRead,
  type AdminNotification,
  type AdminNotificationMeta,
} from '@/api/adminNotifications'

const items = ref<AdminNotification[]>([])
const meta = ref<AdminNotificationMeta>({ unread_count: 0, total: 0 })
const unreadOnly = ref(false)
const page = ref(1)
const loading = ref(true)
const error = ref('')
const busy = ref(false)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const r = await listAdminNotifications({ unread: unreadOnly.value, page: page.value, per_page: 20 })
    items.value = r.items
    meta.value = r.meta
  } catch (e) {
    error.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat notifikasi.'
  } finally {
    loading.value = false
  }
}
load()

async function markRead(n: AdminNotification) {
  if (n.read_at) return
  try {
    n.read_at = (await markAdminNotificationRead(n.id)).read_at
    meta.value.unread_count = Math.max(0, meta.value.unread_count - 1)
  } catch (e) {
    error.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menandai notifikasi.'
  }
}

async function markAll() {
  busy.value = true
  try {
    await markAllAdminNotificationsRead()
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menandai semua notifikasi.'
  } finally {
    busy.value = false
  }
}

function toggleUnread() {
  unreadOnly.value = !unreadOnly.value
  page.value = 1
  load()
}

function go(delta: number) {
  page.value = Math.max(1, page.value + delta)
  load()
}

const lastPage = computed(() => meta.value.last_page ?? 1)

const SEVERITY_CLASS: Record<string, string> = {
  info: 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
  warning: 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  critical: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300',
}
const SEVERITY_LABEL: Record<string, string> = { info: 'Info', warning: 'Perhatian', critical: 'Penting' }

function when(iso: string | null) {
  return iso ? new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : ''
}

const cardClass = 'rounded-2xl border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900'
const btnGhost =
  'rounded-lg border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-50 dark:border-stone-700 dark:text-stone-200 dark:hover:bg-stone-800'
</script>

<template>
  <DashboardLayout>
    <h1 class="font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">Notifikasi</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
      Pemberitahuan operasional untuk akun Anda: pesanan baru, pembayaran ditolak, retur dan refund yang perlu tindakan,
      kegagalan pengiriman notifikasi, dan status perangkat WhatsApp.
    </p>

    <div class="mb-4 flex flex-wrap items-center gap-2">
      <button type="button" :class="btnGhost" data-test="toggle-unread" @click="toggleUnread">
        {{ unreadOnly ? 'Tampilkan semua' : 'Hanya belum dibaca' }}
      </button>
      <button type="button" :class="btnGhost" :disabled="busy || meta.unread_count === 0" data-test="mark-all" @click="markAll">
        Tandai semua dibaca
      </button>
      <span class="text-sm text-stone-500">{{ meta.unread_count }} belum dibaca</span>
    </div>

    <p v-if="error" role="alert" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
      {{ error }}
    </p>
    <p v-if="meta.migration_pending" class="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-300">
      Pusat notifikasi belum aktif di server ini (migrasi belum diterapkan).
    </p>

    <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>
    <p v-else-if="items.length === 0" :class="cardClass" class="p-4 text-sm text-stone-500" data-test="empty">
      Tidak ada notifikasi.
    </p>
    <ul v-else :class="cardClass" class="divide-y divide-stone-100 dark:divide-stone-800" data-test="list">
      <li
        v-for="n in items"
        :key="n.id"
        class="flex cursor-pointer items-start gap-3 p-4 hover:bg-stone-50 dark:hover:bg-stone-800/50"
        :class="n.read_at ? 'opacity-70' : ''"
        @click="markRead(n)"
      >
        <span class="mt-0.5 shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="SEVERITY_CLASS[n.severity]">
          {{ SEVERITY_LABEL[n.severity] ?? n.severity }}
        </span>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-stone-800 dark:text-stone-100">
            {{ n.title }}
            <span v-if="!n.read_at" class="ml-1 inline-block h-2 w-2 rounded-full bg-brand-600 align-middle" aria-label="Belum dibaca" />
          </p>
          <p class="break-words text-sm text-stone-600 dark:text-stone-300">{{ n.body }}</p>
          <p class="mt-1 text-xs text-stone-400">
            {{ when(n.created_at) }}
            <RouterLink v-if="n.order_id" :to="`/orders/${n.order_id}`" class="ml-2 underline" @click.stop>Lihat pesanan</RouterLink>
          </p>
        </div>
      </li>
    </ul>

    <div v-if="lastPage > 1" class="mt-4 flex items-center gap-2 text-sm">
      <button type="button" :class="btnGhost" :disabled="page <= 1" @click="go(-1)">Sebelumnya</button>
      <span class="text-stone-500">Halaman {{ page }} dari {{ lastPage }}</span>
      <button type="button" :class="btnGhost" :disabled="page >= lastPage" @click="go(1)">Berikutnya</button>
    </div>
  </DashboardLayout>
</template>
