<script setup lang="ts">
import { ref, onMounted, onServerPrefetch, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ShopLayout from '@/layouts/ShopLayout.vue'
import { listArticles } from '@/api/cms'
import type { Article } from '@/api/cmsContent'
import { listLanguages, type Language } from '@/api/languages'
import { useLocaleStore } from '@/stores/locale'
import { pickTranslation } from '@/utils/i18nContent'
import { useSsrData } from '@/utils/ssrData'
import { articlesKey, fetchArticlesPageSeed, type ArticlesPageSeed } from '@/utils/ssrLoaders'

const locale = useLocaleStore()
const route = useRoute()
const router = useRouter()
/** The page lives in the URL (`?page=N`) so every result page can be loaded, shared and crawled directly. */
const pageFromRoute = () => Math.max(1, Number(Array.isArray(route.query.page) ? route.query.page[0] : route.query.page) || 1)
const initialPage = pageFromRoute()

// Nuxt storefront SSR seam (no-op in the SPA): the requested page is server-rendered.
const ssr = useSsrData()
const seeded = ssr.get<ArticlesPageSeed>(articlesKey(initialPage))
const articles = ref<Article[]>(seeded?.items ?? [])
const languages = ref<Language[]>(seeded?.languages ?? [])
const loading = ref(!seeded)
const currentPage = ref(seeded?.currentPage ?? initialPage)
const lastPage = ref(seeded?.lastPage ?? 1)

async function load(page = 1) {
  loading.value = true
  const [result, languageList] = await Promise.all([listArticles({ page }), listLanguages()])
  articles.value = result.items
  currentPage.value = result.currentPage
  lastPage.value = result.lastPage
  languages.value = languageList
  loading.value = false
}

onServerPrefetch(async () => {
  try {
    const first = await ssr.load<ArticlesPageSeed>(articlesKey(initialPage), () => fetchArticlesPageSeed(initialPage))
    articles.value = first.items
    currentPage.value = first.currentPage
    lastPage.value = first.lastPage
    languages.value = first.languages
  } finally {
    loading.value = false
  }
})

onMounted(() => {
  if (!seeded) load(pageFromRoute())
})

function pageLocation(page: number) {
  const query = { ...route.query }
  delete query.page
  return { query: { ...query, ...(page > 1 ? { page: String(page) } : {}) } }
}
/** Real, crawlable URL of a result page; clicking still navigates in-app (the route change below drives the load). */
function pageHref(page: number): string {
  return router.resolve?.(pageLocation(page))?.href ?? '#'
}
function goTo(page: number) {
  router.push(pageLocation(page))
}
watch(
  () => route.query.page,
  () => load(pageFromRoute()),
)
</script>

<template>
  <ShopLayout>
    <div class="mx-auto max-w-4xl py-8">
      <h1 class="mb-6 font-display text-2xl font-semibold text-stone-900 dark:text-stone-50">Artikel & Berita</h1>

      <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>
      <div v-else-if="articles.length === 0" class="text-sm text-stone-500">Belum ada artikel.</div>

      <div v-else class="grid gap-4 sm:grid-cols-2">
        <RouterLink
          v-for="article in articles"
          :key="article.id"
          :to="{ name: 'article-detail', params: { slug: article.slug } }"
          class="overflow-hidden rounded-xl border border-stone-200 bg-white hover:shadow-md dark:border-stone-800 dark:bg-stone-900"
        >
          <img
            v-if="article.cover_image_url"
            :src="article.cover_image_url"
            class="h-40 w-full object-cover"
            alt=""
          />
          <div class="p-4">
            <p class="text-xs uppercase tracking-wide text-brand-600 dark:text-brand-400">{{ article.type }}</p>
            <h2 class="mt-1 font-medium text-stone-900 dark:text-stone-50">
              {{ pickTranslation(article.translations, languages, locale.locale)?.title }}
            </h2>
            <p class="mt-1 line-clamp-2 text-sm text-stone-500 dark:text-stone-400">
              {{ pickTranslation(article.translations, languages, locale.locale)?.excerpt }}
            </p>
          </div>
        </RouterLink>
      </div>

      <div v-if="lastPage > 1" class="mt-6 flex items-center justify-center gap-2 text-sm">
        <a
          v-if="currentPage > 1"
          :href="pageHref(currentPage - 1)"
          rel="prev"
          class="rounded-lg border border-stone-200 px-3 py-1.5 dark:border-stone-700"
          @click.prevent="goTo(currentPage - 1)"
        >
          Sebelumnya
        </a>
        <span v-else class="rounded-lg border border-stone-200 px-3 py-1.5 opacity-40 dark:border-stone-700">Sebelumnya</span>
        <span class="text-stone-500">{{ currentPage }} / {{ lastPage }}</span>
        <a
          v-if="currentPage < lastPage"
          :href="pageHref(currentPage + 1)"
          rel="next"
          class="rounded-lg border border-stone-200 px-3 py-1.5 dark:border-stone-700"
          @click.prevent="goTo(currentPage + 1)"
        >
          Berikutnya
        </a>
        <span v-else class="rounded-lg border border-stone-200 px-3 py-1.5 opacity-40 dark:border-stone-700">Berikutnya</span>
      </div>
    </div>
  </ShopLayout>
</template>
