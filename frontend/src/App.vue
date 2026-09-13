<script setup lang="ts">
import { onMounted } from 'vue'
import { useUiStore } from '@/stores/ui'
import { useLocaleStore } from '@/stores/locale'
import { useSiteStore } from '@/stores/site'

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
  <RouterView />
</template>
