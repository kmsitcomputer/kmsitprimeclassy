<script setup lang="ts">
/**
 * `content.html` is sanitized server-side (allowlisted tags only) before it
 * is ever persisted — see backend HomepageBlockService::sanitizeContent.
 * v-html here renders that already-cleaned string, not raw admin input.
 */
defineProps<{ content: Record<string, any> }>()
</script>

<template>
  <section
    class="max-w-none rounded-2xl border border-stone-200 bg-white p-6 text-sm leading-relaxed text-stone-600 [&_a]:text-brand-600 [&_h2]:font-display [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-stone-800 [&_h3]:font-display [&_h3]:font-semibold [&_p]:mb-3 dark:border-stone-800 dark:bg-stone-900 dark:text-stone-300 dark:[&_h2]:text-stone-100"
    v-html="content.html"
  />
</template>
