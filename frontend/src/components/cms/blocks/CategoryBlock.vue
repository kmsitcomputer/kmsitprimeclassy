<script setup lang="ts">
defineProps<{ content: Record<string, any> }>()
</script>

<template>
  <section v-if="content.categories?.length">
    <h2 v-if="content.heading" class="mb-3 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">
      {{ content.heading }}
    </h2>
    <div class="scrollbar-none flex gap-3 overflow-x-auto pb-1">
      <RouterLink
        v-for="category in content.categories"
        :key="category.id"
        :to="{ name: 'products', query: { category: category.slug } }"
        class="flex shrink-0 flex-col items-center gap-2 rounded-2xl border border-stone-200 bg-white px-5 py-3 text-center text-sm font-medium text-stone-700 hover:border-brand-300 hover:text-brand-700 dark:border-stone-800 dark:bg-stone-900 dark:text-stone-200"
      >
        {{ category.name }}
      </RouterLink>
    </div>
  </section>
</template>
