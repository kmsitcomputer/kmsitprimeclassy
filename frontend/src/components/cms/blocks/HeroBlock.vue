<script setup lang="ts">
defineProps<{ content: Record<string, any> }>()
</script>

<template>
  <section
    class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-600 to-brand-800 px-6 py-10 text-white sm:px-10"
    :style="content.image_url ? { backgroundImage: `url(${content.image_url})`, backgroundSize: 'cover', backgroundPosition: 'center' } : {}"
  >
    <div v-if="content.image_url" class="absolute inset-0 bg-black/40" />
    <div class="relative flex flex-col gap-3">
      <h1 class="font-display text-2xl font-semibold leading-tight sm:text-3xl">{{ content.heading }}</h1>
      <p v-if="content.subheading" class="max-w-xl text-brand-100">{{ content.subheading }}</p>
      <a
        v-if="content.cta_label"
        :href="content.cta_url ?? '#'"
        class="mt-2 inline-flex w-fit items-center gap-1.5 rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-brand-700 hover:bg-brand-50"
      >
        {{ content.cta_label }}
      </a>
    </div>
  </section>
</template>
