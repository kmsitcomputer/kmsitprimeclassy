<script setup lang="ts">
import { computed } from 'vue'
import { useSiteStore } from '@/stores/site'
import { useLocaleStore } from '@/stores/locale'
import { localeDir } from '@/i18n'
import OfflineBanner from '@/components/pwa/OfflineBanner.vue'
import PwaUpdateBanner from '@/components/pwa/PwaUpdateBanner.vue'

// Mirrors the shell of the SPA's src/App.vue (banners + page outlet). Session/theme/locale/PWA start-up that
// the SPA does in App.vue's onMounted and router guard lives in plugins/90.client-init.client.ts.
const site = useSiteStore()
const locale = useLocaleStore()
// Brand identity (logo/name) comes from the PUBLIC settings endpoint: render it on the server so the
// header is identical in the first paint. Pinia state travels to the client in the Nuxt payload.
if (import.meta.server) await site.load()

useHead({
  // Follows the locale store (server: 'id'; client: the persisted choice after hydration) so unhead never resets it.
  htmlAttrs: { lang: computed(() => locale.locale), dir: computed(() => localeDir(locale.locale)) },
  link: computed(() => (site.faviconUrl ? [{ rel: 'icon', href: site.faviconUrl }] : [])),
})
</script>

<template>
  <PwaUpdateBanner />
  <OfflineBanner />
  <NuxtPage />
</template>
