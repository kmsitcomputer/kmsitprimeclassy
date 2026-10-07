<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useSiteStore } from '@/stores/site'

const { t } = useI18n()
const router = useRouter()
const site = useSiteStore()

function retry() {
  if (window.history.length > 1) {
    router.back()
  } else {
    router.push({ name: 'home' })
  }
}
</script>

<template>
  <main class="mx-auto flex min-h-[60vh] max-w-md flex-col items-center justify-center px-6 py-16 text-center">
    <img
      v-if="site.logoUrl"
      :src="site.logoUrl"
      :alt="site.siteTitle"
      class="mb-6 h-14 w-auto object-contain"
    />
    <div
      v-else
      class="mb-6 flex h-14 w-14 items-center justify-center rounded-2xl bg-[#8f1d3c] text-xl font-bold text-white"
      aria-hidden="true"
    >
      PC
    </div>
    <h1 class="text-xl font-bold text-stone-900 dark:text-stone-100">
      {{ t('pwa.offlineTitle') }}
    </h1>
    <p class="mt-2 text-sm text-stone-600 dark:text-stone-300">
      {{ t('pwa.offlineMessage') }}
    </p>
    <button
      type="button"
      class="mt-6 rounded bg-[#8f1d3c] px-5 py-2 text-sm font-semibold text-white"
      @click="retry"
    >
      {{ t('pwa.retry') }}
    </button>
  </main>
</template>
