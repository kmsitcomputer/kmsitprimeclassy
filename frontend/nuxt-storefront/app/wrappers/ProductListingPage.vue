<script setup lang="ts">
import ProductListingView from '@/views/ProductListingView.vue'
import { useSeo } from '../composables/useSeo'

const route = useRoute()
const q = route.query
// Filtered/sorted/searched permutations are duplicate content: crawlable (follow) but not indexed and
// canonicalised to a stable URL. Category and page remain indexable.
const filtered = Boolean(q.search || q.sort || q.min_price || q.max_price)
const params = new URLSearchParams()
if (q.category) params.set('category', String(q.category))
if (q.page && Number(q.page) > 1) params.set('page', String(q.page))
const qs = params.toString()

useSeo({
  title: q.category ? `Produk ${String(q.category)} — Prime Classy` : 'Semua Produk — Prime Classy',
  description: 'Jelajahi seluruh kue dan kukis Prime Classy. Filter berdasarkan kategori dan harga.',
  path: `/products${qs ? `?${qs}` : ''}`,
  noindex: filtered,
})
</script>

<template><ProductListingView /></template>
