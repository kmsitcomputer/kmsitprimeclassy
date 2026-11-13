<script setup lang="ts">
/**
 * Owner-approved design: ONE white rounded card — a centered title, a centered description and a single
 * row of small circular platform icons. No brown tiles, no separate headings, no side-by-side layout.
 * Icons are labelled for assistive tech (aria-label), links open safely in a new tab.
 */
import BrandIcon from '../BrandIcon.vue'
import type { PlatformItem } from '@/utils/socialMarketplace'

defineProps<{ heading?: string; description?: string; items: PlatformItem[] }>()
</script>

<template>
  <section v-if="items.length" data-testid="social-marketplace-card">
    <div class="mx-auto max-w-2xl rounded-2xl border border-stone-200 bg-white px-4 py-6 text-center text-stone-900 shadow-sm sm:px-8 sm:py-8">
      <h2 v-if="heading" class="text-lg font-semibold sm:text-2xl" data-testid="smc-title">{{ heading }}</h2>
      <p v-if="description" class="mx-auto mt-1.5 max-w-prose text-sm text-stone-600 sm:text-base" data-testid="smc-description">{{ description }}</p>
      <ul class="mt-4 flex flex-nowrap items-center justify-center gap-2.5 sm:mt-5 sm:gap-4" data-testid="smc-icons">
        <li v-for="item in items" :key="item.key" class="shrink-0">
          <a
            :href="item.href"
            target="_blank"
            rel="noopener noreferrer"
            :aria-label="item.label"
            :title="item.label"
            :data-platform="item.key"
            class="grid h-10 w-10 place-items-center rounded-full border border-stone-200 bg-white shadow-sm transition hover:border-brand-300 hover:shadow active:scale-95 sm:h-12 sm:w-12"
          >
            <BrandIcon :name="item.key" :size="22" aria-hidden="true" />
          </a>
        </li>
      </ul>
    </div>
  </section>
</template>
