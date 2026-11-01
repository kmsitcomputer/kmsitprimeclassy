<script setup lang="ts">
import { computed } from 'vue'
import BrandIcon from '../BrandIcon.vue'
import { safeHref } from '@/utils/safeUrl'

const props = defineProps<{ content: { heading?: string; description?: string; layout?: string; platforms?: Record<string, { enabled?: boolean; url?: string }> } }>()
const labels: Record<string, string> = { instagram: 'Instagram', tiktok: 'TikTok', facebook: 'Facebook' }

// Only configured, enabled platforms with a safe link; the CMS order is kept.
const items = computed(() =>
  Object.entries(props.content.platforms ?? {}).flatMap(([key, item]) => {
    const href = item?.enabled ? safeHref(item.url) : undefined
    return href ? [{ key, href, label: labels[key] ?? key }] : []
  }),
)
// Mobile: one row of cards (up to three); the card width follows how many are configured.
const columns = computed(() => Math.min(items.value.length, 3))
</script>
<template>
  <!-- The page container supplies width and the gap between blocks; no vertical padding of its own. -->
  <section v-if="items.length">
    <h2 v-if="content.heading" class="text-xl font-semibold sm:text-2xl">{{ content.heading }}</h2>
    <p v-if="content.description" class="mt-1.5 text-sm text-stone-600 sm:mt-2 sm:text-base">{{ content.description }}</p>
    <div
      :class="content.layout === 'compact'
        ? 'mt-4 grid grid-cols-[repeat(var(--cols),minmax(0,1fr))] gap-2.5 sm:mt-5 sm:flex sm:flex-wrap sm:gap-3'
        : 'mt-4 grid grid-cols-[repeat(var(--cols),minmax(0,1fr))] gap-2.5 sm:mt-5 sm:grid-cols-3 sm:gap-4'"
      :style="{ '--cols': columns }"
    >
      <a v-for="item in items" :key="item.key" :href="item.href" target="_blank" rel="noopener noreferrer" class="flex min-w-0 flex-col items-center justify-center gap-2 rounded-2xl border border-brand-500/25 bg-gradient-to-br from-stone-900 to-brand-900 px-2 py-3.5 text-[12.5px] font-semibold leading-tight text-white shadow-sm transition active:scale-[0.98] sm:inline-flex sm:flex-row sm:gap-2 sm:rounded-xl sm:border-current sm:bg-none sm:p-4 sm:text-base sm:leading-normal sm:text-inherit sm:shadow-none sm:hover:bg-stone-50">
        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-white text-stone-900 sm:block sm:h-auto sm:w-auto sm:rounded-none sm:bg-transparent sm:text-inherit"><BrandIcon :name="item.key" :size="22" aria-hidden="true" /></span>
        <span class="max-w-full truncate">{{ item.label }}</span>
      </a>
    </div>
  </section>
</template>
