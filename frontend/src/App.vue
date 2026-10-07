<script setup lang="ts">
import { onMounted } from 'vue'
import { useUiStore } from '@/stores/ui'
import { useLocaleStore } from '@/stores/locale'
import { useSiteStore } from '@/stores/site'
import OfflineBanner from '@/components/pwa/OfflineBanner.vue'
import PwaUpdateBanner from '@/components/pwa/PwaUpdateBanner.vue'

const ui = useUiStore()
const locale = useLocaleStore()
const site = useSiteStore()
onMounted(() => {
  ui.initTheme()
  locale.initLocale()
  site.load()
})
</script>

<template>
  <PwaUpdateBanner />
  <OfflineBanner />
  <RouterView />
</template>
