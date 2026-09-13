<script setup lang="ts">
import { formatDate } from '@/utils/format'
defineProps<{ content: Record<string, any> }>()
</script>

<template>
  <section v-if="content.articles?.length">
    <h2 v-if="content.heading" class="mb-3 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">
      {{ content.heading }}
    </h2>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
      <a
        v-for="article in content.articles"
        :key="article.id"
        :href="`/articles/${article.slug}`"
        class="overflow-hidden rounded-2xl border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900"
      >
        <div class="aspect-video overflow-hidden bg-stone-100 dark:bg-stone-800">
          <img v-if="article.cover_image_url" :src="article.cover_image_url" :alt="article.title" class="h-full w-full object-cover" />
        </div>
        <div class="p-3">
          <h3 class="line-clamp-2 text-sm font-medium text-stone-800 dark:text-stone-100">{{ article.title }}</h3>
          <p v-if="article.published_at" class="mt-1 text-xs text-stone-400">{{ formatDate(article.published_at) }}</p>
        </div>
      </a>
    </div>
  </section>
</template>
