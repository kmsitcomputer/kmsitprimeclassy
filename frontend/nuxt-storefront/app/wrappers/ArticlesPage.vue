<script setup lang="ts">
import ArticlesListingView from '@/views/ArticlesListingView.vue'
import { useSeo } from '../composables/useSeo'

useSeo({
  title: 'Artikel & Berita — Prime Classy',
  description: 'Artikel, tips, dan berita terbaru dari Prime Classy.',
  path: '/articles',
})
</script>

<template><ArticlesListingView /></template>
