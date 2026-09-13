<script setup lang="ts">
defineProps<{ content: Record<string, any> }>()
</script>

<template>
  <section class="flex flex-col items-center gap-3 rounded-2xl bg-stone-900 px-6 py-10 text-center text-white dark:bg-stone-800">
    <h2 class="font-display text-xl font-semibold">{{ content.heading }}</h2>
    <a
      :href="content.button_url"
      class="inline-flex w-fit items-center gap-1.5 rounded-full bg-brand-500 px-6 py-3 text-sm font-semibold text-white hover:bg-brand-600"
    >
      {{ content.button_label }}
    </a>
  </section>
</template>
