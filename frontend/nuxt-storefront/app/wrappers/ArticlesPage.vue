<script setup lang="ts">
import ArticlesListingView from '@/views/ArticlesListingView.vue'
import { useSsrData } from '@/utils/ssrData'
import { articlesKey, fetchArticlesPageSeed, type ArticlesPageSeed } from '@/utils/ssrLoaders'
import { useSeo } from '../composables/useSeo'

const route = useRoute()
const page = Math.max(1, Number(route.query.page) || 1)
const ssr = useSsrData()
const seed = await ssr.load<ArticlesPageSeed>(articlesKey(page), () => fetchArticlesPageSeed(page)).catch(() => null)
// The listing view renders this heading in Indonesian for every language (existing behaviour), so the metadata matches it.
useSeo(() => ({
  title: `Artikel & Berita${page > 1 ? ` — Halaman ${page}` : ''} — Prime Classy`,
  path: `/articles${page > 1 ? `?page=${page}` : ''}`,
  notFound: !!seed && page > 1 && page > seed.lastPage,
}))
</script>

<template><ArticlesListingView /></template>
