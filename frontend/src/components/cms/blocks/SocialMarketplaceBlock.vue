<script setup lang="ts">
/** The storefront's merged Social Media + Marketplace card (see mergeSocialMarketplaceBlocks). */
import { computed } from 'vue'
import SocialMarketplaceCard from './SocialMarketplaceCard.vue'
import { platformItems, type PlatformConfig } from '@/utils/socialMarketplace'

const props = defineProps<{ content: { heading?: string; description?: string; platforms?: Record<string, PlatformConfig> } }>()
const items = computed(() => platformItems(props.content.platforms))
</script>
<template><SocialMarketplaceCard :heading="content.heading" :description="content.description" :items="items" /></template>
