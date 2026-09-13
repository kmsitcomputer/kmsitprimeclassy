<script setup lang="ts">
defineProps<{ content: Record<string, any> }>()
</script>

<template>
  <section class="rounded-2xl border border-stone-200 bg-white p-6 dark:border-stone-800 dark:bg-stone-900">
    <h2 v-if="content.heading" class="mb-2 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">
      {{ content.heading }}
    </h2>
    <p class="whitespace-pre-line text-sm leading-relaxed text-stone-600 dark:text-stone-300">{{ content.body }}</p>
  </section>
</template>
