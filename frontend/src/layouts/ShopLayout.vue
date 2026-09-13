<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import AppHeader from '@/components/shop/AppHeader.vue'
import BottomNav from '@/components/shop/BottomNav.vue'

const { t } = useI18n()
</script>

<template>
  <div class="flex min-h-screen flex-col bg-stone-50 dark:bg-stone-950">
    <AppHeader />
    <main class="mx-auto w-full max-w-6xl flex-1 px-4 pb-20 pt-4 sm:px-6 sm:pb-8">
      <slot />
    </main>
    <footer class="hidden border-t border-stone-200 py-6 text-center text-xs text-stone-400 sm:block dark:border-stone-800">
      <RouterLink :to="{ name: 'articles' }" class="hover:text-stone-600 hover:underline dark:hover:text-stone-300">
        Artikel &amp; Berita
      </RouterLink>
      <span class="mx-2">&middot;</span>
      <RouterLink :to="{ name: 'store-locator' }" class="hover:text-stone-600 hover:underline dark:hover:text-stone-300">
        Cari Agen / Toko Kami
      </RouterLink>
      <span class="mx-2">&middot;</span>
      {{ t('common.copyright', { year: new Date().getFullYear() }) }}
    </footer>
    <BottomNav />
  </div>
</template>
