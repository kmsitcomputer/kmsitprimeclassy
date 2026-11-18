<script setup lang="ts">
import HomeView from '@/views/HomeView.vue'
import { useSeo, siteOrigin } from '../composables/useSeo'

useSeo({
  title: 'Prime Classy Cake & Cookies',
  description: 'Toko kue dan kukis Prime Classy — pesan online, kirim ke seluruh Indonesia.',
  path: '/',
  jsonLd: { '@context': 'https://schema.org', '@type': 'WebSite', name: 'Prime Classy', url: siteOrigin() },
})
</script>

<template><HomeView /></template>
