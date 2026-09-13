<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import ShopLayout from '@/layouts/ShopLayout.vue'
import { getPage } from '@/api/cms'
import type { Page } from '@/api/cmsContent'
import { listLanguages, type Language } from '@/api/languages'
import { useLocaleStore } from '@/stores/locale'
import { pickTranslation } from '@/utils/i18nContent'

const props = defineProps<{ slug: string }>()
const locale = useLocaleStore()

const page = ref<Page | null>(null)
const languages = ref<Language[]>([])
const loading = ref(true)
const notFound = ref(false)

async function load() {
  loading.value = true
  notFound.value = false
  try {
    const [loaded, languageList] = await Promise.all([getPage(props.slug), listLanguages()])
    page.value = loaded
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

    <div v-else-if="notFound" class="py-16 text-center text-sm text-stone-500">Halaman tidak ditemukan.</div>

    <article v-else-if="page" class="mx-auto max-w-3xl py-8">
      <h1 class="mb-6 font-display text-2xl font-semibold text-stone-900 dark:text-stone-50">
        {{ pickTranslation(page.translations, languages, locale.locale)?.title }}
      </h1>
      <div
        class="cms-content text-sm leading-relaxed text-stone-700 dark:text-stone-300"
        v-html="pickTranslation(page.translations, languages, locale.locale)?.body ?? ''"
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
