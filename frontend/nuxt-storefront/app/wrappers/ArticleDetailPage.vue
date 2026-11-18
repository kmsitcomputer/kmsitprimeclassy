<script setup lang="ts">
import ArticleDetailView from '@/views/ArticleDetailView.vue'
import { pickTranslation } from '@/utils/i18nContent'
import { useLocaleStore } from '@/stores/locale'
import { useSsrData } from '@/utils/ssrData'
import { fetchArticleSeed, articleKey, type ArticleSeed } from '@/utils/ssrLoaders'
import { useSeo, stripHtml, contentLocalesFor } from '../composables/useSeo'

const props = defineProps<{ slug: string }>()
const locale = useLocaleStore()
const ssr = useSsrData()
const seed = await ssr.load<ArticleSeed>(articleKey(props.slug), () => fetchArticleSeed(props.slug)).catch(() => null)
const article = seed?.article ?? null
const contentLocales = seed ? contentLocalesFor(seed.article.translations, seed.languages) : []

useSeo(() => {
  // Same translation the view renders for the active language (with the same fallback).
  const tr = seed ? pickTranslation(seed.article.translations, seed.languages, locale.locale) : null
  return {
    title: tr ? `${tr.title} — Prime Classy` : 'Prime Classy',
    description: tr ? tr.excerpt || stripHtml(tr.body) : null,
    path: `/articles/${props.slug}`,
    image: article?.cover_image_url,
    ogType: 'article',
    notFound: !article || !tr,
    contentLocales,
    jsonLd:
      article && tr
        ? {
            '@context': 'https://schema.org',
            '@type': article.type === 'news' ? 'NewsArticle' : 'Article',
            headline: tr.title,
            inLanguage: locale.locale,
            datePublished: article.published_at ?? undefined,
            dateModified: article.updated_at,
            image: article.cover_image_url ?? undefined,
            author: article.author_name ? { '@type': 'Person', name: article.author_name } : undefined,
          }
        : undefined,
  }
})
</script>

<template><ArticleDetailView :slug="slug" /></template>
