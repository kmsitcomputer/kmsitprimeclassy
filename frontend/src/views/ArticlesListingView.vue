<script setup lang="ts">
import { ref, onMounted } from 'vue'
import ShopLayout from '@/layouts/ShopLayout.vue'
import { listArticles } from '@/api/cms'
import type { Article } from '@/api/cmsContent'
import { listLanguages, type Language } from '@/api/languages'
import { useLocaleStore } from '@/stores/locale'
import { pickTranslation } from '@/utils/i18nContent'

const locale = useLocaleStore()
const articles = ref<Article[]>([])
const languages = ref<Language[]>([])
const loading = ref(true)
const currentPage = ref(1)
const lastPage = ref(1)

async function load(page = 1) {
  loading.value = true
  const [result, languageList] = await Promise.all([listArticles({ page }), listLanguages()])
  articles.value = result.items
  currentPage.value = result.currentPage
  lastPage.value = result.lastPage
  languages.value = languageList
  loading.value = false
}

onMounted(() => load())
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
        <button
          type="button"
          class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700"
          :disabled="currentPage <= 1"
          @click="load(currentPage - 1)"
        >
          Sebelumnya
        </button>
        <span class="text-stone-500">{{ currentPage }} / {{ lastPage }}</span>
        <button
          type="button"
          class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700"
          :disabled="currentPage >= lastPage"
          @click="load(currentPage + 1)"
        >
          Berikutnya
        </button>
      </div>
    </div>
  </ShopLayout>
</template>
