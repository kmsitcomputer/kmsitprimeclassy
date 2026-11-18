<script setup lang="ts">
import HomeView from '@/views/HomeView.vue'
import { useLocaleStore } from '@/stores/locale'
import { useSeo, siteOrigin } from '../composables/useSeo'

const locale = useLocaleStore()
const origin = siteOrigin() // resolved at setup: getters below run later, outside the Nuxt instance context
// Brand title is not language-specific. The description is only published in the language it is written in (id):
// no translation exists in the catalogues for it, and none is invented.
useSeo(() => ({
  title: 'Prime Classy Cake & Cookies',
  description: locale.locale === 'id' ? 'Toko kue dan kukis Prime Classy — pesan online, kirim ke seluruh Indonesia.' : null,
  path: '/',
  jsonLd: { '@context': 'https://schema.org', '@type': 'WebSite', name: 'Prime Classy', url: origin },
}))
</script>

<template><HomeView /></template>
