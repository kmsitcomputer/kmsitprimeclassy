<script setup lang="ts">
import CategoriesView from '@/views/CategoriesView.vue'
import { useSeo } from '../composables/useSeo'

useSeo({
  title: 'Kategori Produk — Prime Classy',
  description: 'Telusuri produk Prime Classy berdasarkan kategori.',
  path: '/categories',
})
</script>

<template><CategoriesView /></template>
