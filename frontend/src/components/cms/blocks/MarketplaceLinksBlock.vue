<script setup lang="ts">
defineProps<{ content: { heading?: string; description?: string; layout?: string; platforms?: Record<string, { enabled?: boolean; url?: string }> } }>()
const labels: Record<string, string> = { tokopedia: 'Tokopedia', shopee: 'Shopee' }
</script>
<template>
  <section class="mx-auto max-w-6xl px-4 py-10">
    <h2 v-if="content.heading" class="text-2xl font-semibold">{{ content.heading }}</h2>
    <p v-if="content.description" class="mt-2 text-stone-600">{{ content.description }}</p>
    <div :class="content.layout === 'horizontal' ? 'mt-5 flex flex-wrap gap-3' : 'mt-5 grid gap-4 sm:grid-cols-2'">
      <a v-for="(item, key) in content.platforms" v-show="item.enabled && item.url" :key="key" :href="item.url" target="_blank" rel="noopener noreferrer" class="rounded-xl border p-4 text-center font-semibold hover:bg-stone-50">{{ labels[key] ?? key }}</a>
    </div>
  </section>
</template>
