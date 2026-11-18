<script setup lang="ts">
import StoreLocatorView from '@/views/StoreLocatorView.vue'
import { useSeo } from '../composables/useSeo'

useSeo({
  title: 'Cari Agen / Toko Kami — Prime Classy',
  description: 'Temukan agen dan toko Prime Classy terdekat.',
  path: '/toko-kami',
})
</script>

<template><StoreLocatorView /></template>
