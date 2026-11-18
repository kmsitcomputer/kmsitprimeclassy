<script setup lang="ts">
import PageView from '@/views/PageView.vue'
import { pickTranslation } from '@/utils/i18nContent'
import { useLocaleStore } from '@/stores/locale'
import { useSsrData } from '@/utils/ssrData'
import { fetchPageSeed, pageKey, type PageSeed } from '@/utils/ssrLoaders'
import { useSeo, stripHtml, contentLocalesFor } from '../composables/useSeo'

const props = defineProps<{ slug: string }>()
const locale = useLocaleStore()
const ssr = useSsrData()
const seed = await ssr.load<PageSeed>(pageKey(props.slug), () => fetchPageSeed(props.slug)).catch(() => null)
const contentLocales = seed ? contentLocalesFor(seed.page.translations, seed.languages) : []

useSeo(() => {
  const tr = seed ? pickTranslation(seed.page.translations, seed.languages, locale.locale) : null
  return {
    title: tr ? `${tr.title} — Prime Classy` : 'Prime Classy',
    description: tr ? stripHtml(tr.body) : null,
    path: `/page/${props.slug}`,
    notFound: !seed || !tr,
    contentLocales,
  }
})
</script>

<template><PageView :slug="slug" /></template>
