<script setup lang="ts">
/**
 * Catalog sidebar / drawer category tree: real parent→child hierarchy at any depth, expand/collapse per branch,
 * active category highlighted, ancestors of the active category kept open by the owner (ProductListingView).
 * Plain disclosure pattern: every control is a native <button>, so Tab / Enter / Space work with no extra scripting.
 */
import { useI18n } from 'vue-i18n'
import type { CategoryNode } from '@/utils/categoryTree'
import AppIcon from '@/components/ui/AppIcon.vue'

defineProps<{ nodes: CategoryNode[]; activeSlug: string; expanded: Set<number> }>()
const emit = defineEmits<{ select: [slug: string]; toggle: [id: number] }>()
const { t } = useI18n()
</script>

<template>
  <ul class="space-y-0.5">
    <li v-for="node in nodes" :key="node.category.id" :data-category-id="node.category.id" :data-depth="node.depth">
      <div
        class="flex items-center rounded-lg"
        :class="activeSlug === node.category.slug ? 'bg-brand-50 dark:bg-brand-900/40' : 'hover:bg-stone-100 dark:hover:bg-stone-800'"
      >
        <button
          v-if="node.children.length"
          type="button"
          class="grid h-9 w-9 flex-none place-items-center rounded-lg text-stone-500 focus-visible:outline-2 focus-visible:outline-brand-600 dark:text-stone-400"
          :aria-expanded="expanded.has(node.category.id)"
          :aria-controls="`cat-children-${node.category.id}`"
          :aria-label="t(expanded.has(node.category.id) ? 'productList.collapseCategory' : 'productList.expandCategory', { name: node.category.name })"
          data-testid="category-toggle"
          @click="emit('toggle', node.category.id)"
        >
          <AppIcon name="chevron-right" :size="16" class="transition-transform" :class="expanded.has(node.category.id) && 'rotate-90'" />
        </button>
        <span v-else class="h-9 w-9 flex-none" aria-hidden="true" />
        <button
          type="button"
          class="min-h-9 flex-1 rounded-lg py-1.5 pr-2 text-left text-sm focus-visible:outline-2 focus-visible:outline-brand-600"
          :class="[
            node.depth === 0 ? 'font-semibold' : 'font-normal',
            activeSlug === node.category.slug ? 'font-semibold text-brand-700 dark:text-brand-200' : 'text-stone-700 dark:text-stone-200',
          ]"
          :aria-current="activeSlug === node.category.slug ? 'true' : undefined"
          data-testid="category-select"
          @click="emit('select', node.category.slug)"
        >
          {{ node.category.name }}
        </button>
      </div>
      <div
        v-if="node.children.length && expanded.has(node.category.id)"
        :id="`cat-children-${node.category.id}`"
        class="ml-[18px] border-l border-stone-200 pl-1.5 dark:border-stone-700"
      >
        <CatalogCategoryTree :nodes="node.children" :active-slug="activeSlug" :expanded="expanded" @select="emit('select', $event)" @toggle="emit('toggle', $event)" />
      </div>
    </li>
  </ul>
</template>
