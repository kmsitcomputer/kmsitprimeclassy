<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import AppIcon from '@/components/ui/AppIcon.vue'
import { ADMIN_NOTIFICATION_ROLES, getAdminNotificationUnreadCount } from '@/api/adminNotifications'

/** Topbar bell for operators: unread count of the caller's OWN in-app notifications, refreshed every minute. */
const auth = useAuthStore()
const unread = ref(0)
let timer: ReturnType<typeof setInterval> | null = null

const visible = computed(() => (ADMIN_NOTIFICATION_ROLES as readonly string[]).includes(auth.user?.role ?? ''))

async function refresh() {
  if (!visible.value) return
  try {
    unread.value = await getAdminNotificationUnreadCount()
  } catch {
    /* non-fatal: the bell simply keeps its last value */
  }
}

onMounted(() => {
  void refresh()
  timer = setInterval(refresh, 60_000)
})
onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})

defineExpose({ refresh })
</script>

<template>
  <RouterLink
    v-if="visible"
    :to="{ name: 'admin-notifications' }"
    class="relative grid h-9 w-9 shrink-0 place-items-center rounded-full text-stone-500 hover:bg-stone-100 dark:text-stone-400 dark:hover:bg-stone-800"
    :aria-label="unread > 0 ? `Notifikasi, ${unread} belum dibaca` : 'Notifikasi'"
  >
    <AppIcon name="bell" :size="18" />
    <span
      v-if="unread > 0"
      class="absolute -right-0.5 -top-0.5 grid min-w-[18px] place-items-center rounded-full bg-brand-600 px-1 text-[10px] font-bold leading-[18px] text-white"
    >
      {{ unread > 99 ? '99+' : unread }}
    </span>
  </RouterLink>
</template>
