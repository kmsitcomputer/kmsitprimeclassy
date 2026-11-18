<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import ProductListingView from '@/views/ProductListingView.vue'
import { useSsrData } from '@/utils/ssrData'
import { fetchProductListingSeed, productListingKey, type ProductListingSeed } from '@/utils/ssrLoaders'
import { useSeo } from '../composables/useSeo'

const { t } = useI18n()
const route = useRoute()
const q = route.query
// Filtered/sorted/searched permutations are duplicate content: crawlable (follow) but not indexed. Category and page
// stay indexable and are SELF-canonical (page N is its own canonical, never page 1), so pagination URLs load and index directly.
const filtered = Boolean(q.search || q.sort || q.min_price || q.max_price)
const page = Math.max(1, Number(q.page) || 1)
const params = new URLSearchParams()
if (q.category) params.set('category', String(q.category))
if (page > 1) params.set('page', String(page))
const qs = params.toString()

// Same key as the view → one API call. A page beyond the last one is a real 404, not an indexable empty page.
const ssr = useSsrData()
const seed = await ssr.load<ProductListingSeed>(productListingKey(q), () => fetchProductListingSeed(q)).catch(() => null)
const outOfRange = !!seed && page > 1 && page > seed.meta.last_page

useSeo(() => ({
  title: `${t('productList.allProducts')}${q.category ? ` · ${String(q.category)}` : ''}${page > 1 ? ` · ${page}` : ''} — Prime Classy`,
  path: `/products${qs ? `?${qs}` : ''}`,
  noindex: filtered,
  notFound: outOfRange,
}))
</script>

<template><ProductListingView /></template>
