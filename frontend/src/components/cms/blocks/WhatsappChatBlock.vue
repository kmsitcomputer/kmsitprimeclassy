<script setup lang="ts">
import { computed } from 'vue'
const props = defineProps<{ content: { heading?: string; description?: string; phone: string; message?: string; button_label: string; mode?: string } }>()
const url = computed(() => `https://wa.me/${props.content.phone}?text=${encodeURIComponent(props.content.message ?? '')}`)
</script>
<template>
  <section :class="content.mode === 'floating' ? 'fixed bottom-5 right-5 z-40' : 'mx-auto max-w-6xl px-4 py-8'">
    <div class="rounded-2xl bg-[#25D366] p-5 text-white shadow-lg">
      <h2 v-if="content.heading" class="font-semibold">{{ content.heading }}</h2>
      <p v-if="content.description" class="mt-1 text-sm">{{ content.description }}</p>
      <a :href="url" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 font-semibold text-[#128C7E]" aria-label="WhatsApp">
        <span aria-hidden="true">◉</span>{{ content.button_label }}
      </a>
    </div>
  </section>
</template>
