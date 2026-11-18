<script setup lang="ts">
import ArticleDetailView from '@/views/ArticleDetailView.vue'
import { pickTranslation } from '@/utils/i18nContent'
import { useSsrData } from '@/utils/ssrData'
import { fetchArticleSeed, articleKey, type ArticleSeed } from '@/utils/ssrLoaders'
import { useSeo, stripHtml } from '../composables/useSeo'

const props = defineProps<{ slug: string }>()
const ssr = useSsrData()
const seed = await ssr.load<ArticleSeed>(articleKey(props.slug), () => fetchArticleSeed(props.slug)).catch(() => null)
const article = seed?.article ?? null
// The server renders the default locale; metadata follows the same translation the view renders.
const tr = seed ? pickTranslation(seed.article.translations, seed.languages, 'id') : null

useSeo({
  title: tr ? `${tr.title} — Prime Classy` : 'Artikel tidak ditemukan — Prime Classy',
  description: tr ? tr.excerpt || stripHtml(tr.body) : null,
  path: `/articles/${props.slug}`,
  image: article?.cover_image_url,
  ogType: 'article',
  notFound: !article || !tr,
  jsonLd:
    article && tr
      ? {
          '@context': 'https://schema.org',
          '@type': article.type === 'news' ? 'NewsArticle' : 'Article',
          headline: tr.title,
          datePublished: article.published_at ?? undefined,
          dateModified: article.updated_at,
          image: article.cover_image_url ?? undefined,
          author: article.author_name ? { '@type': 'Person', name: article.author_name } : undefined,
        }
      : undefined,
})
</script>

<template><ArticleDetailView :slug="slug" /></template>
