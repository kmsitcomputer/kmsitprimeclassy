<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
defineProps<{ content: Record<string, any> }>()
</script>

<template>
  <section class="overflow-hidden rounded-2xl border border-stone-200 dark:border-stone-800">
    <div class="grid grid-cols-1 sm:grid-cols-2">
      <img v-if="content.image_url" :src="content.image_url" alt="" class="h-48 w-full object-cover sm:h-full" />
      <div class="flex flex-col justify-center gap-2 bg-white p-6 dark:bg-stone-900">
        <h2 class="font-display text-xl font-semibold text-stone-800 dark:text-stone-100">{{ content.heading }}</h2>
        <p v-if="content.description" class="text-sm text-stone-500 dark:text-stone-400">{{ content.description }}</p>
        <a v-if="content.url" :href="content.url" class="mt-2 inline-flex w-fit rounded-full bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
          {{ t('cms.readMore') }}
        </a>
      </div>
    </div>
  </section>
</template>
