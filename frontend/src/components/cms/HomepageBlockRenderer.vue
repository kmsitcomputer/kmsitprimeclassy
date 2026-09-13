<script setup lang="ts">
import { computed } from 'vue'
import type { HomepageBlock } from '@/api/cms'
import HeroBlock from './blocks/HeroBlock.vue'
import BannerBlock from './blocks/BannerBlock.vue'
import CategoryBlock from './blocks/CategoryBlock.vue'
import ProductBlock from './blocks/ProductBlock.vue'
import PromotionalBlock from './blocks/PromotionalBlock.vue'
import TextBlock from './blocks/TextBlock.vue'
import ImageBlock from './blocks/ImageBlock.vue'
import ArticleBlock from './blocks/ArticleBlock.vue'
import CtaBlock from './blocks/CtaBlock.vue'
import CustomBlock from './blocks/CustomBlock.vue'

const props = defineProps<{ block: HomepageBlock }>()

/**
 * One entry per type registered in the backend's App\Support\HomepageBlockTypes.
 * Adding a new block kind = one new component + one new line here — the
 * homepage itself never needs its markup restructured for it.
 */
const COMPONENTS: Record<string, unknown> = {
  hero: HeroBlock,
  banner: BannerBlock,
  category: CategoryBlock,
  product: ProductBlock,
  promotional: PromotionalBlock,
  text: TextBlock,
  image: ImageBlock,
  article: ArticleBlock,
  cta: CtaBlock,
  custom: CustomBlock,
}

const component = computed(() => COMPONENTS[props.block.type] ?? null)
</script>

<template>
  <component :is="component" v-if="component" :content="block.content" />
</template>
