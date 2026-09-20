<script setup lang="ts">
defineProps<{ content: { url?: string; image_url?: string } }>()
</script>

<template>
  <component :is="content.url ? 'a' : 'div'" :href="content.url" class="block overflow-hidden rounded-2xl">
    <img v-if="content.image_url" :src="content.image_url" alt="" class="w-full object-cover" />
  </component>
</template>
