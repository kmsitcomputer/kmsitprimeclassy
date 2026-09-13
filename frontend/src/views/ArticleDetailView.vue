<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import ShopLayout from '@/layouts/ShopLayout.vue'
import { getArticle } from '@/api/cms'
import type { Article } from '@/api/cmsContent'
import { listLanguages, type Language } from '@/api/languages'
import { useLocaleStore } from '@/stores/locale'
import { pickTranslation } from '@/utils/i18nContent'

const props = defineProps<{ slug: string }>()
const locale = useLocaleStore()

const article = ref<Article | null>(null)
const languages = ref<Language[]>([])
const loading = ref(true)
const notFound = ref(false)

async function load() {
  loading.value = true
  notFound.value = false
  try {
    const [loaded, languageList] = await Promise.all([getArticle(props.slug), listLanguages()])
    article.value = loaded
    languages.value = languageList
  } catch {
    notFound.value = true
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.slug, load)
</script>

<template>
  <ShopLayout>
    <div v-if="loading" class="py-16 text-center text-sm text-stone-500">Memuat...</div>

    <div v-else-if="notFound" class="py-16 text-center text-sm text-stone-500">Artikel tidak ditemukan.</div>

    <article v-else-if="article" class="mx-auto max-w-3xl py-8">
      <p class="text-xs uppercase tracking-wide text-brand-600 dark:text-brand-400">{{ article.type }}</p>
      <h1 class="mb-1 mt-1 font-display text-2xl font-semibold text-stone-900 dark:text-stone-50">
        {{ pickTranslation(article.translations, languages, locale.locale)?.title }}
      </h1>
      <p v-if="article.author_name" class="mb-6 text-sm text-stone-500 dark:text-stone-400">
        Oleh {{ article.author_name }}
      </p>
      <img
        v-if="article.cover_image_url"
        :src="article.cover_image_url"
        class="mb-6 w-full rounded-xl object-cover"
        alt=""
      />
      <div
        class="cms-content text-sm leading-relaxed text-stone-700 dark:text-stone-300"
        v-html="pickTranslation(article.translations, languages, locale.locale)?.body ?? ''"
      />
    </article>
  </ShopLayout>
</template>

<style scoped>
.cms-content :deep(h1),
.cms-content :deep(h2),
.cms-content :deep(h3) {
  margin: 1.25em 0 0.5em;
  font-weight: 600;
  color: inherit;
}
.cms-content :deep(p) {
  margin: 0.75em 0;
}
.cms-content :deep(ul),
.cms-content :deep(ol) {
  margin: 0.75em 0;
  padding-left: 1.5em;
}
.cms-content :deep(a) {
  color: rgb(180 83 9);
  text-decoration: underline;
}
</style>
