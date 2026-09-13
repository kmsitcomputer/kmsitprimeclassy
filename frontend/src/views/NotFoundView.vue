<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import ShopLayout from '@/layouts/ShopLayout.vue'

const { t } = useI18n()
</script>

<template>
  <ShopLayout>
    <div class="flex flex-col items-center gap-3 py-24 text-center">
      <p class="font-display text-4xl font-semibold text-brand-600 dark:text-brand-400">404</p>
      <p class="text-sm text-stone-500 dark:text-stone-400">{{ t('notFound.message') }}</p>
      <RouterLink :to="{ name: 'home' }" class="text-sm font-medium text-brand-600 dark:text-brand-400">{{ t('notFound.backHome') }}</RouterLink>
    </div>
  </ShopLayout>
</template>
