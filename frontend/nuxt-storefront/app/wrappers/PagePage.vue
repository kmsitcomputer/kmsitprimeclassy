<script setup lang="ts">
import PageView from '@/views/PageView.vue'
import { pickTranslation } from '@/utils/i18nContent'
import { useSsrData } from '@/utils/ssrData'
import { fetchPageSeed, pageKey, type PageSeed } from '@/utils/ssrLoaders'
import { useSeo, stripHtml } from '../composables/useSeo'

const props = defineProps<{ slug: string }>()
const ssr = useSsrData()
const seed = await ssr.load<PageSeed>(pageKey(props.slug), () => fetchPageSeed(props.slug)).catch(() => null)
const tr = seed ? pickTranslation(seed.page.translations, seed.languages, 'id') : null

useSeo({
  title: tr ? `${tr.title} — Prime Classy` : 'Halaman tidak ditemukan — Prime Classy',
  description: tr ? stripHtml(tr.body) : null,
  path: `/page/${props.slug}`,
  notFound: !seed || !tr,
})
</script>

<template><PageView :slug="slug" /></template>
