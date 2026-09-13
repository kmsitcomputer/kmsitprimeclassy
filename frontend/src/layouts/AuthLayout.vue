<script setup lang="ts">
import { RouterLink } from 'vue-router'
import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue'
import { useSiteStore } from '@/stores/site'

const site = useSiteStore()
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center bg-stone-50 px-4 py-10 dark:bg-stone-950">
    <div class="mb-6 flex w-full max-w-sm items-center justify-between">
      <RouterLink :to="{ name: 'home' }" class="flex items-center font-display text-2xl font-semibold text-brand-700 dark:text-brand-300">
        <img v-if="site.logoUrl" :src="site.logoUrl" :alt="site.siteTitle" class="h-10 w-auto object-contain" />
        <span v-else>{{ site.siteTitle }}</span>
      </RouterLink>
      <LanguageSwitcher />
    </div>
    <div class="w-full max-w-sm rounded-2xl border border-stone-200 bg-white p-6 shadow-sm dark:border-stone-800 dark:bg-stone-900">
      <slot />
    </div>
  </div>
</template>
