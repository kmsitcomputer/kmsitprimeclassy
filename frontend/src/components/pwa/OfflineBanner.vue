<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useConnectivity } from '@/pwa/connectivity'

const { t } = useI18n()
const { isOffline } = useConnectivity()
</script>

<template>
  <div
    v-if="isOffline"
    role="alert"
    class="flex items-center justify-center gap-2 bg-amber-500 px-4 py-2 text-center text-sm font-medium text-stone-900"
  >
    <span>{{ t('pwa.offlineNotice') }}</span>
    <RouterLink :to="{ name: 'offline' }" class="shrink-0 underline">
      {{ t('pwa.offlineView') }}
    </RouterLink>
  </div>
</template>
